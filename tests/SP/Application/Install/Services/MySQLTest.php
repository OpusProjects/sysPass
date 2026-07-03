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

namespace SP\Tests\Application\Install\Services;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Database\Ports\DatabaseFileInterface;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\Install\Adapters\InstallData;
use SP\Application\Install\Services\MysqlSetup;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Infrastructure\File\FileException;
use SP\Tests\UnitaryTestCase;

use function SP\__;
use function SP\__u;

/**
 * Class MySQLTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class MySQLTest extends UnitaryTestCase
{
    private DbStorageHandler|MockObject $dbStorage;
    private MysqlSetup     $mysqlService;
    private PDO|MockObject $pdo;
    private InstallData                      $installData;
    private ConfigDataInterface              $configData;
    private DatabaseFileInterface|MockObject $databaseFile;
    private DatabaseUtil|MockObject          $databaseUtil;

    /**
     * @throws SPException
     */
    public function testConnectDatabaseIsSuccessful(): void
    {
        $this->dbStorage->expects(self::once())->method('getConnectionSimple');

        $this->mysqlService->connectDatabase();
    }

    /**
     * @throws SPException
     */
    public function testConnectDatabaseIsNotSuccessful(): void
    {
        $this->dbStorage->expects(self::once())
                        ->method('getConnectionSimple')
                        ->willThrowException(
                            new SPException('test')
                        );

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Unable to connect to DB');

        $this->mysqlService->connectDatabase();
    }

    /**
     * @throws SPException
     */
    public function testSetupUserIsSuccessful(): void
    {
        $matcher = self::exactly(2);

        $this->pdo->expects($matcher)
                  ->method('exec')
                  ->with(
                      new Callback(
                          static function (string $query) use ($matcher) {
                              return $matcher->numberOfInvocations() === 1
                                  ? preg_match('/^CREATE USER sp_\w+@.+ IDENTIFIED BY .+$/', $query) === 1
                                  : $query === 'FLUSH PRIVILEGES';
                          }
                      )
                  );

        $this->pdo->method('quote')->willReturnArgument(0);

        [$user, $pass] = $this->mysqlService->setupDbUser();

        $this->assertSame(preg_match('/sp_\w+/', $user), 1);
        $this->assertNotEmpty($pass);
        $this->assertEquals(16, strlen($pass));
    }

    public function testSetupUserIsNotSuccessful(): void
    {
        $this->dbStorage->expects(self::once())
                        ->method('getConnectionSimple')
                        ->willThrowException(new PDOException('test'));

        $this->expectException(SPException::class);
        $this->expectExceptionMessageMatches('/Error while creating the MySQL connection user \'sp_\w+\'/');

        $this->mysqlService->setupDbUser();
    }

    public function testCheckDatabaseDoesNotExist(): void
    {
        $query = 'SELECT COUNT(*) FROM information_schema.schemata WHERE `schema_name` = ? LIMIT 1';

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())->method('prepare')->with($query)->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('execute')->with(
            new Callback(
                fn($args) => $args[0] === $this->installData->getDbName()
            )
        );
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(0);

        $this->assertFalse($this->mysqlService->checkDatabaseExists());
    }

    public function testCheckDatabaseExists(): void
    {
        $query = 'SELECT COUNT(*) FROM information_schema.schemata WHERE `schema_name` = ? LIMIT 1';

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())->method('prepare')->with($query)->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('execute')->with(
            new Callback(
                fn($args) => $args[0] === $this->installData->getDbName()
            )
        );
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(1);

        $this->assertTrue($this->mysqlService->checkDatabaseExists());
    }

    /**
     * @throws SPException
     * @throws Exception
     */
    public function testCreateDatabaseIsSuccessful(): void
    {
        $this->configData->setDbUser(self::$faker->userName());

        $execArguments = [
            [
                sprintf(
                    'CREATE SCHEMA `%s` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci',
                    $this->installData->getDbName()
                ),
            ],
            [
                sprintf(
                    'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s',
                    $this->installData->getDbName(),
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHost()
                ),
            ],
            ['FLUSH PRIVILEGES'],
        ];

        $this->pdo->expects(self::exactly(3))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->expects(self::exactly(2))->method('quote')
                  ->with(
                      ...self::withConsecutive(
                      [$this->configData->getDbUser()],
                      [$this->installData->getDbAuthHost()],
                  )
                  )->willReturnArgument(0);

        $this->mysqlService->createDatabase($this->configData->getDbUser());
    }

    /**
     * @throws SPException
     */
    public function testCreateDatabaseIsANoOpInHostingMode(): void
    {
        $this->installData->setHostingMode(true);

        $this->pdo->expects(self::never())->method('exec');

        $this->mysqlService->createDatabase();
    }

    /**
     * @throws SPException
     */
    public function testCheckDatabaseAvailabilityFailsWithDuplicateDatabase(): void
    {
        $query = 'SELECT COUNT(*) FROM information_schema.schemata WHERE `schema_name` = ? LIMIT 1';

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())->method('prepare')->with($query)->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('execute')->with(
            new Callback(
                fn($args) => $args[0] === $this->installData->getDbName()
            )
        );
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(1);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('The database already exists');

        $this->mysqlService->checkDatabaseAvailability();
    }

    /**
     * @throws SPException
     */
    public function testCheckDatabaseAvailabilityIsSuccessful(): void
    {
        $query = 'SELECT COUNT(*) FROM information_schema.schemata WHERE `schema_name` = ? LIMIT 1';

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())->method('prepare')->with($query)->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('execute')->with(
            new Callback(
                fn($args) => $args[0] === $this->installData->getDbName()
            )
        );
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(0);

        $this->mysqlService->checkDatabaseAvailability();
    }

    /**
     * @throws SPException
     */
    public function testCheckDatabaseAvailabilityIsSuccessfulInHostingMode(): void
    {
        $this->installData->setHostingMode(true);

        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->with(
                      sprintf(
                          'USE `%s`',
                          $this->installData->getDbName()
                      )
                  );

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())
                  ->method('prepare')
                  ->with(
                      new Callback(
                          static fn(string $query) => str_starts_with(
                              $query,
                              'SELECT COUNT(*) FROM information_schema.tables WHERE `table_schema` = ? AND `table_name` IN ('
                          )
                      )
                  )
                  ->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('execute')->with(
            new Callback(
                fn($args) => $args[0] === $this->installData->getDbName()
                             && count($args) === 1 + count(DatabaseUtil::TABLES) + count(DatabaseUtil::VIEWS)
            )
        );
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(0);

        $this->mysqlService->checkDatabaseAvailability();
    }

    /**
     * @throws SPException
     */
    public function testCheckDatabaseAvailabilityFailsWithExistingTablesInHostingMode(): void
    {
        $this->installData->setHostingMode(true);

        $pdoStatement = $this->createMock(PDOStatement::class);

        $this->pdo->expects(self::once())->method('prepare')->willReturn($pdoStatement);
        $pdoStatement->expects(self::once())->method('fetchColumn')->willReturn(5);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('The database already contains sysPass tables');

        $this->mysqlService->checkDatabaseAvailability();
    }

    /**
     * @throws SPException
     */
    public function testCheckDatabaseAvailabilityIsNotSuccessfulInHostingMode(): void
    {
        $this->installData->setHostingMode(true);

        $pdoException = new PDOException('test');

        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->with(
                      sprintf(
                          'USE `%s`',
                          $this->installData->getDbName()
                      )
                  )->willThrowException($pdoException);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(
            sprintf(
                __('Error while selecting \'%s\' database (%s)'),
                $this->installData->getDbName(),
                $pdoException->getMessage()
            )
        );

        $this->mysqlService->checkDatabaseAvailability();
    }

    /**
     * @throws SPException
     * @throws Exception
     */
    public function testCreateDatabaseIsSuccessfulWithDns(): void
    {
        $this->configData->setDbUser(self::$faker->userName());
        $this->installData->setDbAuthHostDns(self::$faker->domainName());

        $execArguments = [
            [
                sprintf(
                    'CREATE SCHEMA `%s` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci',
                    $this->installData->getDbName()
                ),
            ],
            [
                sprintf(
                    'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s',
                    $this->installData->getDbName(),
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHost()
                ),
            ],
            [
                sprintf(
                    'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s',
                    $this->installData->getDbName(),
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHostDns()
                ),
            ],
            ['FLUSH PRIVILEGES'],
        ];

        $this->pdo->expects(self::exactly(4))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->expects(self::exactly(4))->method('quote')
                  ->with(
                      ...self::withConsecutive(
                      [$this->configData->getDbUser()],
                      [$this->installData->getDbAuthHost()],
                      [$this->configData->getDbUser()],
                      [$this->installData->getDbAuthHostDns()],
                  )
                  )->willReturnArgument(0);

        $this->mysqlService->createDatabase($this->configData->getDbUser());
    }

    /**
     * @throws SPException
     */
    public function testCreateDatabaseIsNotSuccessfulWithCreateError(): void
    {
        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->with(
                      sprintf(
                          'CREATE SCHEMA `%s` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci',
                          $this->installData->getDbName()
                      )
                  )
                  ->willThrowException(new PDOException('test'));

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(sprintf(__('Error while creating the DB (\'%s\')'), 'test'));

        $this->mysqlService->createDatabase();
    }

    /**
     * @throws SPException
     * @throws Exception
     */
    public function testCreateDatabaseIsNotSuccessfulWithPermissionError(): void
    {
        $this->configData->setDbUser(self::$faker->userName());

        // No rollback here: the caller (Installer) rolls back on failure
        $execArguments = [
            [
                sprintf(
                    'CREATE SCHEMA `%s` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci',
                    $this->installData->getDbName()
                ),
            ],
            [
                sprintf(
                    'GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s',
                    $this->installData->getDbName(),
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHost()
                ),
            ],
        ];

        $matcher = $this->exactly(2);

        $this->pdo->expects($matcher)
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments))
                  ->willReturnCallback(function () use ($matcher) {
                      if ($matcher->numberOfInvocations() === 2) {
                          throw new PDOException('test');
                      }

                      return 1;
                  });

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(
            sprintf(__('Error while setting the database permissions (\'%s\')'), 'test')
        );

        $this->mysqlService->createDatabase($this->configData->getDbUser());
    }

    public function testRollbackIsSuccessful(): void
    {
        $this->configData->setDbUser(self::$faker->userName());
        $this->installData->setDbAuthHostDns(self::$faker->domainName());

        $execArguments = [
            [
                sprintf(
                    'DROP DATABASE IF EXISTS `%s`',
                    $this->installData->getDbName()
                ),
            ],
            [
                sprintf(
                    'DROP USER IF EXISTS %s@%s',
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHost()
                ),
            ],
            [
                sprintf(
                    'DROP USER IF EXISTS %s@%s',
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHostDns()
                ),
            ],
        ];

        $this->pdo->expects(self::exactly(3))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->mysqlService->rollback($this->configData->getDbUser());
    }

    public function testRollbackIsSuccessfulWithSameDnsHost(): void
    {
        $this->configData->setDbUser(self::$faker->userName());
        $this->installData->setDbAuthHost('localhost');
        $this->installData->setDbAuthHostDns('localhost');

        $execArguments = [
            [
                sprintf(
                    'DROP DATABASE IF EXISTS `%s`',
                    $this->installData->getDbName()
                ),
            ],
            [
                sprintf(
                    'DROP USER IF EXISTS %s@%s',
                    $this->configData->getDbUser(),
                    $this->installData->getDbAuthHost()
                ),
            ],
        ];

        $this->pdo->expects(self::exactly(2))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->mysqlService->rollback($this->configData->getDbUser());
    }

    public function testRollbackIsSuccessfulWithHostingMode(): void
    {
        $this->installData->setHostingMode(true);

        $dbName = $this->installData->getDbName();
        $dropTableRegex = '/^DROP TABLE IF EXISTS `' . $dbName . '`\.`\w+`$/';
        $dropViewRegex = '/^DROP VIEW IF EXISTS `' . $dbName . '`\.`\w+`$/';

        // FK toggle (2) + views + tables — with FK checks off, drop order is irrelevant
        $expectedCount = 2 + count(DatabaseUtil::VIEWS) + count(DatabaseUtil::TABLES);

        $this->pdo->expects(self::exactly($expectedCount))
                  ->method('exec')
                  ->with(
                      $this->callback(
                          static fn($arg) => preg_match($dropTableRegex, $arg) > 0
                                             || preg_match($dropViewRegex, $arg) > 0
                                             || preg_match('/^SET FOREIGN_KEY_CHECKS = [01]$/', $arg) > 0
                      )
                  );

        $this->mysqlService->rollback();
    }

    public function testRollbackNeverThrows(): void
    {
        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->willThrowException(new PDOException('test'));

        // Best-effort: a rollback failure must not mask the error that triggered it
        $this->mysqlService->rollback();
    }

    /**
     * @throws SPException
     */
    public function testCreateDBStructureIsSuccessful(): void
    {
        $execArguments = [
            [
                sprintf('USE `%s`', $this->installData->getDbName()),
            ],
            [
                'DROP TABLE IF EXISTS `Account`;',
            ],
        ];

        $this->pdo->expects(self::exactly(2))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->databaseFile->expects(self::once())
                           ->method('parse')
                           ->willReturn(['DROP TABLE IF EXISTS `Account`;']);

        $this->mysqlService->createDBStructure();
    }

    /**
     * @throws SPException
     */
    public function testCreateDBStructureIsNotSuccessfulWithUseDatabaseError(): void
    {
        $pdoException = new PDOException("test");

        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->with(sprintf('USE `%s`', $this->installData->getDbName()))
                  ->willThrowException($pdoException);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(
            sprintf(
                __('Error while selecting \'%s\' database (%s)'),
                $this->installData->getDbName(),
                $pdoException->getMessage()
            )
        );

        $this->mysqlService->createDBStructure();
    }

    /**
     * @throws SPException
     */
    public function testCreateDBStructureIsNotSuccessfulWithCreateSchemaError(): void
    {
        // No rollback here: the caller (Installer) rolls back on failure
        $execArguments = [
            [
                sprintf('USE `%s`', $this->installData->getDbName()),
            ],
            [
                'DROP TABLE IF EXISTS `Account`;',
            ],
        ];
        $matcher = $this->exactly(2);

        $this->pdo->expects($matcher)
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments))
                  ->willReturnCallback(function () use ($matcher) {
                      if ($matcher->numberOfInvocations() === 2) {
                          throw  new PDOException('test');
                      }

                      return 1;
                  });

        $this->databaseFile->expects(self::once())
                           ->method('parse')
                           ->willReturn(['DROP TABLE IF EXISTS `Account`;']);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(
            sprintf(__('Error while creating the DB (\'%s\')'), 'test')
        );

        $this->mysqlService->createDBStructure();
    }

    /**
     * @throws SPException
     */
    public function testCreateDBStructureIsNotSuccessfulWithParseSchemaError(): void
    {
        $fileException = new FileException("test");

        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->with(sprintf('USE `%s`', $this->installData->getDbName()));

        $this->databaseFile->expects(self::once())
                           ->method('parse')
                           ->willThrowException($fileException);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(
            sprintf(__('Error while creating the DB (\'%s\')'), $fileException->getMessage())
        );

        $this->mysqlService->createDBStructure();
    }

    /**
     * @throws SPException
     */
    public function testCheckConnectionIsSuccessful(): void
    {
        $this->databaseUtil->expects(self::once())
                           ->method('checkDatabaseTables')
                           ->with($this->installData->getDbName())
                           ->willReturn(true);

        $this->mysqlService->checkConnection();
    }

    /**
     * @throws SPException
     */
    public function testCheckConnectionIsNotSuccessful(): void
    {
        $this->databaseUtil->expects(self::once())
                           ->method('checkDatabaseTables')
                           ->with($this->installData->getDbName())
                           ->willReturn(false);

        // No rollback here: the caller (Installer) rolls back on failure
        $this->pdo->expects(self::never())->method('exec');

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(__u('Error while checking the database'));

        $this->mysqlService->checkConnection();
    }

    /**
     * @throws SPException
     */
    public function testCreateDBUserIsSuccessful(): void
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $execArguments = [
            [
                sprintf(
                    'CREATE USER %s@%s IDENTIFIED BY %s',
                    $user,
                    $this->installData->getDbAuthHost(),
                    $pass
                ),
            ],
            [
                'FLUSH PRIVILEGES',
            ],
        ];

        $this->pdo->expects(self::exactly(2))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->mysqlService->createDBUser($user, $pass);
    }

    /**
     * @throws SPException
     */
    public function testCreateDBUserIsSuccessfulWithDns(): void
    {
        $this->installData->setDbAuthHostDns(self::$faker->domainName());

        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $execArguments = [
            [
                sprintf(
                    'CREATE USER %s@%s IDENTIFIED BY %s',
                    $user,
                    $this->installData->getDbAuthHost(),
                    $pass
                ),
            ],
            [
                sprintf(
                    'CREATE USER %s@%s IDENTIFIED BY %s',
                    $user,
                    $this->installData->getDbAuthHostDns(),
                    $pass
                ),
            ],
            [
                'FLUSH PRIVILEGES',
            ],
        ];

        $this->pdo->expects(self::exactly(3))
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments));

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->mysqlService->createDBUser($user, $pass);
    }

    /**
     * @throws SPException
     */
    public function testCreateDBUserIsNotSuccessful(): void
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $this->pdo->expects(self::once())
                  ->method('exec')
                  ->willThrowException(new PDOException('test'));

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(sprintf(__u('Error while creating the MySQL connection user \'%s\''), $user));

        $this->mysqlService->createDBUser($user, $pass);
    }

    /**
     * @throws SPException
     */
    public function testCreateDBUserDropsFirstVariantWhenDnsVariantFails(): void
    {
        $this->installData->setDbAuthHostDns(self::$faker->domainName());

        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $execArguments = [
            [
                sprintf(
                    'CREATE USER %s@%s IDENTIFIED BY %s',
                    $user,
                    $this->installData->getDbAuthHost(),
                    $pass
                ),
            ],
            [
                sprintf(
                    'CREATE USER %s@%s IDENTIFIED BY %s',
                    $user,
                    $this->installData->getDbAuthHostDns(),
                    $pass
                ),
            ],
            [
                sprintf(
                    'DROP USER IF EXISTS %s@%s',
                    $user,
                    $this->installData->getDbAuthHost()
                ),
            ],
        ];

        $matcher = $this->exactly(3);

        $this->pdo->expects($matcher)
                  ->method('exec')
                  ->with(...self::withConsecutive(...$execArguments))
                  ->willReturnCallback(static function () use ($matcher) {
                      if ($matcher->numberOfInvocations() === 2) {
                          throw new PDOException('test');
                      }

                      return 1;
                  });

        $this->pdo->method('quote')->willReturnArgument(0);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage(sprintf(__u('Error while creating the MySQL connection user \'%s\''), $user));

        $this->mysqlService->createDBUser($user, $pass);
    }

    /**
     * @throws SPException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createMock(PDO::class);

        $this->dbStorage = $this->createMock(DbStorageHandler::class);
        $this->dbStorage->method('getConnection')->willReturn($this->pdo);
        $this->dbStorage->method('getConnectionSimple')->willReturn($this->pdo);

        $this->databaseFile = $this->createMock(DatabaseFileInterface::class);

        $this->installData = $this->getInstallData();
        $this->configData = $this->config->getConfigData();
        $this->databaseUtil = $this->createMock(DatabaseUtil::class);
        $this->mysqlService = new MysqlSetup(
            $this->dbStorage,
            $this->installData,
            $this->databaseFile,
            $this->databaseUtil
        );
    }

    /**
     * @return InstallData
     */
    private function getInstallData(): InstallData
    {
        $params = new InstallData();
        $params->setDbAdminUser(self::$faker->userName());
        $params->setDbAdminPass(self::$faker->password());
        $params->setDbName(self::$faker->colorName());
        $params->setDbHost(self::$faker->domainName());
        $params->setAdminLogin(self::$faker->userName());
        $params->setAdminPass(self::$faker->password());
        $params->setMasterPassword(self::$faker->password(11));
        $params->setSiteLang(self::$faker->languageCode());
        $params->setDbAuthHost(self::$faker->ipv4());

        return $params;
    }
}
