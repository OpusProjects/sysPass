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
use SP\Domain\Account\Dtos\AccountCacheDto;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Core\Crypt\VaultInterface;
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
     * A session already holding something that is not a context is refused rather than used. That
     * is what an upgraded install's leftover session looks like, and treating it as a context would
     * mean reading whatever it does hold as the signed-in user.
     */
    #[Test]
    public function aSessionHoldingSomethingElseIsRefused()
    {
        session_start();
        $_SESSION['context'] = 'not a context';

        $this->expectException(ContextException::class);

        (new Session())->initialize();
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
     * A second request landing on a session that already carries a start time does not get that
     * clock reset — otherwise a session's age (and with it, whether it is due for ID regeneration)
     * would never advance past "just started" as long as it kept being used.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function reinitializingAnExistingSessionKeepsItsOriginalStartTime()
    {
        $session = $this->givenAStartedSession();

        $sidStartTime = $session->getSidStartTime();
        $startActivity = $session->getStartActivity();

        // Commits and closes the session without destroying it, the way one request's end
        // leaves it for the next request on the same session to pick back up.
        session_write_close();

        $next = new Session();
        $next->initialize();

        self::assertSame($sidStartTime, $next->getSidStartTime());
        self::assertSame($startActivity, $next->getStartActivity());
    }

    /**
     * A write against a session that has already been committed still lands — the context is bound
     * in memory regardless of whether PHP considers the session "active" — but it is logged, since a
     * write this late is a sign something upstream (like output already having started) sent the
     * response before the application was done mutating the session.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function aWriteAfterTheSessionIsCommittedStillLandsButIsLogged()
    {
        $session = $this->givenAStartedSession();

        session_write_close();

        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());

        $session->setTheme('material-blue');

        self::assertSame('material-blue', $session->getTheme());
    }

    /**
     * The per-account ACL cache is reset by clearing its key outright, rather than by recomputing
     * it — the next read simply finds nothing there and rebuilds it.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function resettingTheAccountAclClearsIt()
    {
        $session = $this->givenAStartedSession();

        $_SESSION['context']->set('accountAcl', 'a-cached-acl');

        $session->resetAccountAcl();

        self::assertNull($_SESSION['context']->get('accountAcl'));
    }

    /**
     * Full authorization is a separate flag from being logged in at all — a two-step login (e.g.
     * pending a second factor) is logged in without yet being fully authorized, and this is the flag
     * that tells the two states apart.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function fullAuthorizationIsCarriedSeparatelyFromBeingLoggedIn()
    {
        $session = $this->givenAStartedSession();

        self::assertFalse($session->getAuthCompleted(), 'not authorized until explicitly completed');

        $session->setAuthCompleted(true);

        self::assertTrue($session->getAuthCompleted());
    }

    /**
     * The temporary master password used while a user is prompted to re-enter it, kept only for the
     * duration of the session that needs it.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theTemporaryMasterPasswordIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->takeTemporaryMasterPass());

        $session->setTemporaryMasterPass('a-temporary-password');

        self::assertSame('a-temporary-password', $session->takeTemporaryMasterPass());
    }

    /**
     * And carried exactly once.
     *
     * It lives in the session only so that the page rendered after the generating request can show
     * it — there is nowhere else to keep it for that one hop, and it is deliberately not persisted
     * in plaintext anywhere. It used to stay for the life of the administrator's session, and
     * ConfigManager's index reads it on every load, so a value meant to be shown once was rendered
     * into the HTML again on every later visit to the Configuration page: after it had expired,
     * after its recipients had used it, and after the master password had been rotated. Nothing
     * removed it, because there was nothing that could — no clear existed.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theTemporaryMasterPasswordIsCarriedOnlyOnce()
    {
        $session = $this->givenAStartedSession();

        $session->setTemporaryMasterPass('a-temporary-password');

        self::assertSame('a-temporary-password', $session->takeTemporaryMasterPass());
        self::assertNull($session->takeTemporaryMasterPass(), 'it must not survive being read');
    }

    /**
     * The public key handed to the browser for client-side encryption, bound to one session so a
     * key from another session is never reused.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function thePublicKeyIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getPublicKey());

        $session->setPublicKey('a-public-key');

        self::assertSame('a-public-key', $session->getPublicKey());
    }

    /**
     * The encrypted master key (vault), kept so it survives from one request to the next without
     * asking the user to re-enter the master password every time.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theVaultIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getVault());

        $vault = $this->createStub(VaultInterface::class);
        $session->setVault($vault);

        self::assertSame($vault, $session->getVault());
    }

    /**
     * The accounts cache, kept so the account list is not rebuilt from the database on every single
     * page of a search.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theAccountsCacheIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertNull($session->getAccountsCache());

        $accountsCache = [$this->createStub(AccountCacheDto::class)];
        $session->setAccountsCache($accountsCache);

        self::assertSame($accountsCache, $session->getAccountsCache());
    }

    /**
     * The configuration load time, used to detect a configuration file that changed on disk since
     * it was last read into this session.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theConfigLoadTimeIsCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertSame(0, $session->getConfigTime());

        $session->setConfigTime(1700000000);

        self::assertSame(1700000000, $session->getConfigTime());
    }

    /**
     * The account colours go in as an array and come back as one. Declared as a string, this
     * raised a TypeError on the way out the first time anybody read back what had been stored —
     * nothing in the application did, which is the only reason it went unnoticed.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theAccountColoursAreCarried()
    {
        $session = $this->givenAStartedSession();

        self::assertSame([], $session->getAccountColor(), 'a session with none set has none');

        $session->setAccountColor([100 => '#ff0000', 200 => '#00ff00']);

        self::assertSame([100 => '#ff0000', 200 => '#00ff00'], $session->getAccountColor());
    }

    /**
     * And the theme is a string even before one has been chosen — read from a session that has not
     * been given one, it used to return null from a method declared to return a string.
     *
     * @throws ContextException
     * @throws SPException
     */
    #[Test]
    public function theThemeIsCarriedAndIsAlwaysAString()
    {
        $session = $this->givenAStartedSession();

        self::assertSame('', $session->getTheme());

        $session->setTheme('material-blue');

        self::assertSame('material-blue', $session->getTheme());
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
