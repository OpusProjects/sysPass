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

namespace SP\Tests\Application\Install\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Application\Install\Services\InstallThrottle;
use SP\Core\Bootstrap\Path;
use SP\Core\Bootstrap\PathsContext;
use SP\Domain\Http\Ports\RequestService;

/**
 * Class InstallThrottleTest
 */
#[Group('unitary')]
class InstallThrottleTest extends TestCase
{
    private string          $cacheDir;
    private InstallThrottle $throttle;

    public function testAttemptsWithinLimitAreAllowed(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->throttle->isAllowed('192.0.2.1'));
        }
    }

    public function testAttemptsOverLimitAreRejected(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->isAllowed('192.0.2.1');
        }

        $this->assertFalse($this->throttle->isAllowed('192.0.2.1'));
    }

    public function testLimitIsPerAddress(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->isAllowed('192.0.2.1');
        }

        $this->assertFalse($this->throttle->isAllowed('192.0.2.1'));
        $this->assertTrue($this->throttle->isAllowed('192.0.2.2'));
    }

    public function testExpiredAttemptsAreDiscarded(): void
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'install_throttle.json';

        // 10 attempts, all outside the 60-second window
        file_put_contents($file, json_encode(['192.0.2.1' => array_fill(0, 10, time() - 61)]));

        $this->assertTrue($this->throttle->isAllowed('192.0.2.1'));
    }

    public function testUnknownAddressIsAllowed(): void
    {
        $this->assertTrue($this->throttle->isAllowed(''));
    }

    public function testFailsOpenOnCorruptStore(): void
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'install_throttle.json';

        file_put_contents($file, 'this is not json');

        $this->assertTrue($this->throttle->isAllowed('192.0.2.1'));
    }

    /**
     * check() must key on REMOTE_ADDR, not the spoofable Forwarded header that
     * getClientAddress() trusts — otherwise an attacker rotates the header to
     * land in a fresh bucket every request and the limit never fires.
     */
    public function testCheckKeysOnRemoteAddrAndCannotBeSpoofed(): void
    {
        $request = $this->createStub(RequestService::class);
        $request->method('getServer')->willReturnCallback(
            static fn(string $key) => $key === 'REMOTE_ADDR' ? '198.51.100.7' : ''
        );

        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::CACHE, $this->cacheDir);

        $throttle = new InstallThrottle($pathsContext, $request);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($throttle->check());
        }

        // Same REMOTE_ADDR — over the limit, no matter what a client could forge
        $this->assertFalse($throttle->check());

        // The stored key is the REMOTE_ADDR, confirming getClientAddress() is not used
        $stored = json_decode(
            (string)file_get_contents($this->cacheDir . DIRECTORY_SEPARATOR . 'install_throttle.json'),
            true
        );
        $this->assertSame(['198.51.100.7'], array_keys($stored));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_throttle_', true);
        mkdir($this->cacheDir, 0777, true);

        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::CACHE, $this->cacheDir);

        $this->throttle = new InstallThrottle($pathsContext, $this->createStub(RequestService::class));
    }

    protected function tearDown(): void
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'install_throttle.json';

        if (file_exists($file)) {
            unlink($file);
        }

        rmdir($this->cacheDir);

        parent::tearDown();
    }
}
