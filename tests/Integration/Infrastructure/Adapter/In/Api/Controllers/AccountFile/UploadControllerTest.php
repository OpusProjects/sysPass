<?php

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;

use function SP\Tests\getDbHandler;
use stdClass;

/**
 * Integration tests for API AccountFile UploadController validation.
 *
 * Each test configures the allowed MIME / size policy through the config.xml
 * that ApiTestCase writes during setUp(), then drives the real REST Bootstrap.
 */
#[Group('integration')]
class UploadControllerTest extends ApiTestCase
{
    /** A small plaintext payload (13 bytes) that fits well within any sane limit. */
    private const PLAIN_TEXT_CONTENT = 'Hello, World!';

    /** MIME types permitted by the test config. */
    private const ALLOWED_MIME = ['text/plain', 'application/pdf'];

    /** Size limit written to config.xml (KB). */
    private const ALLOWED_SIZE_KB = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $configFile = $this->configPath . DIRECTORY_SEPARATOR . 'config.xml';

        $config = file_get_contents($configFile);

        // Allow text/plain and application/pdf only.
        $config = preg_replace(
            '#<filesAllowedMime>.*?</filesAllowedMime>#s',
            "<filesAllowedMime>\n" .
            "  <item type=\"filesAllowedMime\">text/plain</item>\n" .
            "  <item type=\"filesAllowedMime\">application/pdf</item>\n" .
            "</filesAllowedMime>",
            $config
        );

        // Use a 1 KB limit so the oversized test does not have to send megabytes.
        $config = preg_replace(
            '#<filesAllowedSize>\d+</filesAllowedSize>#',
            '<filesAllowedSize>' . self::ALLOWED_SIZE_KB . '</filesAllowedSize>',
            $config
        );

