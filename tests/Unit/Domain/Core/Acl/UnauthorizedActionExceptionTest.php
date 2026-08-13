<?php

declare(strict_types=1);
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

namespace SP\Tests\Unit\Domain\Core\Acl;

use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\UnauthorizedActionException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Every ACL-guarded web controller and helper (DeleteController, EditController, AccountHelper,
 * the Track controllers, ...) throws this to stop an action a user isn't allowed to run, and its
 * message/hint are what actually reach the user and the log — a generic, translated denial plus a
 * "contact the administrator" hint, deliberately with no operation-specific detail attached. None
 * of those call sites are exercised by the mocked unit suite (ACL is stubbed to always allow), so
 * nothing else in the suite builds one of these; this pins the exception itself directly.
 */
#[Group('unitary')]
class UnauthorizedActionExceptionTest extends UnitaryTestCase
{
    /**
     * The no-argument shape is how most call sites throw it (`throw new
     * UnauthorizedActionException()`), so the defaults have to resolve to the standard,
     * user-facing denial rather than an empty or null message.
     */
    #[Test]
    public function defaultConstructionCarriesTheStandardDenialMessageAndHint()
    {
        $exception = new UnauthorizedActionException();

        self::assertSame('You don\'t have permission to do this operation', $exception->getMessage());
        self::assertSame('Please contact to the administrator', $exception->getHint());
        self::assertSame(SPException::ERROR, $exception->getType());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    /**
     * Some call sites pass a specific $type (e.g. SPException::ERROR explicitly) and a $previous
     * exception describing the underlying ACL failure; those have to reach SPException unchanged
     * so upstream logging/handling can act on them, even though the message shown to the user
     * never varies with them.
     */
    #[Test]
    public function customTypeCodeAndPreviousAreForwardedToTheParentException()
    {
        $previous = new Exception('acl lookup failed');

        $exception = new UnauthorizedActionException(SPException::WARNING, 42, $previous);

        self::assertSame(SPException::WARNING, $exception->getType());
        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame('You don\'t have permission to do this operation', $exception->getMessage());
    }
}
