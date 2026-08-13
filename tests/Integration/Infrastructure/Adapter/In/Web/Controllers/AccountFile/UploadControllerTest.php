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
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Events\EventReceiver;
use SP\Domain\Core\Messages\TextFormatter;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\IntegrationTestCase;
use stdClass;
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

    /** @var string[] The MIME types the installation accepts */
    private array $allowedMime = ['text/plain'];

    /** @var string[] */
    private array $tempFiles = [];

    private AccountView $account;

    protected function getConfigData(): array
    {
        return array_merge(
            parent::getConfigData(),
            [
                'getFilesAllowedMime' => $this->allowedMime,
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

        $this->account = AccountDataGenerator::factory()->buildAccountDataView();

        $this->addDatabaseMapperResolver(AccountView::class, new QueryResult([$this->account]));
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
     * An installation that accepts no MIME types accepts no attachments. Without this check the
     * allow-list would be empty and every type would resolve against nothing.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNoAllowedTypes')]
    public function anInstallationWithNoAllowedTypesRefusesEverything()
    {
        $this->allowedMime = [];

        $this->whenUploading($this->givenAFile('notes.txt', 'Some notes'));
    }

    /**
     * A file with no name is refused rather than stored under an empty one, which would give the
     * attachment no extension to be served with and nothing for the listing to show.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerInvalidFile')]
    public function aFileWithoutANameIsRefused()
    {
        $this->whenUploading($this->givenAFile('', 'Some notes'));
    }

    /**
     * A temporary file that turns out to be unreadable once the type/size checks have passed
     * (a permissions change, a filesystem hiccup) must not let the underlying file error — which
     * would carry the server path — reach the client. The controller reports a generic internal
     * error instead. Simulated with a stream wrapper that reports the file as unreadable, since
     * these tests run as root, which ignores real permission bits.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerInternalFileError')]
    public function aFileThatCannotBeReadReportsAnInternalErrorInsteadOfTheFileDetails()
    {
        $this->whenUploading($this->givenAnUnreadableFile('notes.txt', 'Some notes'));
    }

    /**
     * The event a successful upload notifies names the file, the account, the client, the MIME
     * type and the size — and the EventMessage is built lazily inside a closure, so nothing runs
     * it unless something actually formats it (as a real log listener would). If building it
     * raised, an otherwise-successful upload's log entry would break.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aSuccessfulUploadRecordsAnEventNamingTheFileAccountClientTypeAndSize()
    {
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'accountFile/upload/100'],
                [],
                ['inFile' => $this->givenAFile('notes.txt', 'Some notes')]
            ),
            [EventDispatcherInterface::class => $eventDispatcher]
        );

        IntegrationTestCase::runApp($container);

        $uploadEvents = array_values(
            array_filter(
                $notified->value,
                static fn(Event $event): bool => $event->getName() === 'upload.accountFile'
            )
        );

        self::assertCount(1, $uploadEvents);

        // Formatting the message is what actually runs the closure the controller notified with.
        $message = $uploadEvents[0]->getEventMessage()->getDetails(new TextFormatter(), false);

        self::assertStringContainsString('File: notes.txt', $message);
        self::assertStringContainsString('Account: ' . $this->account->getName(), $message);
        self::assertStringContainsString('Client: ' . $this->account->getClientName(), $message);
        self::assertStringContainsString('Type: text/plain', $message);
        self::assertStringContainsString('Size: ', $message);
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

    private function outputCheckerNoAllowedTypes(string $output): void
    {
        $this->assertRefusedWith($output, 'There aren\'t any allowed MIME types');
    }

    private function outputCheckerInvalidFile(string $output): void
    {
        $this->assertRefusedWith($output, 'Invalid file');
    }

    private function outputCheckerInternalFileError(string $output): void
    {
        $this->assertRefusedWith($output, 'Internal error while reading the file');
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
     * A file that opens fine (so the size/name checks and the FileHandler construction succeed)
     * but is reported unreadable once FileHandler::checkIsReadable() checks it — the branch that
     * raises FileException and is caught by the controller.
     */
    private function givenAnUnreadableFile(string $name, string $contents): UploadedFile
    {
        $realPath = tempnam(sys_get_temp_dir(), 'unreadable');
        file_put_contents($realPath, $contents);

        $this->tempFiles[] = $realPath;

        return new UploadedFile(UnreadableFileStreamWrapper::urlFor($realPath), $name, 'text/plain', null, true);
    }

    /**
     * A real EventDispatcher with a receiver attached that records every notified event, so a
     * test can inspect the event name and its (lazily built) message. EventDispatcher is final
     * and ControllerBase declares its $eventDispatcher property with the interface, but that
     * interface can't be doubled here either without losing the real notify()/receiver
     * mechanics the test relies on, so a real instance with a recording receiver is used instead.
     *
     * @return array{0: EventDispatcherInterface, 1: stdClass}
     */
    private function spyingEventDispatcher(): array
    {
        $captured = new stdClass();
        $captured->value = [];

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->attach(
            new class ($captured) implements EventReceiver {
                public function __construct(private readonly stdClass $captured)
                {
                }

                public function update(Event $event): void
                {
                    $this->captured->value[] = $event;
                }

                public function getEvents(): ?string
                {
                    return '*';
                }
            }
        );

        return [$eventDispatcher, $captured];
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

/**
 * Mirrors a real temp file for every stream operation except url_stat(), which reports it as
 * unreadable. This simulates "the file cannot be read" — the scenario
 * FileHandler::checkIsReadable() guards against — without an actual permission change, since a
 * test process running as root ignores permission bits on real files.
 */
final class UnreadableFileStreamWrapper
{
    private const SCHEME = 'sptest-unreadable-upload';

    /** @var array<string, string> virtual path => backing real path */
    private static array $realPaths = [];

    /** @var resource|null */
    private $handle;

    /** @var resource|null Required by the stream wrapper API even though it is unused here. */
    public $context;

    public static function urlFor(string $realPath): string
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $virtualPath = self::SCHEME . '://' . uniqid('', true);
        self::$realPaths[$virtualPath] = $realPath;

        return $virtualPath;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = self::$realPaths[$path] ?? null;

        if ($real === null) {
            return false;
        }

        $this->handle = fopen($real, $mode);

        return $this->handle !== false;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * Reports the backing file as an unreadable regular file (zeroed permission bits) without
     * touching its real permissions.
     *
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::$realPaths[$path] ?? null;

        if ($real === null) {
            return false;
        }

        $stat = @stat($real);

        if ($stat === false) {
            return false;
        }

        $stat['mode'] = $stat[2] = 0100000;

        return $stat;
    }
}
