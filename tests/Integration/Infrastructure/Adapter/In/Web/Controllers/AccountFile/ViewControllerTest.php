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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\AccountFile;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Account\Models\FileList;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers previewing an attachment in the browser.
 *
 * Only a few types can be shown inline, and what happens for the rest is the part worth pinning:
 * an attachment the browser cannot be trusted to render must be refused a preview rather than
 * being handed over inline.
 */
#[Group('integration')]
class ViewControllerTest extends IntegrationTestCase
{
    /**
     * A text attachment is previewed inline.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPreviewed')]
    public function aTextAttachmentIsPreviewed()
    {
        $this->givenAFileOfType('text/plain');

        $this->whenViewing();
    }

    /**
     * Anything outside the small set of previewable types is refused rather than rendered — the
     * attachment could be anything a user uploaded.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotPreviewable')]
    public function anUnsupportedAttachmentIsNotPreviewed()
    {
        $this->givenAFileOfType('application/x-msdownload');

        $this->whenViewing();
    }

    private function givenAFileOfType(string $type): void
    {
        // getById maps to FileList, not File — the listing model carries the joined account
        // name the view shows alongside the attachment.
        $file = new FileList(
            [
                'id' => 100,
                'accountId' => 100,
                'name' => 'attachment',
                'type' => $type,
                'content' => 'some contents',
                'extension' => 'BIN',
                'size' => 13,
            ]
        );

        $account = AccountDataGenerator::factory()->buildAccountDataView();

        // The endpoint authorises against the file's account before reading it, so both have to
        // resolve, and each query is answered by the model it asked for.
        // Not a static closure: the harness binds the resolver with Closure::call(), which a
        // static one cannot accept, and it then silently returns null.
        $this->databaseQueryResolver = function (QueryData $queryData) use ($file, $account): QueryResult {
            return match ($queryData->getMapClassName()) {
                FileList::class => new QueryResult([$file]),
                AccountView::class => new QueryResult([$account]),
                default => new QueryResult([], 1, 100),
            };
        };
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenViewing(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'accountFile/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerPreviewed(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertNotEmpty($json->data->html);
    }

    private function outputCheckerNotPreviewable(string $output): void
    {
        $json = json_decode($output);

        // A warning rather than an error: nothing failed, the attachment simply cannot be shown
        // inline, and the UI offers a download instead.
        self::assertEquals('WARNING', $json->status);
        self::assertSame('File not supported for preview', $json->description);
    }
}
