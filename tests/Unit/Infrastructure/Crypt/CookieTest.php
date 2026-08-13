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

namespace SP\Tests\Unit\Infrastructure\Crypt;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Crypt\Cookie;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class CookieTest
 *
 * Cookie signs and verifies the value it hands back to callers such as UuidCookie.
 * Everything that trusts a signed cookie — most importantly the sysPass UUID cookie
 * used to recognise a returning browser — only stays trustworthy if a tampered or
 * wrongly-signed value is refused rather than silently accepted or decoded anyway.
 * Cookie is abstract with a protected constructor (subclasses supply the cookie
 * name), so these tests drive it through a minimal concrete subclass that exposes
 * the protected read/write helpers for direct assertions.
 */
#[Group('unitary')]
class CookieTest extends UnitaryTestCase
{
    private const COOKIE_NAME = 'SYSPASS_TEST';

    private RequestService|MockObject $requestService;
    private UriContextInterface|MockObject $uriContext;

    /**
     * Signing and then verifying with the same key must return exactly the value that
     * was signed — the basic contract every caller of sign()/getCookieData() relies on.
     */
    public function testSignThenGetCookieDataRoundTripsTheOriginalValue(): void
    {
        $key = self::$faker->sha1();
        $cookie = $this->buildCookie();

        $signed = $cookie->sign('the-secret-value', $key);

        self::assertSame('the-secret-value', $cookie->getCookieData($signed, $key));
    }

    /**
     * The signed value is base64-encoded before signing specifically so that a value
     * containing the ';' separator itself does not corrupt the [signature];[data]
     * framing. Without that, a value like "a;b" would split unpredictably.
     */
    public function testRoundTripIsSafeForValuesContainingTheSeparatorCharacter(): void
    {
        $key = self::$faker->sha1();
        $cookie = $this->buildCookie();
        $value = "part-one;part-two;part-three";

        $signed = $cookie->sign($value, $key);

        self::assertSame($value, $cookie->getCookieData($signed, $key));
    }

    /**
     * This is the case that matters most: if any byte of the signed payload is changed
     * after signing, verification must refuse it outright rather than return a decoded
     * (and now attacker-influenced) value. Anything riding on cookie authentication is
     * only as safe as this check.
     */
    public function testTamperingASingleByteOfThePayloadIsRejected(): void
    {
        $key = self::$faker->sha1();
        $cookie = $this->buildCookie();

        $signed = $cookie->sign('the-secret-value', $key);
        [$signature, $payload] = explode(';', $signed, 2);

        $tamperedPayload = $this->flipFirstByte($payload);
        $tampered = $signature . ';' . $tamperedPayload;

        self::assertNotSame($signed, $tampered);
        self::assertFalse($cookie->getCookieData($tampered, $key));
    }

    /**
     * Tampering the signature itself (rather than the payload) must be refused the same
     * way — a forged cookie should not be salvageable by mangling either half.
     */
    public function testTamperingASingleByteOfTheSignatureIsRejected(): void
    {
        $key = self::$faker->sha1();
        $cookie = $this->buildCookie();

        $signed = $cookie->sign('the-secret-value', $key);
        [$signature, $payload] = explode(';', $signed, 2);

        $tamperedSignature = $this->flipFirstByte($signature);
        $tampered = $tamperedSignature . ';' . $payload;

        self::assertNotSame($signed, $tampered);
        self::assertFalse($cookie->getCookieData($tampered, $key));
    }

    /**
     * A cookie signed under one key must not verify under a different one — otherwise
     * rotating or per-installation keys would offer no real protection.
     */
    public function testACookieSignedWithADifferentKeyIsRejected(): void
    {
        $signingKey = self::$faker->sha1();
        $wrongKey = 'not-' . $signingKey;
        $cookie = $this->buildCookie();

        $signed = $cookie->sign('the-secret-value', $signingKey);

        self::assertFalse($cookie->getCookieData($signed, $wrongKey));
    }

