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

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Help;

use SP\Domain\Api\Ports\HelpInterface;

use function SP\__;

/**
 * The parameters the user endpoints take, which is what a caller is shown when one is missing.
 */
final class UserHelp implements HelpInterface
{
    use HelpTrait;

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function view(): array
    {
        return [
            self::getItem('id', __('User Id'), true)
        ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function create(): array
    {
        return [
            self::getItem('name', __('User name'), true),
            self::getItem('login', __('User login'), true),
            self::getItem('pass', __('User password'), true),
            self::getItem('userGroupId', __('User group Id'), true),
            self::getItem('userProfileId', __('User profile Id'), true),
            self::getItem('email', __('User email')),
            self::getItem('notes', __('User notes')),
            self::getItem('isAdminApp', __('Application admin')),
            self::getItem('isAdminAcc', __('Accounts admin')),
            self::getItem('isDisabled', __('Disabled')),
            self::getItem('isChangePass', __('Change password'))
        ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function edit(): array
    {
        return [
            self::getItem('id', __('User Id'), true),
            self::getItem('name', __('User name'), true),
            self::getItem('login', __('User login'), true),
            self::getItem('userGroupId', __('User group Id'), true),
            self::getItem('userProfileId', __('User profile Id'), true),
            self::getItem('email', __('User email')),
            self::getItem('notes', __('User notes')),
            self::getItem('isAdminApp', __('Application admin')),
            self::getItem('isAdminAcc', __('Accounts admin')),
            self::getItem('isDisabled', __('Disabled')),
            self::getItem('isChangePass', __('Change password'))
        ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function search(): array
    {
        return [
            self::getItem('text', __('Text to search for')),
            self::getItem('count', __('Number of results to display'))
        ];
    }

    /**
     * @return array<int, array<string, array{description: string, required: bool}>>
     */
    public static function delete(): array
    {
        return [
            self::getItem('id', __('User Id'), true)
        ];
    }
}
