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

namespace SP\Tests\Core\Bootstrap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers lib/Base.php — the web entry point's bootstrap. It is a procedural script
 * that defines global constants (APP_PATH, DEBUG), loads the .env, and returns the
 * built php-di container. Because of those global side effects it cannot be require()d
 * in the test process, so it is exercised in a fresh subprocess.
 */
#[Group('unitary')]
class BaseBootstrapTest extends TestCase
{
    private const BASE = REAL_APP_ROOT . '/lib/Base.php';

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
            $paths = $c->get(\SP\Core\Bootstrap\PathsContext::class);
            echo $paths instanceof \SP\Core\Bootstrap\PathsContext ? 'RESOLVE_OK' : 'BAD_RESOLVE';
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
}
