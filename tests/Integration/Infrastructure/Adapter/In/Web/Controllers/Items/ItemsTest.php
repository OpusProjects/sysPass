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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Items;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Category\Models\Category;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Item;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Notification\Models\Notification;
use SP\Domain\Tag\Models\Tag;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the five item endpoints that feed the account form's select boxes and the notification
 * bell. None of them had tests.
 *
 * Unlike most controllers they don't render a view: each one streams a raw JSON array (or, for
 * the account picker and the notification bell, a small envelope) straight to the response, so
 * the assertions read that output directly rather than crawling HTML.
 *
 * What makes these worth testing carefully is who gets offered what. The account and client
 * pickers exist because a non-admin must not be handed somebody else's accounts or clients, so
 * several tests here inspect the filter that actually reaches the database query — the WHERE
 * clause text and the bound values — for both an administrator and an ordinary user, rather than
 * trusting the response shape alone. Categories and tags are plain shared metadata, not tied to
 * particular accounts, so their lack of scoping is asserted too, as the deliberate behaviour it
 * is.
 */
#[Group('integration')]
class ItemsTest extends IntegrationTestCase
{
    /** Flipped by a test, before building the container, to sign in as an administrator. */
    private bool $asAdmin = false;

    /** Recorded by {@see getUserDataDto()} so a scoping test can assert the bound value. */
    private ?int $signedInUserId = null;

    /** Recorded by {@see captureQueryFor()} for a scoping assertion. */
    private ?string $capturedStatement = null;

    /** @var array<string, mixed> */
    private array $capturedBindValues = [];

    // -----------------------------------------------------------------------------------------
    // AccountsUserController
    // -----------------------------------------------------------------------------------------

