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

namespace SP\Tests\Unit\Infrastructure\Context;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Context\Session;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The session-backed context: everything a signed-in user carries between requests — who they are,
 * what they may do, their master password vault, the CSRF token.
 *
 * It is skipped by the integration harness, which stubs the context so the whole suite can share
 * one process. This runs in its own process against a real PHP session instead, which is the only
 * way the binding to $_SESSION is exercised at all.
 */
#[Group('unitary')]
#[RunClassInSeparateProcess]
class SessionTest extends UnitaryTestCase
{
    /**
     * A fresh session is stamped with when it started, which is what the session lifetime and the
     * id regeneration are both measured from.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function afreshSessionIsStampedWithItsStartTime()
    {
        $session = $this->givenAStartedSession();

        self::assertGreaterThan(0, $session->getSidStartTime());
        self::assertGreaterThan(0, $session->getStartActivity());
    }

    /**
     * Initializing binds the context to PHP's own session array, so what is set on it is what the
     * next request reads back.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function whatIsSetOnTheContextLandsInThePhpSession()
    {
        $session = $this->givenAStartedSession();

        $session->setTheme('material-blue');

        self::assertArrayHasKey('context', $_SESSION);
        self::assertSame('material-blue', $session->getTheme());
    }

    /**
     * A context can only be bound once. A second binding would silently detach everything already
     * held from the array the next request reads.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function aSessionCannotBeBoundTwice()
    {
        $session = $this->givenAStartedSession();

        $this->expectException(ContextException::class);

        $session->initialize();
    }

    /**
     * Read before the context is bound, an accessor answers with its default rather than raising.
     * These are called from template helpers that run whether or not a session was started.
     */
    #[Test]
    public function anUnboundSessionReadsAsEmpty()
    {
        $session = new Session();

        self::assertNull($session->getCSRF());
        self::assertNull($session->getLocale());
        self::assertFalse($session->isLoggedIn());
    }

    /**
     * And a write before binding is dropped rather than raising — it is logged as a locked session.
     */
    #[Test]
    public function aWriteToAnUnboundSessionIsDropped()
    {
        $session = new Session();

        $session->setTheme('material-blue');

        self::assertNull($session->getCSRF());
    }

    /**
     * The signed-in user, and what "signed in" is decided from.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theSignedInUserIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertFalse($session->isLoggedIn(), 'nobody is signed in on a fresh session');

        $session->setUserData(new UserDto(id: 7, login: 'someone'));

        self::assertTrue($session->isLoggedIn());
        self::assertSame(7, $session->getUserData()->id);
    }

    /**
     * The profile decides what the user may do, and is read on every page.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theProfileIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getUserProfile());

        $session->setUserProfile(new ProfileData(['accAdd' => true]));

        self::assertTrue($session->getUserProfile()->isAccAdd());
    }

    /**
     * The CSRF token lives here, which is what binds it to one session — a token from another one
     * has to be worthless.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theCsrfTokenIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getCSRF(), 'a fresh session has no token until one is made');

        $session->setCSRF('a-token');

        self::assertSame('a-token', $session->getCSRF());
    }

    /**
     * The account listing's filter, kept so the listing can be returned to.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theSearchFilterIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getSearchFilters());

        $session->setSearchFilters(AccountSearchFilterDto::build('a term'));

        self::assertSame('a term', $session->getSearchFilters()->getTxtSearch());
    }

    /**
     * Activity is what the session timeout is measured against, so both ends of it are held.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theActivityTimesAreCarried()
    {
        $session = $this->givenAStartedSession();

        $session->setLastActivity(1700000000);
        $session->setSessionTimeout(300);

        self::assertSame(1700000000, $session->getLastActivity());
        self::assertSame(300, $session->getSessionTimeout());
    }

    /**
     * A plugin's own values are namespaced by plugin, so two plugins using the same key do not read
     * each other's.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function pluginValuesAreKeptPerPlugin()
    {
        $session = $this->givenAStartedSession();

        $session->setPluginKey('authenticator', 'twofa', 'first');
        $session->setPluginKey('other', 'twofa', 'second');

        self::assertSame('first', $session->getPluginKey('authenticator', 'twofa'));
        self::assertSame('second', $session->getPluginKey('other', 'twofa'));
    }

    /**
     * The application status is a one-shot flag — reading it back clears it, so a reload is not
     * treated as the same event twice.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theApplicationStatusIsClearedOnceRead()
    {
        $session = $this->givenAStartedSession();

        $session->setAppStatus('reloaded');

        self::assertSame('reloaded', $session->getAppStatus());

        $session->resetAppStatus();

        self::assertNull($session->getAppStatus());
    }

    /**
     * Closing commits the session rather than leaving it open, which is what releases the lock the
     * next request would otherwise wait on.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function closingCommitsTheSession()
    {
        $this->givenAStartedSession();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());

        Session::close();

        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * @throws ContextException
     * @throws SPException
     */
    private function givenAStartedSession(): Session
    {
        $session = new Session();
        $session->initialize();

        return $session;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];

        parent::tearDown();
    }
}
