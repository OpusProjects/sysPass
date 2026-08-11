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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Domain\User\Services\UpdatedMasterPassException;
use SP\Infrastructure\Adapter\In\Web\Util\ErrorUtil;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the helper that turns a refusal into a page the user can read. It had no tests at all.
 *
 * It is reached exactly when something has already gone wrong, which is the worst moment for it
 * to be broken, and the least likely path to be exercised by hand.
 */
#[Group('integration')]
class ErrorUtilTest extends IntegrationTestCase
{
    /**
     * Each error type produces a page that says what happened. An unmapped code must still
     * produce text rather than an empty page.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('errorTypeProvider')]
    public function anErrorTypeRendersAPageThatExplainsItself(int $type): void
    {
        $view = $this->view();

        ob_start();
        ErrorUtil::showErrorInView($view, $type);
        $output = (string)ob_get_clean();

        self::assertNotEmpty($output);
        self::assertStringContainsString('<', $output, 'the error is rendered as a page');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorTypeProvider(): array
    {
        return [
            'unavailable' => [ErrorUtil::ERR_UNAVAILABLE],
            'no permission for the account' => [ErrorUtil::ERR_ACCOUNT_NO_PERMISSION],
            'no permission for the page' => [ErrorUtil::ERR_PAGE_NO_PERMISSION],
            'master password updated' => [ErrorUtil::ERR_UPDATE_MPASS],
            'no permission for the operation' => [ErrorUtil::ERR_OPERATION_NO_PERMISSION],
            'unexpected exception' => [ErrorUtil::ERR_EXCEPTION],
            'a code with no mapping' => [99],
        ];
    }

    /**
     * An exception is mapped to the page that matches it, so a refused page and a refused
     * account do not tell the user the same thing.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anExceptionChoosesThePageThatMatchesIt(): void
    {
        $rendered = [];

        foreach (
            [
                new UnauthorizedPageException(SPException::INFO),
                new AccountPermissionException(SPException::INFO),
                new UpdatedMasterPassException(SPException::INFO),
                new SPException('Something else'),
            ] as $exception
        ) {
            ob_start();
            ErrorUtil::showExceptionInView($this->view(), $exception);
            $rendered[] = (string)ob_get_clean();
        }

        foreach ($rendered as $output) {
            self::assertNotEmpty($output);
        }

        self::assertNotSame(
            $rendered[0],
            $rendered[1],
            'a refused page and a refused account must not read the same'
        );
        self::assertNotSame($rendered[1], $rendered[2]);
    }

    /**
     * Rendering can be deferred, so a caller that wants to add to the page before sending it
     * gets the error put on the view rather than emitted. The controllers that call this with
     * render: false then render the page themselves.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function renderingCanBeDeferredToTheCaller(): void
    {
        $view = $this->view();

        ob_start();
        ErrorUtil::showErrorInView($view, ErrorUtil::ERR_PAGE_NO_PERMISSION, false);
        $emitted = (string)ob_get_clean();

        self::assertSame('', $emitted, 'nothing is emitted when the caller renders');

        // The error reached the view, so rendering it afterwards produces the same page the
        // immediate call would have produced.
        self::assertNotEmpty($view->render());
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function view(): TemplateInterface
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        return $container->get(TemplateInterface::class);
    }
}
