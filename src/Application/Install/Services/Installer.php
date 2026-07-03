<?php

declare(strict_types=1);

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

namespace SP\Application\Install\Services;

use Exception;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Config\Ports\ConfigService;
use SP\Application\Install\Ports\InstallerService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Core\Crypt\Hash;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Models\Config;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Install\Adapters\DatabaseHost;
use SP\Domain\Install\Adapters\InstallData;
use SP\Domain\Install\Services\DatabaseSetupService;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User;
use SP\Domain\User\Models\UserGroup;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Database\DatabaseConnectionData;
use SP\Infrastructure\File\FileException;
use Throwable;

use function SP\__u;
use function SP\getFromEnv;
use function SP\processException;

/**
 * Installer class
 */
final class Installer implements InstallerService
{
    /** @deprecated Use AppInfoInterface::APP_VERSION */
    public const VERSION = AppInfoInterface::APP_VERSION;
    /** @deprecated Use AppInfoInterface::APP_BUILD */
    public const BUILD   = AppInfoInterface::APP_BUILD;

    private RequestService $request;
    private ?InstallData   $installData = null;

    public function __construct(
        RequestService               $request,
        private readonly ConfigFileService      $config,
        private readonly UserService $userService,
        private readonly UserGroupService       $userGroupService,
        private readonly UserProfileService     $userProfileService,
        private readonly ConfigService          $configService,
        private readonly DatabaseConnectionData $databaseConnectionData,
        private readonly DatabaseSetupService $databaseSetup
    ) {
        $this->request = $request;
    }

    /**
     * @throws InvalidArgumentException
     * @throws SPException
     */
    public function run(InstallData $installData): InstallerService
    {
        $this->installData = $installData;

        $this->checkData();
        $this->install();

        return $this;
    }

