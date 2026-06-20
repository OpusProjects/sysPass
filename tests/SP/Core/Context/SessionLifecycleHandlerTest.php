<?php

declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Core\Context {

    /**
     * Toggle that lets the test below force the shadowed headers_sent() override
     * (declared right after this class) to report that headers have already been sent.
     */
    final class HeadersSentState
    {
        public static bool $override = false;
    }

    /**
     * Shadows the global headers_sent() for code running in the SP\Core\Context
     * namespace (i.e. SessionLifecycleHandler). PHP resolves unqualified function calls
     * to the current namespace first, so SessionLifecycleHandler::start() picks this up.
     * When the override flag is off it delegates to the real global function.
     */
    function headers_sent(&$filename = null, &$line = null): bool
    {
        if (HeadersSentState::$override) {
            $filename = __FILE__;
            $line = __LINE__;

            return true;
        }

        return \headers_sent($filename, $line);
    }
}

namespace SP\Tests\Core\Context {

    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
    use SP\Core\Context\HeadersSentState;
    use SP\Core\Context\SessionLifecycleHandler;
    use SP\Domain\Core\Exceptions\SPException;
    use SP\Tests\UnitaryTestCase;

    /**
     * Class SessionUtilTest
     */
    #[Group('unitary')]
    #[RunClassInSeparateProcess]
    class SessionLifecycleHandlerTest extends UnitaryTestCase
    {

    /**
     * @throws SPException
     */
    public function testCleanSession()
    {
        session_start();

        $_SESSION['test'] = self::$faker->colorName();

        SessionLifecycleHandler::clean();

        $this->assertArrayNotHasKey('test', $_SESSION);
    }

    /**
     * @throws SPException
     */
    public function testStart()
    {
        $this->assertEquals(PHP_SESSION_NONE, session_status());

        SessionLifecycleHandler::start();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertEquals('1', ini_get('session.use_strict_mode'));
    }

    /**
     * @throws SPException
     */
    public function testStartWithHeadersSent()
    {
        $this->assertEquals(PHP_SESSION_NONE, session_status());

        // PHPUnit 13 wraps every test in an output buffer, so output never reaches the
        // SAPI and the global headers_sent() stays false (a plain echo/ob_flush() no
        // longer works). Instead we shadow headers_sent() in the production namespace
        // (see the SP\Core\Context block at the top of this file) and force it to report
        // that headers have already been sent, which is exactly the condition start()
        // must guard against.
        HeadersSentState::$override = true;

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Session cannot be initialized');

        SessionLifecycleHandler::start();
    }

    /**
     * @throws SPException
     */
    public function testStartWithGarbageSessionWithDestroyTimeout()
    {
        session_start();

        $_SESSION['destroy_time'] = time() - 600;

        SessionLifecycleHandler::start();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertArrayNotHasKey('destroy_time', $_SESSION);
    }

    /**
     * @throws SPException
     */
    public function testStartWithGarbageSessionAndNewId()
    {
        session_start();

        $newId = session_create_id('test-');

        $_SESSION['destroy_time'] = time();
        $_SESSION['new_session_id'] = $newId;

        SessionLifecycleHandler::start();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertEquals($newId, session_id());
    }

    /**
     * @throws SPException
     */
    public function testRegenerate()
    {
        $this->assertEquals(PHP_SESSION_NONE, session_status());

        $oldId = session_id();

        $out = SessionLifecycleHandler::regenerate();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotEquals($oldId, $out);
        $this->assertEquals($out, session_id());
    }

    /**
     * @throws SPException
     */
    public function testRestart()
    {
        $this->assertEquals(PHP_SESSION_NONE, session_status());

        session_start();

        $_SESSION['test'] = self::$faker->colorName();

        SessionLifecycleHandler::restart();

        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertArrayNotHasKey('test', $_SESSION);
    }

    /**
     * @throws SPException
     */
    public function testClean()
    {
        $this->assertEquals(PHP_SESSION_NONE, session_status());

        session_start();

        $_SESSION['test'] = self::$faker->colorName();

        SessionLifecycleHandler::clean();

        $this->assertEquals(PHP_SESSION_NONE, session_status());
        $this->assertArrayNotHasKey('test', $_SESSION);
    }

    public function testNeedsRegenerate()
    {
        $this->assertFalse(SessionLifecycleHandler::needsRegenerate(time()));
    }

    public function testNeedsRegenerateWithTimeout()
    {
        $this->assertTrue(SessionLifecycleHandler::needsRegenerate(time() - 2000));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        HeadersSentState::$override = false;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    }
}
