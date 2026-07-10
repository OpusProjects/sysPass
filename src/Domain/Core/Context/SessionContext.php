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

use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Core\Crypt\VaultInterface;

/**
 * Class Session
 */
interface SessionContext extends Context
{
    /**
     * Return the visual theme used in sysPass
     *
     * @return string
     */
    public function getTheme(): string;

    /**
     * Set the visual theme used in sysPass
     *
     * @param $theme string The visual theme to use
     */
    public function setTheme(string $theme);

    /**
     * @return AccountSearchFilterDto|null
     */
    public function getSearchFilters(): ?AccountSearchFilterDto;

    /**
     * @param AccountSearchFilterDto $searchFilters
     */
    public function setSearchFilters(AccountSearchFilterDto $searchFilters): void;

    public function resetAccountAcl();

    /**
     * Set whether the user is fully authorized
     */
    public function setAuthCompleted(bool $bool): void;

    /**
     * Return whether the user is fully logged in
     */
    public function getAuthCompleted();

    /**
     * Return the temporary master password
     *
     * @return ?string
     */
    public function getTemporaryMasterPass(): ?string;

    /**
     * Return the public key
     *
     * @return string|null
     */
    public function getPublicKey(): ?string;

    /**
     * Set the public key
     */
    public function setPublicKey(string $key): void;

    /**
     * Return the session timeout
     *
     * @return int|null The value in seconds
     */
    public function getSessionTimeout(): ?int;

    /**
     * Set the session timeout
     *
     * @param int $timeout The value in seconds
     *
     * @return int
     */
    public function setSessionTimeout(int $timeout): int;

    /**
     * Return the time of the last activity
     *
     * @return int
     */
    public function getLastActivity(): int;

    /**
     * Set the time of the last activity
     *
     * @param $time int The timestamp
     */
    public function setLastActivity(int $time): void;

    /**
     * Return the activity start time.
     *
     * @return int
     */
    public function getStartActivity(): int;

    /**
     * Return the color associated with an account
     *
     * @return string
     */
    public function getAccountColor(): string;

    /**
     * Set the color associated with an account
     *
     * @param array<int, string> $color
     */
    public function setAccountColor(array $color): void;

    /**
     * Return the CSRF key
     *
     * @return string|null
     */
    public function getCSRF(): ?string;

    /**
     * Set the CSRF key
     *
     * @param string $csrf
     */
    public function setCSRF(string $csrf): void;

    /**
     * Return the encrypted master password
     *
     * @return VaultInterface|null
     */
    public function getVault(): ?VaultInterface;

    /**
     * Set the encrypted master password
     *
     * @param VaultInterface $vault
     */
    public function setVault(VaultInterface $vault): void;

    /**
     * Return the time at which the session SID was created
     *
     * @return int
     */
    public function getSidStartTime(): int;

    /**
     * Set the SID creation time
     *
     * @param $time int The timestamp
     *
     * @return int
     */
    public function setSidStartTime(int $time): int;

    /**
     * Set the activity start time
     *
     * @param $time int The timestamp
     *
     * @return int
     */
    public function setStartActivity(int $time): int;
}
