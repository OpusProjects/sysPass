<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Domain\Core\Events\Event;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

final class SearchController extends AccountFileBase
{
    public function searchAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_FILE_LIST);

        $accountId = $this->apiService->getParamInt('id', true);

        $this->accountFileAcl->requireView($accountId);

        $this->eventDispatcher->notify(new Event('search.accountFile', $this));

        return ApiResponse::makeSuccess(
            $this->accountFileService->getByAccountId($accountId)
        );
    }
}
