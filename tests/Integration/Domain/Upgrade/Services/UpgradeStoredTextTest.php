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

namespace SP\Tests\Integration\Domain\Upgrade\Services;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\Database\MysqlFileParser;
use SP\Infrastructure\File\FileHandler;

use function SP\Tests\getDbHandler;

/**
 * The migration for text that was stored escaped.
 *
 * Every value submitted to a version that escaped HTML on the way in is sitting in the database as
 * entities: a category called `Q&A` is `Q&amp;A`, and an LDAP filter `(&(objectClass=user))` was
 * handed to the directory as `(&amp;(objectClass=user))`. The application stores text as typed
 * now, and `schemas/40024240101.sql` is what brings the rows written before that in line.
 *
 * It is a file of `REPLACE()` chains rather than a call to a decoder, so what is actually being
 * tested is the ordering inside those chains. Decoding the ampersand first would take `&amp;lt;` —
 * which is how somebody who typed `&lt;` had it stored — through `&lt;` and on to `<`, quietly
 * replacing what they wrote. The ampersand therefore comes last, and that is the case below that
 * would otherwise pass unnoticed.
 */
#[Group('integration')]
class UpgradeStoredTextTest extends TestCase
{
    private PDO $pdo;
    private string $suffix = '';

    /**
     * Each pair is what the old escaping would have stored, and what the value must read after the
     * migration has run.
     *
     * @return array<string, array{string, string}>
     */
    public static function storedTextProvider(): array
    {
        return [
            'an ampersand' => ['Q&amp;A', 'Q&A'],
            'markup' => ['&lt;b&gt;notes&lt;/b&gt;', '<b>notes</b>'],
            'an LDAP filter' => ['(&amp;(objectClass=user))', '(&(objectClass=user))'],
            'a query string' => ['https://x.invalid/?a=1&amp;b=2', 'https://x.invalid/?a=1&b=2'],
            // The one the ordering exists for: this is `&lt;` as somebody typed it.
            'an entity that was typed' => ['&amp;lt;', '&lt;'],
            'and the other one' => ['&amp;amp;', '&amp;'],
            'nothing to do' => ['an ordinary name', 'an ordinary name'],
            'not ASCII' => ['Caf&amp;eacute; ☕', 'Caf&eacute; ☕'],
        ];
    }

