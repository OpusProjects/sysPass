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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\DataGrid\Action;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridAction;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A row action is a button on a listing, and most of what is set on it is printed straight into the
 * page — the javascript call it makes, its classes, its data attributes.
 *
 * The one piece of logic is how the call is assembled, which is where a value that should have been
 * quoted reaches the browser as an identifier instead.
 */
#[Group('unitary')]
class DataGridActionTest extends UnitaryTestCase
{
    /**
     * A non-numeric argument is quoted so the browser reads it as a string; a number, and the
     * element itself, are left alone.
     */
    #[Test]
    public function theCallQuotesTheArgumentsThatNeedIt()
    {
        $action = (new DataGridAction())
            ->setOnClickFunction('account/delete')
            ->setOnClickArgs('this')
            ->setOnClickArgs('tblAccounts')
            ->setOnClickArgs('12');

        self::assertSame("account/delete(this,'tblAccounts',12)", $action->getOnClick());
    }

    /**
     * With no arguments the call is the bare function name, not a call with empty parentheses.
     */
    #[Test]
    public function aCallWithoutArgumentsIsJustTheFunction()
    {
        self::assertSame('account/delete', (new DataGridAction())->setOnClickFunction('account/delete')->getOnClick());
    }

    /**
     * Whether the action opens a helper rather than navigating is carried, and is unset until it is
     * said either way — the templates tell the two apart.
     */
    #[Test]
    public function whetherTheActionIsAHelperIsCarried()
    {
        self::assertNull((new DataGridAction())->isHelper());
        self::assertTrue((new DataGridAction())->setIsHelper(true)->isHelper());
        self::assertFalse((new DataGridAction())->setIsHelper(false)->isHelper());
    }

    /**
     * Attributes are printed onto the button as they are given, whether set as a block or added one
     * at a time.
     */
    #[Test]
    public function theAttributesAreCarried()
    {
        $action = (new DataGridAction())->setAttributes(['name' => 'delete', 'title' => 'Delete']);

        self::assertSame(['name' => 'delete', 'title' => 'Delete'], $action->getAttributes());

        $action->addAttribute('disabled', 'disabled');

        self::assertSame('disabled', $action->getAttributes()['disabled']);
    }

    /**
     * An action with nothing set on it prints no attributes, rather than the null it holds.
     */
    #[Test]
    public function anActionWithoutAttributesHasNone()
    {
        self::assertSame([], (new DataGridAction())->getAttributes());
    }

    /**
     * The classes are both listed and joined, since the templates use each in a different place.
     */
    #[Test]
    public function theClassesAreCarriedAndJoined()
    {
        $action = (new DataGridAction())->addClass('btn')->addClass('btn-delete');

        self::assertSame(['btn', 'btn-delete'], $action->getClasses());
        self::assertSame('btn btn-delete', $action->getClassesAsString());
    }

    /**
     * An action with no classes joins to nothing rather than printing 'null' into the class
     * attribute.
     */
    #[Test]
    public function anActionWithoutClassesJoinsToNothing()
    {
        self::assertSame('', (new DataGridAction())->getClassesAsString());
    }
}
