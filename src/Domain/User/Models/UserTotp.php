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
 * A user's one-time password secret, as stored
 *
 * The secret is encrypted: see UserTotp's application service for the key. A row whose enabledAt
 * is null is an enrolment that was never confirmed, and is not enforced at sign-in.
 */
class UserTotp extends Model
{
    public const TABLE = 'UserTotp';

    protected ?int    $userId    = null;
    protected ?string $secret    = null;
    protected ?string $secretKey = null;
    protected ?int    $lastStep  = null;
    protected ?int    $enabledAt = null;

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    public function getLastStep(): ?int
    {
        return $this->lastStep;
    }

    public function getEnabledAt(): ?int
    {
        return $this->enabledAt;
    }

    /**
     * Whether enrolment was confirmed, which is what makes sign-in ask for a code
     */
    public function isEnabled(): bool
    {
        return $this->enabledAt !== null;
    }
}
