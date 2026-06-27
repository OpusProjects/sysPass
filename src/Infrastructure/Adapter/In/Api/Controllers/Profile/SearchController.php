<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Core\Events\Event;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;

final class SearchController extends ProfileBase
{
    public function searchAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PROFILE_SEARCH);

        $itemSearchData = new ItemSearchDto(
            $this->apiService->getParamString('text'),
            0,
            $this->apiService->getParamInt('count', false, self::SEARCH_COUNT_ITEMS)
        );

        $this->eventDispatcher->notify(new Event('search.profile', $this));

        return ApiResponse::makeSuccess(
            $this->profileService->search($itemSearchData)->getDataAsArray()
        );
    }
}
