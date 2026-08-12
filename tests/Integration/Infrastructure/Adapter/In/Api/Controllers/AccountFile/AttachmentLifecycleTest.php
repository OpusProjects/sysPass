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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * Uploading an attachment over the API was covered; listing, reading back and removing one were
 * not — those three endpoints had no test of any kind.
 *
 * They are covered together because each is only meaningful against the others: a delete that did
 * nothing looks the same as one that worked until the listing is asked again, and a read that
 * returns the wrong attachment looks like a read that worked.
 */
#[Group('integration')]
class AttachmentLifecycleTest extends ApiTestCase
{
    private const CONTENT = 'the contents of the attachment';

    /** A PNG header followed by bytes that are not valid UTF-8. */
    private const BINARY_CONTENT = "\x89PNG\r\n\x1a\n\xff\xfe\x00\x01";

    /**
     * The fixture install allows no mime types at all, so nothing can be attached until one is.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $configFile = $this->configPath . DIRECTORY_SEPARATOR . 'config.xml';

        file_put_contents(
            $configFile,
            preg_replace(
                '#<filesAllowedMime></filesAllowedMime>#',
                "<filesAllowedMime>\n  <item type=\"filesAllowedMime\">text/plain</item>\n  <item type=\"filesAllowedMime\">application/octet-stream</item>\n</filesAllowedMime>",
                file_get_contents($configFile)
            )
        );
    }

    /**
     * The listing of an account's attachments is what the client shows beside it.
     *
     * @throws Exception
     */
    #[Test]
    public function anAccountsAttachmentsAreListed()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenAnAttachmentOn($accountId, 'notes.txt');
        $this->givenAnAttachmentOn($accountId, 'second.txt');

        $response = $this->searchAttachmentsOf($accountId);

