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

namespace SP\Infrastructure\Definitions;

use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Common\Ports\Repository;
use SP\Domain\Common\Providers\Image;
use SP\Application\Export\Ports\XmlVerifyService;
use SP\Application\Export\Services\XmlVerify;
use SP\Domain\Image\Ports\ImageService;
use SP\Application\Import\Ports\ImportHelperInterface;
use SP\Application\Import\Services\ImportHelper;
use SP\Infrastructure\Adapter\Out\Common\Repositories\SimpleRepository;
use SP\Domain\File\FileSystem;

use function DI\autowire;
use function DI\factory;

/**
 * Class DomainDefinitions
 */
final class DomainDefinitions
{
    private const DOMAINS = [
        'Account',
        'Api',
        'Auth',
        'Category',
        'Client',
        'Config',
        'Crypt',
        'CustomField',
        'Export',
        'Import',
        'Install',
        'ItemPreset',
        'Notification',
        'Plugin',
        'Security',
        'Tag',
        'User',
    ];

    private const PORTS = [
        'Service' => 'SP\Domain\%s\Services\*',
        'Repository' => 'SP\Infrastructure\Adapter\Out\%s\Repositories\*',
        'Adapter' => 'SP\Domain\%s\Adapters\*',
        'Builder' => 'SP\Domain\%s\Services\Builders\*'
    ];

