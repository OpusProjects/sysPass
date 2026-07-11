<?php

declare(strict_types=1);

namespace SP\Domain\Database\Ports;

interface DatabaseUtilService
{
    public const TABLES = [
        'Client',
        'Category',
        'Tag',
        'UserGroup',
        'UserProfile',
        'User',
        'Account',
        'AccountToFavorite',
        'AccountFile',
        'AccountToUserGroup',
        'AccountHistory',
        'AccountToTag',
        'AccountToUser',
        'AuthToken',
        'Config',
        'CustomFieldType',
        'CustomFieldDefinition',
        'CustomFieldData',
        'EventLog',
        'ItemPreset',
        'PublicLink',
        'UserPassRecover',
        'UserToUserGroup',
        'Plugin',
        'PluginData',
        'Track',
        'Notification',
    ];

    public const VIEWS = [
        'account_data_v',
        'account_search_v',
    ];

    public function checkDatabaseTables(string $dbName): bool;

    public function checkDatabaseConnection(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getDBinfo(): array;

    public function escape(string $str): string;
}
