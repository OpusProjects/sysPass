<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends AccountFileBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_FILE_DOWNLOAD);

        $fileId = $this->apiService->getParamInt('fileId', true);
        $fileData = $this->accountFileService->getById($fileId);

        $this->accountFileAcl->requireView($fileData->accountId ?? 0);

        $this->eventDispatcher->notify(new Event(
            'show.accountFile',
            $this,
            EventMessage::build()
                ->addDescription(__u('File displayed'))
                ->addDetail(__u('Name'), $fileData->name)
                ->addDetail('ID', $fileId)
        ));

        return ApiResponse::makeSuccess($fileData);
    }
}
