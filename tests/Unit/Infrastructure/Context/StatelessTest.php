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
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\ContextException;
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
}
