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

namespace SP\Tests\Unit\Domain\Account\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Adapters\AccountSearchItem;
use SP\Domain\Account\Models\AccountSearchView;
use SP\Domain\Common\Models\Item;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountSearchItemTest
 *
 * Each row of an account listing is presented through this adapter: it shortens the text to fit
 * the column, decides which of the optional links are offered, and collects who the account is
 * shared with. It had almost no coverage.
 *
 * The optional features are static flags on the class, so they are restored after every test —
 * leaving one set would silently change what later tests see.
 */
#[Group('unitary')]
class AccountSearchItemTest extends UnitaryTestCase
{
    private const MAX_LENGTH = 10;

    protected function tearDown(): void
    {
        AccountSearchItem::$wikiEnabled = false;
        AccountSearchItem::$publicLinkEnabled = false;
        AccountSearchItem::$optionalActions = false;
        AccountSearchItem::$accountLink = false;

        parent::tearDown();
    }

    /**
     * Text is shortened to fit its column, and the client name gets a third of the room since it
     * shares its cell.
     *
     * @throws Exception
     */
    public function testLongTextIsShortenedToFitItsColumn(): void
    {
        $item = $this->item([
            'url' => 'https://example.invalid/a/very/long/path/that/will/not/fit',
            'login' => 'a-very-long-login-name',
            'clientName' => 'A very long client name',
        ]);

        self::assertLessThanOrEqual(self::MAX_LENGTH + 3, strlen($item->getShortUrl()));
        self::assertLessThanOrEqual(self::MAX_LENGTH + 3, strlen($item->getShortLogin()));
        self::assertLessThanOrEqual((int)(self::MAX_LENGTH / 3) + 3, strlen($item->getShortClientName()));
    }

