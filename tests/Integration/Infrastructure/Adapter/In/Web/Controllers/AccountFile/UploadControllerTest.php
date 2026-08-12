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
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Covers the browser upload endpoint. The API one was tested; this one was not, and it is the
 * path a user actually takes.
 *
 * The size and type checks are what stop an account's attachments becoming somewhere to put
 * arbitrary files, so each refusal is covered rather than only the success.
 */
#[Group('integration')]
class UploadControllerTest extends IntegrationTestCase
{
    private const ALLOWED_SIZE_KB = 1;

    /** @var string[] */
    private array $tempFiles = [];

    protected function getConfigData(): array
    {
        return array_merge(
            parent::getConfigData(),
            [
                'getFilesAllowedMime' => ['text/plain'],
                'getFilesAllowedSize' => self::ALLOWED_SIZE_KB,
            ]
        );
    }

    /**
     * The endpoint authorises the caller against the account before touching the file, so the
     * account has to resolve for any of the file checks to be reached.
     *
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->addDatabaseMapperResolver(
            AccountView::class,
            new QueryResult([AccountDataGenerator::factory()->buildAccountDataView()])
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPermittedFileIsSaved()
    {
        $this->whenUploading($this->givenAFile('notes.txt', 'Some notes'));

        $this->expectOutputString('{"status":"OK","description":"File saved","data":null}');
    }

    /**
     * A file over the configured limit is refused rather than stored, since the content ends up
     * in the database row.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerOversized')]
    public function anOversizedFileIsRefused()
    {
        $oversized = str_repeat('A', self::ALLOWED_SIZE_KB * 1024 + 1);

        $this->whenUploading($this->givenAFile('big.txt', $oversized));

    }

    /**
     * Content the server identifies as something outside the allow-list is refused, whatever the
     * upload claims it is.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDisallowedType')]
    public function aDisallowedTypeIsRefused()
    {
        $this->whenUploading($this->givenAFile('script.txt', '<?php system($_GET[0]); ?>'));

    }

    /**
     * A request naming no account has nothing to attach the file to.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerInvalidQuery')]
    public function anUploadWithoutAnAccountIsRefused()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'accountFile/upload/0'],
                [],
                ['inFile' => $this->givenAFile('notes.txt', 'Some notes')]
            )
        );

        IntegrationTestCase::runApp($container);

    }

    /**
     * These refusals are raised as exceptions, so the response carries the trace as data; the
     * status and the message are the contract.
     */
    private function outputCheckerOversized(string $output): void
    {
        $this->assertRefusedWith($output, 'File size exceeded');
    }

    private function outputCheckerDisallowedType(string $output): void
    {
        $this->assertRefusedWith($output, 'File type not allowed');
    }

    private function outputCheckerInvalidQuery(string $output): void
    {
        $this->assertRefusedWith($output, 'INVALID QUERY');
    }

    private function assertRefusedWith(string $output, string $message): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame($message, $json->description);
    }

    private function givenAFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        // test: true, so Symfony does not require the file to have come through PHP's upload
        // handling — the controller only reads the path, name and size.
        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenUploading(UploadedFile $file): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'accountFile/upload/100'],
                [],
                ['inFile' => $file]
            )
        );

        IntegrationTestCase::runApp($container);
    }
}
