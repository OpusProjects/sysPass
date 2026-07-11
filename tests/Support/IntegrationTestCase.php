<?php
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

declare(strict_types=1);

namespace SP\Tests\Support;

use Closure;
use DI\ContainerBuilder;
use Faker\Factory;
use Faker\Generator;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Http\Services\Response;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionObject;
use SP\Infrastructure\Bootstrap\Path;
use SP\Infrastructure\Bootstrap\PathsContext;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Infrastructure\UI\ThemeContext;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Application\Account\Ports\AccountAclService;
use SP\Domain\Auth\Ports\LdapConnectionHandler;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Config\Ports\ConfigService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Crypt\VaultInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\UI\ThemeContextInterface;
use SP\Infrastructure\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Database\Ports\DbStorageHandler;
use SP\Application\Notification\Ports\MailService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Database\QueryResult;
use SP\Infrastructure\File\ArchiveHandler;
use SP\Infrastructure\File\FileSystem;
use SP\Infrastructure\Adapter\In\Web\Bootstrap;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\Generators\UserProfileDataGenerator;

use function DI\autowire;
use function DI\factory;

/**
 * Class IntegrationTestCase
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Fixed faker seed for reproducible, isolation-independent test data. */
    protected const FAKER_SEED = 1;

    protected static Generator $faker;
    private static array      $definitionsCache;
    private static string     $moduleFile;
    protected readonly string $passwordSalt;
    protected Closure|null    $databaseQueryResolver = null;
    protected array           $definitions;
    /**
     * @var array<string, QueryResult> $databaseMapperResolvers
     */
    private array $databaseMapperResolvers = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$faker = Factory::create();
        self::$definitionsCache = array_merge(
            DomainDefinitions::getDefinitions(),
            CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'web')
        );
        self::$moduleFile = FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Web', 'module.php');
    }

    protected static function buildRequest(
        string $method,
        string $uri,
        array  $paramsGet = [],
        array  $paramsPost = [],
        array  $files = []
    ): Request {
        $server = array_merge(
            $_SERVER,
            [
                'REQUEST_METHOD' => strtoupper($method),
                'REQUEST_URI' => $uri,
                'HTTP_USER_AGENT' => self::$faker->userAgent(),
                'HTTP_HOST' => 'localhost',
                // A real web request always has a client address; Track keys its
                // brute-force counter on REMOTE_ADDR, which must be a valid IP.
                'REMOTE_ADDR' => '127.0.0.1'
                //'QUERY_STRING' => $query
            ]
        );

        return new Request(
            array_merge($_GET, $paramsGet),
            array_merge($_POST, $paramsPost),
            [],
            $_COOKIE,
            array_merge($_FILES, $files),
            $server,
            null
        );
    }

    /**
     * @param ContainerInterface $container
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function runApp(ContainerInterface $container): void
    {
        Bootstrap::run($container->get(BootstrapInterface::class), $container->get(ModuleInterface::class));
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function buildContainer(Request $request, array $definitionsOverride = []): ContainerInterface
    {
        $definitionsOverride[Request::class] = $request;

        $testDefinitions = array_merge(
            FileSystem::require(self::$moduleFile),
            $this->getMockedDefinitions(),
            $definitionsOverride
        );

        $this->processAttributes(
            $this->getClassAttributesMap($testDefinitions),
            $this->getMethodAttributesMap($testDefinitions)
        );

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(self::$definitionsCache, $testDefinitions);

        return $containerBuilder->build();
    }

    /**
     * @return array
     * @throws Exception
     * @throws SPException
     */
    private function getMockedDefinitions(): array
    {
        $configData = self::createConfiguredStub(ConfigDataInterface::class, $this->getConfigData());

        $configFileService = $this->createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        $databaseUtil = self::createStub(\SP\Infrastructure\Database\DatabaseUtil::class);
        $databaseUtil->method('checkDatabaseConnection')->willReturn(true);
        $databaseUtil->method('checkDatabaseTables')->willReturn(true);

        $database = self::createStub(DatabaseInterface::class);
        $database->method('runQuery')->willReturnCallback($this->getDatabaseReturn());
        $database->method('beginTransaction')->willReturn(true);
        $database->method('endTransaction')->willReturn(true);
        $database->method('rollbackTransaction')->willReturn(true);

        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $accountAcl = self::createStub(AccountAclService::class);
        $accountAcl->method('getAcl')
                   ->willReturnCallback(static function (int $actionId) {
                       $accountPermission = new AccountPermission($actionId);
                       $accountPermission->setCompiledAccountAccess(true);
                       $accountPermission->setCompiledShowAccess(true);
                       $accountPermission->setResultView(true);
                       $accountPermission->setResultEdit(true);

                       return $accountPermission;
                   });

        return [
            ConfigFileService::class => $configFileService,
            LdapConnectionHandler::class => self::createStub(LdapConnectionHandler::class),
            'backup.dbArchiveHandler' => self::createStub(ArchiveHandler::class),
            'backup.appArchiveHandler' => self::createStub(ArchiveHandler::class),
            \SP\Infrastructure\Database\DatabaseUtil::class => $databaseUtil,
            Context::class => $this->getContext(),
            MailService::class => self::createStub(MailService::class),
            DbStorageHandler::class => self::createStub(DbStorageHandler::class),
            DatabaseInterface::class => $database,
            ThemeContextInterface::class => autowire(ThemeContext::class)
                ->constructorParameter(
                    'basePath',
                    factory(static fn(PathsContext $p) => $p[Path::VIEW])
                )
                ->constructorParameter('baseUri', factory([UriContextInterface::class, 'getWebRoot']))
                ->constructorParameter('module', 'web')
                ->constructorParameter('name', 'material-blue'),
            AclInterface::class => $acl,
            AccountAclService::class => $accountAcl,
        ];
    }

    protected function getConfigData(): array
    {
        return [
            'isInstalled' => true,
            'isMaintenance' => false,
            'getDbName' => self::$faker->colorName(),
            'getPasswordSalt' => $this->passwordSalt,
            // A stub method not listed here defaults to '' for a string return
            // type, which Init::checkUpgradeNeeded() reads as "needs an upgrade".
            // Keep these at the current version so the fixture represents an
            // up-to-date install, like every other integration test expects.
            'getAppVersion' => Version::getVersionStringNormalized(),
            'getDatabaseVersion' => Version::getVersionStringNormalized(),
        ];
    }

    protected function getDatabaseReturn(): callable
    {
        return function (QueryData $queryData): QueryResult {
            if ($this->databaseQueryResolver) {
                return $this->databaseQueryResolver->call($this, $queryData);
            }

            $mapClassName = $queryData->getMapClassName();

            if (isset($this->databaseMapperResolvers[$mapClassName])) {
                return $this->databaseMapperResolvers[$mapClassName];
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * @throws Exception
     * @throws SPException
     */
    protected function getContext(): SessionContext|Stub
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn(true);
        $context->method('getAuthCompleted')->willReturn(true);
        $context->method('getUserData')->willReturn($this->getUserDataDto());
        $context->method('getUserProfile')->willReturn($this->getUserProfile());

        return $context;
    }

    /**
     * @return UserDto
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        $properties = [
            'isAdminApp' => false,
            'isAdminAcc' => false,
            'userGroupName' => self::$faker->colorName()
        ];

        return UserDto::fromModel(UserDataGenerator::factory()->buildUserData())
                      ->mutate($properties);
    }

    /**
     * @return ProfileData
     */
    protected function getUserProfile(): ProfileData
    {
        return UserProfileDataGenerator::factory()->buildProfileData();
    }

    private function processAttributes(array $classAtrributes, array $methodAttributes): void
    {
        $object = new ReflectionObject($this);

        if (!empty($classAtrributes)) {
            $this->invokeAttributes($object, $classAtrributes);
        }

        if (!empty($methodAttributes)) {
            $methods = array_filter(
                $object->getMethods(),
                fn(ReflectionMethod $method) => $method->name === $this->name()
            );

            foreach ($methods as $method) {
                $this->invokeAttributes($method, $methodAttributes);
            }
        }
    }

    private function invokeAttributes(ReflectionObject|ReflectionMethod $reflection, array $attributeMap): void
    {
        foreach ($reflection->getAttributes() as $attribute) {
            $callable = $attributeMap[$attribute->getName()] ?? null;

            if (is_callable($callable)) {
                $callable($attribute);
            }
        }
    }

    private function getClassAttributesMap(array &$definitions): array
    {
        return [
            InjectVault::class => function () use (&$definitions): void {
                $vault = self::createStub(VaultInterface::class);
                $vault->method('getData')->willReturn('some_data');

                /** @var Context|Stub $context */
                $context = $definitions[Context::class];
                $context->method('getVault')->willReturn($vault);
            }
        ];
    }

    /**
     * @param array $definitions
     * @return Closure[]
     */
    private function getMethodAttributesMap(array &$definitions): array
    {
        return [
            BodyChecker::class =>
                function (ReflectionAttribute $attribute) use (&$definitions): void {
                    /** @var ReflectionAttribute<BodyChecker> $attribute */
                    $bodyChecker = (new ReflectionMethod($this, $attribute->newInstance()->target))
                        ->getClosure($this);

                    // Run the per-test checker against the response body and return the response
                    // for chaining, exactly as the previous with(callback)->willReturnSelf() stub
                    // did. The checker's assertions (and any failure they raise) must run inline
                    // here so they propagate through the application's response flow as before;
                    // body() can legitimately be invoked more than once while a response is
                    // assembled, so the count expectation is left open with atLeastOnce(), which
                    // also pairs the double with an explicit expectation (replacing the bare
                    // with() that PHPUnit 14 forbids).
                    $response = $this->getMockBuilder(Response::class)->onlyMethods(['body'])->getMock();
                    $response
                        ->expects($this->atLeastOnce())
                        ->method('body')
                        ->willReturnCallback(
                            static function (string $output) use ($response, $bodyChecker) {
                                self::assertNotEmpty($output);

                                $bodyChecker($output);

                                return $response;
                            }
                        );

                    $definitions[ResponseService::class] = $response;
                },
            InjectCrypt::class => function (ReflectionAttribute $attribute) use (&$definitions): void {
                /** @var ReflectionAttribute<InjectCrypt> $attribute */
                $value = $attribute->newInstance()->returnValue;

                $crypt = $this->createStub(CryptInterface::class);
                $crypt->method('decrypt')->willReturn($value);
                $crypt->method('encrypt')->willReturn($value);

                $definitions[CryptInterface::class] = $crypt;
            },
            InjectConfigParam::class => function (ReflectionAttribute $attribute) use (&$definitions): void {
                /** @var ReflectionAttribute<InjectConfigParam> $attribute */
                $parameterValueMap = $attribute->newInstance()->parameterValueMap;

                $configService = self::createStub(ConfigService::class);

                if (!empty($parameterValueMap)) {
                    $configService
                        ->method('getByParam')
                        ->willReturnCallback(
                            static function (string $parameter) use ($parameterValueMap): string {
                                if (array_key_exists($parameter, $parameterValueMap)) {
                                    return $parameterValueMap[$parameter];
                                }

                                return self::$faker->colorName();
                            }
                        );
                } else {
                    $configService
                        ->method('getByParam')
                        ->willReturnArgument(0);
                }

                $definitions[ConfigService::class] = $configService;
            }
        ];
    }

    final protected function addDatabaseMapperResolver(string $className, QueryResult $queryResult): void
    {
        $this->databaseMapperResolvers[$className] = $queryResult;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic test data: re-seed faker per test (see UnitaryTestCase) so a
        // test produces the same values in isolation and in the full suite; the unseeded
        // generator made data-dependent assertions fail intermittently across runs.
        self::$faker->seed(self::FAKER_SEED);

        // Reset the process-global locale per test so a language-switching test in this
        // suite doesn't leak into later tests (e.g. responses in the wrong language).
        putenv('LANG=en_US.UTF-8');
        putenv('LANGUAGE=en_US.UTF-8');
        setlocale(LC_ALL, 'en_US.UTF-8');
        setlocale(LC_MESSAGES, 'en_US.UTF-8');

        $this->passwordSalt = self::$faker->sha1();
    }
}
