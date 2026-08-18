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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

use function SP\Tests\getDbHandler;

/**
 * Covers the public-link endpoints over the REST API. None of them had tests.
 *
 * A public link hands an account's password to whoever holds the URL, so creating, re-keying and
 * revoking one over the API is the surface worth pinning down. These run against the real
 * database.
 */
#[Group('integration')]
class PublicLinkControllerTest extends ApiTestCase
{
    /**
     * The test makes its own account rather than naming one from the fixture.
     *
     * Accounts 1 and 2 already have a link and a second one for the same account is refused, and
     * the only two left are private — 3 to its owner, 4 to its group — so neither can be linked by
     * the admin this suite authenticates as, now that minting a link is scoped to what the caller
     * may read. An account of its own is independent of all of that.
     */
    private ?int $accountId = null;

    public function testCreateAction(): void
    {
        $r = $this->createLink();

        $this->assertSame(201, $r->status);
        $this->assertGreaterThan(0, $r->body->itemId);
    }

    /**
     * A caller cannot choose when a link expires, or how many times it may be opened.
     *
     * `getPublinksMaxTime()` and `getPublinksMaxViews()` are the administrator's configuration —
     * a maximum — and neither web path offers a way to set either. The endpoint nonetheless
     * accepted `dateExpire` and `maxCountViews`, advertised them in its own help, and discarded
     * them on the way to the database.
     *
     * What made that costly rather than merely useless is the response: it echoed the request
     * back, so a caller asking for a link that expired at a particular time was told it would,
     * while the stored link expired at whatever the configuration said. For a feature that hands
     * out credentials, being wrong about the expiry in the safe direction is luck, not design.
     */
    public function testTheExpiryAndViewLimitComeFromTheConfigurationNotTheCaller(): void
    {
        $wanted = time() + 12345;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_CREATE, [
            'itemId' => $this->accountId(),
            'dateExpire' => $wanted,
            'maxCountViews' => 7,
        ]);

        $this->assertSame(201, $r->status);

        [$storedExpiry, $storedViews] = $this->storedLimitsFor($r->body->itemId);

        $this->assertNotSame($wanted, $storedExpiry, 'the caller must not be able to set the expiry');
        $this->assertNotSame(7, $storedViews, 'nor the view limit');

        // The half that actually misled anyone: the answer has to describe the link that exists.
        $this->assertSame(
            $storedExpiry,
            $r->body->data->dateExpire,
            'the response reported an expiry the link does not have'
        );
        $this->assertSame(
            $storedViews,
            $r->body->data->maxCountViews,
            'the response reported a view limit the link does not have'
        );
    }

    /**
     * Creating a link answers with the link.
     *
     * `account/viewLink/{hash}` is the URL that gets handed out, and the service mints the hash,
     * so a caller who had only what they sent back could not hand out what they had just made.
     * The way round it was to fetch the link again — which answers with `data`, the sealed vault,
     * so working around the omission gave out more than reporting the hash does.
     *
     * Both halves are asserted: the hash is there and is the one stored, and `data` is still not.
     */
    public function testCreatingALinkAnswersWithTheHashThatIsTheUrl(): void
    {
        $r = $this->createLink();

        $this->assertSame(201, $r->status);
        $this->assertNotEmpty($r->body->data->hash, 'without the hash the caller cannot build the URL');

        $statement = getDbHandler()->getConnection()
                                   ->prepare('SELECT `hash` FROM `PublicLink` WHERE `id` = :id');
        $statement->execute(['id' => $r->body->itemId]);

        $this->assertSame(
            $statement->fetchColumn(),
            $r->body->data->hash,
            'the hash reported must be the one the link is stored under'
        );

        $this->assertNull($r->body->data->data, 'creating a link must not hand back the sealed vault');
    }

    /**
     * The expiry and the view limit as the row holds them.
     *
     * @return array{int, int}
     */
    private function storedLimitsFor(int $id): array
    {
        $statement = getDbHandler()->getConnection()
                                   ->prepare('SELECT `dateExpire`, `maxCountViews` FROM `PublicLink` WHERE `id` = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, sprintf('No PublicLink row with id %d', $id));

        return [(int)$row['dateExpire'], (int)$row['maxCountViews']];
    }

    /**
     * A link with no account behind it would hand out nothing, so the account is required.
     */
    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->callApi(AclActionsInterface::PUBLICLINK_CREATE, []);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('itemId', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createLink()->body->itemId;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame($this->accountId(), $r->body->data->itemId);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => 10000]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
    }

    /**
     * Refreshing re-keys the link, so the URL handed out previously stops working. The hash is
     * asserted to change, since that is the whole point of the operation.
     */
    public function testRefreshActionChangesTheHash(): void
    {
        $id = $this->createLink()->body->itemId;

        $before = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id])->body->data->hash;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_REFRESH, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Public link refreshed', $r->body->message);

        $after = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id])->body->data->hash;

        $this->assertNotSame($before, $after, 'a refreshed link must not keep its old URL');
    }

    public function testDeleteAction(): void
    {
        $id = $this->createLink()->body->itemId;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Public link removed', $r->body->message);

        $view = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id]);

        $this->assertInstanceOf(stdClass::class, $view->body->error, 'a revoked link is gone');
    }

    public function testSearchAction(): void
    {
        $this->createLink();

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_SEARCH, []);

        $this->assertSame(200, $r->status);
        $this->assertGreaterThan(0, $r->body->count);
    }

    private function createLink(): stdClass
    {
        return $this->callApi(AclActionsInterface::PUBLICLINK_CREATE, ['itemId' => $this->accountId()]);
    }

    /**
     * An ordinary account owned by the caller, made once per test.
     *
     * @throws \Exception
     */
    private function accountId(): int
    {
        if ($this->accountId === null) {
            $created = $this->callApi(
                AclActionsInterface::ACCOUNT_CREATE,
                [
                    'name' => 'Link target ' . bin2hex(random_bytes(4)),
                    'categoryId' => 1,
                    'clientId' => 1,
                    'pass' => 'a-password',
                ]
            );

            $this->accountId = (int)$created->body->itemId;
        }

        return $this->accountId;
    }
}