    /**
     * Each account is offered as "client - account", not the account name alone: account names
     * are only unique within a client, so the bare name would be ambiguous in the picker.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function accountsUserOffersEveryVisibleAccountLabelledWithItsClient(): void
    {
        $this->addDatabaseMapperResolver(
            Simple::class,
            new QueryResult([
                new Simple(['id' => 7, 'name' => 'Widget', 'clientName' => 'Acme']),
                new Simple(['id' => 9, 'name' => 'Router', 'clientName' => 'Globex']),
            ])
        );

        $this->whenRequesting('items/accountsUser');

        $this->expectOutputString(
            '{"status":"OK","description":"","data":[{"id":7,"name":"Acme - Widget"},{"id":9,"name":"Globex - Router"}]}'
        );
    }

    /**
     * With nothing visible, the picker degrades to an empty list rather than an error or a
     * malformed body — a user with no accounts still has to be able to open the form.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function accountsUserWithNoneVisibleReturnsAnEmptyList(): void
    {
        $this->addDatabaseMapperResolver(Simple::class, new QueryResult([]));

        $this->whenRequesting('items/accountsUser');

        $this->expectOutputString('{"status":"OK","description":"","data":[]}');
    }

    /**
     * The account picker is built from the same ACL filter the account search uses: for an
     * ordinary user it restricts the query to accounts owned by, or shared with, the signed-in
     * user (and their group). If this clause were ever dropped, an ordinary user's account
     * dropdown would silently start offering every account in the installation, including ones
     * that carry other people's credentials.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function accountsUserQueryIsScopedToTheSignedInUserForAnOrdinaryUser(): void
    {
        $this->captureQueryFor(Simple::class);

        $this->whenRequesting('items/accountsUser');

        self::assertStringContainsString('AccountToUser', $this->capturedStatement);
        self::assertSame($this->signedInUserId, $this->capturedBindValues['userId']);
    }

    /**
     * An administrator's account picker skips the ownership clause entirely — that is what
     * "can manage every account" means for this dropdown.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function accountsUserQueryIsNotScopedForAnAdministrator(): void
    {
        $this->asAdmin = true;

        $this->captureQueryFor(Simple::class);

        $this->whenRequesting('items/accountsUser');

        self::assertStringNotContainsString('AccountToUser', $this->capturedStatement);
    }

    // -----------------------------------------------------------------------------------------
    // CategoriesController
    // -----------------------------------------------------------------------------------------

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function categoriesListsEveryCategoryAsAnIdNamePair(): void
    {
        $this->addDatabaseMapperResolver(
            Category::class,
            new QueryResult([
                new Category(['id' => 1, 'name' => 'Support']),
                new Category(['id' => 2, 'name' => 'Sales']),
            ])
        );

        $this->whenRequesting('items/categories');

        $this->expectOutputString('[{"id":1,"name":"Support"},{"id":2,"name":"Sales"}]');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function categoriesWithNoneDefinedReturnsAnEmptyList(): void
    {
        $this->addDatabaseMapperResolver(Category::class, new QueryResult([]));

        $this->whenRequesting('items/categories');

        $this->expectOutputString('[]');
    }

    /**
     * Categories are shared metadata, not a secret belonging to particular accounts, so unlike
     * the accounts and clients pickers this one is deliberately not scoped: the query carries no
     * WHERE clause at all, and an admin and an ordinary user are offered the same list.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function categoriesQueryCarriesNoUserScoping(): void
    {
        $this->captureQueryFor(Category::class);

        $this->whenRequesting('items/categories');

        self::assertStringNotContainsString('WHERE', $this->capturedStatement);
    }

    // -----------------------------------------------------------------------------------------
    // ClientsController
    // -----------------------------------------------------------------------------------------

    /**
     * The clients a user may see come back through the account filter, which maps rows to the
     * generic Item model rather than the Client model.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clientsListsEveryClientVisibleToTheUser(): void
    {
        $this->addDatabaseMapperResolver(
            Item::class,
            new QueryResult([
                new Item(['id' => 5, 'name' => 'Acme']),
                new Item(['id' => 6, 'name' => 'Globex']),
            ])
        );

        $this->whenRequesting('items/clients');

        $this->expectOutputString('[{"id":5,"name":"Acme"},{"id":6,"name":"Globex"}]');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clientsWithNoneVisibleReturnsAnEmptyList(): void
    {
        $this->addDatabaseMapperResolver(Item::class, new QueryResult([]));

        $this->whenRequesting('items/clients');

        $this->expectOutputString('[]');
    }

    /**
     * A client is offered to an ordinary user only if it has no accounts, is marked global, or
     * the user has ACL access to at least one of its accounts — the same ownership subquery the
     * account picker uses. Without it, a non-admin's "client" dropdown would leak the names of
     * every client in the installation, including ones whose accounts they can't open.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clientsQueryIsScopedToTheSignedInUserForAnOrdinaryUser(): void
    {
        $this->captureQueryFor(Item::class);

        $this->whenRequesting('items/clients');

        self::assertStringContainsString('AccountToUser', $this->capturedStatement);
        self::assertSame($this->signedInUserId, $this->capturedBindValues['userId']);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clientsQueryIsNotScopedForAnAdministrator(): void
    {
        $this->asAdmin = true;

        $this->captureQueryFor(Item::class);

        $this->whenRequesting('items/clients');

        self::assertStringNotContainsString('AccountToUser', $this->capturedStatement);
    }

    // -----------------------------------------------------------------------------------------
    // TagsController
    // -----------------------------------------------------------------------------------------

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function tagsListsEveryTagAsAnIdNamePair(): void
    {
        $this->addDatabaseMapperResolver(
            Tag::class,
            new QueryResult([
                new Tag(['id' => 3, 'name' => 'urgent']),
                new Tag(['id' => 4, 'name' => 'legacy']),
            ])
        );

        $this->whenRequesting('items/tags');

        $this->expectOutputString('[{"id":3,"name":"urgent"},{"id":4,"name":"legacy"}]');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function tagsWithNoneDefinedReturnsAnEmptyList(): void
    {
        $this->addDatabaseMapperResolver(Tag::class, new QueryResult([]));

        $this->whenRequesting('items/tags');

        $this->expectOutputString('[]');
    }

    /**
     * Like categories, tags are shared labels rather than account-scoped data, so the query is
     * deliberately unfiltered for every signed-in user.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function tagsQueryCarriesNoUserScoping(): void
    {
        $this->captureQueryFor(Tag::class);

        $this->whenRequesting('items/tags');

        self::assertStringNotContainsString('WHERE', $this->capturedStatement);
    }

    // -----------------------------------------------------------------------------------------
    // NotificationsController
    // -----------------------------------------------------------------------------------------

    /**
     * The bell lists the signed-in user's own pending notifications, each formatted as
     * "(component) - description". The hash lets the front end poll cheaply, comparing it
     * instead of re-rendering the list on every request.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function notificationsListsThePendingOnesForTheSignedInUser(): void
    {
        $this->addDatabaseMapperResolver(
            Notification::class,
            new QueryResult([
                new Notification(['id' => 1, 'component' => 'sysPass', 'description' => 'Backup completed']),
                new Notification(['id' => 2, 'component' => 'sysPass', 'description' => 'Update available']),
            ])
        );

        $this->whenRequesting('items/notifications');

        $this->expectOutputString(
            '{"status":0,"description":null,"data":{"message":"There aren\'t any pending notifications",'
            . '"message_has":"There are pending notifications: 2",'
            . '"count":2,'
            . '"notifications":["(sysPass) - Backup completed","(sysPass) - Update available"],'
            . '"hash":"53d7c580c7a4a14b0da3473d666bf48ea1fdcddd"},'
            . '"messages":[]}'
        );
    }

    /**
     * With nothing pending, the "no notifications" message is used instead of the counted one,
     * and the hash of an empty list is still a well-formed value the front end can compare
     * against on the next poll.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function notificationsWithNonePendingReturnsTheEmptyMessage(): void
    {
        $this->addDatabaseMapperResolver(Notification::class, new QueryResult([]));

        $this->whenRequesting('items/notifications');

        $this->expectOutputString(
            '{"status":0,"description":null,"data":{"message":"There aren\'t any pending notifications",'
            . '"message_has":"There are pending notifications: 0",'
            . '"count":0,'
            . '"notifications":[],'
            . '"hash":"da39a3ee5e6b4b0d3255bfef95601890afd80709"},'
            . '"messages":[]}'
        );
    }

    /**
     * An ordinary user's bell is scoped to their own id (plus anything sticky) and excludes
     * admin-only entries — if this ever regressed to the admin query, a non-admin would start
     * seeing notifications addressed to every user in the installation.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function notificationsQueryIsScopedToTheOrdinaryUserAndExcludesAdminOnlyEntries(): void
    {
        $this->captureQueryFor(Notification::class);

        $this->whenRequesting('items/notifications');

        self::assertStringContainsString('onlyAdmin = 0', $this->capturedStatement);
        self::assertStringNotContainsString('userId IS NULL', $this->capturedStatement);
        self::assertSame($this->signedInUserId, $this->capturedBindValues['userId']);
    }

    /**
     * An administrator's bell additionally picks up notifications with no owning user at all
     * (broadcasts to admins), and drops the "not admin-only" restriction — the opposite shape of
     * the ordinary-user query above.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function notificationsQueryForAnAdministratorAlsoIncludesUnassignedEntries(): void
    {
        $this->asAdmin = true;

        $this->captureQueryFor(Notification::class);

        $this->whenRequesting('items/notifications');

        self::assertStringContainsString('userId IS NULL', $this->capturedStatement);
        self::assertStringNotContainsString('onlyAdmin = 0', $this->capturedStatement);
        self::assertSame($this->signedInUserId, $this->capturedBindValues['userId']);
    }

    // -----------------------------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------------------------

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Installs a resolver that, instead of answering with fixed rows, records the SQL statement
     * and bound values of the query mapped to the given class, so a test can assert on the filter
     * the controller's service actually built rather than on canned output. Any other query
     * issued during the same request (there is at most one besides the target here, since the
     * session and ACL are already stubbed at the context level) gets the harness's normal empty
     * result, matching the default behaviour when no resolver is set.
     */
    private function captureQueryFor(string $mapClassName): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData) use ($mapClassName): QueryResult {
            if ($queryData->getMapClassName() === $mapClassName) {
                $this->capturedStatement = $queryData->getQuery()->getStatement();
                $this->capturedBindValues = $queryData->getQuery()->getBindValues();

                return new QueryResult([]);
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * Signs in as an administrator when {@see $asAdmin} was set, an ordinary user otherwise —
     * the two paths the scoping tests above compare — and records the id so a test can assert it
     * against the bound query parameter.
     */
    protected function getUserDataDto(): UserDto
    {
        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData())
            ->mutate([
                'isAdminApp' => $this->asAdmin,
                'isAdminAcc' => false,
                'userGroupName' => self::$faker->colorName(),
            ]);

        $this->signedInUserId = $userDto->id;

        return $userDto;
    }
}
