<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Account\Models\File;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\File\AllowedMimeType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Http\Code;

use function SP\__;
use function SP\__u;

final class UploadController extends AccountFileBase
{
    public function uploadAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_FILE_UPLOAD);

        $accountId  = $this->apiService->getParamInt('id', true);
        $rawContent = $this->apiService->getParamRaw('content', true);
        $clientType = $this->apiService->getParamString('type', false, 'application/octet-stream');

        $this->accountFileAcl->requireEdit($accountId);

        $filesAllowedMime = $this->configData->getFilesAllowedMime();

        if (empty($filesAllowedMime)) {
            throw ServiceException::error(
                __u('There aren\'t any allowed MIME types'),
                null,
                Code::BAD_REQUEST->value
            );
        }

        $content     = base64_decode($rawContent);
        $allowedSize = $this->configData->getFilesAllowedSize();

        if (strlen($content) > $allowedSize * 1024) {
            throw ServiceException::error(
                __u('File size too large'),
                sprintf(__u('Maximum size: %d KB'), $allowedSize),
                Code::BAD_REQUEST->value
            );
        }

        $detected   = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $serverType = $detected !== false ? $detected : 'application/octet-stream';

        $resolvedType = AllowedMimeType::resolve($serverType, $clientType, $filesAllowedMime);

        if ($resolvedType === null) {
            throw ServiceException::error(
                __u('File type not allowed'),
                sprintf(__('MIME type: %s'), $serverType),
                Code::BAD_REQUEST->value
            );
        }

        $name = $this->apiService->getParamString('name', true);

        $fileData = new File([
            'accountId' => $accountId,
            'name'      => $name,
            'type'      => $resolvedType,
            // The extension is documented as optional and the column is NOT NULL, so leaving it
            // out sent null to the database and came back as HTTP 500 "Integrity constraint" with
            // a raw SQLSTATE in the detail — the parameter was required in everything but name.
            // It is derived from the file name instead, which is what the web upload has always
            // done, so the two paths now store the same thing for the same file.
            'extension' => self::extensionFrom(
                $this->apiService->getParamString('extension'),
                $name
            ),
            'size'      => strlen($content),
            'content'   => $content,
        ]);

        $id = $this->accountFileService->create($fileData);

        $this->eventDispatcher->notify(new Event(
            'upload.accountFile',
            $this,
            EventMessage::build()
                ->addDescription(__u('File uploaded'))
                ->addDetail(__u('Name'), $fileData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess(
            ['id' => $id, 'name' => $fileData->getName()],
            __('File uploaded'),
            $id
        );
    }

    /**
     * The extension to store: the caller's, or the one the file name carries.
     *
     * Uppercased to match the web upload, which stores `mb_strtoupper(pathinfo(…))` — the same
     * file arriving by either route should not end up recorded two different ways.
     *
     * A name with no extension yields an empty string rather than null. The column is NOT NULL
     * with no default, and an empty extension is a fact about the file; null is a failed insert.
     */
    private static function extensionFrom(?string $given, string $name): string
    {
        $given = trim((string)$given);

        return mb_strtoupper($given !== '' ? $given : pathinfo($name, PATHINFO_EXTENSION));
    }
}
