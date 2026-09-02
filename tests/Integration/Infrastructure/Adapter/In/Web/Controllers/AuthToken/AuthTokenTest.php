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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\AuthToken;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Auth\Models\AuthToken;
use SP\Domain\Auth\Models\AuthTokenList;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AuthTokenGenerator;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class AuthTokenTest
 */
#[Group('integration')]
#[InjectVault]
class AuthTokenTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerCreate')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/create'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiple()
    {
        // The batch delete asserts that as many rows were affected as ids were sent.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 3, 0);
        };
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'authToken/delete', 'items' => [100, 200, 300]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Authorizations deleted","data":null}');
    }

    /**
     * If the DELETE affects fewer rows than ids were sent — one of them no longer existed, or
     * another session removed it first — the batch is reported as a failure rather than as a
     * partial success. This service used to refuse only when *nothing* matched, so a selection of
     * which one item was already gone came back as the whole selection removed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialFailure()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            // Only 1 of the 2 requested ids was actually removed.
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while removing the tokens","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingle()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Authorization deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerEdit')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(
            AuthToken::class,
            new QueryResult([AuthTokenGenerator::factory()->buildAuthToken()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreate()
    {
        $data = [
            'users' => self::$faker->randomNumber(3),
            'actions' => self::$faker->randomNumber(3),
            'pass' => self::$faker->sha1()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Authorization added","data":null}');
    }

    /**
     * A missing `users` field is null (not 0), so it must be rejected as a
     * validation error instead of reaching the int-typed repository calls as a
     * TypeError / HTTP 500.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutUserIsRejectedNotFatal()
    {
        $data = [
            'actions' => self::$faker->randomNumber(3),
            'pass' => self::$faker->sha1()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        // Clean validation error, not a TypeError. (data carries a debug trace
        // when DEBUG is on, so match status + description rather than the whole body.)
        $this->expectOutputRegex('/\{"status":"ERROR","description":"User not set"/');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $data = [
            'users' => self::$faker->randomNumber(3),
            'actions' => self::$faker->randomNumber(3),
            'pass' => self::$faker->sha1()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveEdit/100'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Authorization updated","data":null}');
    }

    /**
     * A token authorises API access on behalf of a user for one action; editing one to collide
     * with another must be refused before the update runs, otherwise two tokens would grant the
     * same access and the caller could no longer tell which one is authoritative.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditDuplicateUserActionPairIsRefused()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            // The pre-update uniqueness check ('id <>' only appears in that query, not in the
            // create-side check or any other query this request issues): answering it with a
            // row simulates another token already using this user/action pair.
            if (str_contains($statement, 'id <>')) {
                return new QueryResult([new Simple(['id' => 999])]);
            }

            return new QueryResult([], 1, 100);
        };

        $data = [
            'users' => self::$faker->randomNumber(3),
            'actions' => self::$faker->randomNumber(3),
            'pass' => self::$faker->sha1()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveEdit/100'], $data)
        );

        IntegrationTestCase::runApp($container);

        // The controller has no try/catch of its own; the DuplicatedItemException bubbles up to
        // Bootstrap's outer handler, which attaches a debug trace under "data" the same way an
        // uncaught ValidationException does (see saveCreateWithoutUserIsRejectedNotFatal above).
        $this->expectOutputRegex('/\{"status":"ERROR","description":"Authorization already exist"/');
    }

    /**
     * Some actions (viewing/editing an account's password, creating an account, refreshing or
     * creating a public link) hand out a token that can decrypt the vault, so the token's own
     * password cannot be left blank for those — unlike an ordinary token, which needs none.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditMissingPasswordForSecuredActionIsRejectedNotFatal()
    {
        $data = [
            'users' => self::$faker->randomNumber(3),
            'actions' => AclActionsInterface::ACCOUNT_VIEW_PASS,
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveEdit/100'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"ERROR","description":"Password cannot be blank"/');
    }

    /**
     * Editing authorises API access, so a caller without AUTHTOKEN_EDIT must be refused outright.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditDeniedByAcl()
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $data = [
            'users' => self::$faker->randomNumber(3),
            'actions' => self::$faker->randomNumber(3),
            'pass' => self::$faker->sha1()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveEdit/100'], $data),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * Refreshing a token takes a different persistence path than a plain edit: it regenerates
     * the user's token and re-encrypts their vault under it (AuthTokenSaveBase's isRefresh()
     * branch), instead of just updating the edited fields. The response text is identical
     * either way, so the branch is told apart by which repository queries actually ran:
     * refreshTokenByUserId() and refreshVaultByUserId() both update by userId, unlike the plain
     * edit path, which updates by id.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditRefreshRegeneratesTokenAndVault()
    {
        $refreshQueriesRan = 0;

        $this->databaseQueryResolver = function (QueryData $queryData) use (&$refreshQueriesRan): QueryResult {
            $statement = $queryData->getQuery()->getStatement();
            $bindValues = $queryData->getQuery()->getBindValues();

            // The plain edit's UPDATE also binds 'userId' (as a SET column), but keyed by 'id'
            // in the WHERE clause; only refreshTokenByUserId()/refreshVaultByUserId() look up
            // by userId with no 'id' bind at all.
            if (str_starts_with($statement, 'UPDATE')
                && array_key_exists('userId', $bindValues)
                && !array_key_exists('id', $bindValues)
            ) {
                $refreshQueriesRan++;
            }

            return new QueryResult([], 1, 100);
        };

        $data = [
            'users' => self::$faker->randomNumber(3),
            // Not a real ACL action id, so neither secured nor "can use secure token" — keeps
            // the injected data on the plain (non-vault) path so only the refresh queries below
            // are the ones tied to userId.
            'actions' => 999999,
            'pass' => self::$faker->sha1(),
            'refreshtoken' => 1,
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'authToken/saveEdit/100'], $data)
        );

        IntegrationTestCase::runApp($container);

        self::assertSame(2, $refreshQueriesRan, 'refreshing must regenerate both the token and the vault');
        $this->expectOutputString('{"status":"OK","description":"Authorization updated","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSearch')]
    public function search()
    {
        $authTokenGenerator = AuthTokenGenerator::factory();

        $this->addDatabaseMapperResolver(
            AuthTokenList::class,
            QueryResult::withTotalNumRows(
                [
                    $authTokenGenerator->buildAuthToken(),
                    $authTokenGenerator->buildAuthToken()
                ],
                2
            )
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerView')]
    public function view()
    {
        $this->addDatabaseMapperResolver(
            AuthToken::class,
            new QueryResult([AuthTokenGenerator::factory()->buildAuthToken()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'authToken/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerCreate(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmTokens"]//select|//input'
        )->extract(['_name']);

        self::assertCount(5, $filter);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerEdit(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmTokens"]//select|//input'
        )->extract(['_name']);

        self::assertCount(5, $filter);
        self::assertEquals('OK', $json->status);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblTokens"]//tr[string-length(@data-item-id) > 0]'
        )
                          ->extract(['data-item-id']);

        self::assertCount(2, $filter);
        self::assertEquals('OK', $json->status);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerView(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmTokens"]//select|//input'
        )->extract(['_name']);

        self::assertCount(3, $filter);
        self::assertEquals('OK', $json->status);
    }
}
