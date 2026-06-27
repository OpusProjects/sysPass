<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Help;

use function SP\__;

final class UserGroupHelp implements HelpInterface
{
    use HelpTrait;

    public static function view(): array
    {
        return
            [
                self::getItem('id', __('User group Id'), true)
            ];
    }

    public static function create(): array
    {
        return
            [
                self::getItem('name', __('User group name'), true),
                self::getItem('description', __('User group description')),
                self::getItem('usersId', __('Users Id\'s to add'))
            ];
    }

    public static function edit(): array
    {
        return
            [
                self::getItem('id', __('User group Id'), true),
                self::getItem('name', __('User group name'), true),
                self::getItem('description', __('User group description')),
                self::getItem('usersId', __('Users Id\'s to add'))
            ];
    }

    public static function search(): array
    {
        return
            [
                self::getItem('text', __('Text to search for')),
                self::getItem('count', __('Number of results to display'))
            ];
    }

    public static function delete(): array
    {
        return
            [
                self::getItem('id', __('User group Id'), true)
            ];
    }
}
