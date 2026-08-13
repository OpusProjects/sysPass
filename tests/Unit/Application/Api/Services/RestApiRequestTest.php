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

namespace SP\Tests\Unit\Application\Api\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Application\Api\Services\RestApiRequest;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class RestApiRequestTest
 *
 * RestApiRequest is where every REST API call is turned into the parameter bag and
 * auth token that the rest of the application trusts without re-checking. A mistake
 * here (the wrong source winning a name clash, a route id that a request body could
 * override, a token that could be smuggled in through a query parameter) would be a
 * request-forgery bug reachable from any HTTP client, not just an internal defect.
 */
#[Group('unitary')]
class RestApiRequestTest extends UnitaryTestCase
{
    public static function nonObjectJsonBodyDataProvider(): array
    {
        // A JSON body can decode to any JSON type, not just an object. json_decode()
        // only merges when it produces a PHP array, so a scalar body must be a no-op.
        return [
            'json string' => ['"just-a-string"'],
            'json number' => ['42'],
            'json true' => ['true'],
            'json false' => ['false'],
            'json null' => ['null'],
        ];
    }

    /**
     * GET requests carry their filters in the query string; RestApiRequest must expose
     * them under their own name so controllers can read them via get().
     */
    public function testQueryStringParameterIsReadable(): void
    {
        $request = Request::create('/api/v1/accounts?search=' . urlencode('some client'), 'GET');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('some client', $apiRequest->get('search'));
        self::assertTrue($apiRequest->exists('search'));
    }

