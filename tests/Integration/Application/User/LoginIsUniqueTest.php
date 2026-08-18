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

namespace SP\Tests\Integration\Application\User;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function SP\Tests\getDbHandler;

/**
 * Two people cannot share a login, and it is the database that has to say so.
 *
 * `checkDuplicatedOnAdd()` refuses a login that already exists: a SELECT, then the INSERT if
 * nothing came back. Two requests arriving together both find nothing and both insert, so what
 * stops the second is the index — and `uk_User_01` covered `(login, ssoLogin)` rather than `login`.
 * MySQL treats NULLs in a unique index as distinct, so two rows with the same login and no SSO
 * login, which is most of them, were both accepted. Two simultaneous first-time logins through
 * `createOnLogin()` are all it takes.
 *
 * `getByLogin()` then answers with `LIMIT 1` and no ordering, so which of the two a login resolves
 * to is the server's choice — and `DatabaseAuth` is the caller.
 *
 * Asserted against the schema as it is installed, because a constraint is only worth what the
 * server enforces.
 */
#[Group('integration')]
final class LoginIsUniqueTest extends TestCase
{
    private const SCHEMA = 'syspass_login_probe';

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = getDbHandler()->getConnection();

        // The real schema, in a database of its own: this inserts users, and the fixture database
        // belongs to every other test in the run.
        $this->pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::SCHEMA));
        $this->pdo->exec(sprintf('CREATE DATABASE `%s`', self::SCHEMA));
        $this->pdo->exec(sprintf('USE `%s`', self::SCHEMA));
        $this->pdo->exec(self::userTableFromSchema());
    }

    protected function tearDown(): void
    {
        $this->pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::SCHEMA));

        parent::tearDown();
    }

    /**
     * The second one is refused, whatever the SELECT before it saw.
     */
    #[Test]
    public function aSecondUserCannotTakeALoginAlreadyInUse(): void
    {
        $this->givenAUser('alice', null);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry/');

        $this->givenAUser('alice', null);
    }

    /**
     * Everybody without an SSO login is still their own user.
     *
     * The rule is about `login`, and `ssoLogin` is left alone on purpose: the application exempts
     * an empty one, the user form stores `''` for a field left blank, and `''` is not distinct the
     * way NULL is — so a unique index over `ssoLogin` would refuse the second user who has none.
     * That is the regression this test exists to rule out.
     */
    #[Test]
    public function usersWithNoSsoLoginDoNotCollideWithEachOther(): void
    {
        $this->givenAUser('alice', null);
        $this->givenAUser('bob', null);
        $this->givenAUser('carol', '');
        $this->givenAUser('dave', '');

        self::assertSame(4, $this->userCount(), 'having no SSO login is not a thing users share');
    }

    private function givenAUser(string $login, ?string $ssoLogin): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `User` (`name`, `login`, `ssoLogin`, `userGroupId`, `userProfileId`, `pass`, `hashSalt`)
             VALUES (:name, :login, :ssoLogin, 1, 1, \'\', \'\')'
        );

        $statement->execute(['name' => ucfirst($login), 'login' => $login, 'ssoLogin' => $ssoLogin]);
    }

    private function userCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM `User`')->fetchColumn();
    }

    /**
     * The `User` table exactly as `dbstructure.sql` declares it, minus the foreign keys — the
     * tables they point at are not what is under test, and creating them would say nothing more.
     */
    private static function userTableFromSchema(): string
    {
        $schema = (string)file_get_contents(REAL_APP_ROOT . '/schemas/dbstructure.sql');

        self::assertSame(
            1,
            preg_match('/CREATE TABLE `User`\s*\((.*?)\n\) ENGINE/s', $schema, $matches),
            'no CREATE TABLE for User in dbstructure.sql'
        );

        $body = (string)preg_replace('/,?\s*CONSTRAINT[^,]*(?=,|\s*$)/s', '', $matches[1]);

        return sprintf('CREATE TABLE `User` (%s) ENGINE = InnoDB', rtrim(trim($body), ','));
    }
}
