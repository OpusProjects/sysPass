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

namespace SP\Domain\User\Models;

use SP\Domain\Common\Models\Model;

/**
 * Class ProfileData
 */
class ProfileData extends Model
{
    protected bool $accView          = false;
    protected bool $accViewPass      = false;
    protected bool $accViewHistory   = false;
    protected bool $accEdit          = false;
    protected bool $accEditPass      = false;
    protected bool $accAdd           = false;
    protected bool $accDelete        = false;
    protected bool $accFiles         = false;
    protected bool $accPrivate       = false;
    protected bool $accPrivateGroup  = false;
    protected bool $accPermission    = false;
    protected bool $accPublicLinks   = false;
    protected bool $accGlobalSearch  = false;
    protected bool $configGeneral    = false;
    protected bool $configEncryption = false;
    protected bool $configBackup     = false;
    protected bool $configImport     = false;
    protected bool $mgmUsers         = false;
    protected bool $mgmGroups        = false;
    protected bool $mgmProfiles      = false;
    protected bool $mgmCategories    = false;
    protected bool $mgmCustomers     = false;
    protected bool $mgmApiTokens     = false;
    protected bool $mgmPublicLinks   = false;
    protected bool $mgmAccounts      = false;
    protected bool $mgmTags          = false;
    protected bool $mgmFiles         = false;
    protected bool $mgmItemsPreset   = false;
    protected bool $evl              = false;
    protected bool $mgmCustomFields  = false;

    public function isAccView(): bool
    {
        return $this->accView;
    }

    public function isAccViewPass(): bool
    {
        return $this->accViewPass;
    }

    public function isAccViewHistory(): bool
    {
        return $this->accViewHistory;
    }

    public function isAccEdit(): bool
    {
        return $this->accEdit;
    }

    public function isAccEditPass(): bool
    {
        return $this->accEditPass;
    }

    public function isAccAdd(): bool
    {
        return $this->accAdd;
    }

    public function isAccDelete(): bool
    {
        return $this->accDelete;
    }

    public function isAccFiles(): bool
    {
        return $this->accFiles;
    }

    public function isAccPrivate(): bool
    {
        return $this->accPrivate;
    }

    public function isAccPrivateGroup(): bool
    {
        return $this->accPrivateGroup;
    }

    public function isAccPermission(): bool
    {
        return $this->accPermission;
    }

    public function isAccPublicLinks(): bool
    {
        return $this->accPublicLinks;
    }

    public function isAccGlobalSearch(): bool
    {
        return $this->accGlobalSearch;
    }

    public function isConfigGeneral(): bool
    {
        return $this->configGeneral;
    }

    public function isConfigEncryption(): bool
    {
        return $this->configEncryption;
    }

    public function isConfigBackup(): bool
    {
        return $this->configBackup;
    }

    public function isConfigImport(): bool
    {
        return $this->configImport;
    }

    public function isMgmUsers(): bool
    {
        return $this->mgmUsers;
    }

    public function isMgmGroups(): bool
    {
        return $this->mgmGroups;
    }

    public function isMgmProfiles(): bool
    {
        return $this->mgmProfiles;
    }

    public function isMgmCategories(): bool
    {
        return $this->mgmCategories;
    }

    public function isMgmCustomers(): bool
    {
        return $this->mgmCustomers;
    }

    public function isMgmApiTokens(): bool
    {
        return $this->mgmApiTokens;
    }

    public function isMgmPublicLinks(): bool
    {
        return $this->mgmPublicLinks;
    }

    public function isMgmAccounts(): bool
    {
        return $this->mgmAccounts;
    }

    public function isMgmTags(): bool
    {
        return $this->mgmTags;
    }

    public function isMgmFiles(): bool
    {
        return $this->mgmFiles;
    }

    public function isMgmItemsPreset(): bool
    {
        return $this->mgmItemsPreset;
    }

    public function isEvl(): bool
    {
        return $this->evl;
    }

    public function isMgmCustomFields(): bool
    {
        return $this->mgmCustomFields;
    }

    /**
     * unserialize() checks for the presence of a function with the magic name __wakeup.
     * If present, this function can reconstruct any resources that the object may have.
     * The intended use of __wakeup is to reestablish any database connections that may have been lost during
     * serialization and perform other reinitialization tasks.
     *
     * @return void
     * @link http://php.net/manual/en/language.oop5.magic.php#language.oop5.magic.sleep
     */
    public function __wakeup()
    {
        // To perform the renaming of properties whose names start with _
        foreach (get_object_vars($this) as $name => $value) {
            if ($name[0] === '_') {
                $this->{substr($name, 1)} = $value;
            }
        }
    }
}
