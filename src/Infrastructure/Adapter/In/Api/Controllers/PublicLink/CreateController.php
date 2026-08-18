<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\PublicLink;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Account\Models\PublicLink;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Account\PublicLinkType;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class CreateController extends PublicLinkBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PUBLICLINK_CREATE);

        $linkData = new PublicLink([
            'itemId'        => $this->apiService->getParamInt('itemId', true),
            // NOT NULL on the table, and set by both web paths. Leaving it out made every
            // call fail on the insert.
            'typeId'        => PublicLinkType::Account->value,
            'notify'        => (bool) $this->apiService->getParamInt('notify'),
        ]);

        $id = $this->publicLinkService->create($linkData);

        // When the link expires and how often it may be opened are the administrator's
        // configuration — `getPublinksMaxTime()` is a maximum, and neither web path lets anyone
        // choose either. The service sets both, so the `dateExpire` and `maxCountViews` this
        // endpoint used to accept were discarded on the way to the database. What made that
        // costly rather than merely useless is that the response echoed the request back, so a
        // caller was told a link would expire when they asked, and it did not.
        $stored = $this->publicLinkService->getById($id);

        $linkData = $linkData->mutate(
            [
                'id' => $id,
                'dateExpire' => $stored->getDateExpire(),
                'maxCountViews' => $stored->getMaxCountViews(),
            ]
        );

        $this->eventDispatcher->notify(new Event(
            'create.publicLink',
            $this,
            EventMessage::build()
                ->addDescription(__u('Public link added'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($linkData, __('Public link added'), $id);
    }
}
