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


use SP\Core\Application;
use SP\Core\Language;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Install\Adapters\InstallData;
use SP\Application\Install\Ports\InstallerService;
use SP\Application\Install\Services\InstallThrottle;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use Throwable;

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
        InstallData $installData,
        private readonly LanguageInterface $language,
        private readonly InstallThrottle $installThrottle
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
            // Respond in the language chosen in the wizard, not the browser's
            $lang = $this->installData->getSiteLang();

            if ($lang && array_key_exists($lang, Language::getAvailableLanguages())) {
                $this->language->setLocales($lang);
            }

            if ($this->configData->isInstalled()) {
                return ActionResponse::error(__u('sysPass is already installed'));
            }

            // Unauthenticated endpoint that opens outbound connections: rate-limit it
            if (!$this->installThrottle->check()) {
                return ActionResponse::error(__u('Attempts exceeded'));
            }

            $this->installer->run($this->installData);

            return ActionResponse::ok(__u('Installation finished'));
        } catch (Throwable $e) {
            // Throwable, not Exception: the wizard expects a JSON response
            // even for a TypeError/Error during the install
            processException($e);

            return ActionResponse::error($e->getMessage());
        }
    }
}
