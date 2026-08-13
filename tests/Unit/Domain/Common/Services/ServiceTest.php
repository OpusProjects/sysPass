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

namespace SP\Tests\Unit\Domain\Common\Services;

use Defuse\Crypto\Exception\CryptoException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Infrastructure\Context\Stateless;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class ServiceTest
 *
 * Service holds the master password read/write logic every account-related service relies
 * on. It picks between two backends (a per-session encrypted key vs. a plain transient
 * context value) depending on what kind of Context the app is running under, and it must
 * turn any lower-level crypto/context failure into a ServiceException rather than leaking a
 * raw CryptoException/ContextException/CryptException out of the service layer.
 */
#[Group('unitary')]
class ServiceTest extends UnitaryTestCase
{
    /**
     * Outside of a real web session (e.g. the CLI, or API requests), the context is a plain
     * Context rather than a SessionContext, so the master key is read back from the same
     * transient slot it was written to. If either side used a different key or a different
     * storage path, the master password would appear lost even though it was set moments
     * before.
     *
     * @throws Exception
     * @throws ServiceException
     * @throws ContextException
     */
    public function testSetThenGetMasterKeyRoundTripsThroughAPlainContext(): void
    {
        $service = $this->buildService(new Stateless());

        $service->setKey('a-master-password');

        self::assertSame('a-master-password', $service->getKey());
    }

    /**
     * A plain Context that was never given a master key (a fresh request, before login
     * completes) must not be reported as having one — returning an empty string would let
     * account decryption proceed with a blank key instead of failing loudly.
     *
     * @throws Exception
     * @throws ContextException
     */
    public function testGetMasterKeyFailsWhenThePlainContextHasNoKeyStored(): void
    {
        $service = $this->buildService(new Stateless());

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while retrieving master password from context');

        $service->getKey();
    }

    /**
     * Under a real web session, the master key is delegated to the SessionKeyService
     * (an encrypted, session-bound store) instead of the plain context slot. If Service
     * failed to route to it, a logged-in user's master password would never be found even
     * though the session key service holds it.
     *
     * @throws Exception
     * @throws ServiceException
     */
    public function testGetMasterKeyDelegatesToTheSessionKeyServiceUnderASessionContext(): void
    {
        $context = $this->createStub(SessionContext::class);
        $sessionKeyService = $this->createMock(SessionKeyService::class);
        $sessionKeyService->expects(self::once())
                           ->method('getSessionKey')
                           ->with($context)
                           ->willReturn('session-backed-key');

        $service = $this->buildService($context, $sessionKeyService);

        self::assertSame('session-backed-key', $service->getKey());
    }

    /**
     * The session key service can fail (e.g. the session vault was tampered with or the
     * encryption key rotated). That must surface as the same ServiceException every other
     * failure mode does, not as a raw Defuse CryptoException bubbling out of the service
     * layer into code that only expects SPException-family errors.
     *
     * @throws Exception
     */
    public function testGetMasterKeyWrapsACryptoExceptionFromTheSessionKeyService(): void
    {
        $context = $this->createStub(SessionContext::class);
        $sessionKeyService = $this->createStub(SessionKeyService::class);
        $sessionKeyService->method('getSessionKey')
                           ->willThrowException(new CryptoException('bad session vault'));

        $service = $this->buildService($context, $sessionKeyService);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while retrieving master password from context');

        $service->getKey();
    }

    /**
     * Under a real web session, setting the master key must go through the
     * SessionKeyService rather than the plain context slot, or a logged-in user's key would
     * be written somewhere getMasterKeyFromContext() never looks.
     *
     * @throws Exception
     * @throws ServiceException
     */
    public function testSetMasterKeyDelegatesToTheSessionKeyServiceUnderASessionContext(): void
    {
        $context = $this->createStub(SessionContext::class);
        $sessionKeyService = $this->createMock(SessionKeyService::class);
        $sessionKeyService->expects(self::once())
                           ->method('saveSessionKey')
                           ->with('a-master-password', $context);

        $service = $this->buildService($context, $sessionKeyService);

        $service->setKey('a-master-password');
    }

    /**
     * Saving the session key can fail the same way reading it can. That must also collapse
     * to a ServiceException, so callers have exactly one exception type to handle regardless
     * of which context flavour is active.
     *
     * @throws Exception
     */
    public function testSetMasterKeyWrapsAContextExceptionFromTheSessionKeyService(): void
    {
        $context = $this->createStub(SessionContext::class);
        $sessionKeyService = $this->createStub(SessionKeyService::class);
        $sessionKeyService->method('saveSessionKey')
                           ->willThrowException(new ContextException('session is not writable'));

        $service = $this->buildService($context, $sessionKeyService);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while setting master password in context');

        $service->setKey('a-master-password');
    }

    /**
     * saveSessionKey() is also documented to throw CryptException (a distinct type from
     * ContextException); both are caught by the same multi-catch, and both must produce the
     * same ServiceException.
     *
     * @throws Exception
     */
    public function testSetMasterKeyWrapsACryptExceptionFromTheSessionKeyService(): void
    {
        $context = $this->createStub(SessionContext::class);
        $sessionKeyService = $this->createStub(SessionKeyService::class);
        $sessionKeyService->method('saveSessionKey')
                           ->willThrowException(new CryptException('encryption key unavailable'));

        $service = $this->buildService($context, $sessionKeyService);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while setting master password in context');

        $service->setKey('a-master-password');
    }

    /**
     * Builds a Service test double over a fresh Application wired with the given context
     * (and, for the SessionContext scenarios, session key service) and exposes the two
     * protected master-key methods under test.
     *
     * @throws Exception
     */
    private function buildService(Context $context, ?SessionKeyService $sessionKeyService = null): object
    {
        $application = new Application(
            $this->config,
            $this->createStub(EventDispatcherInterface::class),
            $context,
            $sessionKeyService
        );

        return new class($application) extends Service {
            public function getKey(): string
            {
                return $this->getMasterKeyFromContext();
            }

            public function setKey(string $masterPass): void
            {
                $this->setMasterKeyInContext($masterPass);
            }
        };
    }
}
