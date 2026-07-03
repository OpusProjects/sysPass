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

use PDO;
use PDOException;
use SP\Application\Install\Services\InstallThrottle;
use SP\Core\Application;
use SP\Core\Language;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Install\Adapters\DatabaseHost;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use Throwable;

use function SP\__;
use function SP\__u;
use function SP\processException;

/**
 * Class CheckConnectionController
 *
 * Tries a MySQL connection with the credentials entered in the install
 * wizard's Database step, without selecting any schema.
 */
final class CheckConnectionController extends ControllerBase
{
    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        private readonly LanguageInterface $language,
        private readonly InstallThrottle $installThrottle
    ) {
        parent::__construct($application, $webControllerHelper);
    }

    #[Action(ResponseType::JSON)]
    public function checkConnectionAction(): ActionResponse
    {
        // Respond in the language chosen in the wizard, not the browser's
        $lang = $this->request->analyzeString('sitelang');

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

        $host = trim($this->request->analyzeString('dbhost') ?? '');
        $user = $this->request->analyzeString('dbuser') ?? '';
        // PKI-encrypted client-side, like the install submit; falls back to the
        // raw value when the client could not encrypt
        $pass = $this->request->analyzeEncrypted('dbpass') ?? '';

        if ($host === '') {
            // Without a host, PDO would silently connect to localhost and report
            // a misleading success
            return ActionResponse::error(__u('Please, enter the database server'));
        }

        try {
            // Same host formats the installer accepts: host, host:port, [ipv6]:port, unix:socket
            $target = DatabaseHost::parse($host);

            $dsn = $target->socket !== null
                ? sprintf('mysql:unix_socket=%s', $target->socket)
                : sprintf('mysql:host=%s;port=%d', $target->host, $target->port ?? 3306);

            new PDO(
                $dsn,
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            return ActionResponse::ok(__u('Connection successful'));
        } catch (PDOException $e) {
            processException($e);

            // translate now — the response serializer can only translate exact msgids
            return ActionResponse::error(
                sprintf('%s (%s)', __('Unable to connect to DB'), $e->getMessage())
            );
        } catch (Throwable $e) {
            processException($e);

            return ActionResponse::error($e->getMessage());
        }
    }
}
