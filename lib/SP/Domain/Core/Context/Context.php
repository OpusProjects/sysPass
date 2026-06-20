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

namespace SP\Domain\Core\Context;

use SP\Core\Context\ContextException;
use SP\Domain\Account\Dtos\AccountCacheDto;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;

/**
 * Interface Context
 */
interface Context
{
    public const MASTER_PASSWORD_KEY = '_masterpass';

    /**
     * @throws ContextException
     */
    public function initialize();

    public function isInitialized(): bool;

    /**
     * Set the configuration load time
     */
    public function setConfigTime(int $time);

    /**
     * Return the configuration load time
     */
    public function getConfigTime(): int;

    /**
     * Set the user data in the session.
     */
    public function setUserData(?UserDto $userDataDto = null);

    /**
     * Get the user profile object from the session.
     */
    public function getUserProfile(): ?ProfileData;

    /**
     * Set the user profile object in the session.
     */
    public function setUserProfile(ProfileData $profileData);

    /**
     * Returns if user is logged in
     */
    public function isLoggedIn(): bool;

    /**
     * Return the user data in the session.
     */
    public function getUserData(): UserDto;

    /**
     * Set the session language
     */
    public function setLocale(string $locale);

    /**
     * Return the session language
     */
    public function getLocale(): ?string;

    /**
     * Return the application status
     */
    public function getAppStatus(): ?string;

    /**
     * Set the application status
     */
    public function setAppStatus(string $status);

    /**
     * Reset the application status
     */
    public function resetAppStatus(): ?bool;

    /**
     * @return AccountCacheDto[]|null
     */
    public function getAccountsCache(): ?array;

    /**
     * Set the accounts cache
     *
     * @param array $accountsCache
     */
    public function setAccountsCache(array $accountsCache): void;

    /**
     * Sets an arbitrary key in the trasient collection.
     * This key is not bound to any known method or type
     *
     * @param string $key
     * @param mixed $value
     *
     * @throws ContextException
     */
    public function setTrasientKey(string $key, mixed $value);

    /**
     * Gets an arbitrary key from the trasient collection.
     * This key is not bound to any known method or type
     *
     * @param string $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function getTrasientKey(string $key, mixed $default = null): mixed;

    /**
     * Sets a temporary master password
     */
    public function setTemporaryMasterPass(string $password);

    /**
     * @param string $pluginName
     * @param string $key
     * @param mixed $value
     */
    public function setPluginKey(string $pluginName, string $key, mixed $value);

    public function getPluginKey(string $pluginName, string $key): mixed;
}
