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
use RuntimeException;

use SP\Domain\Core\Exceptions\SPException;

use function SP\_t;
use function SP\formatStackTrace;
use function SP\getElapsedTime;
use function SP\getFromEnv;
use function SP\getLastCaller;
use function SP\initModule;
use function SP\logger;
use function SP\mb_ucfirst;
use function SP\processException;

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

    /**
     * Debug-level messages are noise outside of active debugging (DEBUG is false throughout this
     * suite). An operator sifting the log for a real incident should never have to wade through
     * routine debug traces that were never meant to reach it.
     */
    public function testLoggerSuppressesADebugMessageWhenNotInDebugMode(): void
    {
        $marker = uniqid('sp-debug-suppressed-', true);

        logger($marker); // default $type is 'DEBUG'

        $this->assertStringNotContainsString($marker, (string)file_get_contents(LOG_FILE));
    }

    /**
     * A non-debug message (e.g. an exception being logged) always has to reach the log file
     * regardless of the debug flag, in the application's own format, since that file is what
     * gets shipped to the operator via the "download log" feature.
     */
    public function testLoggerWritesANonDebugMessageToTheLogFile(): void
    {
        $marker = uniqid('sp-info-written-', true);

        logger($marker, 'INFO');

        $this->assertStringContainsString(
            sprintf('syspass.INFO: logger {"message":"%s"', $marker),
            (string)file_get_contents(LOG_FILE)
        );
    }

    /**
     * If the log file itself cannot be written (a full disk, a permissions mistake on an
     * operator's install), the log line must not simply vanish — logger() falls back to the PHP
     * error log instead, which is what this proves by pointing that ini setting at a real file
     * and making the configured log file unwritable.
     */
    public function testLoggerFallsBackToErrorLogWhenTheLogFileCannotBeWritten(): void
    {
        $marker = uniqid('sp-error-log-fallback-', true);
        $errorLogFile = tempnam(sys_get_temp_dir(), 'sp_error_log_');
        $originalErrorLog = ini_set('error_log', $errorLogFile);

        if (!file_exists(LOG_FILE)) {
            touch(LOG_FILE);
        }

        try {
            chmod(LOG_FILE, 0000);
            logger($marker, 'INFO');
        } finally {
            chmod(LOG_FILE, 0666);
            ini_set('error_log', $originalErrorLog);
        }

        $this->assertStringContainsString(
            '[INFO] [' . $marker . ']',
            (string)file_get_contents($errorLogFile)
        );

        unlink($errorLogFile);
    }

    /**
     * The logged caller name is what lets an operator tell, from the log alone, which part of the
     * application produced a given line. Asking for a depth with nothing at it (e.g. a call made
     * from the very top of the stack) must not raise a notice for the missing array keys — it has
     * to answer with a placeholder instead.
     */
    public function testGetLastCallerReturnsNAWhenNoFrameExistsAtThatDepth(): void
    {
        $this->assertSame('N/A', getLastCaller(10));
    }

    /**
     * At a depth that does exist, the caller is reported as Class::method, which is what makes the
     * log line actionable instead of just saying "something logged this".
     */
    public function testGetLastCallerReturnsTheImmediateCallersClassAndMethod(): void
    {
        $this->assertSame(
            self::class . '::invokeGetLastCallerAtDepthOne',
            $this->invokeGetLastCallerAtDepthOne()
        );
    }

    private function invokeGetLastCallerAtDepthOne(): string
    {
        return getLastCaller(1);
    }

    /**
     * A frame from a call made through a built-in higher-order function (array_map() calling the
     * user callback here) carries no file/line in the trace — the formatter has to fall back to a
     * placeholder instead of emitting a PHP warning for the missing array keys, and still has to
     * type every argument, rendering an object argument as "Object(Class)" so the log records
     * which kind of object was passed rather than just the bare word "Object".
     */
    public function testFormatStackTraceRendersInternalFramesAndTypesEachArgument(): void
    {
        // Whether a trace carries its arguments at all is an ini setting, and it differs between
        // a development build and a production one — so it is pinned here rather than assumed,
        // otherwise this passes locally and fails wherever the production ini is in force.
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            array_map(static function ($x) {
                throw new RuntimeException('boom');
            }, [1]);
            $this->fail('the callback was expected to throw');
        } catch (RuntimeException $e) {
            $trace = formatStackTrace($e);
        } finally {
            ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
        }

        $this->assertStringContainsString(
            '[internal function]',
            $trace,
            'a frame with no file falls back to a placeholder'
        );
        $this->assertStringContainsString(
            '(Integer)',
            $trace,
            'a scalar argument is typed by its gettype()'
        );
        $this->assertStringContainsString(
            'array_map(Object(Closure),Array)',
            $trace,
            'an object argument is typed with its class name, not just "Object"'
        );
    }

    /**
     * A frame that belongs to a method call is rendered as Class->method rather than a bare
     * function name, and a call made with no arguments renders empty parentheses instead of
     * looping over a missing 'args' key.
     */
    public function testFormatStackTraceRendersAMethodCallWithNoArguments(): void
    {
        $thrower = new class {
            public function go(): void
            {
                throw new RuntimeException('method-throw');
            }
        };

        try {
            $thrower->go();
            $this->fail('go() was expected to throw');
        } catch (RuntimeException $e) {
            $trace = formatStackTrace($e);
        }

        $this->assertStringContainsString('->go()', $trace);
    }

    /**
     * processException() is the only record left of an exception the application chooses to
     * swallow rather than propagate — an operator diagnosing an incident afterwards has nothing
     * else to go on, so the message has to reach the log every time, and the cause of a chained
     * exception has to reach it too, but only when there is one to report.
     */
    public function testProcessExceptionLogsTheMessageAndOnlyLogsAPreviousCauseWhenThereIsOne(): void
    {
        $standaloneMarker = uniqid('sp-standalone-', true);
        $before = strlen((string)file_get_contents(LOG_FILE));

        processException(new RuntimeException($standaloneMarker));

        $standaloneLogged = substr((string)file_get_contents(LOG_FILE), $before);

        $this->assertStringContainsString($standaloneMarker, $standaloneLogged);
        $this->assertStringNotContainsString(
            '(P) ',
            $standaloneLogged,
            'an exception with no previous cause must not log a "(P)" section'
        );

        $innerMarker = uniqid('sp-inner-', true);
        $outerMarker = uniqid('sp-outer-', true);
        $before = strlen((string)file_get_contents(LOG_FILE));

        processException(new RuntimeException($outerMarker, 0, new RuntimeException($innerMarker)));

        $chainedLogged = substr((string)file_get_contents(LOG_FILE), $before);

        $this->assertStringContainsString($outerMarker, $chainedLogged);
        $this->assertStringContainsString('(P) ' . $innerMarker, $chainedLogged);
    }
}
