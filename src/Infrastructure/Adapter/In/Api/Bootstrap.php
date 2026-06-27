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

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Core\Bootstrap\BootstrapBase;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Code;
use SP\Domain\Http\Ports\ResponseService;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use Throwable;

use function SP\logger;
use function SP\processException;

/**
 * Class Bootstrap
 */
final class Bootstrap extends BootstrapBase
{
    protected ModuleInterface $module;

    private const ROUTE_MAP = [
        // Accounts
        ['GET',    '/api/v1/accounts',                'account',   'search'],
        ['POST',   '/api/v1/accounts',                'account',   'create'],
        ['GET',    '/api/v1/accounts/{id}',           'account',   'view'],
        ['PUT',    '/api/v1/accounts/{id}',           'account',   'edit'],
        ['DELETE', '/api/v1/accounts/{id}',           'account',   'delete'],
        ['POST',   '/api/v1/accounts/{id}/password',  'account',   'viewPass'],
        ['PUT',    '/api/v1/accounts/{id}/password',  'account',   'editPass'],
        ['GET',    '/api/v1/accounts/{id}/files',           'accountFile', 'search'],
        ['POST',   '/api/v1/accounts/{id}/files',           'accountFile', 'upload'],
        ['GET',    '/api/v1/accounts/{id}/files/{fileId}',  'accountFile', 'view'],
        ['DELETE', '/api/v1/accounts/{id}/files/{fileId}',  'accountFile', 'delete'],

        // Categories
        ['GET',    '/api/v1/categories',              'category',  'search'],
        ['POST',   '/api/v1/categories',              'category',  'create'],
        ['GET',    '/api/v1/categories/{id}',         'category',  'view'],
        ['PUT',    '/api/v1/categories/{id}',         'category',  'edit'],
        ['DELETE', '/api/v1/categories/{id}',         'category',  'delete'],

        // Clients
        ['GET',    '/api/v1/clients',                 'client',    'search'],
        ['POST',   '/api/v1/clients',                 'client',    'create'],
        ['GET',    '/api/v1/clients/{id}',            'client',    'view'],
        ['PUT',    '/api/v1/clients/{id}',            'client',    'edit'],
        ['DELETE', '/api/v1/clients/{id}',            'client',    'delete'],

        // Tags
        ['GET',    '/api/v1/tags',                    'tag',       'search'],
        ['POST',   '/api/v1/tags',                    'tag',       'create'],
        ['GET',    '/api/v1/tags/{id}',               'tag',       'view'],
        ['PUT',    '/api/v1/tags/{id}',               'tag',       'edit'],
        ['DELETE', '/api/v1/tags/{id}',               'tag',       'delete'],

        // User Groups
        ['GET',    '/api/v1/user-groups',             'userGroup', 'search'],
        ['POST',   '/api/v1/user-groups',             'userGroup', 'create'],
        ['GET',    '/api/v1/user-groups/{id}',        'userGroup', 'view'],
        ['PUT',    '/api/v1/user-groups/{id}',        'userGroup', 'edit'],
        ['DELETE', '/api/v1/user-groups/{id}',        'userGroup', 'delete'],

        // Profiles
        ['GET',    '/api/v1/profiles',                'profile',   'search'],
        ['POST',   '/api/v1/profiles',                'profile',   'create'],
        ['GET',    '/api/v1/profiles/{id}',           'profile',   'view'],
        ['PUT',    '/api/v1/profiles/{id}',           'profile',   'edit'],
        ['DELETE', '/api/v1/profiles/{id}',           'profile',   'delete'],

        // Users
        ['GET',    '/api/v1/users',                   'user',      'search'],
        ['POST',   '/api/v1/users',                   'user',      'create'],
        ['GET',    '/api/v1/users/{id}',              'user',      'view'],
        ['PUT',    '/api/v1/users/{id}',              'user',      'edit'],
        ['DELETE', '/api/v1/users/{id}',              'user',      'delete'],

        // Auth Tokens
        ['GET',    '/api/v1/auth-tokens',             'authToken', 'search'],
        ['POST',   '/api/v1/auth-tokens',             'authToken', 'create'],
        ['GET',    '/api/v1/auth-tokens/{id}',        'authToken', 'view'],
        ['PUT',    '/api/v1/auth-tokens/{id}',        'authToken', 'edit'],
        ['DELETE', '/api/v1/auth-tokens/{id}',        'authToken', 'delete'],

        // Public Links
        ['GET',    '/api/v1/public-links',                  'publicLink', 'search'],
        ['POST',   '/api/v1/public-links',                  'publicLink', 'create'],
        ['GET',    '/api/v1/public-links/{id}',             'publicLink', 'view'],
        ['DELETE', '/api/v1/public-links/{id}',             'publicLink', 'delete'],
        ['POST',   '/api/v1/public-links/{id}/refresh',     'publicLink', 'refresh'],

        // Notifications
        ['GET',    '/api/v1/notifications',              'notification', 'search'],
        ['POST',   '/api/v1/notifications',              'notification', 'create'],
        ['GET',    '/api/v1/notifications/{id}',         'notification', 'view'],
        ['PUT',    '/api/v1/notifications/{id}',         'notification', 'edit'],
        ['DELETE', '/api/v1/notifications/{id}',         'notification', 'delete'],
        ['PUT',    '/api/v1/notifications/{id}/check',   'notification', 'check'],

        // Event Log
        ['GET',    '/api/v1/event-log',               'eventlog',    'search'],
        ['DELETE', '/api/v1/event-log',               'eventlog',    'clear'],

        // Custom Fields
        ['GET',    '/api/v1/custom-fields',           'customField', 'search'],
        ['POST',   '/api/v1/custom-fields',           'customField', 'create'],
        ['GET',    '/api/v1/custom-fields/{id}',      'customField', 'view'],
        ['PUT',    '/api/v1/custom-fields/{id}',      'customField', 'edit'],
        ['DELETE', '/api/v1/custom-fields/{id}',      'customField', 'delete'],

        // Config
        ['POST',   '/api/v1/config/backup',           'config',    'backup'],
        ['POST',   '/api/v1/config/export',           'config',    'export'],
    ];

