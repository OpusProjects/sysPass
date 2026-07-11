<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Domain\Core\Events\Event;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;

final class SearchController extends UserBase
{
    public function searchAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_SEARCH);

        $itemSearchData = new ItemSearchDto(
            $this->apiService->getParamString('text'),
            0,
            $this->apiService->getParamInt('count', false, self::SEARCH_COUNT_ITEMS)
        );

        $this->eventDispatcher->notify(new Event('search.user', $this));

        return ApiResponse::makeSuccess(
            $this->userService->search($itemSearchData)->getDataAsArray()
        );
    }
}
