<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends AccountFileBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_FILE_DELETE);

        $fileId = $this->apiService->getParamInt('fileId', true);
        $fileData = $this->accountFileService->getById($fileId);

        $this->accountFileAcl->requireEdit($fileData->accountId ?? 0);

        $this->accountFileService->delete($fileId);

        $this->eventDispatcher->notify(new Event(
            'delete.accountFile',
            $this,
            EventMessage::build()
                ->addDescription(__u('File removed'))
                ->addDetail(__u('Name'), $fileData->name)
                ->addDetail('ID', $fileId)
        ));

        return ApiResponse::makeSuccess($fileData, __('File removed'), $fileId);
    }
}
