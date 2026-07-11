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

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Category;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

/**
 * Class DeleteController
 */
final class DeleteController extends CategoryBase
{
    /**
     * deleteAction
     */
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CATEGORY_DELETE);

        $id = $this->apiService->getParamInt('id', true);

        $categoryData = $this->categoryService->getById($id);

        $this->categoryService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.category',
            $this,
            EventMessage::build()
                    ->addDescription(__u('Category deleted'))
                    ->addDetail(__u('Name'), $categoryData->getName())
                    ->addDetail('ID', $categoryData->getId())
        ));

        return ApiResponse::makeSuccess($categoryData, __('Category deleted'), $id);
    }
}