    /**
     * Explicit driving-port → application-service bindings.
     *
     * Application use-case services (and their driving ports) live in SP\Application; this map
     * replaces the former 'Service' wildcard so each port is bound to its concrete service.
     */
    private const APP_SERVICES = [
        'SP\Application\Account\Ports\AccountService' => 'SP\Application\Account\Services\Account',
        'SP\Application\Account\Ports\AccountAclService' => 'SP\Application\Account\Services\AccountAcl',
        'SP\Application\Account\Ports\AccountCacheService' => 'SP\Application\Account\Services\AccountCache',
        'SP\Application\Account\Ports\AccountCryptService' => 'SP\Application\Account\Services\AccountCrypt',
        'SP\Application\Account\Ports\AccountFileService' => 'SP\Application\Account\Services\AccountFile',
        'SP\Application\Account\Ports\AccountHistoryService' => 'SP\Application\Account\Services\AccountHistory',
        'SP\Application\Account\Ports\AccountItemsService' => 'SP\Application\Account\Services\AccountItems',
        'SP\Application\Account\Ports\AccountMasterPasswordService' => 'SP\Application\Account\Services\AccountMasterPassword',
        'SP\Application\Account\Ports\AccountPresetService' => 'SP\Application\Account\Services\AccountPreset',
        'SP\Application\Account\Ports\AccountSearchService' => 'SP\Application\Account\Services\AccountSearch',
        'SP\Application\Account\Ports\AccountToFavoriteService' => 'SP\Application\Account\Services\AccountToFavorite',
        'SP\Application\Account\Ports\AccountToTagService' => 'SP\Application\Account\Services\AccountToTag',
        'SP\Application\Account\Ports\AccountToUserService' => 'SP\Application\Account\Services\AccountToUser',
        'SP\Application\Account\Ports\AccountToUserGroupService' => 'SP\Application\Account\Services\AccountToUserGroup',
        'SP\Application\Account\Ports\PublicLinkService' => 'SP\Application\Account\Services\PublicLink',
        'SP\Application\Api\Ports\ApiService' => 'SP\Application\Api\Services\Api',
        // ApiRequestService is NOT bound here: RestApiRequest has a non-instantiable constructor
        // and is provided via factory() in the Api module's module.php, which overrides this map
        // for the only module that needs it. An autowire entry here would fail to compile for the
        // web and cli modules (php-di compiles ALL definitions, not just the active module's).
        'SP\Application\Auth\Ports\AuthTokenService' => 'SP\Application\Auth\Services\AuthToken',
        'SP\Application\Auth\Ports\AuthTokenActionService' => 'SP\Application\Auth\Services\AuthTokenAction',
        'SP\Application\Auth\Ports\LdapCheckService' => 'SP\Application\Auth\Services\LdapCheck',
        'SP\Application\Auth\Ports\LoginService' => 'SP\Application\Auth\Services\Login',
        'SP\Application\Auth\Ports\LoginAuthHandlerService' => 'SP\Application\Auth\Services\LoginAuthHandler',
        'SP\Application\Auth\Ports\LoginMasterPassService' => 'SP\Application\Auth\Services\LoginMasterPass',
        'SP\Application\Auth\Ports\LoginUserService' => 'SP\Application\Auth\Services\LoginUser',
        'SP\Application\Category\Ports\CategoryService' => 'SP\Application\Category\Services\Category',
        'SP\Application\Client\Ports\ClientService' => 'SP\Application\Client\Services\Client',
        'SP\Application\Config\Ports\ConfigService' => 'SP\Application\Config\Services\Config',
        'SP\Application\Config\Ports\ConfigBackupService' => 'SP\Application\Config\Services\ConfigBackup',
        'SP\Application\Config\Ports\ConfigFileService' => 'SP\Application\Config\Services\ConfigFile',
        'SP\Application\Crypt\Ports\MasterPassService' => 'SP\Application\Crypt\Services\MasterPass',
        'SP\Application\Crypt\Ports\SecureSessionService' => 'SP\Application\Crypt\Services\SecureSession',
        'SP\Application\Crypt\Ports\TemporaryMasterPassService' => 'SP\Application\Crypt\Services\TemporaryMasterPass',
        'SP\Application\CustomField\Ports\CustomFieldCryptService' => 'SP\Application\CustomField\Services\CustomFieldCrypt',
        'SP\Application\CustomField\Ports\CustomFieldDataService' => 'SP\Application\CustomField\Services\CustomFieldData',
        'SP\Application\CustomField\Ports\CustomFieldDefinitionService' => 'SP\Application\CustomField\Services\CustomFieldDefinition',
        'SP\Application\CustomField\Ports\CustomFieldTypeService' => 'SP\Application\CustomField\Services\CustomFieldType',
        'SP\Application\ItemPreset\Ports\ItemPresetService' => 'SP\Application\ItemPreset\Services\ItemPreset',
        'SP\Application\Notification\Ports\MailService' => 'SP\Application\Notification\Services\Mail',
        'SP\Application\Notification\Ports\NotificationService' => 'SP\Application\Notification\Services\Notification',
        'SP\Application\Security\Ports\EventlogService' => 'SP\Application\Security\Services\Eventlog',
        'SP\Application\Security\Ports\TrackService' => 'SP\Application\Security\Services\Track',
        'SP\Application\Tag\Ports\TagService' => 'SP\Application\Tag\Services\Tag',
        'SP\Application\User\Ports\UserService' => 'SP\Application\User\Services\User',
        'SP\Application\User\Ports\UserGroupService' => 'SP\Application\User\Services\UserGroup',
        'SP\Application\User\Ports\UserMasterPassService' => 'SP\Application\User\Services\UserMasterPass',
        'SP\Application\User\Ports\UserPassService' => 'SP\Application\User\Services\UserPass',
        'SP\Application\User\Ports\UserPassRecoverService' => 'SP\Application\User\Services\UserPassRecover',
        'SP\Application\User\Ports\UserProfileService' => 'SP\Application\User\Services\UserProfile',
        'SP\Application\User\Ports\UserToUserGroupService' => 'SP\Application\User\Services\UserToUserGroup',
        'SP\Application\Export\Ports\BackupFileService' => 'SP\Application\Export\Services\BackupFile',
        'SP\Application\Export\Ports\BackupHandlersFactory' => 'SP\Infrastructure\File\FileBackupHandlersFactory',
        'SP\Application\Export\Ports\XmlAccountExportService' => 'SP\Application\Export\Services\XmlAccountExport',
        'SP\Application\Export\Ports\XmlCategoryExportService' => 'SP\Application\Export\Services\XmlCategoryExport',
        'SP\Application\Export\Ports\XmlClientExportService' => 'SP\Application\Export\Services\XmlClientExport',
        'SP\Application\Export\Ports\XmlExportService' => 'SP\Application\Export\Services\XmlExport',
        'SP\Application\Export\Ports\XmlTagExportService' => 'SP\Application\Export\Services\XmlTagExport',
        // XmlVerifyService is bound explicitly above (needs the schema constructor parameter).
        'SP\Application\Import\Ports\ImportService' => 'SP\Application\Import\Services\Import',
        'SP\Application\Import\Ports\ImportStrategyService' => 'SP\Application\Import\Services\ImportStrategy',
        'SP\Application\Import\Ports\LdapImportService' => 'SP\Application\Import\Services\LdapImport',
        'SP\Application\Import\Ports\XmlFileService' => 'SP\Application\Import\Services\XmlFile',
        'SP\Application\Install\Ports\InstallerService' => 'SP\Application\Install\Services\Installer',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function getDefinitions(): array
    {
        $sources = [
            ImageService::class => autowire(Image::class)
                ->constructorParameter(
                    'font',
                    factory(
                        static fn(PathsContext $p) => FileSystem::buildPath(
                            $p[Path::PUBLIC],
                            'vendor',
                            'fonts',
                            'NotoSans-Regular-webfont.ttf'
                        )
                    )
                )
                ->constructorParameter(
                    'tempPath',
                    factory(static fn(PathsContext $p) => $p[Path::TMP])
                ),
            Repository::class => autowire(SimpleRepository::class),
            XmlVerifyService::class => autowire(XmlVerify::class)->constructorParameter(
                'schema',
                factory(static fn(PathsContext $p) => $p[Path::XML_SCHEMA])
            ),
            ImportHelperInterface::class => autowire(ImportHelper::class)
        ];

        foreach (self::DOMAINS as $domain) {
            foreach (self::PORTS as $suffix => $target) {
                $key = sprintf('SP\Domain\%s\Ports\*%s', $domain, $suffix);

                if (!array_key_exists($key, $sources)) {
                    $sources[$key] = autowire(sprintf($target, $domain));
                }
            }
        }

        foreach (self::APP_SERVICES as $port => $service) {
            $sources[$port] = autowire($service);
        }

        return [
            ...$sources
        ];
    }
}
