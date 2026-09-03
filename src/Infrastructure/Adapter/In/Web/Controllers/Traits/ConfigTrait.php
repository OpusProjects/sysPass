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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Traits;

use Exception;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Ports\ConfigBackupService;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__u;
use function SP\processException;

/**
 * Trait ConfigTrait
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers\Traits
 */
trait ConfigTrait
{
    /**
     * Save the configuration
     *
     * @throws SPException
     */
    protected function saveConfig(
        ConfigDataInterface $configData,
        ConfigFileService   $config,
        ConfigBackupService $configBackup,
        ?callable           $onSuccess = null
    ): ActionResponse {
        try {
            if ($configData->isDemoEnabled()) {
                return ActionResponse::warning(__u('Ey, this is a DEMO!!'));
            }

            // Keep the configuration being replaced, so there is something to go back to.
            //
            // `ConfigBackupService::backup()` has existed since this rewrite was imported and was
            // called from nowhere, so `config_backup` was never written — and the "Download config
            // backup" link the Information page renders answered "Unable to retrieve the
            // configuration" every time, for every installation.
            //
            // Here rather than inside `ConfigFile::save()`, which is where it belongs on paper:
            // that would put `ConfigBackupService` in the constructor of a service the container
            // builds while booting, and it needs `ConfigService`, which needs `Application`, which
            // needs `ConfigFileService` — a cycle that only a lazy proxy breaks, on the one object
            // every request depends on. This is every door an administrator changes configuration
            // through, and it costs the boot path nothing.
            //
            // `getConfigData()` answers a clone, and `save()` has not run yet, so what it hands
            // over here is still the previous configuration. `backup()` logs and swallows its own
            // failures, so a database that cannot take it does not stop the save.
            $configBackup->backup($config->getConfigData());

            $config->save($configData);

            if ($onSuccess !== null) {
                $onSuccess();
            }

            return ActionResponse::ok(__u('Configuration updated'));
        } catch (Exception $e) {
            processException($e);

            return ActionResponse::error(__u('Error while saving the configuration'), $e->getMessage());
        }
    }
}
