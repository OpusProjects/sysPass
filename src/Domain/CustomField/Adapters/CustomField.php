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

namespace SP\Domain\CustomField\Adapters;

use SP\Domain\Common\Adapters\Adapter;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Common\Dtos\Dto;
use SP\Domain\CustomField\Ports\CustomFieldAdapter;
use SP\Domain\CustomField\Services\CustomFieldItem;

/**
 * Class CustomFieldAdapter
 */
final class CustomField extends Adapter implements CustomFieldAdapter
{
    /** What the theme prints in place of a value the viewer may not see. */
    public const MASKED = '***';

    /**
     * @param CustomFieldItem $data
     * @return array<string, mixed>
     */
    public function transform($data): array
    {
        return [
            'type' => $data->typeName,
            'typeText' => $data->typeText,
            'definitionId' => $data->definitionId,
            'definitionName' => $data->definitionName,
            'help' => $data->help,
            'value' => $this->valueFor($data),
            'encrypted' => $data->isEncrypted,
            'required' => $data->required,
        ];
    }

    /**
     * The value, or what the interface would show in its place.
     *
     * `ItemTrait::getCustomFieldsForItem()` decrypts a stored value whenever the row carries a
     * key, without asking who is looking — the deciding is left to whoever renders it. The theme
     * does decide: `aux-customfields.inc` prints `***` unless `showViewCustomPass`, which
     * `AccountHelper` sets from the account's own view-password permission. Nothing decided here,
     * so `account/view?customFields=1` answered with the decrypted value — on a token for
     * `account/view`, an action the API otherwise keeps apart from `account/viewPass` precisely
     * because one of them hands out secrets.
     *
     * A field that was never encrypted is not a secret and is returned as it is.
     */
    private function valueFor(CustomFieldItem $data): ?string
    {
        if (!$data->isValueEncrypted) {
            return $data->value;
        }

        return $this->acl->checkUserAccess(AclActionsInterface::CUSTOMFIELD_VIEW_PASS)
            ? $data->value
            : self::MASKED;
    }
}
