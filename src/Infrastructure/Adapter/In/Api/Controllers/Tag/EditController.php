<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Tag;


use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Tag\Models\Tag;

use function SP\__;
use function SP\__u;

/**
 * Class EditController
 */
final class EditController extends TagBase
{
    /**
     * editAction
     */
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::TAG_EDIT);

        $tagData = $this->buildTagData();

        $this->tagService->update($tagData);

        $this->eventDispatcher->notify(new Event('edit.tag', 
                $this,
                EventMessage::build()
                    ->addDescription(__u('Tag updated'))
                    ->addDetail(__u('Name'), $tagData->getName())
                    ->addDetail('ID', $tagData->getId())
            )
        );

        return ApiResponse::makeSuccess($tagData, $tagData->getId(), __('Tag updated'));
    }

    /**
     * @return Tag
     * @throws ServiceException
     */
    private function buildTagData(): Tag
    {
        return new Tag([
            'id' => $this->apiService->getParamInt('id', true),
            'name' => $this->apiService->getParamString('name', true),
        ]);
    }
}
