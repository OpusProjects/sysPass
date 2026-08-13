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

namespace SP\Tests\Unit\Infrastructure\Http\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\Http\Services\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Class ResponseTest
 *
 * Response is the only bridge between application code and the actual HTTP
 * output: every controller reply, download and redirect in the app goes
 * through it. Since the router migration replaced klein/klein with this thin
 * wrapper over Symfony's HttpFoundation Response, a mistranslated method here
 * silently breaks whichever behaviour it wraps (headers not applied, a
 * redirect losing its status code, a chunked download losing bytes) without
 * any type error to catch it.
 */
#[Group('unitary')]
class ResponseTest extends TestCase
{
    /**
     * header() must reach the real Symfony response headers and stay
     * chainable — controllers chain header()->body()->send(). If the value
     * were dropped, clients would receive a response missing e.g. its
     * Content-Type, and JSON/XML payloads would be misinterpreted.
     */
    public function testHeaderSetsHeaderValueAndIsChainable()
    {
        $response = new Response();

        $out = $response->header('X-Test-Header', 'a-value');

        $this->assertSame($response, $out);
        $this->assertEquals('a-value', $response->headers()->get('X-Test-Header'));
    }

    /**
     * headers() must expose the very same header bag that header() writes
     * to and that gets sent on send(); code that inspects or conditionally
     * mutates headers (e.g. cache-control tweaks) would silently affect a
     * detached copy otherwise, and the change would never reach the client.
     */
    public function testHeadersReturnsTheUnderlyingHeaderBag()
    {
        $response = new Response();
        $response->header('X-Test-Header', 'a-value');

        $headers = $response->headers();

        $this->assertInstanceOf(ResponseHeaderBag::class, $headers);
        $this->assertSame($headers, $response->getResponse()->headers);
        $this->assertEquals('a-value', $headers->get('X-Test-Header'));
    }

    /**
     * body() is how every controller sets the page/API payload; getBody()
     * is how JsonResponse and the tests around it verify what would be
     * sent. If body() stopped reaching the wrapped response, every page
     * would render blank while still returning a 200.
     */
    public function testBodySetsResponseContentAndGetBodyReturnsIt()
    {
        $response = new Response();

        $out = $response->body('the-response-body');

        $this->assertSame($response, $out);
        $this->assertEquals('the-response-body', $response->getBody());
    }

    /**
     * getBody() casts Symfony's getContent() to string. A freshly built
     * response has no content set; this must resolve to an empty string,
     * not null/false, or the concatenation in append() would fatal under
     * strict_types on the very first chunk appended to an unset body.
     */
    public function testGetBodyReturnsEmptyStringByDefault()
    {
        $response = new Response();

        $this->assertEquals('', $response->getBody());
    }

    /**
     * append() is used to stream a response in chunks (e.g. large exports).
     * If it replaced the body instead of concatenating, only the last
     * chunk written would ever reach the client and downloads would arrive
     * silently truncated.
     */
    public function testAppendConcatenatesToExistingBody()
    {
        $response = new Response();
        $response->body('first-');

        $out = $response->append('second');

        $this->assertSame($response, $out);
        $this->assertEquals('first-second', $response->getBody());
    }

    /**
     * code() drives the HTTP status line API/browser clients rely on to
     * tell success from failure. A validation failure that stayed on the
     * default 200 would be treated as a success by any caller that checks
     * the status code instead of parsing the body.
     */
    public function testCodeSetsTheHttpStatusCode()
    {
        $response = new Response();

        $out = $response->code(422);

        $this->assertSame($response, $out);
        $this->assertEquals(422, $response->getResponse()->getStatusCode());
    }

    /**
     * redirect() must default to a 302 (temporary) redirect and set the
     * Location header together, since login/logout flows rely on both
     * being present in the same response — a missing Location would leave
     * the browser on a blank 302, and a missing status code would make the
     * browser render the redirect target as a normal page.
     */
    public function testRedirectDefaultsToA302WithLocationHeader()
    {
        $response = new Response();

        $out = $response->redirect('/index.php');

        $this->assertSame($response, $out);
        $this->assertEquals(302, $response->getResponse()->getStatusCode());
        $this->assertEquals('/index.php', $response->headers()->get('Location'));
    }

    /**
     * Callers can request a permanent (301) redirect explicitly; if the
     * $code parameter were ignored, a permanent-redirect use (e.g. old
     * bookmarked URLs) would keep being cached by browsers as temporary
     * and re-checked on every visit instead of being remembered.
     */
    public function testRedirectHonoursAnExplicitStatusCode()
    {
        $response = new Response();

        $response->redirect('/new-location.php', 301);

        $this->assertEquals(301, $response->getResponse()->getStatusCode());
        $this->assertEquals('/new-location.php', $response->headers()->get('Location'));
    }

    /**
     * send() is what actually flushes the body to the client and must mark
     * the response as sent so callers (and isSent()) can avoid double
     * sending. If sendContent() were skipped, the client would receive an
     * HTTP response with the right status/headers but an empty body — a
     * page or API response that looks successful but carries no content.
     */
    public function testSendFlushesBodyAndMarksResponseAsSent()
    {
        $response = new Response();
        $response->body('payload-to-send');

        $this->expectOutputString('payload-to-send');

        $out = $response->send();

        $this->assertSame($response, $out);
        $this->assertTrue($response->isSent());
    }

    /**
     * isSent() must read false until send() actually runs, otherwise code
     * that guards against re-sending (or re-rendering after an error) would
     * wrongly skip the response entirely for a request that never sent one.
     */
    public function testIsSentIsFalseBeforeSendIsCalled()
    {
        $response = new Response();

        $this->assertFalse($response->isSent());
    }

    /**
     * The constructor accepts an injected SymfonyResponse (used in tests
     * and for DI wiring); getResponse() must hand back that exact instance
     * rather than a fresh internal one, or any status/header/content
     * already set on the injected response before wrapping would be lost.
     */
    public function testGetResponseReturnsTheInjectedSymfonyResponse()
    {
        $symfonyResponse = new SymfonyResponse('preset-body', 201);

        $response = new Response($symfonyResponse);

        $this->assertSame($symfonyResponse, $response->getResponse());
        $this->assertEquals('preset-body', $response->getBody());
        $this->assertEquals(201, $response->getResponse()->getStatusCode());
    }
}