    public static function run(BootstrapInterface $bootstrap, ModuleInterface $initModule): void
    {
        logger('------------');
        logger('Boostrap:api');

        try {
            $bootstrap->module = $initModule;
            $bootstrap->handleRequest();
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            processException($e);

            die($e->getMessage());
        }
    }

    protected function configureRouter(): void
    {
        foreach (self::ROUTE_MAP as [$method, $path, $controller, $action]) {
            $requirements = [];
            if (str_contains($path, '{id}')) {
                $requirements['id'] = '\d+';
            }
            if (str_contains($path, '{fileId}')) {
                $requirements['fileId'] = '\d+';
            }
            $this->router->respondPath(
                $method,
                $path,
                $this->handleRestRequest($controller, $action),
                $requirements
            );
        }

        // Catch-all for unmatched /api/v1 paths
        $this->router->respond(
            ['GET', 'POST', 'PUT', 'DELETE'],
            null,
            function ($request, ResponseService $response) {
                $response->code(Code::NOT_FOUND->value);
                $response->headers()->set('Content-type', 'application/json; charset=utf-8');
                $response->body(json_encode([
                    'error' => [
                        'message' => 'Not found. See /api/docs for documentation.',
                    ],
                ]));
            }
        );
    }

    private function handleRestRequest(string $controllerName, string $actionName): Closure
    {
        return function ($request, ResponseService $response) use ($controllerName, $actionName) {
            try {
                logger('REST route: ' . $controllerName . '/' . $actionName);

                $response->headers()->set('Content-type', 'application/json; charset=utf-8');
                $this->setCors($response);

                $request->attributes->set('_rest_method', $controllerName . '/' . $actionName);

                $controllerClass = self::getClassFor($this->module->getName(), $controllerName, $actionName);
                $method = $actionName . 'Action';

                if (!method_exists($controllerClass, $method)) {
                    logger($controllerClass . '::' . $method);

                    $response->code(Code::NOT_FOUND->value);

                    return $response->body(json_encode([
                        'error' => ['message' => 'Endpoint not found'],
                    ]));
                }

                $this->context->setTrasientKey(self::CONTEXT_ACTION_NAME, $actionName);

                $this->initializeCommon();

                $this->module->initialize($controllerName);

                logger('Routing call: ' . $controllerClass . '::' . $method);

                /** @var ApiResponse $apiResponse */
                $apiResponse = call_user_func([$this->buildInstanceFor($controllerClass), $method]);

                $responseData = $apiResponse->getResponse();

                $httpCode = $responseData['resultCode'] === 0
                    ? ($actionName === 'create' ? Code::CREATED->value : Code::OK->value)
                    : Code::BAD_REQUEST->value;

                $body = ['data' => $responseData['result']];

                if ($responseData['resultMessage'] !== null) {
                    $body['message'] = $responseData['resultMessage'];
                }
                if ($responseData['count'] !== null) {
                    $body['count'] = $responseData['count'];
                }
                if ($responseData['itemId'] !== null) {
                    $body['itemId'] = $responseData['itemId'];
                }

                $response->code($httpCode);

                return $response->body(Serde::serializeJson($body, JSON_UNESCAPED_SLASHES));
            } catch (Throwable $e) {
                processException($e);

                $httpCode = self::mapExceptionToHttpCode($e);
                $response->code($httpCode);

                $errorBody = ['error' => ['message' => $e->getMessage()]];

                if ($e instanceof SPException && $e->getHint() !== null) {
                    $errorBody['error']['detail'] = $e->getHint();
                }

                return $response->body(json_encode($errorBody, JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        };
    }

    private static function mapExceptionToHttpCode(Throwable $e): int
    {
        if ($e instanceof NoSuchItemException) {
            return Code::NOT_FOUND->value;
        }

        $code = $e->getCode();

        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return Code::INTERNAL_SERVER_ERROR->value;
    }
}
