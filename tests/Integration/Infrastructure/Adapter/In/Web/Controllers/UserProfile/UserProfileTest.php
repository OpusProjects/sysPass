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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserProfile;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class UserProfileTest
 */
#[Group('integration')]
class UserProfileTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/create'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The stored profile is a serialized blob; editing has to deserialize it to render the
     * permission checkboxes.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerCheckedPermission')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(UserProfile::class, new QueryResult([$this->buildUserProfile()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerCheckedPermission')]
    public function view()
    {
        $this->addDatabaseMapperResolver(UserProfile::class, new QueryResult([$this->buildUserProfile()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Editing a profile id that no longer exists must not render a blank form:
     * UserProfileService::getById() raises NoSuchItemException when the lookup returns no
     * rows, and EditController's generic catch(Exception) has to turn that into an error
     * response instead of letting it bubble up as a fatal.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editNotFound()
    {
        // No addDatabaseMapperResolver() call: the harness default answers with an empty
        // result set, which is exactly the "no such profile" case.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/edit/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Profile not found","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profile deleted","data":null}');
    }

    /**
     * A profile id that is already gone must be reported, not swallowed as success:
     * UserProfileService::delete() raises NoSuchItemException when the DELETE affects no
     * rows, which DeleteController's generic catch(Exception) turns into an error response.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteNotFound()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 0, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Profile not found","data":null}');
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
            return new QueryResult([], 2, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'userProfile/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profiles deleted","data":null}');
    }

    /**
     * If fewer rows are removed than ids were requested, deleteByIdBatch() raises a
     * ServiceException — a batch delete either fully succeeds or is reported as failed, it
     * never silently drops part of the selection.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialFailure()
    {
        // Two ids requested, only one actually removed.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'userProfile/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while removing the profiles","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteWithoutSelection()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/delete', 'items' => []])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"No items selected","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreate()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveCreate'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profile added","data":null}');
    }

    /**
     * A profile without a name is rejected by the form rather than reaching the repository,
     * mirroring UserGroupForm's equivalent guard for groups.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveCreate'],
                ['profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A profile name is needed","data":null}');
    }

    /**
     * A profile name that already exists must not silently overwrite anything: the
     * repository's pre-insert uniqueness check raises a DuplicatedItemException that
     * SaveCreateController's generic catch(Exception) surfaces as an error instead of
     * inserting a second row.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateDuplicateName()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            // The uniqueness check: answering it with a row simulates an existing profile
            // with the same name.
            if (str_contains($statement, 'UPPER(name)')) {
                return new QueryResult([new Simple(['id' => 999])]);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveCreate'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated profile name","data":null}');
    }

    /**
     * A profile is the set of permissions a user gets, so what a delegate can grant to a
     * new profile must never exceed what they hold themselves. UserProfileForm intersects
     * every requested bit with the acting (non-admin) user's own profile before the
     * repository ever sees it: asking for accView while lacking it must persist as denied.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateConstrainsPermissionsToActorProfile()
    {
        $capturedProfile = null;

        $this->databaseQueryResolver = function (QueryData $queryData) use (&$capturedProfile): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'INSERT')) {
                $capturedProfile = $queryData->getQuery()->getBindValues()['profile'] ?? null;
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveCreate'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            ),
            [Context::class => $this->contextWithActorProfile(new ProfileData(['accView' => false]))]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profile added","data":null}');

        self::assertNotNull($capturedProfile, 'The insert must have run and bound a profile blob.');
        $decoded = json_decode($capturedProfile, true);
        self::assertFalse(
            $decoded['accView'],
            'A non-admin actor without accView must not be able to grant it to a new profile.'
        );
    }

    /**
     * The counterpart of saveCreateConstrainsPermissionsToActorProfile(): an admin-app actor
     * is exempt from the constraint, so a requested permission is persisted as-is even when
     * the actor's own stored profile does not carry that bit.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateAdminActorIsNotConstrained()
    {
        $capturedProfile = null;

        $this->databaseQueryResolver = function (QueryData $queryData) use (&$capturedProfile): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'INSERT')) {
                $capturedProfile = $queryData->getQuery()->getBindValues()['profile'] ?? null;
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveCreate'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            ),
            [Context::class => $this->contextWithActorProfile(new ProfileData(['accView' => false]), isAdminApp: true)]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profile added","data":null}');

        self::assertNotNull($capturedProfile, 'The insert must have run and bound a profile blob.');
        $decoded = json_decode($capturedProfile, true);
        self::assertTrue(
            $decoded['accView'],
            'An admin-app actor must be able to grant a permission it does not itself hold.'
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveEdit/100'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Profile updated","data":null}');
    }

    /**
     * A profile name colliding with another profile's must not silently rename onto it:
     * the repository's pre-update uniqueness check raises a DuplicatedItemException, which
     * SaveEditController's generic catch(Exception) surfaces as an error.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditDuplicateName()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'UPPER(name)')) {
                return new QueryResult([new Simple(['id' => 999])]);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveEdit/100'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated profile name","data":null}');
    }

    /**
     * If the update touches no rows (e.g. the profile was removed by someone else in the
     * meantime), UserProfileService::update() raises a ServiceException, and
     * SaveEditController's generic catch(Exception) has to report that rather than the
     * "Profile updated" success message.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditReturnsErrorWhenNoRowsAffected()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'UPDATE')) {
                return new QueryResult([], 0, 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userProfile/saveEdit/100'],
                ['profile_name' => self::$faker->colorName(), 'profile_accview' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while updating the profile","data":null}');
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
        $this->addDatabaseMapperResolver(
            UserProfile::class,
            QueryResult::withTotalNumRows([$this->buildUserProfile(), $this->buildUserProfile()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userProfile/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A session context stub identical to the harness default (IntegrationTestCase::getContext())
     * except for the acting user's own profile permissions and admin flag, so a test can
     * control what UserProfileForm's "a delegate cannot grant more than it holds" check
     * compares the request against.
     *
     * @throws Exception
     */
    private function contextWithActorProfile(ProfileData $actorProfile, bool $isAdminApp = false): SessionContext
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn(true);
        // A real session holds a request token; without one the guard has nothing to check
        // against, which is the state it now refuses rather than waves through.
        $context->method('getCSRF')->willReturn(self::CSRF_TOKEN);
        $context->method('getAuthCompleted')->willReturn(true);
        $context->method('getUserData')->willReturn($this->getUserDataDto()->mutate(['isAdminApp' => $isAdminApp]));
        $context->method('getUserProfile')->willReturn($actorProfile);

        return $context;
    }

    /**
     * A profile whose permission blob grants account viewing, so the rendered form has
     * something specific to assert against.
     */
    private function buildUserProfile(): UserProfile
    {
        $profileData = new ProfileData(['accView' => true]);

        return (new UserProfile(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::$faker->colorName(),
            ]
        ))->dehydrate($profileData);
    }

    /**
     * The profile form is a grid of permission checkboxes plus the profile name.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $fields = $crawler->filterXPath('//form[@name="frmProfile"]//input')->extract(['name']);

        self::assertContains('profile_name', $fields);
        self::assertContains('profile_accview', $fields);
    }

    /**
     * The serialized permission blob has to survive deserialization and reach the form: the
     * accView grant it carries must come back as a checked box.
     */
    private function outputCheckerCheckedPermission(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);

        self::assertCount(1, $crawler->filterXPath('//input[@name="profile_accview"][@checked]'));
    }

    /**
     * One row per profile returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblProfiles"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }
}
