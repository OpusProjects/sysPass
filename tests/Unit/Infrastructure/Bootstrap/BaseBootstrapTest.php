<?php

declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Tests\Unit\Infrastructure\Bootstrap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\File\FileSystem;

/**
 * Covers src/Base.php — the real composition root every entry point runs
 * (public/index.php, public/api.php and bin/cli.php all do
 * `$dic = require 'src/Base.php'`). It is a procedural script that defines global
 * constants (APP_PATH, DEBUG), loads the .env, and returns the built php-di
 * container. Because of those global side effects it cannot be require()d in the
 * test process — tests/bootstrap.php has already required Infrastructure/Functions.php
 * (a "cannot redeclare" fatal if Base.php requires it again) and already defined
 * APP_PATH against a vfsStream URL (Base.php tries to define() it again, against the
 * real APP_ROOT) — so it is exercised in a fresh subprocess instead, one that never
 * loads tests/bootstrap.php. php -r does not process auto_prepend_file the way a real
 * script file does (production relies on it to autoload SP\ classes before Base.php's
 * own code runs), so every script below requires the autoloader explicitly first.
 *
 * Covers all three modules ('web' implicitly via Base.php's own APP_MODULE default,
 * 'api' and 'cli' explicitly), and both branches Base.php can build: DEBUG=true (live,
 * as above) and DEBUG=false with CACHE_PATH pointed at a throwaway directory — the
 * compiled branch a real deployment actually runs, with enableCompilation() and
 * writeProxiesToFile() turned on exactly as Base.php turns them on.
 *
 * Line coverage cannot see any of this: the work these tests are actually pinning
 * happens inside a child process, and the coverage driver only instruments the parent
 * PHPUnit process. That is the reason src/Base.php still reads as uncovered in a
 * coverage report despite being exercised, thoroughly, right here — do not "fix" that
 * gap by writing another copy of this test; there is nothing more for coverage
 * tooling to see.
 */
