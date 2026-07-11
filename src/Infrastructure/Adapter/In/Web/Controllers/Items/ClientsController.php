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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Items;

use SP\Application\Application;
use SP\Application\Client\Ports\ClientService;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Http\Services\JsonResponse;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Domain\Common\Adapters\SelectItemAdapter;

/**
 * Class ClientsController
 */
final class ClientsController extends SimpleControllerBase
{
    private ClientService $clientService;

    public function __construct(
        Application $application,
        SimpleControllerHelper $simpleControllerHelper,
        ClientService $clientService
    ) {
        parent::__construct($application, $simpleControllerHelper);

        $this->checks();

        $this->clientService = $clientService;
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    #[Action(ResponseType::PLAIN_TEXT)]
    public function clientsAction(): ActionResponse
    {
        JsonResponse::factory($this->router->response())
                    ->sendRaw(
                        SelectItemAdapter::factory($this->clientService->getAllForUser())->getJsonItemsFromModel()
                    );

        return ActionResponse::ok('');
    }
}
