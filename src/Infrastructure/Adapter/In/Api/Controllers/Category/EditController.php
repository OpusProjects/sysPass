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


use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Category\Models\Category;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;

final class EditController extends CategoryBase
{
    /**
     * editAction
     */
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CATEGORY_EDIT);

        $categoryData = $this->buildCategoryData();

        $this->categoryService->update($categoryData);

        $this->eventDispatcher->notify(new Event('edit.category', 
                $this,
                EventMessage::build()
                    ->addDescription(__u('Category updated'))
                    ->addDetail(__u('Name'), $categoryData->getName())
                    ->addDetail('ID', $categoryData->getId())
            )
        );

        return ApiResponse::makeSuccess($categoryData, $categoryData->getId(), __('Category updated'));
    }

    /**
     * @return Category
     * @throws ServiceException
     */
    private function buildCategoryData(): Category
    {
        $categoryData = new Category();
        $categoryData->setId($this->apiService->getParamInt('id', true));
        $categoryData->setName($this->apiService->getParamString('name', true));
        $categoryData->setDescription($this->apiService->getParamString('description'));

        return $categoryData;
    }
}
