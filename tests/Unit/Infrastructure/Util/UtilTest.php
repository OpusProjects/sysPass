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

namespace SP\Tests\Unit\Infrastructure\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use SP\Infrastructure\File\FileHandler;
use SP\Tests\Support\UnitaryTestCase;
use SP\Infrastructure\Util\Util;

/**
 * Class UtilTest
 */
#[Group('unitary')]
class UtilTest extends UnitaryTestCase
{
    /**
     * This method is called after the last test of this test class is run.
     */
    public static function tearDownAfterClass(): void
    {
        ini_set('memory_limit', -1);
    }

    public static function boolProvider(): array
    {
        return [
            ['false', false],
            ['no', false],
            ['n', false],
            ['0', false],
            ['off', false],
            [0, false],
            ['true', true],
            ['yes', true],
            ['y', true],
            ['1', true],
            ['on', true],
            [1, true]
        ];
    }

    public static function unitsProvider(): array
    {
        return [
            ['128K', 131072],
            ['128M', 134217728],
            ['128G', 137438953472],
            ['131072', 131072],
            ['134217728', 134217728],
            ['137438953472', 137438953472],
        ];
    }

    #[DataProvider('unitsProvider')]
    public function testConvertShortUnit(string $unit, int $expected)
    {
        $this->assertEquals($expected, Util::convertShortUnit($unit));
    }

    public function testGetMaxUpload()
    {
        $upload = Util::convertShortUnit(ini_get('upload_max_filesize'));
        $post = Util::convertShortUnit(ini_get('post_max_size'));
        $memory = Util::convertShortUnit(ini_get('memory_limit'));

        $this->assertEquals(min($upload, $post, $memory), Util::getMaxUpload());
    }

    /**
     * @param $value
     * @param $expected
     */
    #[DataProvider('boolProvider')]
    public function testBoolval($value, $expected)
    {
        $this->assertEquals($expected, Util::boolval($value));
        $this->assertEquals($expected, Util::boolval($value, true));
    }

    /**
     * getMaxDownloadChunk() has two branches: an "unlimited" memory_limit (negative, or 0)
     * answers with a fixed fallback chunk size, and a real cap derives the chunk from it instead.
     * The fallback is already exercised indirectly through FileHandlerTest, which runs under this
     * suite's own memory_limit=-1 ini setting — this pins the other branch, where a real cap is in
     * effect and the download rate must shrink along with it (divided by FileHandler::CHUNK_FACTOR)
     * rather than silently reusing the unlimited fallback.
     */
    #[Test]
    public function testGetMaxDownloadChunkDerivesFromAPositiveMemoryLimit(): void
    {
        $original = ini_set('memory_limit', '256M');

        try {
            $this->assertSame(
                (int)(Util::convertShortUnit('256M') / FileHandler::CHUNK_FACTOR),
                Util::getMaxDownloadChunk()
            );
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * mapScalarParameters() reads each reflected parameter's declared type and casts the raw value
     * handed in (always a scalar coming off a route or an API request) to match it. The int and
     * bool arms are already exercised elsewhere in this class's use — this pins the three that are
     * not: a float parameter is cast with (float), an array parameter wraps the raw value the way
     * PHP's (array) cast does (a single-element array keyed at 0, not a parsed list), and an object
     * parameter becomes a stdClass with the raw value under its "scalar" property — both very
     * different shapes from what a caller expecting real array/object data would assume, so a
     * caller of this API has to know that, not guess it.
     */
    #[Test]
    public function testMapScalarParametersCastsFloatArrayAndObjectParameters(): void
    {
        $result = Util::mapScalarParameters(
            UtilFixtureScalarParameters::class,
            'withScalarParameters',
            [
                0 => '5',
                1 => 'true',
                2 => '3.14',
                3 => 'hello',
                4 => 'hello',
                5 => 'text',
            ]
        );

        $this->assertSame(3.14, $result[2], 'a float-typed parameter is cast with (float)');
        $this->assertSame(['hello'], $result[3], 'an array-typed parameter wraps the raw value, it does not parse it');
        $this->assertEquals((object)'hello', $result[4], 'an object-typed parameter becomes a stdClass wrapping the raw value');
        $this->assertSame('hello', $result[4]->scalar);
    }

    /**
     * A union-typed parameter (e.g. `int|string $value`) has no single ReflectionNamedType —
     * getMethodParameterTypes() has to unwrap the ReflectionUnionType into the list of named types
     * it is made of, which is what lets mapScalarParameters() pick a type to cast to at all instead
     * of failing on a parameter it cannot make sense of.
     */
    #[Test]
    public function testGetMethodParameterTypesUnwrapsAUnionType(): void
    {
        $method = new ReflectionMethod(UtilFixtureUnionParameter::class, 'withUnionParameter');
        $types = Util::getMethodParameterTypes($method->getParameters()[0]);

        $this->assertCount(2, $types);
        $this->assertEqualsCanonicalizing(
            ['int', 'string'],
            array_map(static fn($type) => $type->getName(), $types)
        );
    }
}

/**
 * Fixture for testMapScalarParametersCastsFloatArrayAndObjectParameters() — never actually invoked,
 * only reflected on: mapScalarParameters() only needs its parameters' declared types.
 */
final class UtilFixtureScalarParameters
{
    public static function withScalarParameters(
        int $intParam,
        bool $boolParam,
        float $floatParam,
        array $arrayParam,
        object $objectParam,
        string $stringParam
    ): void {
    }
}

/**
 * Fixture for testGetMethodParameterTypesUnwrapsAUnionType().
 */
final class UtilFixtureUnionParameter
{
    public static function withUnionParameter(int|string $value): void
    {
    }
}
