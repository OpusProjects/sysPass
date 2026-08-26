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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserSettingsGeneral;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the "my preferences" save endpoint that every ordinary, non-privileged user reaches
 * from their own account menu. Two things matter more here than for most save endpoints:
 *
 * the row it writes has to be the signed-in user's own — there is no separate admin-only
 * variant of this controller, so anything that let the target id be influenced by the request
 * would let a user rewrite somebody else's settings; and the form is an all-or-nothing submit
 * (every field is always rendered and always posted), so a value that looks unrelated to what
 * changed can still be silently overwritten by whatever the request happened to carry.
 */
#[Group('integration')]
class UserSettingsGeneralTest extends IntegrationTestCase
{
    /** The id of the user the session belongs to for every test in this class. */
    private const SESSION_USER_ID = 4242;

    /**
     * Whatever the controller last handed to the session's setUserData(), captured so a test
     * can check both that it happened and what it was refreshed with. Null means the session
     * was never touched during the request.
     */
    private ?UserDto $refreshedSessionUserData = null;

    /**
     * The bind values of the UPDATE issued against the preferences column, captured by
     * {@see captureThePreferencesUpdate()}. Null means that statement was never issued.
     */
    private ?array $capturedUpdateBindValues = null;

    /**
     * Pins the session's own user id to a fixed, known value, independent of whatever id faker
     * would otherwise have generated, so every assertion below can compare against one constant
     * instead of re-deriving "the logged-in user's id" per test.
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['id' => self::SESSION_USER_ID]);
    }

    /**
     * Same stub the base harness builds, plus a callback on setUserData() so a test can see
     * what — if anything — the controller refreshed the session with.
     *
     * @throws Exception
     */
    protected function getContext(): SessionContext|Stub
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn(true);
        // A real session holds a request token; without one the guard has nothing to check
        // against, which is the state it now refuses rather than waves through.
        $context->method('getCSRF')->willReturn(self::CSRF_TOKEN);
        $context->method('getAuthCompleted')->willReturn(true);
        $context->method('getUserData')->willReturn($this->getUserDataDto());
        $context->method('getUserProfile')->willReturn($this->getUserProfile());
        $context->method('setUserData')
                 ->willReturnCallback(function (?UserDto $userDto): void {
                     $this->refreshedSessionUserData = $userDto;
                 });

