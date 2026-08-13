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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\Generators\PublicLinkDataGenerator;
use SP\Tests\Support\InjectCrypt;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;

/**
 * A public link is the one way an account is reachable without signing in, so create, refresh and
 * delete are the operations that decide who holds a working anonymous URL. PublicLinkTest and
 * PublicLinkSaveEditTest cover the happy paths for those endpoints; this class covers what happens
 * when each one is asked to do something it must refuse — a second link for an already-linked
 * account, refreshing or editing a link id that no longer exists, and a batch delete that only
 * partially succeeds.
 */
#[Group('integration')]
// Creating or re-keying a link re-encrypts the account's data with the master password, which is
// held in the session vault (needed by the create/refresh tests; harmless for the others).
#[InjectVault]
class PublicLinkGuardsTest extends IntegrationTestCase
{
    /**
     * The repository refuses a second link for an account that already has one
     * (PublicLink::create()'s checkDuplicatedOnAdd()). Without this guard, saveCreate would hand
     * out a second, independently-refreshable URL to the same account, defeating "refresh
     * invalidates the old link" as an access-revocation mechanism.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[InjectCrypt]
    public function saveCreateRefusesALinkForAnAccountThatAlreadyHasOne(): void
    {
        $account = new Simple([
            'id' => 100,
            'name' => self::$faker->colorName(),
            'clientName' => self::$faker->colorName(),
            'pass' => self::$faker->sha1(),
            'key' => self::$faker->sha1(),
        ]);

        // The account lookup (for sealing the link's data) and the "does this account already
        // have a link" check both go through as plain rows, so they are told apart by statement:
        // answering the duplicate check with one row is what makes the endpoint refuse.
        $this->databaseQueryResolver = function (QueryData $queryData) use ($account): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'account_data_v')) {
                return new QueryResult([$account]);
            }

            if (str_starts_with($statement, 'SELECT') && str_contains($statement, 'PublicLink')) {
                return new QueryResult([new Simple(['id' => 1])]);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'publicLink/saveCreate'],
                ['accountId' => 100, 'notify' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Link already created","data":null}');
    }

    /**
     * The form validator requires an account before it will even attempt to create a link —
     * covered for saveEdit already, this is the create endpoint's own copy of that guard.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutAnAccountIsRefused(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'publicLink/saveCreate'],
                ['notify' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"An account is needed","data":null}');
    }

    /**
     * Refreshing an id that no longer exists (already deleted, or never existed) must fail rather
     * than silently doing nothing that looks like success — a caller polling for "did my refresh
     * take" needs to know the link it thought it owned is gone.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function refreshOfAMissingLinkIsRefused(): void
    {
        // No PublicLink mapper resolver is registered, so the harness's default response
        // (0 rows) is exactly what a deleted/unknown id looks like.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/refresh/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Link not found","data":null}');
    }

    /**
     * The whole point of "refresh" is that it invalidates the previous URL: the row is re-keyed
     * with a freshly generated hash rather than reusing the one it already had. This proves the
     * hash bound into the UPDATE is both present and different from the one the link came in
     * with, not just that the endpoint returned "OK".
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[InjectCrypt]
    public function refreshGeneratesANewHashInvalidatingTheOldUrl(): void
    {
        $oldHash = self::$faker->sha1();
        $link = PublicLinkDataGenerator::factory()->buildPublicLink()->mutate(['hash' => $oldHash, 'itemId' => 100]);

        $account = new Simple([
            'id' => 100,
            'name' => self::$faker->colorName(),
            'clientName' => self::$faker->colorName(),
            'pass' => self::$faker->sha1(),
            'key' => self::$faker->sha1(),
        ]);

        $newHash = null;

        $this->databaseQueryResolver = function (QueryData $queryData) use ($account, $link, &$newHash): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'account_data_v')) {
                return new QueryResult([$account]);
            }

            // The link being re-keyed, loaded by refresh() before it rewrites it.
            if (str_starts_with($statement, 'SELECT') && str_contains($statement, 'PublicLink')) {
                return new QueryResult([$link]);
            }

            // The re-key itself: capture what hash it actually bound into the UPDATE.
            if (str_starts_with($statement, 'UPDATE') && str_contains($statement, 'PublicLink')) {
                $newHash = $queryData->getQuery()->getBindValues()['hash'] ?? null;
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/refresh/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Link updated","data":null}');

        self::assertNotNull($newHash, 'refresh() must bind a new hash into the update');
        self::assertNotSame($oldHash, $newHash);
        // Password::generateRandomBytes(30) hex-encoded: 60 hex characters.
        self::assertMatchesRegularExpression('/^[0-9a-f]{60}$/', $newHash);
    }

    /**
     * A batch delete that only removes some of the requested rows must be reported as an error,
     * not folded into "deleted" — a link that silently survives the batch keeps handing its
     * account out to whoever holds the URL.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteBatchPartialFailureIsReported(): void
    {
        // Two ids requested, only one row actually affected.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'publicLink/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while removing the links","data":null}');
    }

    /**
     * Opening the edit form for a link id that no longer exists must fail closed with an error
     * instead of falling back to a blank/default form that still carries the requested id — that
     * form's submit posts to saveEdit/{id}, so silently rendering it would let a stale id be
     * reused to attach a link to a different account.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editOfAMissingLinkIsRefused(): void
    {
        // No PublicLink mapper resolver is registered, so getById() comes back empty, the same
        // as a deleted or never-existing id.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/edit/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Link not found","data":null}');
    }
}
