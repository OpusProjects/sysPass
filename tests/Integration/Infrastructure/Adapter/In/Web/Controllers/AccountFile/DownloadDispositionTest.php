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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Account\Models\FileList;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The name an attachment is downloaded under.
 *
 * A file's name is whoever uploaded it's — through the API it is a plain request parameter, and
 * `Filter::getString()` passes quotes through untouched (`ENT_NOQUOTES`) — and it reaches the
 * browser as the `filename` of the download. It used to be interpolated straight into
 * `filename="…"`, so a name could close the quoted string and append parameters of its own. That
 * matters because `filename*=` wins over `filename=` in every browser that implements RFC 6266: an
 * attachment listed in the account as one thing downloaded as another, which in a password manager
 * is a file shared between people who trust the listing.
 *
 * What is asserted here is the shape of the header rather than one exact string: the name must not
 * appear raw inside it, and the parameters a browser reads must be the ones the application meant
 * to send.
 */
#[Group('integration')]
class DownloadDispositionTest extends IntegrationTestCase
{
    private const CONTENT = 'the bytes of the attachment';

    /**
     * A name that ends the quoted string and adds an RFC 5987 parameter naming a different file.
     * With the old header a browser saved this as `payload.exe`.
     */
    private const HOSTILE = 'invoice.pdf"; filename*=UTF-8\'\'payload.exe';

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aNameCannotAppendParametersOfItsOwn(): void
    {
        $disposition = $this->downloadNamed(self::HOSTILE);

        // One `filename=` and one `filename*=`, both the application's own. The hostile text
        // survives only inside a quoted string or percent-encoded, never as header syntax.
        self::assertSame(1, substr_count($disposition, 'filename='), $disposition);
        self::assertSame(1, substr_count($disposition, 'filename*='), $disposition);
        self::assertStringNotContainsString('UTF-8\'\'payload.exe', $disposition, $disposition);
        self::assertStringStartsWith('attachment; ', $disposition);
    }

    /**
     * The ordinary case still says what it always said, so the fix is not just an escape hatch:
     * a plain name goes out as itself.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anOrdinaryNameIsUnchanged(): void
    {
        self::assertSame('attachment; filename=invoice.pdf', $this->downloadNamed('invoice.pdf'));
    }

    /**
     * A PDF is shown in the browser rather than saved, which is the one behaviour the old header
     * had that had to survive being rewritten.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPdfIsStillShownInline(): void
    {
        self::assertSame(
            'inline; filename=report.pdf',
            $this->downloadNamed('report.pdf', 'application/pdf')
        );
    }

    /**
     * A name the header cannot carry as-is must still produce a valid header. A path separator is
     * refused outright by the encoder — and a download is one file whatever its name claims — while
     * a name that is empty, or has nothing left after being reduced to ASCII, has to fall back to
     * something rather than emit an empty `filename=`.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('namesTheHeaderCannotCarry')]
    public function aNameTheHeaderCannotCarryStillProducesAValidOne(string $name, string $expected): void
    {
        self::assertSame($expected, $this->downloadNamed($name));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namesTheHeaderCannotCarry(): array
    {
        return [
            'a path' => ['../../etc/passwd', 'attachment; filename=.._.._etc_passwd'],
            'a windows path' => ['..\\secrets.txt', 'attachment; filename=.._secrets.txt'],
            'no name at all' => ['', 'attachment; filename=file'],
            'only spaces' => ['   ', 'attachment; filename=file'],
            'nothing ASCII' => ['…', 'attachment; filename=file; filename*=utf-8\'\'%E2%80%A6'],
            'accented' => [
                'año.pdf',
                'attachment; filename=a__o.pdf; filename*=utf-8\'\'a%C3%B1o.pdf',
            ],
        ];
    }

    /**
     * Dispatch a real download of a file with the given name and hand back the
     * Content-Disposition the application answered with.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function downloadNamed(string $name, string $type = 'text/plain'): string
    {
        $this->addDatabaseMapperResolver(
            FileList::class,
            new QueryResult([
                FileList::buildFromSimpleModel(
                    new Simple([
                        'id' => 100,
                        'accountId' => 1,
                        'name' => $name,
                        'type' => $type,
                        'content' => self::CONTENT,
                        'extension' => 'TXT',
                        'thumb' => 'no_thumb',
                        'size' => strlen(self::CONTENT),
                    ])
                ),
            ])
        );

        // The download guard resolves the owning account to check view access; the harness's
        // AccountAclService grants it.
        $this->addDatabaseMapperResolver(
            AccountView::class,
            new QueryResult([AccountDataGenerator::factory()->buildAccountDataView()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'accountFile/download/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(self::CONTENT);

        return (string)$container->get(ResponseService::class)->headers()->get('Content-Disposition');
    }
}
