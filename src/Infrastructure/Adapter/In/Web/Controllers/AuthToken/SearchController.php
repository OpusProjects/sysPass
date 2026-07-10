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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken;

use SP\Core\Application;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AuthTokenGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\SearchGridControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class SearchController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class SearchController extends SearchGridControllerBase
{
    use ItemTrait;

    /**
     * @param AuthTokenService<AuthTokenModel> $authTokenService
     * @throws AuthException
     * @throws SessionTimeout
     */
    public function __construct(
        Application                       $application,
        WebControllerHelper               $webControllerHelper,
        private readonly AuthTokenService $authTokenService,
        private readonly AuthTokenGrid    $authTokenGrid
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();
    }

    /**
     * getSearchGrid
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getSearchGrid(): DataGridInterface
    {
        $itemSearchData = $this->getSearchData($this->configData->getAccountCount(), $this->request);

        return $this->authTokenGrid->updatePager(
            $this->authTokenGrid->getGrid($this->authTokenService->search($itemSearchData)),
            $itemSearchData
        );
    }

    protected function getAclAction(): int
    {
        return AclActionsInterface::AUTHTOKEN_SEARCH;
    }
}
