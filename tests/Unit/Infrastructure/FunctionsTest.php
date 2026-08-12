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

namespace SP\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use SP\Domain\Core\Exceptions\SPException;

use function SP\_t;
use function SP\getElapsedTime;
use function SP\getFromEnv;
use function SP\initModule;
use function SP\mb_ucfirst;

/**
 * Class FunctionsTest
 *
 * Covers SP\getFromEnv() and SP\mb_ucfirst() from src/Core/Functions.php
 */
#[Group('unitary')]
class FunctionsTest extends TestCase
{
    private const ENV_VAR = 'SYSPASS_TEST_GET_FROM_ENV';

    private mixed $originalEnv = null;
    private bool $wasSet = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wasSet = array_key_exists(self::ENV_VAR, $_ENV);
        $this->originalEnv = $_ENV[self::ENV_VAR] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->wasSet) {
            $_ENV[self::ENV_VAR] = $this->originalEnv;
        } else {
            unset($_ENV[self::ENV_VAR]);
        }

        parent::tearDown();
    }

    public static function truthyStringsProvider(): array
    {
        return [
            ['true'],
            ['1'],
            ['on'],
            ['yes'],
        ];
    }

    public static function falsyStringsProvider(): array
    {
        return [
            ['false'],
            ['0'],
            ['off'],
            ['no'],
        ];
    }

    #[DataProvider('truthyStringsProvider')]
    public function testBooleanDefaultParsesTruthyStrings(string $value): void
    {
        $_ENV[self::ENV_VAR] = $value;

        $this->assertTrue(getFromEnv(self::ENV_VAR, false));
    }

    #[DataProvider('falsyStringsProvider')]
    public function testBooleanDefaultParsesFalsyStrings(string $value): void
    {
        $_ENV[self::ENV_VAR] = $value;

        $this->assertFalse(getFromEnv(self::ENV_VAR, true));
    }

    public function testBooleanDefaultUsedWhenVariableIsUnset(): void
    {
        unset($_ENV[self::ENV_VAR]);

        $this->assertTrue(getFromEnv(self::ENV_VAR, true));
        $this->assertFalse(getFromEnv(self::ENV_VAR, false));
    }

    public function testBooleanDefaultUsedWhenValueIsUnparseable(): void
    {
        $_ENV[self::ENV_VAR] = 'not-a-boolean';

        $this->assertTrue(getFromEnv(self::ENV_VAR, true));
        $this->assertFalse(getFromEnv(self::ENV_VAR, false));
    }

    public static function ucfirstProvider(): array
    {
        return [
            'ascii word' => ['hello', 'Hello'],
            'already capitalized' => ['World', 'World'],
            'multibyte word' => ['ñandú', 'Ñandú'],
            'single multibyte char' => ['ñ', 'Ñ'],
            'single ascii char' => ['a', 'A'],
            'empty string' => ['', ''],
        ];
    }

    #[DataProvider('ucfirstProvider')]
    public function testMbUcfirst(string $input, string $expected): void
    {
        $this->assertSame($expected, mb_ucfirst($input));
    }

    /**
     * The plugin translations go through their own gettext domain. Everything the function refuses
     * to translate has to come back as it was, since the message is what is shown either way.
     */
    public function testAMessageFromAnUnknownDomainComesBackAsItIs(): void
    {
        $this->assertSame('Some message', _t('a-plugin', 'Some message'));
    }

    public function testTranslationCanBeTurnedOff(): void
    {
        $this->assertSame('Some message', _t('a-plugin', 'Some message', false));
    }

    /**
     * gettext is given neither an empty message — which would return the catalogue header — nor an
     * unreasonably long one.
     */
    public function testAnEmptyMessageIsNotLookedUp(): void
    {
        $this->assertSame('', _t('a-plugin', ''));
    }

    public function testAnOverlongMessageIsNotLookedUp(): void
    {
        $message = str_repeat('a', 4096);

        $this->assertSame($message, _t('a-plugin', $message));
    }

    /**
     * The rendering time is measured from a mark taken at the start of the request. A mark that was
     * never taken reads as no time at all, rather than as the seconds since 1970.
     */
    public function testAnUnsetStartMarkMeasuresNoTime(): void
    {
        $this->assertSame(0.0, getElapsedTime(0.0));
    }

    public function testTheElapsedTimeIsMeasuredFromTheMark(): void
    {
        $elapsed = getElapsedTime(microtime(true) - 0.5);

        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(30.0, $elapsed);
    }

    /**
     * Each entry point builds its container from its module's definitions, so a module that cannot
     * be loaded has to say so rather than yielding an empty container that fails later, one missing
     * service at a time.
     *
     * @throws SPException
     */
    #[DataProvider('moduleProvider')]
    public function testEachModuleOffersItsDefinitions(string $module): void
    {
        $this->assertNotEmpty(initModule($module));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function moduleProvider(): array
    {
        return ['web' => ['web'], 'api' => ['api'], 'cli' => ['cli']];
    }

    /**
     * The module name reaches this from the entry point, so a name that is not one is refused
     * rather than silently building nothing.
     *
     * @throws SPException
     */
    public function testAnUnknownModuleIsRefused(): void
    {
        $this->expectException(SPException::class);

        initModule('not-a-module');
    }
}
