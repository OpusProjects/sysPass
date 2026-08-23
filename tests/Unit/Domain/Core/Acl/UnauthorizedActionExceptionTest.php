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
use TypeError;
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

    /**
     * The inherited static factories cannot be used on this class, and that is worth knowing here
     * rather than from a fatal.
     *
     * `SPException` offers `error()`, `info()`, `critical()`, `warning()` and `from()`, each of
     * which does `new static($message, …)`. This class fixes its own message and takes an
     * `int $type` first instead, so `UnauthorizedActionException::error('...')` hands a string to a
     * parameter declared `int` and dies. The idiom is used everywhere else in the codebase —
     * `ServiceException::error(…)`, `ValidationException::error(…)` — which is exactly why reaching
     * for it here is a natural mistake.
     *
     * Pinned rather than fixed: the four fixed-message exceptions are constructed at some twenty
     * sites that all pass a type first, so changing the signature is wide, behaviour-neutral churn
     * to close a trap nobody has yet stepped in. `new` with a type is the way to raise these.
     */
    #[Test]
    public function theInheritedStaticFactoriesDoNotWorkOnAFixedMessageException(): void
    {
        $this->expectException(TypeError::class);
        // Named, so this cannot pass on a TypeError raised for some unrelated reason: the failure
        // has to be the message arriving where the type belongs.
        $this->expectExceptionMessage('Argument #1 ($type) must be of type int, string given');

        UnauthorizedActionException::error('a message this class has no room for');
    }

    /**
     * And the way that does work, so the pin above says what to do instead rather than only what
     * not to do.
     */
    #[Test]
    public function raisingItMeansConstructingItWithATypeInstead(): void
    {
        $exception = new UnauthorizedActionException(SPException::INFO);

        self::assertSame(SPException::INFO, $exception->getType());
        self::assertSame('You don\'t have permission to do this operation', $exception->getMessage());
    }
}
