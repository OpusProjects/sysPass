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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Account\Models\AccountSearchView;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserPreferences;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Where the account listing gets the filter it searches with.
 *
 * Reaching the listing from the menu is supposed to bring back the search the user last ran, and a
 * request built from an empty query string would silently widen it back to everything — so which of
 * the two the listing uses is the part worth pinning.
 */
#[Group('integration')]
class SearchFiltersTest extends IntegrationTestCase
{
    private const ACCOUNT_NAME = 'an-account';

    private ?AccountSearchFilterDto $savedFilter = null;
    private ?int                    $resultsPerPageOverride = null;
    private ?int                    $accountCountOverride = null;

    protected function getContext(): SessionContext|Stub
    {
        $context = parent::getContext();
        $context->method('getSearchFilters')->willReturn($this->savedFilter);

        return $context;
    }

    protected function getUserDataDto(): UserDto
    {
        $userDto = parent::getUserDataDto();

        if ($this->resultsPerPageOverride === null) {
            return $userDto;
        }

        $preferences = ($userDto->preferences ?? new UserPreferences())
            ->mutate(['resultsPerPage' => $this->resultsPerPageOverride]);

        return $userDto->mutate(['preferences' => $preferences]);
    }

    protected function getConfigData(): array
    {
        $data = parent::getConfigData();

        if ($this->accountCountOverride !== null) {
            $data['getAccountCount'] = $this->accountCountOverride;
        }

        return $data;
    }

    /**
     * Reaching the listing from the menu brings back the search the user last ran, rather than
     * starting again from an empty query string — which would widen the listing back to everything
     * without the user asking.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSearchTermIsBack')]
    public function theListingReachedFromTheMenuKeepsTheLastSearch()
    {
        $this->savedFilter = $this->savedFilterFor('the-saved-term');

        $this->whenOpeningTheListing();
    }

    /**
     * A saved filter is only restored for the listing itself. A search request carries its own
     * terms, so it must not be answered with the previous ones.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSearchTermIsNotBack')]
    public function aSearchRequestIsNotAnsweredWithTheSavedFilter()
    {
        $this->savedFilter = $this->savedFilterFor('the-saved-term');

        $this->whenSearchingFor('a-new-term');
    }

    /**
     * A user who never set a "results per page" preference is not left with an unbounded (or
     * zero-row) listing: the page size falls back to the site-wide default. That number is what
     * actually caps how many accounts show on the page, so it has to be the configured one, not
     * whatever a zero preference would otherwise produce.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theListingFallsBackToTheSiteDefaultPageSizeWhenTheUserHasNoPreference()
    {
        $this->resultsPerPageOverride = 0;
        $this->accountCountOverride = 4;

        $this->givenAnAccountToList();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'account/index'])
        );

        IntegrationTestCase::runApp($container);

        // The pager renders "<first page> / <last page> (<page size>)" — the page size shown to
        // the user has to be the site default, not a zero-row page.
        $this->expectOutputRegex('/\d+ \/ \d+ \(4\)/');
    }

    /**
     * A filter as the listing itself saves one: every part set, the way it is built from a request.
     */
    private function savedFilterFor(string $term): AccountSearchFilterDto
    {
        $filter = AccountSearchFilterDto::build($term);
        $filter->setTagsId([]);
        $filter->setLimitCount(25);

        return $filter;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenOpeningTheListing(): void
    {
        $this->givenAnAccountToList();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'account/index']),
            $this->withRealRoutes()
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The listing decides it was reached from the menu by comparing the request's route with the
     * one the ACL holds for the accounts action. The harness answers getRouteFor() with the action
     * id, so the real route is put back for that one action — otherwise the comparison can never be
     * true and the saved filter is dead in every test.
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    private function withRealRoutes(): array
    {
        $acl = self::createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);
        $acl->method('getRouteFor')->willReturnCallback(
            static fn(int $actionId) => $actionId === AclActionsInterface::ACCOUNT
                ? 'account/index'
                : (string)$actionId
        );

        return [AclInterface::class => $acl];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenSearchingFor(string $term): void
    {
        $this->givenAnAccountToList();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'account/search'],
                ['search' => $term]
            )
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws Exception
     */
    private function givenAnAccountToList(): void
    {
        $this->addDatabaseMapperResolver(
            AccountSearchView::class,
            QueryResult::withTotalNumRows(
                [AccountDataGenerator::factory()->buildAccountSearchView()->mutate(['name' => self::ACCOUNT_NAME])],
                1
            )
        );
    }

    private function outputCheckerSearchTermIsBack(string $output): void
    {
        self::assertStringContainsString('the-saved-term', $output);
    }

    private function outputCheckerSearchTermIsNotBack(string $output): void
    {
        self::assertStringNotContainsString('the-saved-term', $output);
    }

}
