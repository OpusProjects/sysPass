<?php
declare(strict_types=1);
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

namespace SP\Tests\Infrastructure\Adapter\In\Api;

use DI\ContainerBuilder;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\User\Ports\UserService;
use SP\Core\Bootstrap\Path;
use SP\Core\Definitions\CoreDefinitions;
use SP\Core\Definitions\DomainDefinitions;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Api\Bootstrap;
use SP\Infrastructure\Database\DatabaseConnectionData;
use SP\Infrastructure\File\FileSystem;
use SP\Tests\DatabaseTrait;
use stdClass;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Base class for the REST API controller tests.
 *
 * Builds the REAL container (like CliTestCase) against a REAL database seeded
 * with the fixtures, and drives the real REST Bootstrap dispatch — exactly what
 * public/api.php does. Each call authenticates with a real, crypto-backed auth
 * token created through AuthTokenService.
 *
 * The REST envelope the product emits: success => HTTP 200/201 with
 * {data, message?, count?, itemId?}; error => 4xx with {error:{message, detail?}}.
 */
abstract class ApiTestCase extends TestCase
{
    use DatabaseTrait;

    /** The token password. */
    protected const AUTH_TOKEN_PASS = '123456';
    /** The master password stored in the fixture DB. */
    private const MASTER_PASS = '12345678900';
    private const ADMIN_USER_ID = 1;

    /** actionId => [HTTP method, REST path template]. */
    private const REST_ROUTES = [
        AclActionsInterface::ACCOUNT_SEARCH    => ['GET', '/api/v1/accounts'],
        AclActionsInterface::ACCOUNT_CREATE    => ['POST', '/api/v1/accounts'],
        AclActionsInterface::ACCOUNT_VIEW      => ['GET', '/api/v1/accounts/{id}'],
        AclActionsInterface::ACCOUNT_EDIT      => ['PUT', '/api/v1/accounts/{id}'],
        AclActionsInterface::ACCOUNT_DELETE    => ['DELETE', '/api/v1/accounts/{id}'],
        AclActionsInterface::ACCOUNT_VIEW_PASS   => ['POST', '/api/v1/accounts/{id}/password'],
        AclActionsInterface::ACCOUNT_EDIT_PASS   => ['PUT', '/api/v1/accounts/{id}/password'],
        AclActionsInterface::ACCOUNT_FILE_UPLOAD => ['POST', '/api/v1/accounts/{id}/files'],
        AclActionsInterface::CATEGORY_SEARCH   => ['GET', '/api/v1/categories'],
        AclActionsInterface::CATEGORY_CREATE   => ['POST', '/api/v1/categories'],
        AclActionsInterface::CATEGORY_VIEW     => ['GET', '/api/v1/categories/{id}'],
        AclActionsInterface::CATEGORY_EDIT     => ['PUT', '/api/v1/categories/{id}'],
        AclActionsInterface::CATEGORY_DELETE   => ['DELETE', '/api/v1/categories/{id}'],
        AclActionsInterface::CLIENT_SEARCH     => ['GET', '/api/v1/clients'],
        AclActionsInterface::CLIENT_CREATE     => ['POST', '/api/v1/clients'],
        AclActionsInterface::CLIENT_VIEW       => ['GET', '/api/v1/clients/{id}'],
        AclActionsInterface::CLIENT_EDIT       => ['PUT', '/api/v1/clients/{id}'],
        AclActionsInterface::CLIENT_DELETE     => ['DELETE', '/api/v1/clients/{id}'],
        AclActionsInterface::TAG_SEARCH        => ['GET', '/api/v1/tags'],
        AclActionsInterface::TAG_CREATE        => ['POST', '/api/v1/tags'],
        AclActionsInterface::TAG_VIEW          => ['GET', '/api/v1/tags/{id}'],
        AclActionsInterface::TAG_EDIT          => ['PUT', '/api/v1/tags/{id}'],
        AclActionsInterface::TAG_DELETE        => ['DELETE', '/api/v1/tags/{id}'],
        AclActionsInterface::GROUP_SEARCH      => ['GET', '/api/v1/user-groups'],
        AclActionsInterface::GROUP_CREATE      => ['POST', '/api/v1/user-groups'],
        AclActionsInterface::GROUP_VIEW        => ['GET', '/api/v1/user-groups/{id}'],
        AclActionsInterface::GROUP_EDIT        => ['PUT', '/api/v1/user-groups/{id}'],
        AclActionsInterface::GROUP_DELETE      => ['DELETE', '/api/v1/user-groups/{id}'],
        AclActionsInterface::CONFIG_BACKUP_RUN => ['POST', '/api/v1/config/backup'],
        AclActionsInterface::CONFIG_EXPORT_RUN => ['POST', '/api/v1/config/export'],
    ];

    private static ?array $apiModuleDefinitions = null;
    protected string      $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();
        self::truncateTable('AuthToken');

        // The fixture's serialized User.preferences / UserProfile.profile use the
        // pre-rewrite SP\DataModel namespace, which the current hydrators reject.
        // These API tests authenticate as the admin user (id 1, ACL bypassed) and
        // never assert this data, so replace it with values the hydrators accept:
        // NULL preferences, and a serialized empty (current) ProfileData.
        $conn = getDbHandler()->getConnection();
        $conn->exec('UPDATE `User` SET `preferences` = NULL');
        $profile = $conn->prepare('UPDATE `UserProfile` SET `profile` = ?');
        $profile->execute([serialize(new ProfileData())]);

