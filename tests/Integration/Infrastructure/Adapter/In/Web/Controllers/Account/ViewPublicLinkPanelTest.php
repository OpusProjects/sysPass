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
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Account\Ports\AccountAclService;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Item;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * An account that has been published shows its link on the account view, so whoever is looking at
 * it can see the account is reachable without signing in and can copy or withdraw the URL.
 *
 * The panel is only built for a viewer who may publish links, on an instance where they are on —
 * and the link's own hash is what makes the URL work, so it is the hash the page has to carry.
 */
#[Group('integration')]
class ViewPublicLinkPanelTest extends IntegrationTestCase
{
    private const ACCOUNT_ID = 100;
    private const LINK_HASH = 'a1b2c3d4e5f6a1b2c3d4e5f6';

    private bool $publicLinksEnabled = true;
    private bool $hasLink = true;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isPublinksEnabled' => $this->publicLinksEnabled]);
    }

    /**
     * A published account carries the link's own URL, which is the only way to reach it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerShowsTheLink')]
    public function aPublishedAccountShowsItsLink()
    {
        $this->whenViewingTheAccount();
    }

    /**
     * One that has not been published offers the panel without a link in it, rather than a URL that
     * leads nowhere.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerShowsNoLink')]
    public function anUnpublishedAccountShowsNoLink()
    {
        $this->hasLink = false;

        $this->whenViewingTheAccount();
    }

    /**
     * With public links switched off the panel is not built at all — a link that cannot be created
     * is not something to offer, and an old one must not be advertised either.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerShowsNoLink')]
    public function noLinkIsShownWhileTheFeatureIsOff()
    {
        $this->publicLinksEnabled = false;

        $this->whenViewingTheAccount();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenViewingTheAccount(): void
    {
        $account = AccountDataGenerator::factory()->buildAccountDataView();
        $link = new Simple(['id' => 5, 'hash' => self::LINK_HASH, 'userId' => 1]);
        $hasLink = $this->hasLink;

        // The link lookup maps to the plain row every unmapped query does, so it is told apart by
        // the table it reads — resolving every plain-row query would also answer the item presets
        // and the pickers on the same page, and hand each of them a row of the wrong shape.
        // Not a static closure: the harness binds the resolver with Closure::call(), which a static
        // one cannot accept, and it then silently returns null.
        $this->databaseQueryResolver = function (QueryData $queryData) use ($account, $link, $hasLink): QueryResult {
            return match (true) {
                $queryData->getMapClassName() === AccountView::class => new QueryResult([$account]),
                $queryData->getMapClassName() === Item::class => new QueryResult(
                    [new Item(['id' => 1, 'name' => 'an item'])]
                ),
                str_contains($queryData->getQuery()->getStatement(), 'PublicLink') => new QueryResult(
                    $hasLink ? [$link] : []
                ),
                default => new QueryResult([]),
            };
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'account/view/id/' . self::ACCOUNT_ID]
            ),
            $this->withAViewerWhoMayPublish()
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The harness's permission grants the account's own access but never the link flag, which is
     * what the panel is behind — so it is granted here.
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    private function withAViewerWhoMayPublish(): array
    {
        $accountAcl = self::createStub(AccountAclService::class);
        $accountAcl->method('getAcl')->willReturnCallback(
            static fn(int $actionId) => (new AccountPermission($actionId))
                ->setCompiledAccountAccess(true)
                ->setCompiledShowAccess(true)
                ->setResultView(true)
                ->setResultEdit(true)
                ->setShowLink(true)
                ->setShowViewPass(true)
        );

        return [AccountAclService::class => $accountAcl];
    }

    private function outputCheckerShowsTheLink(string $output): void
    {
        self::assertStringContainsString(self::LINK_HASH, $output);
    }

    private function outputCheckerShowsNoLink(string $output): void
    {
        self::assertStringNotContainsString(self::LINK_HASH, $output);
    }
}