        file_put_contents($configFile, $config);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a throwaway account and return its ID so the upload has a target.
     */
    private function createAccount(): int
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_CREATE, [
            'name'       => 'File upload test account',
            'categoryId' => 2,
            'clientId'   => 2,
            'login'      => 'testuser',
            'pass'       => 'password123',
        ]);

        $this->assertSame(201, $r->status, 'account creation prerequisite failed');

        return $r->body->itemId;
    }

    private function upload(int $accountId, array $params): stdClass
    {
        return $this->callApi(AclActionsInterface::ACCOUNT_FILE_UPLOAD, array_merge(
            ['id' => $accountId],
            $params
        ));
    }

    /**
     * The extension as it was actually stored.
     *
     * Read from the row rather than from the upload's own response, which echoes back only the id
     * and the name — an assertion against what the endpoint just told us would not show what the
     * database now holds, which is the thing that was wrong.
     */
    private function extensionStoredFor(int $fileId): string
    {
        $statement = getDbHandler()->getConnection()
                                   ->prepare('SELECT `extension` FROM `AccountFile` WHERE `id` = :id');
        $statement->execute(['id' => $fileId]);

        $extension = $statement->fetchColumn();

        $this->assertNotFalse($extension, sprintf('No AccountFile row with id %d', $fileId));

        return (string)$extension;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testValidUpload(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'      => 'hello.txt',
            'content'   => base64_encode(self::PLAIN_TEXT_CONTENT),
            'type'      => 'text/plain',
            'extension' => 'TXT',
        ]);

        // Bootstrap maps actionName==='create' to 201; 'upload' gets 200.
        $this->assertSame(200, $r->status);
        $this->assertSame('File uploaded', $r->body->message);
        $this->assertSame('hello.txt', $r->body->data->name);
        $this->assertGreaterThan(0, $r->body->itemId);
    }

    /**
     * The extension is documented as optional, so leaving it out has to work.
     *
     * It did not: the column is NOT NULL, so an omitted extension reached the database as null and
     * the caller got HTTP 500 `Integrity constraint`, with `SQLSTATE[23000] … Column 'extension'
     * cannot be null` in the detail — an internal database error handed to an API client for using
     * the endpoint as documented. Every test here passed one, which is why nothing noticed.
     */
    public function testAnUploadWithoutAnExtensionTakesItFromTheName(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'    => 'notes.txt',
            'content' => base64_encode(self::PLAIN_TEXT_CONTENT),
            'type'    => 'text/plain',
        ]);

        $this->assertSame(200, $r->status, 'an optional parameter must be optional');
        $this->assertSame('File uploaded', $r->body->message);

        $this->assertSame(
            'TXT',
            $this->extensionStoredFor($r->body->itemId),
            'the name says .txt, and the web upload would have stored TXT for the same file'
        );
    }

    /**
     * A name with no extension stores an empty one rather than failing.
     *
     * Empty is a fact about the file — plenty of real attachments have no extension — while null
     * is an insert the database refuses.
     */
    public function testAnUploadOfAFileWithNoExtensionIsStored(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'    => 'README',
            'content' => base64_encode(self::PLAIN_TEXT_CONTENT),
            'type'    => 'text/plain',
        ]);

        $this->assertSame(200, $r->status);
        $this->assertSame('', $this->extensionStoredFor($r->body->itemId));
    }

    /**
     * An extension the caller does give is still the one used, and is normalised the way the web
     * upload normalises it.
     */
    public function testAnExtensionTheCallerGivesIsKept(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'      => 'notes.txt',
            'content'   => base64_encode(self::PLAIN_TEXT_CONTENT),
            'type'      => 'text/plain',
            'extension' => 'log',
        ]);

        $this->assertSame(200, $r->status);
        $this->assertSame('LOG', $this->extensionStoredFor($r->body->itemId));
    }

    public function testOversizedUploadIsRejected(): void
    {
        $accountId = $this->createAccount();

        // 1 025 bytes exceeds the 1 KB (1 024 byte) limit set in setUp().
        $oversized = str_repeat('A', self::ALLOWED_SIZE_KB * 1024 + 1);

        $r = $this->upload($accountId, [
            'name'      => 'big.txt',
            'content'   => base64_encode($oversized),
            'type'      => 'text/plain',
            'extension' => 'TXT',
        ]);

        $this->assertSame(400, $r->status);
        $this->assertSame('File size too large', $r->body->error->message);
        $this->assertStringContainsString(
            sprintf('Maximum size: %d KB', self::ALLOWED_SIZE_KB),
            $r->body->error->detail
        );
    }

    /**
     * Declaring an allowed type must not launder content the server identified as something
     * else. Before, detection failing the allow-list simply handed the decision to the caller,
     * so this PHP script was stored as application/pdf.
     */
    public function testDeclaredTypeCannotLaunderIdentifiedContent(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'      => 'invoice.pdf',
            'content'   => base64_encode('<?php system($_GET[0]); ?>'),
            'type'      => 'application/pdf',
            'extension' => 'PDF',
        ]);

        $this->assertSame(400, $r->status);
        $this->assertSame('File type not allowed', $r->body->error->message);
    }

    /**
     * Content the server cannot identify is still stored under the declared type: attachments
     * here are often keystores and certificates, which have no signature to match.
     */
    public function testUnidentifiableContentFallsBackToTheDeclaredType(): void
    {
        $accountId = $this->createAccount();

        $r = $this->upload($accountId, [
            'name'      => 'blob.pdf',
            'content'   => base64_encode("\x00\x01\x02\xff\xfe" . random_bytes(48)),
            'type'      => 'application/pdf',
            'extension' => 'PDF',
        ]);

        $this->assertSame(200, $r->status);
        $this->assertSame('File uploaded', $r->body->message);
    }

    public function testDisallowedMimeTypeIsRejected(): void
    {
        $accountId = $this->createAccount();

        // A truncated PNG: too little to identify, so it comes back inconclusive. The declared
        // image/png is not on the allow-list either, so there is nothing to fall back to.
        $pngPayload = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 50);

        $r = $this->upload($accountId, [
            'name'      => 'image.png',
            'content'   => base64_encode($pngPayload),
            'type'      => 'image/png',
            'extension' => 'PNG',
        ]);

        $this->assertSame(400, $r->status);
        $this->assertSame('File type not allowed', $r->body->error->message);
    }
}
