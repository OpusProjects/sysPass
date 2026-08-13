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

namespace SP\Tests\Unit\Infrastructure\Context;

use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Context\ContextBase;
use SP\Infrastructure\Context\Stateless;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The request-scoped context, and through it the behaviour every context shares.
 *
 * The transient collection is where the master password is held for the length of a request. It is
 * kept under a protected key precisely so that nothing later in the request can quietly swap it —
 * an account encrypted with a substituted master password could not be decrypted afterwards.
 */
#[Group('unitary')]
class StatelessTest extends UnitaryTestCase
{
    /**
     * A protected key — one whose name starts with an underscore, as the master password's does —
     * cannot be changed once it holds a value.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testAProtectedKeyCannotBeChanged(): void
    {
        $context = new Stateless();
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, 'the-master-password');

        $this->expectException(ContextException::class);

        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, 'another-password');
    }

    /**
     * Setting it to the value it already holds is not a change, so the request that re-derives the
     * same master password is not refused.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testAProtectedKeyMayBeSetToTheValueItAlreadyHolds(): void
    {
        $context = new Stateless();
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, 'the-master-password');

        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, 'the-master-password');

        $this->assertSame('the-master-password', $context->getTrasientKey(Context::MASTER_PASSWORD_KEY));
    }

    /**
     * An ordinary key is not protected and can be changed as often as the request needs.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testAnOrdinaryKeyCanBeChanged(): void
    {
        $context = new Stateless();

        $context->setTrasientKey('actionName', 'first');
        $context->setTrasientKey('actionName', 'second');

        $this->assertSame('second', $context->getTrasientKey('actionName'));
    }

    /**
     * A key that was never set reads as its default, and a numeric default types the answer — the
     * callers use these as counters and timestamps.
     */
    public function testAKeyThatWasNeverSetReadsAsItsDefault(): void
    {
        $context = new Stateless();

        $this->assertNull($context->getTrasientKey('nothing'));
        $this->assertSame(0, $context->getTrasientKey('nothing', 0));
    }

    /**
     * Whether the context has been bound at all. The request-scoped one is usable from the start,
     * since there is no session for it to wait on.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheRequestScopedContextIsUsableFromTheStart(): void
    {
        $context = new Stateless();
        $context->initialize();

        $this->assertTrue($context->isInitialized());
    }

    /**
     * setPluginKey() must persist the value in the underlying context so a
     * subsequent getPluginKey() call in the same request can read it back.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testSetPluginKeyThenGetPluginKeyReturnsValue(): void
    {
        $context = new Stateless();
        $context->initialize();

        $value = self::$faker->sha1();

        $out = $context->setPluginKey('testPlugin', 'testKey', $value);

        $this->assertSame($value, $out);
        $this->assertSame($value, $context->getPluginKey('testPlugin', 'testKey'));
    }

    /**
     * Different plugin/key pairs must not clobber each other in the shared
     * 'plugins' context bucket.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testSetPluginKeyKeepsKeysForDifferentPluginsIsolated(): void
    {
        $context = new Stateless();
        $context->initialize();

        $context->setPluginKey('pluginOne', 'sharedKey', 'valueOne');
        $context->setPluginKey('pluginTwo', 'sharedKey', 'valueTwo');

        $this->assertSame('valueOne', $context->getPluginKey('pluginOne', 'sharedKey'));
        $this->assertSame('valueTwo', $context->getPluginKey('pluginTwo', 'sharedKey'));
    }

    /**
     * @throws ContextException
     * @throws SPException
     */
    public function testGetPluginKeyReturnsNullWhenNeverSet(): void
    {
        $context = new Stateless();
        $context->initialize();

        $this->assertNull($context->getPluginKey('unknownPlugin', 'unknownKey'));
    }