        return $context;
    }

    /**
     * A full submit of the preferences form, exactly as the browser sends it: every field
     * present, every switch flipped on. What lands in the UPDATE has to be the request's
     * values, serialized as-is — not defaults, not whatever the user previously had stored.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function savingPreferencesWritesEveryFieldForTheSignedInUsersRow()
    {
        $this->captureThePreferencesUpdate();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                self::fullPreferencesFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Preferences updated","data":null}');

        self::assertNotNull(
            $this->capturedUpdateBindValues,
            'the save must issue an UPDATE against the preferences column'
        );

        $preferences = $this->decodePreferences();

        self::assertSame('de_DE', $preferences['lang']);
        self::assertSame('dark-theme', $preferences['theme']);
        self::assertSame(48, $preferences['resultsPerPage']);
        self::assertTrue($preferences['accountLink']);
        self::assertTrue($preferences['sortViews']);
        self::assertTrue($preferences['topNavbar']);
        self::assertTrue($preferences['optionalActions']);
        self::assertTrue($preferences['resultsAsCards']);
        self::assertTrue($preferences['checkNotifications']);
        self::assertTrue($preferences['showAccountSearchFilters']);
    }

    /**
     * The preferences form always posts every field, so a switch that is left unchecked is
     * indistinguishable, on the wire, from one that was never offered: both simply do not
     * appear in the POST body. The controller has to fall back to its own defaults for those,
     * not merge them in from whatever the user previously had — otherwise a save that only
     * meant to change the language would also silently reset every switch it left untouched
     * on the page to whatever the user's older preferences happened to hold.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function omittedTogglesFallBackToTheFormsDefaultsInsteadOfThePriorStoredValue()
    {
        $this->captureThePreferencesUpdate();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                ['userlang' => 'en_US']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Preferences updated","data":null}');

        $preferences = $this->decodePreferences();

        self::assertSame('en_US', $preferences['lang']);
        self::assertSame('material-blue', $preferences['theme']);
        self::assertSame(12, $preferences['resultsPerPage']);
        self::assertFalse($preferences['accountLink']);
        self::assertFalse($preferences['sortViews']);
        self::assertFalse($preferences['topNavbar']);
        self::assertFalse($preferences['optionalActions']);
        self::assertFalse($preferences['resultsAsCards']);
        // In-App notifications default to enabled for a brand new user (UserPreferences'
        // own default is true), yet an unchecked box on this all-or-nothing form still
        // turns it off — the same as every other switch, with no special case for it.
        self::assertFalse($preferences['checkNotifications']);
        self::assertFalse($preferences['showAccountSearchFilters']);
    }

    /**
     * Nothing in this request can name whose row gets written — there is no id in the route
     * and the controller never reads one from the body — but the point is worth pinning
     * explicitly: the row updated is always the session's own user, even when the POST body
     * carries fields that look like they might be read as one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theRowWrittenIsAlwaysTheSignedInUsersOwnIdNeverAnythingFromTheRequest()
    {
        $this->captureThePreferencesUpdate();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                self::fullPreferencesFields() + ['id' => 999, 'user_id' => 999, 'userId' => 999]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Preferences updated","data":null}');

        self::assertSame(self::SESSION_USER_ID, $this->capturedUpdateBindValues['id']);

        $preferences = $this->decodePreferences();

        self::assertSame(self::SESSION_USER_ID, $preferences['user_id']);
    }

    /**
     * A saved preference is only useful if the rest of the request sees it straight away —
     * the theme and language the page renders with come from the session's cached copy, not
     * a fresh database read. If the session were left holding the old preferences, the user
     * would save, get a success message, and still see their previous theme and language
     * until their next login.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theSessionsCachedPreferencesAreRefreshedAfterASuccessfulSave()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                self::fullPreferencesFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Preferences updated","data":null}');

        self::assertNotNull(
            $this->refreshedSessionUserData,
            'a successful save must refresh the session copy of the user'
        );
        self::assertSame(self::SESSION_USER_ID, $this->refreshedSessionUserData->id);
        self::assertNotNull($this->refreshedSessionUserData->preferences);
        self::assertSame('de_DE', $this->refreshedSessionUserData->preferences->getLang());
        self::assertSame('dark-theme', $this->refreshedSessionUserData->preferences->getTheme());
        self::assertTrue($this->refreshedSessionUserData->preferences->isCheckNotifications());
    }

    /**
     * The only refusal branch in this controller is the catch around the update itself — there
     * is no separate validation step, since every field has a safe default. When the update
     * throws (the row's gone, the connection dropped, whatever), the response has to report
     * the failure, and the session must be left exactly as it was: refreshing it with
     * preferences that were never actually persisted would make the user's page show settings
     * that do not match what is stored, and that would only be found out on the next login.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveIsReportedAsFailedAndTheSessionIsLeftUntouchedWhenTheUpdateThrows()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'UPDATE') && str_contains($statement, 'preferences')) {
                throw ConstraintException::error('Unable to update the user preferences');
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                self::fullPreferencesFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Unable to update the user preferences","data":null}'
        );

        self::assertNull(
            $this->refreshedSessionUserData,
            'the session must not be refreshed with preferences that were never actually written'
        );
    }

    /**
     * And the same when the update raises nothing at all but simply matches no row — the user's
     * own row deleted from another session, say. It saved nothing, so it is reported as a failure
     * and the session is left alone, rather than the page showing settings that exist nowhere.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveIsReportedAsFailedWhenTheUpdateMatchesNoRow()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'UPDATE') && str_contains($statement, 'preferences')) {
                return new QueryResult([], 0, 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                self::fullPreferencesFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Error while updating the preferences","data":null}'
        );

        self::assertNull(
            $this->refreshedSessionUserData,
            'the session must not be refreshed with preferences that were never actually written'
        );
    }

    /**
     * Registers a resolver that captures the bind values of the UPDATE issued against the
     * preferences column into {@see $capturedUpdateBindValues}. Every other query on the
     * request (the session/user lookups the framework issues on its own, event logging, and
     * so on) is answered with the harness' generic default, so it cannot be mistaken for the
     * one write this class cares about.
     *
     * The resolver closure is rebound to this test case by the harness (it is invoked via
     * Closure::call($this, ...)), so referencing $this here reaches the running test
     * regardless of it being a non-static closure defined in this method.
     */
    private function captureThePreferencesUpdate(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'UPDATE') && str_contains($statement, 'preferences')) {
                $this->capturedUpdateBindValues = $queryData->getQuery()->getBindValues();
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePreferences(): array
    {
        return json_decode($this->capturedUpdateBindValues['preferences'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A complete submit of the preferences form: every field the view renders, all switches on.
     *
     * @return array<string, string|int>
     */
    private static function fullPreferencesFields(): array
    {
        return [
            'userlang' => 'de_DE',
            'usertheme' => 'dark-theme',
            'resultsperpage' => 48,
            'account_link' => 'on',
            'sort_views' => 'on',
            'top_navbar' => 'on',
            'optional_actions' => 'on',
            'resultsascards' => 'on',
            'check_notifications' => 'on',
            'show_account_search_filters' => 'on',
        ];
    }
}
