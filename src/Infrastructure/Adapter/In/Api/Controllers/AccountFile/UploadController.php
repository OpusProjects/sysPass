<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Account\Models\File;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Services\ServiceException;
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

        if (in_array($serverType, $filesAllowedMime, true)) {
            $resolvedType = $serverType;
        } elseif (in_array($clientType, $filesAllowedMime, true)) {
            $resolvedType = $clientType;
        } else {
            throw ServiceException::error(
                __u('File type not allowed'),
                sprintf(__('MIME type: %s'), $serverType),
                Code::BAD_REQUEST->value
            );
        }

        $fileData = new File([
            'accountId' => $accountId,
            'name'      => $this->apiService->getParamString('name', true),
            'type'      => $resolvedType,
            'extension' => $this->apiService->getParamString('extension'),
            'size'      => strlen($content),
            'content'   => $content,
        ]);

        $id = $this->accountFileService->create($fileData);

        $this->eventDispatcher->notify(new Event('upload.accountFile',
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
}
