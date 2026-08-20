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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * A parameter sent with the wrong JSON type is a bad request, on every reader.
 *
 * The API's transport is JSON, so a client decides each parameter's type, and three of the four
 * readers handed whatever arrived straight to a typed function: `Filter::getInt(int|string)`,
 * `Filter::getString(?string)`, and `getParamRaw()`'s own `?string` return. `{"name": 123}` was
 * therefore an uncaught `TypeError` — HTTP 500, with the class, the method and the server's
 * absolute path in the body — and every string and integer parameter on every endpoint could be
 * made to do it. The same values sent through a query string were fine, since everything arrives
 * as a string there, so the transport decided whether a request crashed.
 *
 * `getParamArray()` always answered this correctly; these assert the other three now do too.
 */
#[Group('integration')]
class ParameterTypesTest extends ApiTestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringProvider(): array
    {
        return [
            'int' => [123],
            'float' => [1.5],
            'bool' => [true],
            'array' => [['a', 'b']],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonIntProvider(): array
    {
        return [
            'bool' => [true],
            'float' => [1.5],
            'array' => [[1]],
        ];
    }

    /**
     * `name` is read with getParamString().
     */
    #[Test]
    #[DataProvider('nonStringProvider')]
    public function aStringParameterOfTheWrongTypeIsRefused(mixed $value): void
    {
        $r = $this->callApi(AclActionsInterface::CATEGORY_CREATE, ['name' => $value]);

        $this->assertBadRequest($r);
    }

    /**
     * `userGroupId` is read with getParamInt(). Every other required parameter is supplied, so the
     * type is the only thing under test — without that the refusal would come from the missing
     * `pass` and this would pass with the bug still in place.
     */
    #[Test]
    #[DataProvider('nonIntProvider')]
    public function anIntegerParameterOfTheWrongTypeIsRefused(mixed $value): void
    {
        $r = $this->callApi(
            AclActionsInterface::USER_CREATE,
            [
                'name' => 'a user',
                'login' => 'a_user',
                'pass' => 'a-provisioned-password',
                'userGroupId' => $value,
                'userProfileId' => 1,
            ]
        );

        $this->assertBadRequest($r);
    }

    /**
     * `password` is read with getParamRaw(), which returned the value unconverted from a `?string`
     * method — so this one failed on the way out rather than on the way in.
     */
    #[Test]
    #[DataProvider('nonStringProvider')]
    public function aRawParameterOfTheWrongTypeIsRefused(mixed $value): void
    {
        $r = $this->callApi(
            AclActionsInterface::AUTHTOKEN_CREATE,
            [
                'userId' => 1,
                'actionId' => AclActionsInterface::CATEGORY_SEARCH,
                'password' => $value,
            ]
        );

        $this->assertBadRequest($r);
    }

    /**
     * The reader that was always right, kept here so the rule is asserted as one rule.
     */
    #[Test]
    public function anArrayParameterOfTheWrongTypeIsRefused(): void
    {
        $r = $this->callApi(
            AclActionsInterface::ACCOUNT_CREATE,
            [
                'name' => 'an account',
                'categoryId' => 1,
                'clientId' => 1,
                'pass' => 'a-password',
                'tagsId' => 'not-an-array',
            ]
        );

        $this->assertBadRequest($r);
    }

    /**
     * The control. Every refusal above would be satisfied by an endpoint that had simply stopped
     * accepting anything, so the same call with the right types has to still work.
     */
    #[Test]
    public function wellTypedParametersAreStillAccepted(): void
    {
        $r = $this->callApi(
            AclActionsInterface::ACCOUNT_CREATE,
            [
                'name' => 'an account',
                'categoryId' => 1,
                'clientId' => 1,
                'pass' => 'a-password',
                'tagsId' => [],
            ]
        );

        $this->assertSame(201, $r->status);
        $this->assertSame('Account created', $r->body->message);
    }

    /**
     * Both halves: the status is a bad request, and the body carries the API's own refusal rather
     * than a PHP error. Asserting only the status would pass on a 400 that still leaked the path.
     */
    private function assertBadRequest(stdClass $response): void
    {
        $this->assertSame(400, $response->status);
        $this->assertInstanceOf(stdClass::class, $response->body->error ?? null);
        $this->assertSame('Wrong parameters', $response->body->error->message);
        $this->assertStringNotContainsString('/var/www', json_encode($response->body));
        $this->assertStringNotContainsString('TypeError', json_encode($response->body));
    }
}
