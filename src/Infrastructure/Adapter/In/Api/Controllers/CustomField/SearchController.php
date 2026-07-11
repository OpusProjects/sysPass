<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Infrastructure\Events\Event;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;

final class SearchController extends CustomFieldBase
{
    public function searchAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CUSTOMFIELD_SEARCH);

        $itemSearchData = new ItemSearchDto(
            $this->apiService->getParamString('text'),
            0,
            $this->apiService->getParamInt('count', false, self::SEARCH_COUNT_ITEMS)
        );

        $this->eventDispatcher->notify(new Event('search.customField', $this));

        return ApiResponse::makeSuccess(
            $this->customFieldService->search($itemSearchData)->getDataAsArray()
        );
    }
}
