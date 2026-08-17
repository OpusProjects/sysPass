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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SP\Infrastructure\Adapter\In\Web\Init;

/**
 * `Init::PARTIAL_INIT` is the web entry point's list of controllers that answer without a session.
 *
 * It reads like a convenience — "these don't need full initialization" — and it is not. Partial
 * initialization skips the install check, the database checks, the maintenance and upgrade checks,
 * the CSRF check, and `initUserSession()`. A controller on this list therefore answers **anybody**,
 * with no session to consult about who they are, so no authorization rule expressed in terms of the
 * signed-in user can apply to it. The list has to be short, and every entry has to have a reason
 * that is about the request arriving before there is anything to initialize.
 *
 * The two status controllers were on it. Both fetch a URL from a third-party service on request,
 * and the rule about who may do that lived only in `GetEnvironmentController`, where it decided
 * what the browser was offered. So an unauthenticated caller could make the server open an outbound
 * connection and hold a worker until the far end answered, as often as they liked.
 *
 * These assertions do not re-derive the policy; they make somebody state it. Adding a controller
 * here means editing this file and writing down why it cannot have a session — which is where the
 * cost of skipping every guard above becomes visible.
 */
#[Group('unitary')]
class PartialInitIsLimitedTest extends TestCase
{
    /**
     * Every controller that answers without a session, and why it has to.
     *
     * @return array<class-string, string>
     */
    private static function reasons(): array
    {
        return [
            'SP\Infrastructure\Adapter\In\Web\Controllers\Resource\CssController' =>
                'Serves the stylesheet, which the login page itself needs before anyone is signed in',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Resource\JsController' =>
                'Serves the scripts, needed on the same pages for the same reason',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Install\InstallController' =>
                'Performs the installation, so it runs when there is no database and no user to be',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Install\IndexController' =>
                'Shows the installer, before the application exists',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Install\CheckConnectionController' =>
                'Tests the database credentials being typed into the installer, before they are saved',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Bootstrap\GetEnvironmentController' =>
                'Hands the browser its configuration on every page including the login page; it '
                . 'reads the session when there is one, and tells an anonymous caller nothing',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Upgrade\IndexController' =>
                'Shows the upgrade page, which is reached precisely because the schema is too old '
                . 'to initialize against',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Upgrade\UpgradeController' =>
                'Performs that upgrade, for the same reason',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Error\DatabaseConnectionController' =>
                'Explains that the database is unreachable, so it cannot need the database',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Error\DatabaseErrorController' =>
                'Explains that the schema is wrong, likewise',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Error\MaintenanceErrorController' =>
                'Explains that the instance is in maintenance, which is the state that redirected here',
            'SP\Infrastructure\Adapter\In\Web\Controllers\Error\IndexController' =>
                'Shows an error page, which must render whatever else has failed',
        ];
    }

    /**
     * The list is exactly the controllers above — no more, and no fewer.
     */
    #[Test]
    public function everyControllerThatSkipsInitializationHasAStatedReason(): void
    {
        $undocumented = array_diff(self::partialInit(), array_keys(self::reasons()));

        self::assertSame(
            [],
            array_values($undocumented),
            'These controllers answer with no session, no CSRF check and no install or database '
            . 'checks, and nothing here says why they have to. Add the reason to reasons(), or take '
            . 'them off Init::PARTIAL_INIT.'
        );

        $stale = array_diff(array_keys(self::reasons()), self::partialInit());

        self::assertSame(
            [],
            array_values($stale),
            'These are described here as skipping initialization but no longer do. Remove them.'
        );
    }

    /**
     * Nothing that answers without a session may make the application call out to a third party.
     *
     * This is the specific shape the status endpoints had. An outbound request is the one thing a
     * request can ask this server to do that costs it a connection and a worker for as long as
     * somebody else's service takes to answer, and on a partially-initialized route there is no
     * session, so there is no way to say who is allowed to ask for it.
     */
    #[Test]
    public function nothingThatAnswersWithoutASessionCanMakeTheServerCallOut(): void
    {
        $callers = [];

        foreach (self::partialInit() as $controller) {
            $constructor = (new ReflectionClass($controller))->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType
                    && !$type->isBuiltin()
                    && is_a($type->getName(), ClientInterface::class, true)
                ) {
                    $callers[] = $controller;
                }
            }
        }

        self::assertSame(
            [],
            $callers,
            'An HTTP client reaches a controller that answers without a session, so an '
            . 'unauthenticated caller can make this server open an outbound connection'
        );
    }

    /**
     * Every entry names a class that exists.
     *
     * A renamed or mistyped entry is not a failure anybody sees: `in_array()` simply stops matching,
     * the controller quietly starts taking the full initialization, and the first symptom is the
     * installer redirecting to itself on an instance that is not installed.
     */
    #[Test]
    public function everyEntryNamesAControllerThatExists(): void
    {
        foreach (self::partialInit() as $controller) {
            self::assertTrue(
                class_exists($controller),
                sprintf('Init::PARTIAL_INIT names %s, which does not exist', $controller)
            );
        }
    }

    /**
     * @return list<class-string>
     */
    private static function partialInit(): array
    {
        /** @var list<class-string> $value */
        $value = (new ReflectionClass(Init::class))->getConstant('PARTIAL_INIT');

        return $value;
    }
}
