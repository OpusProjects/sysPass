<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Status;

use GuzzleHttp\ClientInterface;
use SP\Application\Application;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;

/**
 * Class StatusBase
 */
abstract class StatusBase extends SimpleControllerBase
{
    protected ClientInterface $client;

    public function __construct(
        Application $application,
        SimpleControllerHelper $simpleControllerHelper,
        ClientInterface $client
    ) {
        parent::__construct($application, $simpleControllerHelper);

        $this->client = $client;
    }

    /**
     * Whether this caller may make the application call out to a third party.
     *
     * Both status actions fetch a URL over the network, and the client is told whether to offer
     * them by `GetEnvironmentController`:
     *
     * ```php
     * $checkStatus = $this->session->getAuthCompleted()
     *                && ($this->session->getUserData()->isAdminApp || $this->configData->isDemoEnabled());
     * ```
     *
     * Nothing said it here, and these controllers sit in `Init::PARTIAL_INIT`, which skips the
     * session check — so the endpoints answered anybody. An unauthenticated request made the
     * server open an outbound connection and hold a worker for as long as the far end took, with
     * no limit on how often. The same rule is applied here, where it binds.
     */
    final protected function mayCheckStatus(): bool
    {
        return $this->session->getAuthCompleted()
               && ($this->session->getUserData()->isAdminApp || $this->configData->isDemoEnabled());
    }
}