    /**
     * Write requests (POST/PUT) carry their payload as a JSON body; RestApiRequest must
     * decode it and expose each key the same way a query parameter would be.
     */
    public function testJsonBodyParameterIsReadable(): void
    {
        $request = Request::create(
            '/api/v1/accounts',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['name' => 'a new account'], JSON_THROW_ON_ERROR)
        );

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('a new account', $apiRequest->get('name'));
    }

    /**
     * The `id` in a path like /api/v1/accounts/{id} is resolved by the router and handed
     * over as a request attribute, not as a query or body parameter. RestApiRequest must
     * still expose it under its plain name.
     */
    public function testRouteAttributeParameterIsReadable(): void
    {
        $request = Request::create('/api/v1/accounts/5', 'GET');
        $request->attributes->set('id', '5');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('5', $apiRequest->get('id'));
    }

    /**
     * When a name is present in both the query string and the JSON body (no route
     * attribute involved), the body must win: it is the payload the caller actually
     * intends to act on for a write, while the query string is incidental.
     */
    public function testJsonBodyParameterOverridesQueryStringParameterWithTheSameName(): void
    {
        $request = Request::create(
            '/api/v1/accounts?id=111',
            'PUT',
            [],
            [],
            [],
            [],
            json_encode(['id' => 222], JSON_THROW_ON_ERROR)
        );

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame(222, $apiRequest->get('id'));
    }

    /**
     * This is the case that matters most: the `id` resolved from the URL path must win
     * over anything a caller puts in the query string or the JSON body under the same
     * name. If the body could override it, a client could send PUT /accounts/5 with a
     * body of {"id": 999} and act on an account it never named in the URL.
     */
    public function testRouteAttributeOverridesJsonBodyAndQueryStringParameterWithTheSameName(): void
    {
        $request = Request::create(
            '/api/v1/accounts/5?id=111',
            'PUT',
            [],
            [],
            [],
            [],
            json_encode(['id' => 222], JSON_THROW_ON_ERROR)
        );
        $request->attributes->set('id', '5');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('5', $apiRequest->get('id'));
    }

    /**
     * A malformed JSON body (a client bug, or a probe) must not blow up request parsing;
     * it should simply contribute nothing, leaving query string parameters intact.
     */
    public function testInvalidJsonBodyIsIgnoredWithoutError(): void
    {
        $request = Request::create('/api/v1/accounts?foo=bar', 'POST', [], [], [], [], '{not valid json');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('bar', $apiRequest->get('foo'));
        self::assertFalse($apiRequest->exists('name'));
    }

    /**
     * An empty body (e.g. a GET-like write with nothing to send) must also be a no-op,
     * not an error.
     */
    public function testEmptyBodyIsIgnored(): void
    {
        $request = Request::create('/api/v1/accounts?foo=bar', 'POST', [], [], [], [], '');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('bar', $apiRequest->get('foo'));
    }

    /**
     * json_decode() happily parses a bare scalar ("42", "true", a quoted string, "null")
     * as valid JSON. None of those are an object, so none of them should ever be merged
     * into the parameter bag.
     */
    #[DataProvider('nonObjectJsonBodyDataProvider')]
    public function testNonObjectJsonBodyIsIgnored(string $body): void
    {
        $request = Request::create('/api/v1/accounts?foo=bar', 'POST', [], [], [], [], $body);

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('bar', $apiRequest->get('foo'));
    }

    /**
     * The query string only ever carries strings — Symfony does not type-juggle it.
     * RestApiRequest does not coerce values either: an "id" of 42 sent as ?id=42 comes
     * back as the string "42". Turning it into an int is the caller's job
     * (see Api::getParamInt()), not this class's.
     */
    public function testQueryStringValueArrivesAsARawStringNotAnInt(): void
    {
        $request = Request::create('/api/v1/accounts?id=42', 'GET');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('42', $apiRequest->get('id'));
    }

    /**
     * A parameter nobody supplied has to come back as the caller-provided default (or
     * null with none given), and exists() must say so — that boolean is exactly what
     * Api::getParam() relies on to reject a call that is missing a required parameter.
     */
    public function testMissingParameterReturnsTheDefaultAndExistsReportsFalse(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertFalse($apiRequest->exists('missing'));
        self::assertNull($apiRequest->get('missing'));
        self::assertSame('fallback', $apiRequest->get('missing', 'fallback'));
    }

    /**
     * Route-matcher internals (e.g. Symfony's own `_route`) and the `_rest_method`
     * attribute the API bootstrap stashes are both underscore-prefixed on purpose so
     * they never leak into the parameter bag a controller reads with get()/exists().
     * getMethod() must still be able to read `_rest_method` directly, independently of
     * that filter.
     */
    public function testUnderscorePrefixedAttributesAreExcludedFromParamsButRestMethodStaysReadable(): void
    {
        $request = Request::create('/api/v1/accounts/5', 'GET');
        $request->attributes->set('_route', 'some_internal_route_name');
        $request->attributes->set('_rest_method', 'account/view');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertFalse($apiRequest->exists('_route'));
        self::assertFalse($apiRequest->exists('_rest_method'));
        self::assertSame('account/view', $apiRequest->getMethod());
    }

    /**
     * getMethod() must not error out when the API bootstrap never set `_rest_method`
     * (e.g. a request that never matched a route) — it degrades to an empty string.
     */
    public function testGetMethodDefaultsToAnEmptyStringWhenRestMethodAttributeIsMissing(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('', $apiRequest->getMethod());
    }

    /**
     * getId() is a fixed 0 regardless of what the route resolved — callers read the
     * resolved id via get('id'), not this method.
     */
    public function testGetIdAlwaysReturnsZero(): void
    {
        $request = Request::create('/api/v1/accounts/5', 'GET');
        $request->attributes->set('id', '5');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame(0, $apiRequest->getId());
    }

    /**
     * The auth token travels in the Authorization: Bearer header, not as an ordinary
     * parameter — this is what lets it be exempted from ordinary parameter validation
     * while still being readable the same way.
     */
    public function testAuthTokenIsExtractedFromTheBearerAuthorizationHeader(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');
        $request->headers->set('Authorization', 'Bearer some-token-value');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('some-token-value', $apiRequest->get('authToken'));
    }

    /**
     * The Bearer scheme name is matched case-insensitively (the regex carries the /i
     * modifier), matching how HTTP scheme tokens are meant to be compared.
     */
    public function testBearerSchemeMatchingIsCaseInsensitive(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');
        $request->headers->set('Authorization', 'bearer some-token-value');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('some-token-value', $apiRequest->get('authToken'));
    }

    /**
     * No Authorization header at all must leave the auth token genuinely absent, not
     * some empty-string stand-in that could slip past a naive check.
     */
    public function testAuthTokenIsAbsentWhenNoAuthorizationHeaderIsSent(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertFalse($apiRequest->exists('authToken'));
        self::assertNull($apiRequest->get('authToken'));
    }

    /**
     * An Authorization header that does not use the Bearer scheme (e.g. Basic auth)
     * must not be picked up as an auth token.
     */
    public function testAuthTokenIsNotExtractedWhenAuthorizationHeaderIsNotBearerScheme(): void
    {
        $request = Request::create('/api/v1/accounts', 'GET');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertFalse($apiRequest->exists('authToken'));
    }

    /**
     * A caller cannot smuggle a forged token past the header by also sending an
     * `authToken` query parameter: when a real Authorization header is present, it is
     * the one and only source that wins.
     */
    public function testAuthorizationHeaderTakesPrecedenceOverAClientSuppliedAuthTokenParameter(): void
    {
        $request = Request::create('/api/v1/accounts?authToken=forged-token', 'GET');
        $request->headers->set('Authorization', 'Bearer real-token');

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertSame('real-token', $apiRequest->get('authToken'));
    }

    /**
     * And it cannot be smuggled in when there is no header either. An authToken in the query
     * string used to survive an absent or malformed Authorization header — nothing was assigned,
     * so whatever the caller had put in the parameter bag stayed there and was handed to the
     * service as the token. It had to match a stored token to be worth anything, so nothing was
     * exploitable by it; the guarantee this class states was simply not one.
     *
     * @throws SPException
     */
    #[DataProvider('unusableAuthorizationHeaderProvider')]
    public function testAClientSuppliedAuthTokenIsNeverUsedWhateverTheHeaderSays(?string $header): void
    {
        $request = Request::create('/api/v1/accounts?authToken=forged-token', 'GET');

        if ($header !== null) {
            $request->headers->set('Authorization', $header);
        }

        $apiRequest = RestApiRequest::buildFromSymfonyRequest($request);

        self::assertNull($apiRequest->get('authToken'));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableAuthorizationHeaderProvider(): array
    {
        return [
            'no header at all' => [null],
            'another scheme' => ['Basic dXNlcjpwYXNz'],
            'Bearer with nothing after it' => ['Bearer '],
            'a bare token without the scheme' => ['real-looking-token'],
        ];
    }
}