        self::assertSame(200, $response->status);
        self::assertCount(2, $response->body->data);
        self::assertContains($fileId, array_map(static fn($file) => $file->id, $response->body->data));
    }

    /**
     * An account with nothing attached lists nothing, rather than failing or listing somebody
     * else's.
     *
     * @throws Exception
     */
    #[Test]
    public function anAccountWithoutAttachmentsListsNothing()
    {
        $response = $this->searchAttachmentsOf($this->givenAnAccount());

        self::assertSame(200, $response->status);
        self::assertSame([], $response->body->data);
    }

    /**
     * Reading one back gives the content that was uploaded — this is how a client downloads it.
     *
     * @throws Exception
     */
    #[Test]
    public function anAttachmentIsReadBackAsItWasUploaded()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenAnAttachmentOn($accountId, 'notes.txt');

        $response = $this->viewAttachment($accountId, $fileId);

        self::assertSame(200, $response->status);
        self::assertSame('notes.txt', $response->body->data->name);
        // Encoded on the way out, the way the upload takes it in.
        self::assertSame(self::CONTENT, base64_decode($response->body->data->content));
    }

    /**
     * An attachment that is not there is a 404 rather than an empty success a client would save as
     * a zero-byte file.
     *
     * @throws Exception
     */
    #[Test]
    public function readingAnAttachmentThatIsNotThereIsNotFound()
    {
        $response = $this->viewAttachment($this->givenAnAccount(), 999999);

        self::assertSame(404, $response->status);
    }

    /**
     * Removing one takes it off the account, and the response says which was removed so the client
     * can drop it from the listing it already has.
     *
     * @throws Exception
     */
    #[Test]
    public function anAttachmentIsRemoved()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenAnAttachmentOn($accountId, 'notes.txt');

        $response = $this->deleteAttachment($accountId, $fileId);

        self::assertSame(200, $response->status);
        self::assertSame($fileId, $response->body->itemId);
        self::assertSame('notes.txt', $response->body->data->name);

        self::assertSame([], $this->searchAttachmentsOf($accountId)->body->data, 'it is gone from the listing');
    }

    /**
     * And is then no longer readable, so the content is really gone rather than merely hidden from
     * the listing.
     *
     * @throws Exception
     */
    #[Test]
    public function aRemovedAttachmentCannotBeReadBack()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenAnAttachmentOn($accountId, 'notes.txt');

        $this->deleteAttachment($accountId, $fileId);

        self::assertSame(404, $this->viewAttachment($accountId, $fileId)->status);
    }

    /**
     * Removing one that is not there is a 404 rather than a success reporting nothing removed.
     *
     * @throws Exception
     */
    #[Test]
    public function removingAnAttachmentThatIsNotThereIsNotFound()
    {
        self::assertSame(404, $this->deleteAttachment($this->givenAnAccount(), 999999)->status);
    }

    /**
     * An attachment is as likely to be a PDF or an image as a text file, and the upload documents
     * its content as base64. Reading one back has to work for those too — the response cannot carry
     * raw bytes.
     *
     * @throws Exception
     */
    #[Test]
    public function aBinaryAttachmentIsReadBackByteForByte()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenABinaryAttachmentOn($accountId);

        $response = $this->viewAttachment($accountId, $fileId);

        self::assertSame(200, $response->status);
        self::assertSame(self::BINARY_CONTENT, base64_decode($response->body->data->content));
    }

    /**
     * And one binary attachment must not take the whole listing with it. The listing carries every
     * attachment's content, so a single unreadable one used to fail the response for the account.
     *
     * @throws Exception
     */
    #[Test]
    public function aBinaryAttachmentDoesNotBreakTheListing()
    {
        $accountId = $this->givenAnAccount();
        $this->givenAnAttachmentOn($accountId, 'notes.txt');
        $this->givenABinaryAttachmentOn($accountId);

        $response = $this->searchAttachmentsOf($accountId);

        self::assertSame(200, $response->status);
        self::assertCount(2, $response->body->data);
    }

    /**
     * Nor when it is removed — the delete answers with what it removed.
     *
     * @throws Exception
     */
    #[Test]
    public function aBinaryAttachmentIsRemovedWithoutBreakingTheResponse()
    {
        $accountId = $this->givenAnAccount();
        $fileId = $this->givenABinaryAttachmentOn($accountId);

        $response = $this->deleteAttachment($accountId, $fileId);

        self::assertSame(200, $response->status);
        self::assertSame('blob.bin', $response->body->data->name);
    }

    /**
     * @throws Exception
     */
    private function givenAnAccount(): int
    {
        $response = $this->callApi(
            AclActionsInterface::ACCOUNT_CREATE,
            [
                'name' => 'An account with attachments',
                'categoryId' => 2,
                'clientId' => 2,
                'login' => 'someone',
                'pass' => 'password123',
            ]
        );

        self::assertSame(201, $response->status, 'the account the attachments hang off could not be created');

        return $response->body->itemId;
    }

    /**
     * @throws Exception
     */
    private function givenAnAttachmentOn(int $accountId, string $name): int
    {
        $response = $this->callApi(
            AclActionsInterface::ACCOUNT_FILE_UPLOAD,
            [
                'id' => $accountId,
                'name' => $name,
                'content' => base64_encode(self::CONTENT),
                'type' => 'text/plain',
                'extension' => 'TXT',
            ]
        );

        self::assertSame(200, $response->status, 'the attachment under test could not be uploaded');

        return $response->body->itemId;
    }

    /**
     * @throws Exception
     */
    private function givenABinaryAttachmentOn(int $accountId): int
    {
        $response = $this->callApi(
            AclActionsInterface::ACCOUNT_FILE_UPLOAD,
            [
                'id' => $accountId,
                'name' => 'blob.bin',
                'content' => base64_encode(self::BINARY_CONTENT),
                'type' => 'application/octet-stream',
                'extension' => 'BIN',
            ]
        );

        self::assertSame(200, $response->status, 'the binary attachment under test could not be uploaded');

        return $response->body->itemId;
    }

    /**
     * @throws Exception
     */
    private function searchAttachmentsOf(int $accountId): stdClass
    {
        return $this->callApiPath(
            'GET',
            sprintf('/api/v1/accounts/%d/files', $accountId),
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $this->issueToken(AclActionsInterface::ACCOUNT_FILE_LIST)
        );
    }

    /**
     * @throws Exception
     */
    private function viewAttachment(int $accountId, int $fileId): stdClass
    {
        return $this->callApiPath(
            'GET',
            sprintf('/api/v1/accounts/%d/files/%d', $accountId, $fileId),
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $this->issueToken(AclActionsInterface::ACCOUNT_FILE_DOWNLOAD)
        );
    }

    /**
     * @throws Exception
     */
    private function deleteAttachment(int $accountId, int $fileId): stdClass
    {
        return $this->callApiPath(
            'DELETE',
            sprintf('/api/v1/accounts/%d/files/%d', $accountId, $fileId),
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $this->issueToken(AclActionsInterface::ACCOUNT_FILE_DELETE)
        );
    }
}