        // Real (non-vfs) dirs: config for an installed config.xml, and
        // cache/tmp/backup because PharData (backup/export) cannot use vfsStream
        $this->cleanApiTestRoot();

        $this->configPath = FileSystem::buildPath(self::apiTestRoot(), 'config');

        foreach ([$this->configPath, self::cachePath(), self::tmpPath(), self::backupPath()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new Exception(sprintf('Directory "%s" was not created', $dir));
            }
        }

        // The fixture config.xml is not "installed" and has no DB name; patch it so
        // Init's checkInstalled / checkDatabaseTables pass (the actual DB connection
        // is the real getDbHandler() override, so the creds here are not used).
        $dbConn = DatabaseConnectionData::getFromEnvironment();
        $config = getResource('config', 'config.xml');
        $config = preg_replace('#<installed>.*?</installed>#', '<installed>1</installed>', $config);
        $config = preg_replace('#<dbName>.*?</dbName>#', '<dbName>' . $dbConn->getDbName() . '</dbName>', $config);
        $config = preg_replace('#<dbHost>.*?</dbHost>#', '<dbHost>' . $dbConn->getDbHost() . '</dbHost>', $config);

        file_put_contents(FileSystem::buildPath($this->configPath, 'config.xml'), $config);
    }

    protected function tearDown(): void
    {
        $this->cleanApiTestRoot();

        parent::tearDown();
    }

    private function cleanApiTestRoot(): void
    {
        if (is_dir(self::apiTestRoot())) {
            FileSystem::rmdirRecursive(self::apiTestRoot());
        }
    }

    /**
     * Call an API action and return the decoded REST response.
     *
     * @return stdClass {status:int, body:stdClass}
     * @throws Exception
     */
    final protected function callApi(int $actionId, array $params): stdClass
    {
        // Create the auth token first (in its own container) — it persists in the
        // shared real DB for the dispatch container's lookup
        $token = $this->createToken($actionId);

        $request = $this->buildRestRequest($actionId, $token, $params);

        $dic = $this->buildContainer($request);

        Bootstrap::run($dic->get(BootstrapInterface::class), $dic->get(ModuleInterface::class));

        $response = $dic->get(ResponseService::class)->getResponse();

        return (object)[
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @throws Exception
     */
    private function createToken(int $actionId): string
    {
        // One token per call; a repeated action would otherwise duplicate-key
        self::truncateTable('AuthToken');

        $dic = $this->buildContainer();

        $context = $dic->get(Context::class);
        $context->initialize();
        $context->setUserData(
            UserDto::fromModel($dic->get(UserService::class)->getById(self::ADMIN_USER_ID))
        );
        // Needed to build the secure-token vault (getMasterKeyFromContext)
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);

        $authTokenService = $dic->get(AuthTokenService::class);

        $id = $authTokenService->create(
            new AuthTokenModel([
                'actionId' => $actionId,
                'userId' => self::ADMIN_USER_ID,
                'hash' => self::AUTH_TOKEN_PASS,
                'createdBy' => self::ADMIN_USER_ID,
            ])
        );

        return $authTokenService->getById($id)->getToken();
    }

    private function buildRestRequest(int $actionId, string $token, array $params): SymfonyRequest
    {
        [$method, $pathTemplate] = self::REST_ROUTES[$actionId];

        $id = $params['id'] ?? null;
        unset($params['id']);

        $path = str_replace('{id}', (string)($id ?? 0), $pathTemplate);

        // RestApiRequest reads params from the query string and the JSON body (and
        // route attributes for {id}); the auth token comes from the Authorization
        // header. For GET everything goes in the query; for write methods it must
        // go in the JSON body (Symfony's Request::create routes the 3rd arg into
        // the request bag for non-GET, which RestApiRequest does not read).
        $params['tokenPass'] = self::AUTH_TOKEN_PASS;

        if ($method === 'GET') {
            $request = SymfonyRequest::create($path, $method, $params);
        } else {
            $request = SymfonyRequest::create(
                $path,
                $method,
                [],
                [],
                [],
                [],
                json_encode($params, JSON_THROW_ON_ERROR)
            );
        }

        $request->headers->set('Authorization', 'Bearer ' . $token);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /**
     * @throws Exception
     */
    private function buildContainer(?SymfonyRequest $request = null): ContainerInterface
    {
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'api');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        // Real runtime dirs (avoid the repo's var/ and vfsStream)
        $coreDefinitions['paths'] = array_map(
            static fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, self::cachePath()],
                Path::TMP => [Path::TMP, self::tmpPath()],
                Path::BACKUP => [Path::BACKUP, self::backupPath()],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        if (self::$apiModuleDefinitions === null) {
            self::$apiModuleDefinitions = FileSystem::require(
                FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Api', 'module.php')
            );
        }

        $overrides = [DbStorageHandler::class => getDbHandler()];

        if ($request !== null) {
            $overrides[SymfonyRequest::class] = $request;
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            $coreDefinitions,
            self::$apiModuleDefinitions,
            $overrides
        );

        return $builder->build();
    }

    private static function apiTestRoot(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syspass-api-tests';
    }

    protected static function cachePath(): string
    {
        return FileSystem::buildPath(self::apiTestRoot(), 'cache');
    }

    protected static function tmpPath(): string
    {
        return FileSystem::buildPath(self::apiTestRoot(), 'tmp');
    }

    protected static function backupPath(): string
    {
        return FileSystem::buildPath(self::apiTestRoot(), 'backup');
    }
}
