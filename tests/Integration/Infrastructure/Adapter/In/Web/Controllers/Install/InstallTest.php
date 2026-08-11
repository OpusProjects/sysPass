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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Install\Ports\InstallerService;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the install endpoints. None of them had PHPUnit coverage: only the browser wizard spec
 * exercised them, and it runs against a fresh instance, so it never reaches the guards that
 * matter once an instance exists.
 *
 * These endpoints are unauthenticated by necessity — they run before there is anyone to
 * authenticate — so what closes them afterwards is the installed flag.
 */
#[Group('integration')]
class InstallTest extends IntegrationTestCase
{
    /** Whether the instance under test reports itself as installed. */
    private bool $installed = true;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isInstalled' => $this->installed]);
    }

    /**
     * The one that matters: an installed instance must refuse to be installed again. The
     * endpoint takes no credentials, so without this guard anyone reaching it could re-run the
     * installer against a live instance.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function installingAnAlreadyInstalledInstanceIsRefused()
    {
        $installer = $this->createMock(InstallerService::class);
        $installer->expects(self::never())->method('run');

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'install/install'], self::wizardFields()),
            [InstallerService::class => $installer]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"sysPass is already installed","data":null}'
        );
    }

    /**
     * The connection check is equally unauthenticated, and equally closed afterwards.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function checkingAConnectionOnAnInstalledInstanceIsRefused()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'install/checkConnection'],
                self::wizardFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"sysPass is already installed","data":null}'
        );
    }

    /**
     * On a fresh instance the endpoint runs the installer and reports it finished.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function installingAFreshInstanceRunsTheInstaller()
    {
        $this->installed = false;

        $installer = $this->createMock(InstallerService::class);
        $installer->expects(self::once())->method('run');

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'install/install'], self::wizardFields()),
            [InstallerService::class => $installer]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Installation finished","data":null}');
    }

    /**
     * A failure during the install comes back as a JSON error. The wizard is a JSON client, so
     * an error page instead would leave it with nothing it can read — and the endpoint catches
     * Throwable rather than Exception for that reason.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aFailedInstallIsReportedAsJson()
    {
        $this->installed = false;

        $installer = $this->createStub(InstallerService::class);
        $installer->method('run')->willThrowException(new SPException('Database error'));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'install/install'], self::wizardFields()),
            [InstallerService::class => $installer]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Database error","data":null}');
    }

    /**
     * The wizard page renders on a fresh instance.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWizard')]
    public function index()
    {
        $this->installed = false;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'install/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @return array<string, string>
     */
    private static function wizardFields(): array
    {
        return [
            'adminlogin' => 'admin',
            'adminpass' => 'a-very-long-password',
            'masterpassword' => 'another-very-long-password',
            'dbuser' => 'root',
            'dbpass' => 'secret',
            'dbname' => 'syspass',
            'dbhost' => 'localhost',
        ];
    }

    /**
     * The wizard has to offer the fields it collects, otherwise there is no way to install.
     */
    private function outputCheckerWizard(string $output): void
    {
        self::assertStringContainsString('adminlogin', $output);
        self::assertStringContainsString('dbname', $output);
    }
}
