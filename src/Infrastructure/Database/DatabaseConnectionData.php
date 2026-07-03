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

namespace SP\Infrastructure\Database;

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Install\Adapters\InstallData;

use function SP\getFromEnv;

/**
 * Class DatabaseConnectionData
 */
class DatabaseConnectionData
{
    private ?string $dbHost   = null;
    private ?string $dbSocket = null;
    private ?int    $dbPort   = null;
    private ?string $dbName   = null;
    private ?string $dbUser   = null;
    private ?string $dbPass   = null;

    public static function getFromConfig(ConfigDataInterface $configData): DatabaseConnectionData
    {
        $self = new self();
        self::setup($configData, $self);

        return $self;
    }

    /**
     * @param ConfigDataInterface $configData
     * @param DatabaseConnectionData $self
     * @return void
     */
    private static function setup(ConfigDataInterface $configData, DatabaseConnectionData $self): void
    {
        $self->dbSocket = $configData->getDbSocket();
        $self->dbHost = $configData->getDbHost();
        $self->dbPort = $configData->getDbPort();
        $self->dbName = $configData->getDbName();
        $self->dbUser = $configData->getDbUser();
        $self->dbPass = $configData->getDbPass();
    }

    public function getDbSocket(): ?string
    {
        return $this->dbSocket;
    }

    public function getDbHost(): ?string
    {
        return $this->dbHost;
    }

    public function getDbPort(): ?int
    {
        return $this->dbPort;
    }

    public function getDbName(): ?string
    {
        return $this->dbName;
    }

    public function getDbUser(): ?string
    {
        return $this->dbUser;
    }

    public function getDbPass(): ?string
    {
        return $this->dbPass;
    }

    public static function getFromEnvironment(): DatabaseConnectionData
    {
        $self = new self();
        $self->dbSocket = getFromEnv('DB_SOCKET');
        $self->dbHost = getFromEnv('DB_SERVER');
        $self->dbPort = getFromEnv('DB_PORT', 0) ?: null;
        $self->dbName = getFromEnv('DB_NAME');
        $self->dbUser = getFromEnv('DB_USER');
        $self->dbPass = getFromEnv('DB_PASS');

        return $self;
    }

    public static function hasEnvironmentConfig(): bool
    {
        return getFromEnv('DB_SERVER') !== null;
    }

    public static function getFromInstallData(InstallData $installData): DatabaseConnectionData
    {
        return (new self())->refreshFromInstallData($installData);
    }

    public function refreshFromInstallData(InstallData $installData): DatabaseConnectionData
    {
        $this->dbSocket = $installData->getDbSocket();
        $this->dbHost = $installData->getDbHost();
        $this->dbPort = $installData->getDbPort();
        // Don't select the database in the install DSN: in normal mode it doesn't exist yet
        // (it's created during setup). The setup selects it with `USE` once created/confirmed.
        $this->dbName = null;
        $this->dbUser = $installData->getDbAdminUser();
        $this->dbPass = $installData->getDbAdminPass();

        return $this;
    }

    public function refreshFromConfig(ConfigDataInterface $configData): DatabaseConnectionData
    {
        self::setup($configData, $this);

        return $this;
    }
}