    /**
     * A value with no ';' separator at all cannot be [signature];[data] and must be
     * refused directly, without attempting to hash or decode anything.
     */
    public function testACookieValueWithNoSeparatorIsRejected(): void
    {
        $cookie = $this->buildCookie();

        self::assertFalse($cookie->getCookieData('not-a-signed-cookie', self::$faker->sha1()));
    }

    /**
     * An empty string is the simplest malformed case (also missing the separator) and
     * must be refused the same way, not raise.
     */
    public function testAnEmptyCookieValueIsRejected(): void
    {
        $cookie = $this->buildCookie();

        self::assertFalse($cookie->getCookieData('', self::$faker->sha1()));
    }

    /**
     * A value that does contain the separator but carries an empty signature or an
     * empty payload is a degenerate forgery attempt, not a crash: it must fail the
     * signature check like any other mismatch instead of raising.
     */
    public function testACookieValueWithAnEmptySignatureOrEmptyPayloadIsRejected(): void
    {
        $cookie = $this->buildCookie();
        $key = self::$faker->sha1();

        self::assertFalse($cookie->getCookieData(';' . base64_encode('data'), $key));
        self::assertFalse($cookie->getCookieData('somesignature;', $key));
    }

    /**
     * getCookie() reads the raw value straight from the current request's cookie bag,
     * keyed by the name the subclass was constructed with.
     */
    public function testGetCookieReturnsTheStoredValueForTheConfiguredCookieName(): void
    {
        $request = new Request(cookies: [self::COOKIE_NAME => 'stored-value']);
        $this->requestService->method('getRequest')->willReturn($request);

        $cookie = $this->buildCookie();

        self::assertSame('stored-value', $cookie->readCookie());
    }

    /**
     * When the browser never sent this cookie, getCookie() must report that plainly
     * (false), not an empty string that could be mistaken for a real value.
     */
    public function testGetCookieReturnsFalseWhenTheCookieIsAbsent(): void
    {
        $request = new Request();
        $this->requestService->method('getRequest')->willReturn($request);

        $cookie = $this->buildCookie();

        self::assertFalse($cookie->readCookie());
    }

    /**
     * setCookie() is the write side: given headers have not been sent yet, it must
     * succeed and use the configured web root as the cookie path.
     */
    public function testSetCookieSucceedsWhenHeadersHaveNotBeenSent(): void
    {
        $this->uriContext->method('getWebRoot')->willReturn('/');

        $cookie = $this->buildCookie();

        self::assertTrue($cookie->writeCookie('some-value'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestService = $this->createStub(RequestService::class);
        $this->uriContext = $this->createStub(UriContextInterface::class);
    }

    /**
     * Flips the first byte of a non-empty string to a value guaranteed to differ from
     * the original, simulating a single tampered byte anywhere earlier in the value
     * (HMAC-SHA256 makes every bit of the output depend on every bit of the input, so
     * which byte moves does not matter for the assertion).
     */
    private function flipFirstByte(string $value): string
    {
        self::assertNotSame('', $value, 'cannot tamper an empty string');

        $value[0] = $value[0] === 'Z' ? 'z' : 'Z';

        return $value;
    }

    /**
     * Builds a minimal concrete Cookie for the tests: Cookie itself is abstract with a
     * protected constructor (real cookie names are supplied by subclasses such as
     * UuidCookie), so this exposes its protected sign/verify and read/write helpers
     * without adding any behaviour of its own.
     */
    private function buildCookie(): Cookie
    {
        return new class(self::COOKIE_NAME, $this->requestService, $this->uriContext) extends Cookie {
            public function __construct(string $cookieName, RequestService $request, UriContextInterface $uriContext)
            {
                parent::__construct($cookieName, $request, $uriContext);
            }

            public function readCookie(): bool|string
            {
                return $this->getCookie();
            }

            public function writeCookie(string $data): bool
            {
                return $this->setCookie($data);
            }
        };
    }
}
