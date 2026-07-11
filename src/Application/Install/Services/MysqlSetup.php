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
use PDO;
use PDOException;
use SP\Domain\Common\Providers\Password;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Database\Ports\DatabaseFileInterface;
use SP\Infrastructure\Database\Ports\DbStorageHandler;
use SP\Domain\Install\Adapters\InstallData;
use SP\Domain\Install\Services\DatabaseSetupService;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Domain\Core\Exceptions\FileException;

use function SP\__;
use function SP\__u;
use function SP\logger;
use function SP\processException;

/**
 * Class MysqlSetupService
 */
final readonly class MysqlSetup implements DatabaseSetupService
{
    public function __construct(
        private DbStorageHandler      $dbStorage,
        private InstallData           $installData,
        private DatabaseFileInterface $databaseFile,
        private DatabaseUtil          $databaseUtil
    ) {
    }

    /**
     * Connect to the database
     *
     * Check whether the connection to the sysPass database is possible with
     * the provided details.
     *
     * @throws SPException
     */
    public function connectDatabase(): void
    {
        try {
            $this->dbStorage->getConnectionSimple();
        } catch (SPException $e) {
            processException($e);

            throw new SPException(
                __u('Unable to connect to DB'),
                SPException::ERROR,
                $e->getHint(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws SPException
     * @throws Exception
     */
    public function setupDbUser(): array
    {
        // uniqid() makes a collision with an existing user practically impossible;
        // if one does exist, CREATE USER fails with a clear error. Probing
        // mysql.user beforehand would need privileges managed databases
        // (e.g. RDS) don't grant even to the admin user.
        $user = substr(uniqid('sp_', true), 0, 16);
        $pass = Password::randomPassword();

        $this->createDBUser($user, $pass);

        return [$user, $pass];
    }

    /**
     * Create the user to connect to the database.
     * This function creates the user used to connect to the database.
     *
     * @throws SPException
     */
    public function createDBUser(string $user, string $pass): void
    {
        logger('Creating DB user');

        $createdHosts = [];

        try {
            $query = 'CREATE USER %s@%s IDENTIFIED BY %s';

            $dbc = $this->dbStorage->getConnectionSimple();

            $dbc->exec(
                sprintf(
                    $query,
                    $dbc->quote($user),
                    $dbc->quote($this->installData->getDbAuthHost() ?? ''),
                    $dbc->quote($pass)
                )
            );
            $createdHosts[] = $this->installData->getDbAuthHost() ?? '';

            if (!empty($this->installData->getDbAuthHostDns())
                && $this->installData->getDbAuthHost() !== $this->installData->getDbAuthHostDns()
            ) {
                $dbc->exec(
                    sprintf(
                        $query,
                        $dbc->quote($user),
                        $dbc->quote($this->installData->getDbAuthHostDns()),
                        $dbc->quote($pass)
                    )
                );
                $createdHosts[] = $this->installData->getDbAuthHostDns();
            }

            $dbc->exec('FLUSH PRIVILEGES');
        } catch (PDOException $e) {
            processException($e);

            // Drop any variant already created: the generated name never leaves
            // this method, so the caller's rollback cannot clean it up
            foreach ($createdHosts as $host) {
                try {
                    $dbc->exec(
                        sprintf(
                            'DROP USER IF EXISTS %s@%s',
                            $dbc->quote($user),
                            $dbc->quote($host)
                        )
                    );
                } catch (PDOException $dropException) {
                    processException($dropException);
                }
            }

            throw new SPException(
                sprintf(__u('Error while creating the MySQL connection user \'%s\''), $user),
                SPException::CRITICAL,
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Check that the target database can host a fresh installation.
     *
     * @throws SPException
     */
    public function checkDatabaseAvailability(): void
    {
        if ($this->installData->isHostingMode()) {
            $this->checkDatabase(__u('You need to create it and assign the needed permissions'));

            // Refuse a schema that already holds sysPass objects: a rollback on a
            // later failure would otherwise wipe data from a previous installation
            if ($this->countExistingSysPassObjects() > 0) {
                throw new SPException(
                    __u('The database already contains sysPass tables'),
                    SPException::ERROR,
                    __u('Please, use an empty database or remove the existing tables')
                );
            }
        } elseif ($this->checkDatabaseExists()) {
            throw new SPException(
                __u('The database already exists'),
                SPException::ERROR,
                __u('Please, enter a new database or delete the existing one')
            );
        }
    }

    /**
     * @throws SPException
     */
    private function countExistingSysPassObjects(): int
    {
        $names = array_merge(DatabaseUtil::TABLES, DatabaseUtil::VIEWS);

        try {
            $sth = $this->dbStorage
                ->getConnectionSimple()
                ->prepare(
                    sprintf(
                        'SELECT COUNT(*) FROM information_schema.tables WHERE `table_schema` = ? AND `table_name` IN (%s)',
                        implode(',', array_fill(0, count($names), '?'))
                    )
                );
            $sth->execute([$this->installData->getDbName(), ...$names]);

            return (int)$sth->fetchColumn();
        } catch (PDOException $e) {
            processException($e);

            throw new SPException(
                __u('Error while checking the database'),
                SPException::CRITICAL,
                __u('Please, check the DB connection user rights'),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Create the database
     *
     * @throws SPException
     */
    public function createDatabase(?string $dbUser = null): void
    {
        if ($this->installData->isHostingMode()) {
            // The database is provided by the hosting; availability was already checked
            return;
        }

        try {
            $dbc = $this->dbStorage->getConnectionSimple();

            $dbc->exec(
                sprintf(
                    'CREATE SCHEMA `%s` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci',
                    $this->installData->getDbName()
                )
            );
        } catch (PDOException $e) {
            throw new SPException(
                sprintf(__('Error while creating the DB (\'%s\')'), $e->getMessage()),
                SPException::CRITICAL,
                __u('Please check the database user permissions'),
                $e->getCode(),
                $e
            );
        }

        try {
            $query = 'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s';
            // In a GRANT database name `_` and `%` are LIKE wildcards, even inside
            // backticks: an unescaped `syspass_prod` would also grant rights on
            // `syspassXprod` etc., defeating the least-privilege runtime user
            $grantDbName = $this->escapeGrantDbName($this->installData->getDbName());

            $dbc->exec(
                sprintf(
                    $query,
                    $grantDbName,
                    $dbc->quote($dbUser),
                    $dbc->quote($this->installData->getDbAuthHost() ?? '')
                )
            );

            if (!empty($this->installData->getDbAuthHostDns())
                && $this->installData->getDbAuthHost() !== $this->installData->getDbAuthHostDns()
            ) {
                $dbc->exec(
                    sprintf(
                        $query,
                        $grantDbName,
                        $dbc->quote($dbUser),
                        $dbc->quote($this->installData->getDbAuthHostDns())
                    )
                );
            }

            $dbc->exec('FLUSH PRIVILEGES');
        } catch (PDOException $e) {
            throw new SPException(
                sprintf(__('Error while setting the database permissions (\'%s\')'), $e->getMessage()),
                SPException::CRITICAL,
                __u('Please check the database user permissions'),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Escape the LIKE wildcards `_` and `%` (and the escape char itself) so the
     * database name is matched literally in a GRANT statement.
     */
    private function escapeGrantDbName(string $dbName): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $dbName);
    }

    /**
     * @throws SPException
     */
    public function checkDatabaseExists(): bool
    {
        try {
            $sth = $this->dbStorage
                ->getConnectionSimple()
                ->prepare(
                    'SELECT COUNT(*) FROM information_schema.schemata WHERE `schema_name` = ? LIMIT 1'
                );
            $sth->execute([$this->installData->getDbName()]);

            return (int)$sth->fetchColumn() === 1;
        } catch (PDOException $e) {
            // Runs outside the install try/rollback (before anything is created),
            // so wrap it: a raw PDOException would break Installer::run()'s
            // SPException contract with an untranslated driver message
            processException($e);

            throw new SPException(
                __u('Error while checking the database'),
                SPException::CRITICAL,
                __u('Please, check the DB connection user rights'),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Best-effort: a rollback failure must never mask the error that triggered
     * it, and one failed statement must not stop the remaining cleanup.
     */
    public function rollback(?string $dbUser = null): void
    {
        try {
            $dbc = $this->dbStorage->getConnectionSimple();
        } catch (Exception $e) {
            processException($e);

            logger('Rollback failed');

            return;
        }

        if ($this->installData->isHostingMode()) {
            // The FK constraints between the tables would make ordered drops fail
            $this->execBestEffort($dbc, 'SET FOREIGN_KEY_CHECKS = 0');

            foreach (DatabaseUtil::VIEWS as $view) {
                $this->execBestEffort(
                    $dbc,
                    sprintf(
                        'DROP VIEW IF EXISTS `%s`.`%s`',
                        $this->installData->getDbName(),
                        $view
                    )
                );
            }

            foreach (DatabaseUtil::TABLES as $table) {
                $this->execBestEffort(
                    $dbc,
                    sprintf(
                        'DROP TABLE IF EXISTS `%s`.`%s`',
                        $this->installData->getDbName(),
                        $table
                    )
                );
            }

            $this->execBestEffort($dbc, 'SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $this->execBestEffort(
                $dbc,
                sprintf(
                    'DROP DATABASE IF EXISTS `%s`',
                    $this->installData->getDbName()
                )
            );

            if ($dbUser) {
                $this->execBestEffort(
                    $dbc,
                    sprintf(
                        'DROP USER IF EXISTS %s@%s',
                        $dbc->quote($dbUser),
                        $dbc->quote($this->installData->getDbAuthHost() ?? '')
                    )
                );

                if ($this->installData->getDbAuthHostDns()
                    && $this->installData->getDbAuthHost() !== $this->installData->getDbAuthHostDns()
                ) {
                    $this->execBestEffort(
                        $dbc,
                        sprintf(
                            'DROP USER IF EXISTS %s@%s',
                            $dbc->quote($dbUser),
                            $dbc->quote($this->installData->getDbAuthHostDns())
                        )
                    );
                }
            }
        }

        logger('Rollback');
    }

    private function execBestEffort(PDO $dbc, string $query): void
    {
        try {
            $dbc->exec($query);
        } catch (PDOException $e) {
            processException($e);
        }
    }

    /**
     * @throws SPException
     */
    private function checkDatabase(string $exceptionHint): void
    {
        try {
            $this->dbStorage
                ->getConnectionSimple()
                ->exec(sprintf('USE `%s`', $this->installData->getDbName()));
        } catch (PDOException $e) {
            throw new SPException(
                sprintf(
                    __('Error while selecting \'%s\' database (%s)'),
                    $this->installData->getDbName(),
                    $e->getMessage()
                ),
                SPException::CRITICAL,
                $exceptionHint,
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws SPException
     */
    public function createDBStructure(): void
    {
        $this->checkDatabase(
            __u(
                'Unable to use the database to create the structure. Please check the permissions and it does not exist.'
            )
        );

        try {
            $dbc = $this->dbStorage->getConnectionSimple();

            foreach ($this->databaseFile->parse() as $query) {
                $dbc->exec($query);
            }
        } catch (PDOException $e) {
            processException($e);

            throw new SPException(
                sprintf(__('Error while creating the DB (\'%s\')'), $e->getMessage()),
                SPException::CRITICAL,
                __u('Error while creating database structure.'),
                $e->getCode(),
                $e
            );
        } catch (FileException $e) {
            processException($e);

            throw new SPException(
                sprintf(__('Error while creating the DB (\'%s\')'), $e->getMessage()),
                SPException::ERROR,
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Check the connection to the database
     *
     * @throws SPException
     */
    public function checkConnection(): void
    {
        if (!$this->databaseUtil->checkDatabaseTables($this->installData->getDbName())) {
            throw new SPException(
                __u('Error while checking the database'),
                SPException::CRITICAL,
                __u('Please, try the installation again')
            );
        }
    }
}
