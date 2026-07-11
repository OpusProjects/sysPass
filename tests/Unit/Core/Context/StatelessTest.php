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

namespace SP\Tests\Unit\Core\Context;

use PHPUnit\Framework\Attributes\Group;
use SP\Core\Context\ContextException;
use SP\Core\Context\Stateless;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class StatelessTest
 */
#[Group('unitary')]
class StatelessTest extends UnitaryTestCase
{
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
