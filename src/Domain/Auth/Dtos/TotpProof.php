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

namespace SP\Domain\Auth\Dtos;

/**
 * What a sign-in presented as its second factor, checked and not yet spent
 *
 * The time step of a one-time code, the keyed hash of a recovery code, or the time step of a code
 * from a secret being enrolled — which spending confirms.
 */
final readonly class TotpProof
{
    private function __construct(public ?int $step, public ?string $recoveryHash, public bool $enrolment = false)
    {
    }

    public static function step(int $step): self
    {
        return new self($step, null);
    }

    public static function recoveryCode(string $hash): self
    {
        return new self(null, $hash);
    }

    public static function enrolment(int $step): self
    {
        return new self($step, null, true);
    }

    public function isEnrolment(): bool
    {
        return $this->enrolment;
    }

    public function isRecoveryCode(): bool
    {
        return $this->recoveryHash !== null;
    }
}