    /**
     * empty() would also reject legitimate values like the password "0"
     */
    private static function isBlank(?string $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @throws InvalidArgumentException
     */
    private function checkData(): void
    {
        if (self::isBlank($this->installData->getAdminLogin())) {
            throw new InvalidArgumentException(
                __u('Please, enter the admin username'),
                SPException::ERROR,
                __u('Admin user to log into the application')
            );
        }

        if (self::isBlank($this->installData->getAdminPass())) {
            throw new InvalidArgumentException(
                __u('Please, enter the admin\'s password'),
                SPException::ERROR,
                __u('Application administrator\'s password')
            );
        }

        if ($this->installData->getAdminPass() !== $this->installData->getAdminPassRepeat()) {
            throw new InvalidArgumentException(
                __u('Passwords do not match'),
                SPException::ERROR,
                __u('The admin password and its confirmation must be the same')
            );
        }

        if (self::isBlank($this->installData->getMasterPassword())) {
            throw new InvalidArgumentException(
                __u('Please, enter the Master Password'),
                SPException::ERROR,
                __u('Master password to encrypt the passwords')
            );
        }

        if (strlen($this->installData->getMasterPassword() ?? '') < 11) {
            throw new InvalidArgumentException(
                __u('Master password too short'),
                SPException::CRITICAL,
                __u('The Master Password length need to be at least 11 characters')
            );
        }

        if ($this->installData->getMasterPassword() !== $this->installData->getMasterPasswordRepeat()) {
            throw new InvalidArgumentException(
                __u('Passwords do not match'),
                SPException::ERROR,
                __u('The Master Password and its confirmation must be the same')
            );
        }

        if (self::isBlank($this->installData->getDbAdminUser())) {
            throw new InvalidArgumentException(
                __u('Please, enter the database user'),
                SPException::CRITICAL,
                __u('An user with database administrative rights')
            );
        }

        // An empty DB admin password is allowed: passwordless admin accounts
        // are common on development setups

        if (self::isBlank($this->installData->getDbName())) {
            throw new InvalidArgumentException(
                __u('Please, enter the database name'),
                SPException::ERROR,
                __u('Application database name. eg. syspass')
            );
        }

        if (!preg_match('/^[0-9a-zA-Z$_\-]+$/', $this->installData->getDbName())) {
            throw new InvalidArgumentException(
                __u('Database name contains invalid characters'),
                SPException::CRITICAL,
                __u('Only letters, digits, $, _ and - are allowed in the database name')
            );
        }

        if (self::isBlank($this->installData->getDbHost())) {
            throw new InvalidArgumentException(
                __u('Please, enter the database server'),
                SPException::ERROR,
                __u('Server where the database will be installed')
            );
        }
    }

    /**
     * @throws SPException
     */
    private function install(): void
    {
        $this->setupDbHost();

        // The DI-time DatabaseConnectionData snapshot was taken from the raw,
        // unparsed host value (and, on CLI, from an InstallData the command
        // never filled): refresh it now that the host is parsed
        $this->databaseConnectionData->refreshFromInstallData($this->installData);

        $configData = $this->setupConfig();

        $this->databaseSetup->connectDatabase();
        // Validate the target before anything is created: a failure here must not
        // trigger a rollback, which could otherwise touch pre-existing data
        $this->databaseSetup->checkDatabaseAvailability();

        $dbUser = null;

        if ($this->installData->isHostingMode()) {
            // Save DB connection user and pass
            $configData->setDbUser($this->installData->getDbAdminUser());
            $configData->setDbPass($this->installData->getDbAdminPass());
        } else {
            [$dbUser, $dbPass] = $this->databaseSetup->setupDbUser();

            $configData->setDbUser($dbUser);
            $configData->setDbPass($dbPass);
        }

        $this->config->save($configData, false);

        try {
            $this->databaseSetup->createDatabase($dbUser);
            $this->databaseSetup->createDBStructure();
            $this->databaseSetup->checkConnection();

            // From here on the runtime credentials are used, so they get
            // verified before the installation is marked as finished
            $this->databaseConnectionData->refreshFromConfig($configData);

            $this->saveMasterPassword();
            $this->createAdminAccount();

            $this->configService->create(
                new Config([
                               'parameter' => 'version',
                               'value' => Version::getVersionStringNormalized()
                           ])
            );

            $configData->setInstalled(true);

            $this->config->save($configData);
        } catch (Throwable $e) {
            // Throwable, not Exception: a TypeError mid-install must also roll back
            processException($e);

            // The connection data may already point at the runtime user, which
            // lacks the rights to drop the database and the user itself: roll
            // back over the admin connection
            $this->databaseConnectionData->refreshFromInstallData($this->installData);

            $this->databaseSetup->rollback($dbUser);

            throw $e instanceof SPException
                ? $e
                : new SPException(
                    $e->getMessage(),
                    SPException::CRITICAL,
                    __u('Warn to developer'),
                    $e->getCode(),
                    $e
                );
        }
    }

    /**
     * Setup database connection data
     *
     * @throws InvalidArgumentException
     */
    private function setupDbHost(): void
    {
        $target = DatabaseHost::parse($this->installData->getDbHost());

        if ($target->socket !== null) {
            $this->installData->setDbSocket($target->socket);
            // A socket connection authenticates as user@localhost
            $this->installData->setDbAuthHost('localhost');

            return;
        }

        $this->installData->setDbHost($target->host);
        $this->installData->setDbPort($target->port ?? 3306);

        if ($target->isLocal()) {
            $this->installData->setDbAuthHost('localhost');

            return;
        }

        // Use real IP address when unitary testing, because no HTTP request is performed
        if (defined('SELF_IP_ADDRESS')) {
            $address = SELF_IP_ADDRESS;
        } else {
            $address = $this->request->getServer('SERVER_ADDR');
        }

        // On Docker (SYSPASS_DIR is set by the official image) the container address
        // is neither stable nor necessarily routable; likewise when there is no
        // request address (CLI install). Fall back to a wildcard auth host.
        if (getFromEnv('SYSPASS_DIR') !== null || empty($address)) {
            $this->installData->setDbAuthHost('%');

            return;
        }

        $this->installData->setDbAuthHost($address);

        $dnsHostname = gethostbyaddr($address);

        if ($dnsHostname !== false && strlen($dnsHostname) < 60) {
            $this->installData->setDbAuthHostDns($dnsHostname);
        }
    }

    /**
     * Setup sysPass config data
     * @throws FileException
     */
    private function setupConfig(): ConfigDataInterface
    {
        $configData = $this->config->getConfigData()
            ->setConfigVersion(Version::getVersionStringNormalized())
            ->setDatabaseVersion(Version::getVersionStringNormalized())
            ->setAppVersion(Version::getVersionStringNormalized())
                                   ->setUpgradeKey(null)
                                   ->setDbHost($this->installData->getDbHost())
                                   ->setDbSocket($this->installData->getDbSocket())
                                   ->setDbPort($this->installData->getDbPort())
                                   ->setDbName($this->installData->getDbName())
                                   ->setSiteLang($this->installData->getSiteLang());

        $this->config->save($configData, false);

        return $configData;
    }

    /**
     * Saves the master password metadata
     *
     * Any failure is handled (rollback) by the caller.
     *
     * @throws Exception
     */
    private function saveMasterPassword(): void
    {
        $this->configService->create(
            new Config(
                [
                    'parameter' => 'masterPwd',
                    'value' => Hash::hashKey($this->installData->getMasterPassword() ?? '')
                ]
            )
        );
        $this->configService->create(
            new Config(['parameter' => 'lastupdatempass', 'value' => (string)time()])
        );
    }

    /**
     * Any failure is handled (rollback) by the caller.
     *
     * @throws Exception
     */
    private function createAdminAccount(): void
    {
        $userGroup = new UserGroup(
            [
                'name' => 'Admins',
                'description' => 'sysPass Admins'
            ]
        );

        $userProfile = new UserProfile(['name' => 'Admin', 'profile' => (new ProfileData())->toJson()]);

        $userData = new User([
                                 'userGroupId' => $this->userGroupService->create($userGroup),
                                 'userProfileId' => $this->userProfileService->create($userProfile),
                                 'login' => $this->installData->getAdminLogin(),
                                 'name' => 'sysPass Admin',
                                 'isAdminApp' => true,
                             ]);

        $id = $this->userService->createWithMasterPass(
            $userData,
            $this->installData->getAdminPass(),
            $this->installData->getMasterPassword()
        );

        if ($id === 0) {
            throw new SPException(__u('Error while creating \'admin\' user'));
        }
    }
}
