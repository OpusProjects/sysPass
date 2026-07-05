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

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Category\Ports\CategoryAdapter;
use SP\Application\Category\Ports\CategoryService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\CategoryHelp;

/**
 * Class CategoryBase
 */
abstract class CategoryBase extends ControllerBase
{
    protected CategoryService $categoryService;
    protected CategoryAdapter $categoryAdapter;

    /**
     * @throws InvalidClassException
     */
    public function __construct(
        Application     $application,
        Router           $router,
        ApiService      $apiService,
        AclInterface    $acl,
        CategoryService $categoryService,
        CategoryAdapter $categoryAdapter
    ) {
        parent::__construct($application, $router, $apiService, $acl);

        $this->categoryService = $categoryService;
        $this->categoryAdapter = $categoryAdapter;

        $this->apiService->setHelpClass(CategoryHelp::class);
    }
}
