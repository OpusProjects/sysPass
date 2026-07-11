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

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Events\EventDispatcherInterface;

/**
 * Class ModuleBase
 */
abstract class ModuleBase implements ModuleInterface
{
    protected ConfigFileService   $config;
    protected ConfigDataInterface $configData;
    protected Context             $context;
    private EventDispatcherInterface $eventDispatcher;

    /**
     * Module constructor.
     *
     * @param Application $application
     * @param ProvidersHelper $providersHelper
     */
    public function __construct(Application $application, private readonly ProvidersHelper $providersHelper)
    {
        $this->config = $application->getConfig();
        $this->configData = $this->config->getConfigData();
        $this->context = $application->getContext();
        $this->eventDispatcher = $application->getEventDispatcher();
    }

    /**
     * Initializes event handlers
     */
    protected function initEventHandlers(bool $partialInit = false): void
    {
        $this->eventDispatcher->attach($this->providersHelper->getLogHandler());

        if ($partialInit || !$this->configData->isInstalled()) {
            return;
        }

        if ($this->configData->isLogEnabled() && ($dbLog = $this->providersHelper->getDatabaseLogHandler())) {
            $this->eventDispatcher->attach($dbLog);
        }

        if ($this->configData->isMailEnabled() && ($mail = $this->providersHelper->getMailHandler())) {
            $this->eventDispatcher->attach($mail);
        }

        if ($acl = $this->providersHelper->getAclHandler()) {
            $this->eventDispatcher->attach($acl);
        }

        if ($notification = $this->providersHelper->getNotificationHandler()) {
            $this->eventDispatcher->attach($notification);
        }
    }

    /**
     * Checks whether the application code or its database schema are ahead of the
     * version recorded in the config (i.e. an upgrade needs to run).
     *
     * The stored app/database versions are set at install time and bumped as
     * upgrades are applied, so a fresh install (where both are written as the
     * current version) correctly reports no upgrade needed. A missing/empty
     * stored version is treated as needing an upgrade, mirroring the legacy
     * behaviour that fixed the v2 -> v3 upgrade path.
     */
    protected function checkUpgradeNeeded(): bool
    {
        $currentVersion = Version::getVersionStringNormalized();

        return $this->isVersionOutdated($this->configData->getDatabaseVersion(), $currentVersion)
               || $this->isVersionOutdated($this->configData->getAppVersion(), $currentVersion);
    }

    private function isVersionOutdated(?string $storedVersion, string $currentVersion): bool
    {
        return empty($storedVersion) || Version::checkVersion($storedVersion, $currentVersion);
    }
}