    /**
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    #[Test]
    #[DataProvider('storedTextProvider')]
    public function storedTextIsDecodedToWhatWasTyped(string $stored, string $expected): void
    {
        $id = $this->givenACategoryNamed($stored);

        $this->runTheMigration();

        self::assertSame($expected, $this->nameWithoutTheMarker($id));
    }

    /**
     * It compounds if it is applied twice, and no decode of stored text could avoid that: run
     * again, `&amp;amp;` goes from `&amp;` to `&`. There is nothing in the value that says whether
     * it has been decoded already.
     *
     * That is recorded here rather than wished away, because it is what the rest of the design
     * rests on: the run happens once, gated by the database version UpgradeDatabase records after
     * the file has been applied, and the file is wrapped in a transaction so an interrupted run
     * leaves the rows as they were instead of half decoded with no version written.
     *
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    #[Test]
    public function applyingItTwiceCompoundsWhichIsWhyItIsRunOnce(): void
    {
        $id = $this->givenACategoryNamed('&amp;amp;');

        $this->runTheMigration();
        self::assertSame('&amp;', $this->nameWithoutTheMarker($id));

        $this->runTheMigration();
        self::assertSame('&', $this->nameWithoutTheMarker($id));
    }

    /**
     * And the version is written, which is the thing that stops a second run from happening.
     *
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    #[Test]
    public function theFileIsTheOneTheVersionNames(): void
    {
        // UpgradeDatabase derives the filename from the version on its attribute; if the two ever
        // disagree the upgrade fails at the point an administrator runs it, having already told
        // them it was starting.
        self::assertFileExists(REAL_APP_ROOT . '/schemas/40024240101.sql');

        $attributes = (new \ReflectionClass(\SP\Domain\Upgrade\Services\UpgradeDatabase::class))
            ->getAttributes(\SP\Domain\Common\Attributes\UpgradeVersion::class);

        $versions = array_map(static fn($a) => $a->newInstance()->version, $attributes);

        self::assertContains('400.24240101', $versions);
    }

    /**
     * It reaches the other tables too, not only the one the cases above use.
     *
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    #[Test]
    public function itReachesAnAccountsFields(): void
    {
        $clientId = (int)$this->pdo->query('SELECT MIN(`id`) FROM `Client`')->fetchColumn();
        $categoryId = (int)$this->pdo->query('SELECT MIN(`id`) FROM `Category`')->fetchColumn();
        $userId = (int)$this->pdo->query('SELECT MIN(`id`) FROM `User`')->fetchColumn();
        $groupId = (int)$this->pdo->query('SELECT MIN(`id`) FROM `UserGroup`')->fetchColumn();

        if ($clientId === 0 || $categoryId === 0 || $userId === 0 || $groupId === 0) {
            self::markTestSkipped('the fixture rows an account refers to are not loaded');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO `Account` (`userGroupId`, `userId`, `userEditId`, `clientId`, `categoryId`,
                                    `name`, `login`, `url`, `notes`, `pass`, `key`, `dateAdd`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $statement->execute([
            $groupId,
            $userId,
            $userId,
            $clientId,
            $categoryId,
            'AC&amp;ME',
            'user&amp;name',
            'https://x.invalid/?a=1&amp;b=2',
            '&lt;notes&gt;',
            'x',
            'y',
        ]);

        $id = (int)$this->pdo->lastInsertId();

        $this->runTheMigration();

        $row = $this->pdo->query('SELECT `name`, `login`, `url`, `notes` FROM `Account` WHERE `id` = ' . $id)
                         ->fetch(PDO::FETCH_ASSOC);

        self::assertSame('AC&ME', $row['name']);
        self::assertSame('user&name', $row['login']);
        self::assertSame('https://x.invalid/?a=1&b=2', $row['url']);
        self::assertSame('<notes>', $row['notes']);
    }

    /**
     * The file is wrapped in a transaction, so that a run interrupted half way leaves the rows as
     * they were rather than half of them decoded with no version recorded. Every other assertion
     * here reads back on the connection that ran it, and an open transaction sees its own writes —
     * so they would all pass just as well if the commit never happened. This one asks somebody
     * else.
     *
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    #[Test]
    public function theDecodeIsCommittedAndNotJustVisibleToItsOwnTransaction(): void
    {
        $id = $this->givenACategoryNamed('Q&amp;A');

        $this->runTheMigration();

        $another = getDbHandler()->getConnection();
        $statement = $another->prepare('SELECT `name` FROM `Category` WHERE `id` = ?');
        $statement->execute([$id]);

        self::assertSame('Q&A', str_replace($this->suffix, '', (string)$statement->fetchColumn()));
    }

    /**
     * @throws \SP\Domain\Core\Exceptions\FileException
     */
    private function runTheMigration(): void
    {
        $parser = new MysqlFileParser(new FileHandler(REAL_APP_ROOT . '/schemas/40024240101.sql'));

        foreach ($parser->parse('$$') as $query) {
            $this->pdo->exec($query);
        }
    }

    /**
     * A row of its own each time. The name carries a unique marker so the test neither collides
     * with what the database already holds nor depends on it — the shared fixture database is
     * whatever the previous test left behind.
     */
    private function givenACategoryNamed(string $name): int
    {
        $unique = '#' . bin2hex(random_bytes(4));

        $statement = $this->pdo->prepare('INSERT INTO `Category` (`name`, `description`, `hash`) VALUES (?, ?, ?)');
        $statement->execute([$name . $unique, null, sha1($unique)]);

        $this->suffix = $unique;

        return (int)$this->pdo->lastInsertId();
    }

    private function nameWithoutTheMarker(int $id): string
    {
        return str_replace($this->suffix, '', $this->nameOf($id));
    }

    private function nameOf(int $id): string
    {
        $statement = $this->pdo->prepare('SELECT `name` FROM `Category` WHERE `id` = ?');
        $statement->execute([$id]);

        return (string)$statement->fetchColumn();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = getDbHandler()->getConnection();
    }
}
