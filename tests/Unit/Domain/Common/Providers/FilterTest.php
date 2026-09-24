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

namespace SP\Tests\Unit\Domain\Common\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Domain\Common\Providers\Filter;

/**
 * `Filter::getArray()` is where both doors meet for an array parameter: the API's
 * `getParamArray()` and the web's `Request::analyzeArray()` both hand their value to it.
 *
 * It chose a filter per element by `is_numeric()` alone and passed the element straight on, and
 * under strict types `getInt()` accepts only `int|string` and `getString()` only `?string`. So a
 * bool, a float, a nested array or an object threw a `TypeError` that nothing caught, and the
 * request ended as a 500 naming the class, the method and the server's absolute path. From the web
 * that needs no more than a form field named `x[a][]`, which makes one element an array; the web's
 * `analyzeArray()` reads through `InputBag::all()`, which skips Symfony's scalar check.
 */
#[Group('unitary')]
class FilterTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function unrepresentableElementProvider(): array
    {
        return [
            'a bool' => [true],
            'a float' => [1.5],
            'a nested array' => [[1, 2]],
            'an object' => [new \stdClass()],
        ];
    }

    /**
     * An element the filters cannot represent is answered as null, like a missing one, rather than
     * throwing.
     */
    #[Test]
    #[DataProvider('unrepresentableElementProvider')]
    public function anElementItCannotRepresentIsNull(mixed $element): void
    {
        self::assertSame([null], Filter::getArray([$element]));
    }

    /**
     * The control: the elements it was written for still come through, as the type each filter
     * gives them.
     */
    #[Test]
    public function idsAndTextStillComeThrough(): void
    {
        self::assertSame([5, 7, 'a tag', null], Filter::getArray([5, '7', 'a tag', null]));
    }
}
