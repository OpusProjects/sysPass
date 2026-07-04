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

namespace SP\Tests\Infrastructure\Adapter\In\Api;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SP\Infrastructure\Adapter\In\Api\Bootstrap;

/**
 * Unit tests for Bootstrap::mapExceptionToHttpCode().
 *
 * The method is private-static; accessed via Reflection so the test does not
 * depend on the full DI container.
 */
#[Group('unitary')]
class BootstrapTest extends TestCase
{
    private static function callMapExceptionToHttpCode(\Throwable $e): int
    {
        $method = (new ReflectionClass(Bootstrap::class))
            ->getMethod('mapExceptionToHttpCode');

        return $method->invoke(null, $e);
    }

    public static function provideIntCodeExceptions(): array
    {
        return [
            'int code 404 → 404'                => [new RuntimeException('Not found',   404), 404],
            'int code 500 → 500'                 => [new RuntimeException('Server err',  500), 500],
            'int code 999 → 500 (out of range)'  => [new RuntimeException('Custom code', 999), 500],
            'int code 0 → 500 (zero)'            => [new RuntimeException('No code',       0), 500],
            'int code 399 → 500 (below range)'   => [new RuntimeException('Below range', 399), 500],
            'int code 600 → 500 (above range)'   => [new RuntimeException('Above range', 600), 500],
        ];
    }

    #[DataProvider('provideIntCodeExceptions')]
    public function testMapExceptionToHttpCodeWithIntCodes(\Throwable $exception, int $expected): void
    {
        $this->assertSame($expected, self::callMapExceptionToHttpCode($exception));
    }

    /**
     * Set a SQLSTATE string code on a PDOException via Reflection (the $code
     * property is protected in PHP 8.5 so direct assignment is forbidden).
     */
    private static function makePdoException(string $sqlstate): PDOException
    {
        $e = new PDOException('SQLSTATE[' . $sqlstate . ']');
        $prop = new \ReflectionProperty($e, 'code');
        $prop->setValue($e, $sqlstate);

        return $e;
    }

    /**
     * PDOException::getCode() returns a SQLSTATE string (e.g. "42S02").
     * Verifies that a string SQLSTATE code does not cause a TypeError and
     * is mapped to 500 (not a valid HTTP code).
     */
    public function testMapExceptionToHttpCodeWithPdoStringSqlState(): void
    {
        $this->assertSame(500, self::callMapExceptionToHttpCode(self::makePdoException('42S02')));
    }

    public function testMapExceptionToHttpCodeWithPdoDeadlockState(): void
    {
        // '40001' looks numeric but is not a valid HTTP status range → 500.
        $this->assertSame(500, self::callMapExceptionToHttpCode(self::makePdoException('40001')));
    }
}
