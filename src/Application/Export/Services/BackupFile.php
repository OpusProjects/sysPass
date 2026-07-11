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

namespace SP\Application\Export\Services;

use Exception;
use PDO;
use SP\Infrastructure\Application;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Database\Ports\DatabaseInterface;
use SP\Application\Export\Ports\BackupFileService;
use SP\Application\Export\Ports\BackupHandlersFactory;
use SP\Domain\Export\Dtos\BackupHandlers;
use SP\Infrastructure\Adapter\Out\Common\Repositories\Query;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\File\FileException;
use SP\Infrastructure\File\FileSystem;

use function SP\__u;

/**
 * Class BackupFile
 */
final class BackupFile extends Service implements BackupFileService
{
    public const         BACKUP_INCLUDE_REGEX = /** @lang RegExp */
        '#^(?:[A-Z]:)?(?:/(?!(\.git|backup|cache|temp|vendor|tests))[^/]+)+/[^/]+\.\w+$#Di';

    public function __construct(
        Application                            $application,
        private readonly DatabaseInterface     $database,
        private readonly DatabaseUtil          $databaseUtil,
        private readonly BackupHandlersFactory $backupHandlersFactory,
    ) {
        parent::__construct($application);
    }

    /**
     * Perform a backup of the database and the application.
     *
     * @throws ServiceException
     */
    public function doBackup(string $backupPath, string $applicationPath): void
    {
        set_time_limit(0);

        try {
            $this->deleteOldBackups($backupPath);

            $this->eventDispatcher->notify(new Event('run.backup.start', $this, EventMessage::build()->addDescription(__u('Make Backup'))));

            $configData = $this->config->getConfigData();

            // One hash per run, used for both the archive filenames and the
            // stored backupHash, so the output lands in $backupPath
            $hash = $this->buildHash();
            $handlers = $this->backupHandlersFactory->build($backupPath, $hash);

            $this->backupTables($configData->getDbName() ?? '', $handlers);
            $this->backupApp($applicationPath, $handlers);

            $this->config->save($configData->setBackupHash($hash));
        } catch (Exception $e) {
            $this->eventDispatcher->notify(new Event('exception', $e));

            throw ServiceException::error(
                __u('Error while doing the backup'),
                __u('Please check out the event log for more details'),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Delete the previous backups
     */
    private function deleteOldBackups(string $backupPath): void
    {
        FileSystem::deleteByPattern(
            $backupPath,
            AppInfoInterface::APP_NAME . '_db-*',
            AppInfoInterface::APP_NAME . '_app-*',
            AppInfoInterface::APP_NAME . '*.sql',
        );
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    private function backupTables(string $dbName, BackupHandlers $handlers): void
    {
        $dbBackupFile = $handlers->dbFile;

        $this->eventDispatcher->notify(new Event(
            'run.backup.process',
            $this,
            EventMessage::build()->addDescription(__u('Copying database'))
        ));

        $sqlOut = [
            '-- ',
            sprintf('-- sysPass DB dump generated on %s (START)', time()),
            '-- ',
            '-- Please, do not alter this file, it could break your DB',
            '-- ',
            'SET AUTOCOMMIT = 0;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            'SET UNIQUE_CHECKS = 0;',
            '-- ',
            sprintf('CREATE DATABASE IF NOT EXISTS `%s`;', $dbName),
            '',
            sprintf('USE `%s`;', $dbName),
            ''
        ];

        $dbBackupFile->write(implode(PHP_EOL, $sqlOut));

        $tables = $this->getTables();
        $views = $this->getViews();

        foreach ($tables as $table) {
            $query = Query::buildForMySQL(sprintf('SHOW CREATE TABLE %s', $table), []);

            $data = $this->database->runQuery(QueryData::build($query))->getData();

            $sqlOut = [
                '-- ',
                sprintf('-- Table %s', strtoupper($table)),
                '-- ',
                sprintf('DROP TABLE IF EXISTS `%s`;', $table),
                sprintf('%s;', $data->{'Create Table'} ?? ''),
                ''
            ];

            $dbBackupFile->write(implode(PHP_EOL, $sqlOut));
        }

        foreach ($views as $view) {
            $query = Query::buildForMySQL(sprintf('SHOW CREATE TABLE %s', $view), []);

            $data = $this->database->runQuery(QueryData::build($query))->getData();

            $sqlOut = [
                '-- ',
                sprintf('-- View %s', strtoupper($view)),
                '-- ',
                sprintf('DROP TABLE IF EXISTS `%s`;', $view),
                sprintf('%s;', $data->{'Create View'} ?? ''),
                ''
            ];

            $dbBackupFile->write(implode(PHP_EOL, $sqlOut));
        }

        // Save tables' values
        foreach ($tables as $table) {
            $query = Query::buildForMySQL(sprintf('SELECT * FROM `%s`', $table), []);

            // Get table records
            $rows = $this->database->doFetchWithOptions(
                QueryData::build($query),
                [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL],
                PDO::FETCH_NUM,
                false
            );

            foreach ($rows as $row) {
                $values = array_map(
                    function (mixed $value) {
                        if ($value === null) {
                            return 'NULL';
                        } elseif (is_numeric($value)) {
                            return $value;
                        }

                        return $this->databaseUtil->escape((string)$value);
                    },
                    $row
                );

                $dbBackupFile->write(
                    sprintf('INSERT INTO `%s` VALUES(%s);' . PHP_EOL, $table, implode(',', $values))
                );
            }
        }

        $sqlOut = [
            '-- ',
            'SET AUTOCOMMIT = 1;',
            'SET FOREIGN_KEY_CHECKS = 1;',
            'SET UNIQUE_CHECKS = 1;',
            '-- ',
            sprintf('-- sysPass DB dump generated on %s (END)', time()),
            '-- ',
            '-- Please, do not alter this file, it could break your DB',
            '-- '
        ];

        $dbBackupFile->write(implode(PHP_EOL, $sqlOut));

        $handlers->dbArchive->compressFile($dbBackupFile->getFile());

        $dbBackupFile->delete();
    }

    /**
     * @return array|string[]
     */
    private function getTables(): array
    {
        return DatabaseUtil::TABLES;
    }

    /**
     * @return array|string[]
     */
    private function getViews(): array
    {
        return DatabaseUtil::VIEWS;
    }

    /**
     * Perform a backup of the application and compress it.
     *
     * @throws FileException
     */
    private function backupApp(string $directory, BackupHandlers $handlers): void
    {
        $this->eventDispatcher->notify(new Event('run.backup.process', $this, EventMessage::build()->addDescription(__u('Copying application'))));

        $handlers->appArchive->compressDirectory($directory, self::BACKUP_INCLUDE_REGEX);
    }

    /**
     * @return string
     */
    private function buildHash(): string
    {
        return bin2hex(random_bytes(20));
    }
}
