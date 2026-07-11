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

namespace SP\Infrastructure\Bootstrap;

use PHPMailer\PHPMailer\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SP\Infrastructure\AppLock;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Domain\Common\Providers\Environment;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Config\Services\ConfigUtil;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\CheckException;
use SP\Domain\Core\Exceptions\ConfigException;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Http\Ports\ResponseService;
use Symfony\Component\ErrorHandler\Debug;
use Throwable;

use function SP\__;
use function SP\__u;
use function SP\logger;
use function SP\processException;

/**
 * Class BootstrapBase
 */
abstract class BootstrapBase implements BootstrapInterface
{
    public const CONTEXT_ACTION_NAME = '_actionName';

    protected const OOPS_MESSAGE = 'Oops, it looks like this content does not exist...';

    final public function __construct(
        protected readonly ConfigDataInterface $configData,
        protected readonly Router              $router,
        protected readonly RequestService   $request,
        protected readonly PhpExtensionChecker $extensionChecker,
        protected readonly Context          $context,
        private readonly ContainerInterface    $container,
        protected readonly ResponseService    $response,
        protected readonly RouteContextData $routeContextData,
        LanguageInterface                   $language,
        protected readonly PathsContext     $pathsContext,
        protected readonly EventDispatcher  $eventDispatcher
    ) {
        // Set the default language
        $language->setLocales('en_US');

        $this->initRouter();
        $this->configureRouter();
    }

    private function initRouter(): void
    {
        $this->router->onError(
            static function (Router $router, string $err_msg, string $type, Exception|Throwable $err): void {
                logger(sprintf('Routing error: %s', $err_msg));
                logger(sprintf('Routing error: %s', $err->getTraceAsString()));

                if (defined('TEST_ROOT')) {
                    $router->response()->body(__($err_msg) . PHP_EOL . $err->getTraceAsString());

                    throw $err;
                } else {
                    $router->response()->body(__($err_msg));
                }
            }
        );

        // Manage requests for options
        $this->router->respond(
            'OPTIONS',
            null,
            function ($request, $response) {
                /** @var ResponseService $response */
                $this->setCors($response);
            }
        );
    }

    final protected function setCors(ResponseService $response): void
    {
        $response->header(
            'Access-Control-Allow-Origin',
            $this->configData->getApplicationUrl() ?? $this->request->getHttpHost()
        );
        $response->header(
            'Access-Control-Allow-Headers',
            'X-Requested-With, Content-Type, Accept, Origin, Authorization'
        );
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }

    abstract protected function configureRouter(): void;

    abstract public static function run(BootstrapInterface $bootstrap, ModuleInterface $initModule): void;

    final protected static function getClassFor(string $module, string $controllerName, string $actionName): string
    {
        return sprintf(
            'SP\Infrastructure\Adapter\In\%s\Controllers\%s\%sController',
            ucfirst($module),
            ucfirst($controllerName),
            ucfirst($actionName)
        );
    }

    /**
     * Handle the request through the router
     *
     * @return void
     */
    final protected function handleRequest(): void
    {
        $this->router->dispatch($this->request->getRequest(), $this->response);
    }

    /**
     * @throws CheckException
     * @throws ConfigException
     * @throws InitializationException
     */
    final protected function initializeCommon(): void
    {
        logger(__FUNCTION__);

        $checkPhpVersion = Environment::checkPhpVersion();

        // Initialize authentication variables
        $this->initAuthVariables();

        // Initialize logging
        $this->initPHPVars();

        $this->extensionChecker->checkMandatory();

        if (!$checkPhpVersion) {
            throw InitializationException::error(
                sprintf(__('Required PHP version >= %s <= %s'), '8.4', '8.5'),
                __u('Please update the PHP version to run sysPass')
            );
        }

        // Check and intitialize configuration
        $this->initConfig();
    }

    /**
     * Set the authentication variables
     */
    private function initAuthVariables(): void
    {
        $server = $this->router->request()->server;

        // Copy the HTTP authentication header for apache+php-fcgid
        if ($server->get('HTTP_XAUTHORIZATION') !== null && $server->get('HTTP_AUTHORIZATION') === null) {
            $server->set('HTTP_AUTHORIZATION', $server->get('HTTP_XAUTHORIZATION'));
        }

        // Set the authentication headers so that apache+php-cgi works if the variable
        // is renamed by apache
        $authorization = $server->get('HTTP_AUTHORIZATION') ?? $server->get('REDIRECT_HTTP_AUTHORIZATION', '');

        if (preg_match('/Basic\s+(.*)$/i', $authorization, $matches)) {
            $decoded = base64_decode($matches[1]);
            $separatorPos = strpos($decoded, ':');

            if ($separatorPos !== false) {
                $server->set('PHP_AUTH_USER', strip_tags(substr($decoded, 0, $separatorPos)));
                $server->set('PHP_AUTH_PW', strip_tags(substr($decoded, $separatorPos + 1)));
            }
        }
    }

    /**
     * Set the logging level
     */
    private function initPHPVars(): void
    {
        if (DEBUG) {
            Debug::enable();
        } else {
            // E_STRICT was deprecated in 8.4 and removed in 8.5; it has been a no-op since 8.0.
            error_reporting(E_ALL & ~(E_DEPRECATED | E_NOTICE));

            if (!headers_sent()) {
                ini_set('display_errors', 0);
            }
        }

        $logFile = $this->pathsContext[Path::LOG_FILE];

        if (!file_exists($logFile)
            && touch($logFile)
            && chmod($logFile, 0600)
        ) {
            logger('Setup log file: ' . $logFile);
        }

        if (date_default_timezone_get() === 'UTC') {
            date_default_timezone_set('UTC');
        }

        if (!headers_sent()) {
            // Avoid PHP session cookies from JavaScript
            ini_set('session.cookie_httponly', '1');
            ini_set('session.save_handler', 'files');
        }
    }

    /**
     * Load the configuration
     *
     * @throws ConfigException
     */
    private function initConfig(): void
    {
        ConfigUtil::checkConfigDir();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&object
     */
    final protected function buildInstanceFor(string $class): object
    {
        try {
            return $this->container->get($class);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            processException($e);

            throw new RuntimeException($e->getMessage());
        }
    }
}
