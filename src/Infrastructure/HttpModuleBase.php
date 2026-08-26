<?php

declare(strict_types=1);
/**
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

namespace SP\Infrastructure;

use SP\Application\Application;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Common\Providers\Http;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Http\Ports\RequestService;

/**
 * Base module for HTTP based modules
 */
use function SP\logger;

abstract class HttpModuleBase extends ModuleBase
{
    public function __construct(
        Application                       $application,
        ProvidersHelper                   $providersHelper,
        protected readonly RequestService $request,
        protected readonly Router          $router,
        protected readonly AppLockHandler $appLock
    ) {
        parent::__construct($application, $providersHelper);
    }

    /**
     * Send the request to HTTPS and stop, when the configuration says it must be.
     *
     * The sending and the stopping are one thing, which is why this is here rather than in the
     * helper that works out the address. A redirect that does not halt is not a redirect: the
     * response carries on being built and goes out over the connection the setting exists to
     * refuse.
     *
     * Both entry points call this, and it mirrors what Init already does for a not-installed
     * instance, a database it cannot reach and maintenance mode — redirect through the router,
     * then throw.
     *
     * @throws InitializationException
     */
    protected function redirectToHttpsIfRequired(): void
    {
        $httpsUrl = Http::httpsUrlFor($this->configData, $this->request);

        if ($httpsUrl === null) {
            return;
        }

        logger('Redirecting to HTTPS', 'INFO');

        $this->router->response()->redirect($httpsUrl)->send();

        throw new InitializationException('HTTPS required');
    }

    /**
     * Check whether maintenance mode is enabled
     * This function checks whether maintenance mode is enabled.
     *
     * @return bool
     * @throws SPException
     */
    protected function checkMaintenanceMode(): bool
    {
        if ($this->configData->isMaintenance()) {
            $lock = $this->appLock->getLock();

            return !$this->request->isAjax()
                   || !($lock !== false
                        && $lock > 0
                        && $this->context->isLoggedIn()
                        && $lock === $this->context->getUserData()->id);
        }

        return false;
    }
}
