<?php
/**
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

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Definitions;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use Throwable;

/**
 * Production builds the container **compiled** — Base.php turns compilation on whenever DEBUG is
 * off — while every other test builds it live. The two are not the same: an entry naming a class
 * that no longer exists builds fine live, because nothing resolves it, and cannot be compiled at
 * all.
 *
 * That difference has already cost this fork an outage: an entry for a service that had been
 * deleted sat unused in the definitions, live builds never touched it, and compilation on a real
 * install died on it. Nothing in the suite caught it, because nothing compiled.
 *
 * So this compiles what each entry point actually loads. Checked both ways: an entry pointing at a
 * class that does not exist fails all three modules here, while a literal object does not — php-di
 * keeps those dynamic rather than refusing them, so 'never bind a literal object' stays a rule
 * this cannot enforce.
 */
#[Group('integration')]
class CompilableContainerTest extends TestCase
{
    private string $buildDir;

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('moduleProvider')]
    public function eachModulesContainerCompiles(string $module): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            CoreDefinitions::getDefinitions(REAL_APP_ROOT, $module),
            FileSystem::require(
                FileSystem::buildPath(
                    REAL_APP_ROOT,
                    'src',
                    'Infrastructure',
                    'Adapter',
                    'In',
                    ucfirst($module),
                    'module.php'
                )
            )
        );

        // The same two switches Base.php sets when DEBUG is off.
        $builder->enableCompilation($this->buildDir, sprintf('CompiledContainer%sTest', ucfirst($module)));
        $builder->writeProxiesToFile(true, FileSystem::buildPath($this->buildDir, 'proxies'));

        $container = $builder->build();

        self::assertTrue(
            $container->has(ModuleInterface::class),
            sprintf('The %s module compiled without the module every entry point asks for', $module)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function moduleProvider(): array
    {
        return ['web' => ['web'], 'api' => ['api'], 'cli' => ['cli']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A real directory: the compiler writes PHP it then includes, which vfsStream cannot serve.
        $this->buildDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_compiled_', true);

        mkdir($this->buildDir, 0777, true);
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->buildDir);

        parent::tearDown();
    }
}
