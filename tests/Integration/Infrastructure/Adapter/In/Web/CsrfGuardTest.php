<?php
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

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Context\SessionContext;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Class CsrfGuardTest
 *
 * Covers {@see \SP\Infrastructure\Crypt\Csrf::check()} the way a real request actually exercises
 * it, through {@see \SP\Infrastructure\Adapter\In\Web\Init::initialize()} — not in isolation like
 * {@see \SP\Tests\Unit\Infrastructure\Crypt\CsrfTest}.
 *
 * {@see IntegrationTestCase::getContext()}'s stub answers getCSRF() with null, and Csrf::check()
 * only enforces anything once the session actually holds a token — so, as things stand, every
 * other POST in the integration suite sails straight past the guard without carrying one. This
 * class is the one place that puts a token in the session so the guard itself gets dispatched
 * against, targeting category/saveCreate: a real state-changing route whose effect (an INSERT
 * against the Category table) can be observed directly rather than inferred from a status code.
 */
#[Group('integration')]
class CsrfGuardTest extends IntegrationTestCase
{
    private const WRONG_TOKEN   = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private const REFUSAL_BODY = '{"status":"ERROR","description":"Invalid request token","data":null}';
    private const SUCCESS_BODY = '{"status":"OK","description":"Category added","data":null}';

    /** Set true by {@see watchForCategoryInsert()} the moment the save's INSERT is actually issued. */
    private bool $categoryInsertHappened = false;

    /**
     * Same stub the base harness builds, plus a fixed CSRF token in the session — the one thing
     * IntegrationTestCase's own getContext() deliberately leaves null.
     *
     * @throws Exception
     */
    protected function getContext(): SessionContext|Stub
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn(true);
        $context->method('getAuthCompleted')->willReturn(true);
        $context->method('getUserData')->willReturn($this->getUserDataDto());
        $context->method('getUserProfile')->willReturn($this->getUserProfile());
        $context->method('getCSRF')->willReturn(self::CSRF_TOKEN);

        return $context;
    }

    /**
     * buildRequest() has no header parameter — a header is set by merging it into $_SERVER before
     * the request is built. The harness's Request bag is shared process-wide, so whatever this
     * test sets has to come back out again regardless of how the test finished.
     */
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CSRF'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        parent::tearDown();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function postWithoutTheCsrfHeaderIsRefused(): void
    {
        $this->watchForCategoryInsert();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'category/saveCreate'],
                self::categoryFields(),
                csrfToken: null
            )
        );

        IntegrationTestCase::runApp($container);

        $this->assertRefused($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function postWithTheWrongCsrfTokenIsRefused(): void
    {
        $this->watchForCategoryInsert();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'category/saveCreate'],
                self::categoryFields(),
                csrfToken: self::WRONG_TOKEN
            )
        );

        IntegrationTestCase::runApp($container);

        $this->assertRefused($container);
    }

    /**
     * The counterpart to the two refusals above: without this, a guard that refused every request
     * unconditionally would also make them pass.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function postWithTheRightCsrfTokenIsServed(): void
    {
        $this->watchForCategoryInsert();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'category/saveCreate'],
                self::categoryFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(self::SUCCESS_BODY);

        self::assertTrue($this->categoryInsertHappened, 'a valid token must let the save go through');
    }

    /**
     * Csrf::check() treats a GET carrying X-Requested-With: XMLHttpRequest the same as a POST —
     * this is the branch that would go unnoticed if only the HTTP method were varied.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function getWithXmlHttpRequestHeaderIsSubjectToTheCheckToo(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $this->watchForCategoryInsert();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                array_merge(['r' => 'category/saveCreate'], self::categoryFields()),
                csrfToken: null
            )
        );

        IntegrationTestCase::runApp($container);

        $this->assertRefused($container);
    }

    /**
     * A plain GET — no X-Requested-With, no CSRF header at all — is not covered by the check,
     * regardless of the session holding a token. The save goes through exactly as it would for a
     * signed-in user simply following a link.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPlainGetIsNotSubjectToTheCheck(): void
    {
        $this->watchForCategoryInsert();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                array_merge(['r' => 'category/saveCreate'], self::categoryFields())
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(self::SUCCESS_BODY);

        self::assertTrue($this->categoryInsertHappened, 'a plain GET must not be blocked by the CSRF guard');
    }

    /**
     * Asserts the shape a refusal takes: Init's InitializationException is caught by Bootstrap's
     * generic handler (nothing about a CSRF failure sends its own response first, unlike the
     * not-installed/maintenance guards), so it becomes a 500 carrying category/saveCreate's own
     * JSON action contract — and, critically, that the category was never actually created.
     */
    private function assertRefused(ContainerInterface $container): void
    {
        /** @var ResponseService $responseService */
        $responseService = $container->get(ResponseService::class);
        $response = $responseService->getResponse();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(self::REFUSAL_BODY, $response->getContent());
        self::assertFalse(
            $this->categoryInsertHappened,
            'a refused request must never reach the category creation itself'
        );
    }

    /**
     * Registers a resolver that flags {@see $categoryInsertHappened} the moment the save's INSERT
     * against the Category table is issued, distinguishing it from the SELECT the same create()
     * call issues first (checking for a duplicate name) and from whatever other queries Init's own
     * guards run ahead of the controller (session/item-preset lookups). Every other query on the
     * request is answered with the harness' generic default, exactly as when no resolver is set at
     * all, so nothing about the fixture's behaviour changes beyond the observation itself.
     *
     * The resolver closure is rebound to this test case by the harness (it is invoked via
     * Closure::call($this, ...)), so referencing $this here reaches the running test regardless of
     * it being a non-static closure defined in this method.
     */
    private function watchForCategoryInsert(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'INSERT') && str_contains($statement, 'Category')) {
                $this->categoryInsertHappened = true;
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * @return array<string, string>
     */
    private static function categoryFields(): array
    {
        return [
            'name' => self::$faker->name(),
            'description' => self::$faker->text(),
        ];
    }
}
