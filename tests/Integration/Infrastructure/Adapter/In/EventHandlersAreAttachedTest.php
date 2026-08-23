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

namespace SP\Tests\Integration\Infrastructure\Adapter\In;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Infrastructure\ModuleBase;
use SP\Infrastructure\ProvidersHelper;
use SP\Tests\Support\Stubs\InertConsoleApplication;
use Symfony\Component\Console\Application as ConsoleApplication;

use function DI\create;
use function SP\Tests\getResource;

/**
 * ModuleBase::initEventHandlers() is what attaches the four EventReceivers that make the event
 * bus do anything at all: LogHandler always, and -- once the application is installed --
 * DatabaseHandler (the Eventlog table the web UI's security log reads), MailEvent and
 * NotificationEvent. Nothing fires unless something is attached to receive it.
 *
 * Web\Init::initialize() calls it at line 223 and Api\Init::initialize() calls it at line 122.
 * Cli\Init::initialize() never called it at all: sp:backup and sp:crypt:update-master-password
 * left nothing in the Eventlog table, while the same operations through the web or API were fully
 * recorded. tests/Integration/Infrastructure/Adapter/In/Cli/CliTestCase.php -- the harness the
 * real end-to-end CLI command tests use -- resolves a Command class straight out of the container
 * and runs it with CommandTester, which is exactly what let this slip through: none of those tests
 * go anywhere near Cli\Init::initialize(), so nothing was ever in a position to notice the missing
 * call.
 *
 * This builds each module's real container -- DomainDefinitions + CoreDefinitions($module) + that
 * module's own module.php, the same way CliTestCase and AccountAccessTest do -- and checks the
 * same thing for all three doors, so the invariant is stated once rather than only for the one
 * that broke.
 *
 * The CLI door is exercised through its actual, unmodified initialize(): the only thing swapped
 * out is the Console\Application binding, so initCli() does not go on to actually run a command.
 * Web and Api are not run through their own initialize() here. Doing that for real would need
 * materially more than the CLI does: Web binds Context to a real PHP session, which this project
 * only exercises under #[RunClassInSeparateProcess] (see SessionTest) precisely because a real
 * session started in the shared integration process is not safe for the tests around it. Api's
 * checkInstalled() runs before initEventHandlers(), so reaching it for real needs a config that
 * claims to be installed, an app/database version that matches exactly (or checkUpgradeNeeded()
 * intervenes first) and live checkDatabaseConnection()/checkDatabaseTables() checks against the
 * real schema. Instead, the shared, protected method is invoked directly, by reflection, on a
 * real container-built Init -- proving the ProvidersHelper/EventDispatcher wiring those two doors
 * already depend on is sound, without paying for either door's full request lifecycle.
 */
#[Group('integration')]
final class EventHandlersAreAttachedTest extends TestCase
{
    private string $root;
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        // A real (non-vfs), uniquely-named directory -- vfs is shared process-wide, and every
        // test here builds its own container against its own config.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-event-handlers-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->backupPath()] as $dir) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail(sprintf('Directory "%s" was not created', $dir));
            }
        }

        // The same not-installed fixture config CliTestCase/AccountAccessTest use.
        // ModuleBase::initEventHandlers() attaches only the LogHandler while the application is
        // not installed, which is exactly what makes the LogHandler the one thing safe to assert
        // on every door regardless of whether it also reaches DatabaseHandler/MailEvent/
        // NotificationEvent.
        file_put_contents(
            FileSystem::buildPath($this->configPath, 'config.xml'),
            getResource('config', 'config.xml')
        );
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The fix: Cli\Init::initialize() attaches the event handlers before initCli() hands control
     * to the console application, exactly like the web and API doors already do.
     */
    #[Test]
    public function theCliEntryPointAttachesTheLogHandlerBeforeRunningAnyCommand(): void
    {
        $dic = $this->buildContainer('cli');

        $dic->get(ModuleInterface::class)->initialize('');

        self::assertHandlerAttached($dic, 'cli');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function httpModuleProvider(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    /**
     * The invariant Cli\Init was missing, stated for the two doors that already had it -- see the
     * class docblock for why these two go through initEventHandlers() directly by reflection
     * rather than through their own initialize().
     */
    #[Test]
    #[DataProvider('httpModuleProvider')]
    public function theHttpEntryPointsAlreadyAttachTheLogHandler(string $module): void
    {
        $dic = $this->buildContainer($module);
        $init = $dic->get(ModuleInterface::class);

        (new ReflectionMethod(ModuleBase::class, 'initEventHandlers'))->invoke($init);

        self::assertHandlerAttached($dic, $module);
    }

    private static function assertHandlerAttached(ContainerInterface $dic, string $module): void
    {
        $dispatcher = $dic->get(EventDispatcherInterface::class);
        $logHandler = $dic->get(ProvidersHelper::class)->getLogHandler();

        self::assertTrue(
            $dispatcher->has($logHandler),
            sprintf(
                'The "%s" module\'s EventDispatcher has no LogHandler attached after '
                . 'initEventHandlers() ran, so nothing that module fires would ever reach the log '
                . 'or, once installed, the Eventlog table.',
                $module
            )
        );
    }

    private function buildContainer(string $module): ContainerInterface
    {
        // CoreDefinitions computes the paths when called: point the config (and with it the log
        // file) at this test's own directory rather than the working copy's var/ state.
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, $module);
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        $coreDefinitions['paths'] = array_map(
            fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, $this->cachePath()],
                Path::TMP => [Path::TMP, $this->tmpPath()],
                Path::BACKUP => [Path::BACKUP, $this->backupPath()],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        $moduleDefinitions = FileSystem::require(
            FileSystem::buildPath(
                REAL_APP_ROOT,
                'src',
                'Infrastructure',
                'Adapter',
                'In',
                ucfirst($module),
                'module.php'
            )
        );

        $builder = new ContainerBuilder();
        $definitions = [DomainDefinitions::getDefinitions(), $coreDefinitions, $moduleDefinitions];

        if ($module === 'cli') {
            // initCli() would otherwise hand off to the real console application and run whatever
            // command was on the (empty) command line -- swap in a double whose run() does nothing.
            $definitions[] = [ConsoleApplication::class => create(InertConsoleApplication::class)];
        }

        $builder->addDefinitions(...$definitions);

        return $builder->build();
    }

    private function cachePath(): string
    {
        return FileSystem::buildPath($this->root, 'cache');
    }

    private function tmpPath(): string
    {
        return FileSystem::buildPath($this->root, 'tmp');
    }

    private function backupPath(): string
    {
        return FileSystem::buildPath($this->root, 'backup');
    }
}
