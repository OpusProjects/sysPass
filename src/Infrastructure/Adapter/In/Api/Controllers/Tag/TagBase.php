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


use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Application\Tag\Ports\TagService;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\TagHelp;

/**
 * Class TagBase
 */
abstract class TagBase extends ControllerBase
{
    protected TagService $tagService;

    /**
     * @throws InvalidClassException
     */
    public function __construct(
        Application  $application,
        Router        $router,
        ApiService   $apiService,
        AclInterface $acl,
        TagService $tagService
    ) {
        parent::__construct($application, $router, $apiService, $acl);

        $this->tagService = $tagService;

        $this->apiService->setHelpClass(TagHelp::class);
    }
}