#[Group('unitary')]
class BaseBootstrapTest extends TestCase
{
    private const BASE = REAL_APP_ROOT . '/src/Base.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(REAL_APP_ROOT . '/.env')) {
            self::markTestSkipped('Base.php requires a .env at the app root (written by the dev image entrypoint).');
        }
    }

    public function testBaseExists(): void
    {
        self::assertFileExists(self::BASE);
    }

    /**
     * Requiring Base.php must return a built, resolvable php-di container — this guards
     * the bootstrap's definition wiring (e.g. the Domain-before-Core ordering).
     */
    public function testBaseReturnsResolvableContainer(): void
    {
        // DEBUG=true so the container is not compiled to disk during the test.
        // Base.php uses autoloaded SP\ classes before loading the autoloader (in
        // production the dev image sets auto_prepend_file=vendor/autoload.php); load
        // the autoloader first to reproduce that.
        $script = <<<'PHP'
            require getenv('SP_AUTOLOAD');
            $c = require getenv('SP_BASE');
            if (!$c instanceof \Psr\Container\ContainerInterface) {
                echo 'NOT_A_CONTAINER';
                exit(1);
            }
            // A config-free service: validates the container actually resolves.
            $paths = $c->get(\SP\Domain\Core\Bootstrap\PathsContext::class);
            echo $paths instanceof \SP\Domain\Core\Bootstrap\PathsContext ? 'RESOLVE_OK' : 'BAD_RESOLVE';
            PHP;

        $command = sprintf(
            'SP_AUTOLOAD=%s SP_BASE=%s DEBUG=true %s -r %s 2>&1',
            escapeshellarg(REAL_APP_ROOT . '/vendor/autoload.php'),
            escapeshellarg(self::BASE),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        );

        $output = (string)shell_exec($command);

        self::assertStringContainsString('RESOLVE_OK', $output, $output);
    }

    /**
     * The API module binds ApiRequestService only in its own module.php (to RestApiRequest,
     * built from the request the container assembles from CLI globals) — resolving it proves
     * the 'api' module's own bindings won, not just that some container built.
     */
    public function testBaseReturnsResolvableContainerForApiModule(): void
    {
        $output = self::runBase(
            module: 'api',
            debug: true,
            cachePath: null,
            resolveExpr: 'get_class($c->get(\SP\Application\Api\Ports\ApiRequestService::class))'
        );

        self::assertSame('SP\Application\Api\Services\RestApiRequest', $output, $output);
    }

    /**
     * The CLI module binds no BootstrapInterface, and that absence is deliberate: bin/cli.php
     * only ever asks for ModuleInterface. So ModuleInterface — bound here to Cli\Init — is the
     * entry worth resolving, and resolving it proves 'cli' got its own bindings rather than
     * another module's.
     */
    public function testBaseReturnsResolvableContainerForCliModule(): void
    {
        $output = self::runBase(
            module: 'cli',
            debug: true,
            cachePath: null,
            resolveExpr: 'get_class($c->get(\SP\Domain\Core\Bootstrap\ModuleInterface::class))'
        );

        self::assertSame('SP\Infrastructure\Adapter\In\Cli\Init', $output, $output);
    }

    /**
     * The compiled branch: DEBUG=false, so Base.php turns on enableCompilation() and
     * writeProxiesToFile() exactly as a real deployment does. An entry that builds fine live
     * can still fail to compile (that gap already cost this fork an outage — see
     * CompilableContainerTest), so this is not redundant with the live-branch tests above.
     */
    public function testBaseReturnsACompiledContainerForWebModule(): void
    {
        $this->assertCompiledContainerResolves(
            module: null,
            resolveExpr: 'get_class($c) . "|" . get_class($c->get(\SP\Domain\Core\Bootstrap\PathsContext::class))',
            expectedResolvedClass: 'SP\Domain\Core\Bootstrap\PathsContext'
        );
    }

    public function testBaseReturnsACompiledContainerForApiModule(): void
    {
        $this->assertCompiledContainerResolves(
            module: 'api',
            resolveExpr: 'get_class($c) . "|" . get_class($c->get(\SP\Application\Api\Ports\ApiRequestService::class))',
            expectedResolvedClass: 'SP\Application\Api\Services\RestApiRequest'
        );
    }

    public function testBaseReturnsACompiledContainerForCliModule(): void
    {
        $this->assertCompiledContainerResolves(
            module: 'cli',
            resolveExpr: 'get_class($c) . "|" . get_class($c->get(\SP\Domain\Core\Bootstrap\ModuleInterface::class))',
            expectedResolvedClass: 'SP\Infrastructure\Adapter\In\Cli\Init'
        );
    }

    /**
     * Base.php catches every Throwable itself and ends in die($e->getMessage()) — which exits 0
     * (die() with a string argument does not set a non-zero exit code) and writes to stdout, not
     * stderr. So a broken composition root cannot be told apart from a working one by exit code
     * or by "did it print anything to stderr" alone: the tests above only mean something if this
     * one, checking the failure side, actually fails to see RESOLVE_OK when the module can't be
     * initialised. An unknown module is initModule()'s own documented failure (Functions.php),
     * not a hypothetical one.
     */
    public function testBaseDiesRatherThanReturningAContainerForAnUnknownModule(): void
    {
        $output = self::runBase(
            module: 'doesnotexist',
            debug: true,
            cachePath: null,
            resolveExpr: "'RESOLVE_OK'"
        );

        self::assertStringNotContainsString('RESOLVE_OK', $output, $output);
        self::assertStringContainsString(
            "Either module dir or module file don't exist",
            $output,
            'Base.php should still surface *some* message via die($e->getMessage()), not fail silently: ' . $output
        );
    }

    private function assertCompiledContainerResolves(?string $module, string $resolveExpr, string $expectedResolvedClass): void
    {
        $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_base_compiled_', true);

        mkdir($cachePath, 0777, true);

        try {
            $output = self::runBase($module, debug: false, cachePath: $cachePath, resolveExpr: $resolveExpr);

            $parts = explode('|', $output, 2);

            self::assertCount(2, $parts, $output);
            // The compiled container class carries the module and the application version (see
            // compiledContainerName() in Functions.php) rather than a fixed name, so this only
            // pins that Base.php actually took the compiled path, not the generated class name.
            self::assertStringStartsWith('CompiledContainer', $parts[0], $output);
            self::assertSame($expectedResolvedClass, $parts[1], $output);
        } finally {
            FileSystem::rmdirRecursive($cachePath);
        }
    }

    /**
     * Runs Base.php in a fresh, non-bootstrapped php process and returns whatever the script
     * echoed. $resolveExpr is embedded verbatim as the argument to the child's own `echo`, with
     * $c bound to the container Base.php returned.
     */
    private static function runBase(?string $module, bool $debug, ?string $cachePath, string $resolveExpr): string
    {
        $lines = [];

        if ($module !== null) {
            $lines[] = sprintf("define('APP_MODULE', %s);", var_export($module, true));
        }

        $lines[] = "require getenv('SP_AUTOLOAD');";
        $lines[] = '$c = require getenv(\'SP_BASE\');';
        $lines[] = "if (!\$c instanceof \\Psr\\Container\\ContainerInterface) { echo 'NOT_A_CONTAINER'; exit(1); }";
        $lines[] = 'echo ' . $resolveExpr . ';';

        $script = implode("\n", $lines);

        $envParts = [
            'SP_AUTOLOAD=' . escapeshellarg(REAL_APP_ROOT . '/vendor/autoload.php'),
            'SP_BASE=' . escapeshellarg(self::BASE),
            'DEBUG=' . ($debug ? 'true' : 'false'),
        ];

        if ($cachePath !== null) {
            $envParts[] = 'CACHE_PATH=' . escapeshellarg($cachePath);
        }

        $command = sprintf(
            '%s %s -r %s 2>&1',
            implode(' ', $envParts),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        );

        return (string)shell_exec($command);
    }
}
