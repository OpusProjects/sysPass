<?php

declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Infrastructure\Adapter\In\Api\ApiTestCase;
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

    public function testDisallowedMimeTypeIsRejected(): void
    {
        $accountId = $this->createAccount();

        // PNG magic bytes — finfo detects as image/png, which is not in the allow-list.
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
