<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigGeneral;

use SP\Application\Application;
use SP\Infrastructure\Events\Event;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Traits\ConfigTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;

use function SP\__u;

/**
 * Class SaveController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class SaveController extends SimpleControllerBase
{
    use ConfigTrait;

    public function __construct(
        Application            $application,
        SimpleControllerHelper $simpleControllerHelper,
        private readonly AppLockHandler $appLock
    ) {
        parent::__construct($application, $simpleControllerHelper);
    }


    /**
     * @throws SPException
     * @throws ValidationException
     */
    #[Action(ResponseType::JSON)]
    public function saveAction(): ActionResponse
    {
        $configData = $this->config->getConfigData();

        $this->handleSiteConfig($configData);
        $this->handleProxyConfig($configData);

        return $this->saveConfig(
            $configData,
            $this->config,
            function () use ($configData) {
                if ($configData->isMaintenance()) {
                    $this->appLock->lock($this->session->getUserData()->id, 'config');
                } elseif ($this->appLock->getLock() !== false) {
                    $this->appLock->unlock();
                }

                $this->eventDispatcher->notify(new Event('save.config.general', $this));
            }
        );
    }

    private function handleSiteConfig(ConfigDataInterface $configData): void
    {
        $siteLang = $this->request->analyzeString('site_lang');
        $siteTheme = $this->request->analyzeString('site_theme', 'material-blue');
        $sessionTimeout = $this->request->analyzeInt('session_timeout', 300);
        $applicationUrl = $this->request->analyzeString('app_url');
        $debugEnabled = $this->request->analyzeBool('debug_enabled', false);
        $maintenanceEnabled = $this->request->analyzeBool('maintenance_enabled', false);
        $checkUpdatesEnabled = $this->request->analyzeBool('check_updates_enabled', false);
        $checkNoticesEnabled = $this->request->analyzeBool('check_notices_enabled', false);

        $configData->setSiteLang($siteLang);
        $configData->setSiteTheme($siteTheme);
        $configData->setSessionTimeout($sessionTimeout);
        $configData->setApplicationUrl($applicationUrl);
        $configData->setDebug($debugEnabled);
        $configData->setMaintenance($maintenanceEnabled);
        $configData->setCheckUpdates($checkUpdatesEnabled);
        $configData->setCheckNotices($checkNoticesEnabled);
    }

    /**
     * @throws ValidationException
     */
    private function handleProxyConfig(ConfigDataInterface $configData): void
    {
        $proxyEnabled = $this->request->analyzeBool('proxy_enabled', false);
        $proxyServer  = $this->request->analyzeString('proxy_server');
        $proxyPort    = $this->request->analyzeInt('proxy_port', 8080);
        $proxyUser    = $this->request->analyzeString('proxy_user');
        $proxyPass    = $this->request->analyzeEncrypted('proxy_pass');

        if ($proxyEnabled && (!$proxyServer || !$proxyPort)) {
            throw new ValidationException(__u('Missing Proxy parameters'));
        }

        if ($proxyEnabled) {
            $configData->setProxyEnabled(true);
            $configData->setProxyServer($proxyServer);
            $configData->setProxyPort($proxyPort);
            $configData->setProxyUser($proxyUser);

            if ($proxyPass !== null && $proxyPass !== '***') {
                $configData->setProxyPass($proxyPass);
            }
        } else {
            $configData->setProxyEnabled(false);
        }
    }

    /**
     * @throws SPException
     * @throws SessionTimeout
     */
    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_GENERAL);
    }
}
