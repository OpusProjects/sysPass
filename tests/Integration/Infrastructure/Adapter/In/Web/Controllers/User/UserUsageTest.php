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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\User;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Looking at a user shows what would break if they were removed — the accounts they own, the groups
 * they are in, the public links they published — each with an icon for its kind.
 *
 * The panel is only built for the read-only view, and the icon is chosen per kind, so a kind the
 * mapping does not know has to fall back to something rather than rendering a row with no icon at
 * all.
 */
#[Group('integration')]
class UserUsageTest extends IntegrationTestCase
{
    /**
     * Each kind of use gets its own icon.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIcons')]
    public function eachKindOfUseGetsItsOwnIcon()
    {
        $this->whenViewingAUserUsedBy(
            [
                ['ref' => 'Account', 'name' => 'An account', 'id' => 1],
                ['ref' => 'UserGroup', 'name' => 'A group', 'id' => 2],
                ['ref' => 'PublicLink', 'name' => 'A link', 'id' => 3],
                ['ref' => 'AccountHistory', 'name' => 'A retired account', 'id' => 4],
                ['ref' => 'Notification', 'name' => 'A notification', 'id' => 5],
            ]
        );
    }

    /**
     * A kind the mapping does not know still gets an icon, so the row is not rendered blank.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerFallbackIcon')]
    public function anUnknownKindOfUseFallsBackToAGenericIcon()
    {
        $this->whenViewingAUserUsedBy([['ref' => 'Something', 'name' => 'Something else', 'id' => 4]]);
    }

    /**
     * A user who is used by nothing shows the panel empty rather than failing to render it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNoUsage')]
    public function aUserUsedByNothingStillRenders()
    {
        $this->whenViewingAUserUsedBy([]);
    }

    /**
     * @param array<int, array<string, mixed>> $usage
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenViewingAUserUsedBy(array $usage): void
    {
        $user = UserDataGenerator::factory()->buildUserData()->mutate(['id' => 100, 'login' => 'someone']);

        $rows = array_map(static fn(array $row) => new Simple($row), $usage);

        // The usage query is the only one that asks for the ref column, so it is told apart by that
        // rather than by its model — it maps to the plain row every unmapped query does.
        // Not a static closure: the harness binds the resolver with Closure::call(), which a static
        // one cannot accept, and it then silently returns null.
        $this->databaseQueryResolver = function (QueryData $queryData) use ($user, $rows): QueryResult {
            if ($queryData->getMapClassName() === User::class) {
                return new QueryResult([$user]);
            }

            return str_contains($queryData->getQuery()->getStatement(), 'ref')
                ? new QueryResult($rows)
                : new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerIcons(string $output): void
    {
        $html = $this->renderedPage($output);

        self::assertStringContainsString('mdl-list__item-icon">description</i>', $html, 'an account');
        self::assertStringContainsString('mdl-list__item-icon">group</i>', $html, 'a group');
        self::assertStringContainsString('mdl-list__item-icon">link</i>', $html, 'a public link');
        self::assertStringContainsString('mdl-list__item-icon">history</i>', $html, "an account's history");
        self::assertStringContainsString('mdl-list__item-icon">notifications</i>', $html, 'a notification');
    }

    private function outputCheckerFallbackIcon(string $output): void
    {
        self::assertStringContainsString('mdl-list__item-icon">info_outline</i>', $this->renderedPage($output));
    }

    private function outputCheckerNoUsage(string $output): void
    {
        $html = $this->renderedPage($output);

        self::assertStringContainsString('Used in', $html);
        self::assertStringNotContainsString('mdl-list__item-icon', $html);
    }

    /**
     * The view comes back as the html field of a json response, so the page is read out of it
     * rather than out of the escaped body.
     */
    private function renderedPage(string $output): string
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        return $json->data->html;
    }
}
