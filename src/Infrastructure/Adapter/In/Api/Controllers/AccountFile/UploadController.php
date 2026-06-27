<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Account\Models\File;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class UploadController extends AccountFileBase
{
    public function uploadAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_FILE_UPLOAD);

        $accountId = $this->apiService->getParamInt('id', true);
        $content = $this->apiService->getParamRaw('content', true);

        $fileData = new File([
            'accountId' => $accountId,
            'name'      => $this->apiService->getParamString('name', true),
            'type'      => $this->apiService->getParamString('type', false, 'application/octet-stream'),
            'extension' => $this->apiService->getParamString('extension'),
            'size'      => strlen(base64_decode($content)),
            'content'   => base64_decode($content),
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
