<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Help;

use SP\Domain\Api\Ports\HelpInterface;

use function SP\__;

final class UserGroupHelp implements HelpInterface
{
    use HelpTrait;

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function view(): array
    {
        return
            [
                self::getItem('id', __('User group Id'), true)
            ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function create(): array
    {
        return
            [
                self::getItem('name', __('User group name'), true),
                self::getItem('description', __('User group description')),
                self::getItem('usersId', __('Users Id\'s to add'))
            ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
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

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function search(): array
    {
        return
            [
                self::getItem('text', __('Text to search for')),
                self::getItem('count', __('Number of results to display'))
            ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function delete(): array
    {
        return
            [
                self::getItem('id', __('User group Id'), true)
            ];
    }
}
