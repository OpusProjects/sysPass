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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Cli;

use DI\ContainerBuilder;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Domain\Core\Bootstrap\Path;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Domain\Core\Context\Context;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use Symfony\Component\Console\Tester\CommandTester;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;
use function SP\Tests\recreateDir;

/**
 * Base class for end-to-end CLI command tests.
 *
 * Unlike the mocked web harness (IntegrationTestCase), this builds the REAL
 * container — real config file, real DI wiring and, where the test provides
 * one, a real database — mirroring what bin/cli.php does. A fresh container
 * and a pristine, not-installed config are used for every test.
 *
 * The runtime directories are real (not vfsStream): PharData & friends cannot
 * operate on stream wrappers.
 */
abstract class CliTestCase extends TestCase
{
    protected static ContainerInterface $dic;
    /**
     * @var string[]
     */
    protected static array $commandInputData = [];
    private static ?array  $cliModuleDefinitions = null;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Fresh runtime dirs and a pristine (not installed) config for every test
        recreateDir(CLI_TEST_ROOT);

        $configPath = FileSystem::buildPath(CLI_TEST_ROOT, 'config');

        foreach ([$configPath, CACHE_PATH, CLI_TMP_PATH, FileSystem::buildPath(CLI_TEST_ROOT, 'backup')] as $dir) {
            if (!mkdir($dir) && !is_dir($dir)) {
                throw new Exception(sprintf('Directory "%s" was not created', $dir));
            }
        }

        file_put_contents(
            FileSystem::buildPath($configPath, 'config.xml'),
            getResource('config', 'config.xml')
        );

        self::$dic = $this->buildContainer($configPath);

        self::$dic->get(Context::class)->initialize();
    }

    /**
     * @throws Exception
     */
    private function buildContainer(string $configPath): ContainerInterface
    {
        // CoreDefinitions computes the paths when called: point the config
        // (and with it the log file) at the per-test directory
        $_ENV['CONFIG_PATH'] = $configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'cli');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        // Redirect the runtime-writable paths at the per-test directories too,
        // so tests never touch the working copy's var/ state
        $coreDefinitions['paths'] = array_map(
            static fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, CACHE_PATH],
                Path::TMP => [Path::TMP, CLI_TMP_PATH],
                Path::BACKUP => [Path::BACKUP, FileSystem::buildPath(CLI_TEST_ROOT, 'backup')],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        if (self::$cliModuleDefinitions === null) {
            // require it only once: the module file declares constants
            self::$cliModuleDefinitions = FileSystem::require(
                FileSystem::buildPath(
                    REAL_APP_ROOT,
                    'src',
                    'Infrastructure',
                    'Adapter',
                    'In',
                    'Cli',
                    'module.php'
                )
            );
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            $coreDefinitions,
            self::$cliModuleDefinitions
        );

        return $builder->build();
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function executeCommandTest(
        string $commandClass,
        ?array $inputData = null,
        bool $useInputData = true
    ): CommandTester {
        $installCommand = self::$dic->get($commandClass);

        if (null === $inputData && $useInputData) {
            $inputData = static::$commandInputData;
        }

        $commandTester = new CommandTester($installCommand);
        $commandTester->execute(
            $inputData ?? [],
            ['interactive' => false]
        );

        return $commandTester;
    }

    protected function setupDatabase(): void
    {
        self::$dic->set(DbStorageHandler::class, getDbHandler());
    }
}
