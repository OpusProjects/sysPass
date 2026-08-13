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
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\User\Dtos\UserDto;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * A user cannot delete the account they are signed in as.
 *
 * The guard exists in the user form, but nothing called it: neither delete controller referenced
 * the form at all, so an administrator could remove their own account — and the last administrator
 * could lock the installation out of its own user management — while the code looked like it
 * prevented exactly that.
 *
 * Both ways in are covered, since a selection must not do what deleting one at a time refuses.
 */
#[Group('integration')]
class SelfDeleteTest extends IntegrationTestCase
{
    private const VIEWER_ID = 100;
    private const SOMEBODY_ELSE_ID = 200;

    /**
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['id' => self::VIEWER_ID]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRefused')]
    public function aUserCannotDeleteTheirOwnAccount()
    {
        $this->whenDeleting(['r' => 'user/delete/' . self::VIEWER_ID]);
    }

    /**
     * Nor by putting themselves in a selection, which is the same operation with a different shape.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRefused')]
    public function aUserCannotDeleteThemselvesAsPartOfASelection()
    {
        $this->whenDeleting(
            ['r' => 'user/delete'],
            ['items' => [self::SOMEBODY_ELSE_ID, self::VIEWER_ID]]
        );
    }

    /**
     * Somebody else is still deletable — otherwise the guard above would be satisfied by an
     * endpoint that refused everything.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDeleted')]
    public function anotherUserIsStillDeletable()
    {
        $this->whenDeleting(['r' => 'user/delete/' . self::SOMEBODY_ELSE_ID]);
    }

    /**
     * And a selection of other people goes through as well.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDeletedMany')]
    public function aSelectionOfOtherUsersIsStillDeletable()
    {
        // The batch delete refuses unless as many rows were removed as ids were given, and the
        // harness answers every statement with one affected row — so the delete is answered with
        // its own count here, otherwise this fails for that reason rather than for the guard.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return str_contains($queryData->getQuery()->getStatement(), 'DELETE')
                ? new QueryResult([], 2)
                : new QueryResult([], 1, 100);
        };

        $this->whenDeleting(['r' => 'user/delete'], ['items' => [self::SOMEBODY_ELSE_ID, 300]]);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenDeleting(array $query, array $post = []): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', $query, $post)
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerRefused(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Unable to delete, user in use', $json->description);
    }

    private function outputCheckerDeleted(string $output): void
    {
        self::assertSame('User deleted', json_decode($output)->description);
    }

    private function outputCheckerDeletedMany(string $output): void
    {
        self::assertSame('Users deleted', json_decode($output)->description);
    }
}
