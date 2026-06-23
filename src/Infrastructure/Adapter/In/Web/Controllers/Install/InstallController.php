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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Install;


use Exception;
use SP\Core\Application;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Install\Adapters\InstallData;
use SP\Application\Install\Ports\InstallerService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;
use function SP\processException;

/**
 * Class InstallController
 */
final class InstallController extends ControllerBase
{
    private InstallerService $installer;
    private InstallData $installData;

    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        InstallerService $installer,
        InstallData $installData
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->installer = $installer;
        // Inject the same shared InstallData instance the setup services use, so the host
        // detection in Installer::setupDbHost() is visible to MysqlSetup (otherwise a second,
        // freshly-built copy leaves DbAuthHost null and the CREATE USER quoting fails).
        $this->installData = $installData;
    }

    /**
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function installAction(): ActionResponse
    {
        try {
            $this->installer->run($this->installData);

            return ActionResponse::ok(__u('Installation finished'));
        } catch (Exception $e) {
            processException($e);

            return ActionResponse::error($e->getMessage());
        }
    }
}
