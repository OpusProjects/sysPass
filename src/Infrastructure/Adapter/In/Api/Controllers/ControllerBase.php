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

namespace SP\Infrastructure\Adapter\In\Api\Controllers;

use SP\Infrastructure\Bootstrap\Router;
use League\Fractal\Manager;
use SP\Application\Application;
use SP\Infrastructure\Bootstrap\BootstrapBase;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__u;

/**
 * Class ControllerBase
 *
 * @package SP\Infrastructure\Adapter\In\Api\Controllers
 */
abstract class ControllerBase
{
    protected const SEARCH_COUNT_ITEMS = 25;

    protected string                   $controllerName;
    protected Context                  $context;
    protected EventDispatcherInterface $eventDispatcher;
    protected ConfigDataInterface      $configData;
    protected Manager                  $fractal;
    protected string                   $actionName;

    public function __construct(
        Application                     $application,
        protected readonly Router        $router,
        protected readonly ApiService   $apiService,
        protected readonly AclInterface $acl
    ) {
        $this->context = $application->getContext();
        $this->configData = $application->getConfig()->getConfigData();
        $this->eventDispatcher = $application->getEventDispatcher();

        $this->fractal = new Manager();
        $this->controllerName = $this->getControllerName();
        $this->actionName = $this->context->getTrasientKey(BootstrapBase::CONTEXT_ACTION_NAME);
    }

    final protected function getControllerName(): string
    {
        $class = static::class;

        return substr($class, strrpos($class, '\\') + 1, -strlen('Controller')) ?: '';
    }

    /**
     * @throws SPException
     * @throws ServiceException
     */
    /**
     * Demo mode makes an instance refuse to change or copy itself. The web enforces that in five
     * config actions and in `UserForm`; nothing on the API surface mentioned demo mode at all.
     *
     * A demo deployment is the one place where the caller is *expected* to hold administrator
     * credentials — they are published so people can try the thing — so this guard, not the ACL,
     * is what stands between a visitor and the instance. Signing in as the demo admin, minting a
     * token and calling the API ran the backup, the export or the user change that the interface
     * had just refused, and a visitor who changed the demo admin's password ended the demo for
     * everyone after them.
     *
     * @throws ValidationException
     */
    final protected function denyOnDemo(): void
    {
        if ($this->configData->isDemoEnabled()) {
            throw ValidationException::error(__u('Ey, this is a DEMO!!'));
        }
    }

    /**
     * The same refusal, narrowed to the account a demo instance publishes: every other user on a
     * demo may be created, edited and deleted freely, which is most of what there is to try.
     *
     * @throws ValidationException
     */
    final protected function denyOnDemoUser(int $userId): void
    {
        if ($userId === UserModel::DEMO_ADMIN_ID) {
            $this->denyOnDemo();
        }
    }

    final protected function setupApi(int $actionId): void
    {
        $this->apiService->setup($actionId);
    }
}
