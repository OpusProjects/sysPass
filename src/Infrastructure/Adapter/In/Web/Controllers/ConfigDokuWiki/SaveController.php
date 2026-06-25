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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigDokuWiki;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Traits\ConfigTrait;

use function SP\__u;

final class SaveController extends SimpleControllerBase
{
    use ConfigTrait;

    #[Action(ResponseType::JSON)]
    public function saveAction(): ActionResponse
    {
        $eventMessage = EventMessage::build();
        $configData = $this->config->getConfigData();

        $dokuwikiEnabled = $this->request->analyzeBool('dokuwiki_enabled', false);
        $dokuwikiUrl = $this->request->analyzeString('dokuwiki_url');
        $dokuwikiUrlBase = $this->request->analyzeString('dokuwiki_urlbase');
        $dokuwikiUser = $this->request->analyzeString('dokuwiki_user');
        $dokuwikiPass = $this->request->analyzeString('dokuwiki_pass');
        $dokuwikiNamespace = $this->request->analyzeString('dokuwiki_namespace');

        if ($dokuwikiEnabled && (!$dokuwikiUrl || !$dokuwikiUrlBase)) {
            return ActionResponse::error(__u('Missing DokuWiki parameters'));
        }

        if ($dokuwikiEnabled) {
            $configData->setDokuwikiEnabled(true);
            $configData->setDokuwikiUrl($dokuwikiUrl);
            $configData->setDokuwikiUrlBase($dokuwikiUrlBase);
            $configData->setDokuwikiUser($dokuwikiUser);
            $configData->setDokuwikiPass($dokuwikiPass);
            $configData->setDokuwikiNamespace($dokuwikiNamespace);

            if ($configData->isDokuwikiEnabled() === false) {
                $eventMessage->addDescription(__u('DokuWiki enabled'));
            }
        } elseif ($configData->isDokuwikiEnabled()) {
            $configData->setDokuwikiEnabled(false);

            $eventMessage->addDescription(__u('DokuWiki disabled'));
        } else {
            return ActionResponse::ok(__u('No changes'));
        }

        return $this->saveConfig(
            $configData,
            $this->config,
            function () use ($eventMessage) {
                $this->eventDispatcher->notify(new Event('save.config.dokuwiki', $this, $eventMessage));
            }
        );
    }

    /**
     * @throws SessionTimeout
     */
    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_WIKI);
    }
}
