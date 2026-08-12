<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Account\Ports\AccountFileService;
use SP\Application\Account\Services\AccountFileAcl;
use SP\Domain\Account\Dtos\FileDto;
use SP\Domain\Account\Models\File;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class AccountFileBase extends ControllerBase
{
    protected AccountFileService $accountFileService;
    protected AccountFileAcl     $accountFileAcl;

    public function __construct(
        Application        $application,
        Router             $router,
        ApiService         $apiService,
        AclInterface       $acl,
        AccountFileService $accountFileService,
        AccountFileAcl     $accountFileAcl
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->accountFileService = $accountFileService;
        $this->accountFileAcl = $accountFileAcl;
    }

    /**
     * An attachment is stored as the file's raw bytes, and is as likely to be a PDF or an image as
     * a text file. Raw bytes cannot go into a JSON response — a single byte that is not valid UTF-8
     * fails the encode and takes the whole response with it — so the content goes out
     * base64-encoded, the way the upload takes it in. The thumbnail is already encoded.
     *
     * @throws SPException
     */
    protected static function withEncodedContent(FileDto|File $file): FileDto|File
    {
        $content = $file instanceof File ? $file->getContent() : $file->content;

        return $file->mutate(['content' => base64_encode((string)$content)]);
    }
}