    /**
     * The signed-in user gates every access check made for the rest of the request. A freshly
     * built context (e.g. one used from a CLI or API request that never authenticates) must read
     * as nobody being signed in, and setting the user data is what flips that.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheSignedInUserIsCarried(): void
    {
        $context = new Stateless();

        $this->assertFalse($context->isLoggedIn(), 'nobody is signed in on a fresh context');

        $context->setUserData(new UserDto(id: 7, login: 'someone'));

        $this->assertTrue($context->isLoggedIn());
        $this->assertSame(7, $context->getUserData()->id);
    }

    /**
     * The profile decides what the signed-in user may do, and is read on every request that
     * checks a permission.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheUserProfileIsCarried(): void
    {
        $context = new Stateless();

        $this->assertNull($context->getUserProfile());

        $context->setUserProfile(new ProfileData(['accAdd' => true]));

        $this->assertTrue($context->getUserProfile()->isAccAdd());
    }

    /**
     * The response language is read from the context once per request; a locale that was never
     * set must read back as unset rather than an empty string, so the caller falls back to the
     * configured default instead of rendering with a blank language.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheLocaleIsCarried(): void
    {
        $context = new Stateless();

        $this->assertNull($context->getLocale());

        $context->setLocale('en_US');

        $this->assertSame('en_US', $context->getLocale());
    }

    /**
     * The application status is a one-shot flag (e.g. "just reloaded the config"): reading it back
     * has to be paired with clearing it, otherwise the next unrelated request would be treated as
     * the same event.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheApplicationStatusIsClearedOnceRead(): void
    {
        $context = new Stateless();

        $context->setAppStatus(Stateless::APP_STATUS_RELOADED);

        $this->assertSame(Stateless::APP_STATUS_RELOADED, $context->getAppStatus());

        $context->resetAppStatus();

        $this->assertNull($context->getAppStatus());
    }

    /**
     * The accounts cache is what the search listing reuses across the request instead of hitting
     * the repository again for the same page.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheAccountsCacheIsCarried(): void
    {
        $context = new Stateless();

        $this->assertNull($context->getAccountsCache());

        $context->setAccountsCache([1, 2, 3]);

        $this->assertSame([1, 2, 3], $context->getAccountsCache());
    }

    /**
     * A temporary master password (set while the real one is being changed) is stored under a
     * protected key, exactly like the real master password — nothing later in the same request may
     * quietly swap it for a different value, or a partially-migrated account could be encrypted
     * with the wrong key.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testSetTemporaryMasterPassStoresItUnderAProtectedKey(): void
    {
        $context = new Stateless();
        $context->setTemporaryMasterPass('temp-pass');

        $this->assertSame('temp-pass', $context->getTrasientKey('_tempmasterpass'));

        $this->expectException(ContextException::class);

        $context->setTemporaryMasterPass('different-pass');
    }

    /**
     * The configuration load time is what a caller compares against the config file's own
     * timestamp to decide whether the in-memory config is stale.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testTheConfigTimeIsCarried(): void
    {
        $context = new Stateless();

        $context->setConfigTime(1700000000);

        $this->assertSame(1700000000, $context->getConfigTime());
    }

    /**
     * setContextKey()/getContextKey() catch the ContextException the parent class raises when the
     * context reference is gone, and degrade to a no-op instead of propagating it — a write is
     * silently dropped and a read comes back as its default, rather than crashing the request over
     * what is logged as an internal-state problem.
     *
     * The context is always bound by the constructor, so the only way to reach this path is to
     * force the underlying reference back to null, the way a corrupted internal state would.
     *
     * @throws ContextException
     * @throws SPException
     */
    public function testAWriteAfterTheContextIsLostIsDroppedRatherThanThrown(): void
    {
        $context = new Stateless();
        $context->setLocale('en_US');

        $this->assertSame('en_US', $context->getLocale());

        $property = new ReflectionProperty(ContextBase::class, 'context');
        $property->setValue($context, null);

        $context->setLocale('fr_FR');

        $this->assertNull(
            $context->getLocale(),
            'the write is silently dropped once the context reference is gone'
        );
    }
}