    /**
     * Whether the URL is rendered as a link depends on it having a scheme, so a bare host or a
     * note in the field is shown as text rather than becoming a broken link.
     *
     * @throws Exception
     */
    #[DataProvider('urlProvider')]
    public function testOnlyAnAddressWithASchemeIsALink(string $url, bool $isLink): void
    {
        self::assertSame($isLink, $this->item(['url' => $url])->isUrlIslink());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function urlProvider(): array
    {
        return [
            'https' => ['https://example.invalid', true],
            'ssh' => ['ssh://host.invalid', true],
            'a bare host' => ['example.invalid', false],
            'a note' => ['ask the admin', false],
            'empty' => ['', false],
        ];
    }

    /**
     * The wiki link is only offered when the wiki is switched on, and is built from the client
     * name so it has to be escaped for a query string.
     *
     * @throws Exception
     */
    public function testTheClientLinkIsOfferedOnlyWithTheWikiEnabled(): void
    {
        $item = $this->item(['clientName' => 'Client & Co']);

        self::assertNull($item->getClientLink(), 'no wiki, no link');

        AccountSearchItem::$wikiEnabled = true;

        $link = $this->item(['clientName' => 'Client & Co'])->getClientLink();

        self::assertNotNull($link);
        self::assertStringContainsString(urlencode('Client & Co'), $link);
    }

    /**
     * A public link is offered only when the feature is on and the account actually has one.
     *
     * @throws Exception
     */
    public function testAPublicLinkIsOfferedOnlyWhenOneExists(): void
    {
        AccountSearchItem::$publicLinkEnabled = true;

        self::assertNull(
            $this->item(['publicLinkHash' => null])->getPublicLink(),
            'an account without a link has none to offer'
        );

        self::assertNotNull($this->item(['publicLinkHash' => 'abc123'])->getPublicLink());

        AccountSearchItem::$publicLinkEnabled = false;

        self::assertNull(
            $this->item(['publicLinkHash' => 'abc123'])->getPublicLink(),
            'the feature being off hides an existing link'
        );
    }

    /**
     * The accesses column lists who the account is shared with. Groups are labelled by their
     * name and users by their login — not the same field, which is easy to miss since both are
     * carried in an Item.
     *
     * @throws Exception
     */
    public function testAccessesListsTheGroupsAndUsersSharedWith(): void
    {
        $item = $this->item(
            [],
            users: [new Item(['id' => 1, 'login' => 'alice']), new Item(['id' => 2, 'login' => 'bob'])],
            userGroups: [new Item(['id' => 3, 'name' => 'admins'])]
        );

        $joined = implode(' ', $item->getAccesses());

        self::assertStringContainsString('alice', $joined);
        self::assertStringContainsString('bob', $joined);
        self::assertStringContainsString('admins', $joined);
    }

    /**
     * The owner and the account's own group are always listed, marked with an asterisk, so an
     * account shared with nobody still shows who it belongs to.
     *
     * @throws Exception
     */
    public function testTheOwnerAndMainGroupAreAlwaysListed(): void
    {
        $accesses = $this->item(['userGroupName' => 'Admins', 'userLogin' => 'owner'])->getAccesses();

        self::assertCount(2, $accesses, 'nothing is shared, so only the owner and its group');
        self::assertStringContainsString('(G*)', $accesses[0]);
        self::assertStringContainsString('Admins', $accesses[0]);
        self::assertStringContainsString('(U*)', $accesses[1]);
        self::assertStringContainsString('owner', $accesses[1]);
    }

    /**
     * A share that carries edit rights is marked differently from a read-only one, so the column
     * shows not just who has access but what kind.
     *
     * @throws Exception
     */
    public function testASharedEditRightIsMarkedDifferently(): void
    {
        $readOnly = $this->item(
            ['otherUserEdit' => 0, 'otherUserGroupEdit' => 0],
            users: [new Item(['login' => 'alice'])],
            userGroups: [new Item(['name' => 'admins'])]
        )->getAccesses();

        $editable = $this->item(
            ['otherUserEdit' => 1, 'otherUserGroupEdit' => 1],
            users: [new Item(['login' => 'alice'])],
            userGroups: [new Item(['name' => 'admins'])]
        )->getAccesses();

        self::assertStringContainsString('(G)', $readOnly[2]);
        self::assertStringContainsString('(U)', $readOnly[3]);
        self::assertStringContainsString('(G+)', $editable[2]);
        self::assertStringContainsString('(U+)', $editable[3]);
    }

    /**
     * A name is escaped before it reaches the column, so it cannot inject markup into the
     * listing.
     *
     * @throws Exception
     */
    public function testAnAccessNameIsEscaped(): void
    {
        $accesses = $this->item([], userGroups: [new Item(['name' => '<script>x</script>'])]);

        self::assertStringNotContainsString('<script>', implode(' ', $accesses->getAccesses()));
    }

    /**
     * The row's buttons come from the permission, so what the viewer may do is what the row
     * offers.
     *
     * @throws Exception
     */
    public function testTheRowsButtonsFollowThePermission(): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH);
        $permission->setResultView(true);
        $permission->setResultEdit(true);
        $permission->setShowView(true);
        $permission->setShowEdit(true);

        $item = $this->item([], $permission);

        self::assertTrue($item->isShowView());
        self::assertTrue($item->isShowEdit());

        $none = $this->item([], new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH));

        self::assertFalse($none->isShowView());
        self::assertFalse($none->isShowEdit());
    }

    /**
     * Tags that are not items are dropped, so the row never renders something it cannot label.
     *
     * @throws Exception
     */
    public function testOnlyRealTagsAreKept(): void
    {
        $item = $this->item(tags: [new Item(['id' => 1, 'name' => 'linux']), 'not-a-tag', 42]);

        self::assertCount(1, $item->getTags());
    }

    /**
     * @param array<string, mixed> $account
     * @param Item[] $tags
     * @param Item[]|null $users
     * @param Item[]|null $userGroups
     *
     * @throws Exception
     */
    private function item(
        array $account = [],
        ?AccountPermission $permission = null,
        array $tags = [],
        ?array $users = null,
        ?array $userGroups = null
    ): AccountSearchItem {
        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('getWikiSearchurl')->willReturn('https://wiki.invalid/?q=');
        $configData->method('isFilesEnabled')->willReturn(true);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebUri')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        return new AccountSearchItem(
            new AccountSearchView(array_merge(['id' => 1, 'name' => 'An account'], $account)),
            $permission ?? new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH),
            $configData,
            $uriContext,
            $tags,
            self::MAX_LENGTH,
            false,
            $users,
            $userGroups
        );
    }
}
