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

namespace SP\Infrastructure\Adapter\In\Api;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Infrastructure\Context\ContextException;
use SP\Infrastructure\HttpModuleBase;
use SP\Infrastructure\Language;
use SP\Infrastructure\ProvidersHelper;
use SP\Domain\Common\Providers\Http;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Infrastructure\Http\Ports\RequestService;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Infrastructure\File\FileException;

use function SP\logger;

/**
 * Class Init
 */
final class Init extends HttpModuleBase
{
    private Language     $language;
    private DatabaseUtil $databaseUtil;

    public function __construct(
        Application       $application,
        ProvidersHelper   $providersHelper,
        RequestService    $request,
        Router            $router,
        AppLockHandler    $appLock,
        LanguageInterface $language,
        DatabaseUtil      $databaseUtil
    ) {
        parent::__construct(
            $application,
            $providersHelper,
            $request,
            $router,
            $appLock
        );

        $this->language = $language;
        $this->databaseUtil = $databaseUtil;
    }

    /**
     * @param string $controller
     * @throws ContextException
     * @throws InitializationException
     * @throws SPException
     * @throws FileException
     * @throws EnvironmentIsBrokenException
     */
    public function initialize(string $controller): void
    {
        logger(__FUNCTION__);

        // Initialize context
        $this->context->initialize();

        // Load language
        $this->language->setLanguage();

        // Checks if it needs to switch the request over HTTPS
        Http::checkHttps($this->configData, $this->request);

        // Checks if sysPass is installed
        $this->checkInstalled();

        // Checks if maintenance mode is turned on
        if ($this->checkMaintenanceMode()) {
            throw new InitializationException('Maintenance mode');
        }

        // Checks if upgrade is needed
        if ($this->checkUpgradeNeeded()) {
            logger('Upgrade needed', 'INFO');

            $this->config->generateUpgradeKey();

            throw new InitializationException('Upgrade needed');
        }

        // Checks if the database is set up
        if (!$this->databaseUtil->checkDatabaseConnection()) {
            throw new InitializationException('Database connection error');
        }

        if (!$this->databaseUtil->checkDatabaseTables($this->configData->getDbName() ?? '')) {
            throw new InitializationException('Database checking error');
        }

        // Initialize event handlers
        $this->initEventHandlers();
    }

    /**
     * Checks that the application is installed
     * This method checks whether the application is installed. If it is not, it redirects to the installer.
     *
     * @throws InitializationException
     */
    private function checkInstalled(): void
    {
        if (!$this->configData->isInstalled()) {
            throw new InitializationException('Not installed');
        }
    }

    public function getName(): string
    {
        return 'api';
    }
}
