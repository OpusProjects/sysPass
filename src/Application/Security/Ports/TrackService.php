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

namespace SP\Application\Security\Ports;

use Exception;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Domain\Security\Models\Track;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;

/**
 * Class TrackService
 *
 * @package SP\Domain\Common\Services
 */
interface TrackService
{
    /**
     * @throws InvalidArgumentException
     */
    public function buildTrackRequest(string $source): TrackRequest;

    /**
     * @throws QueryException
     * @throws ConstraintException
     * @throws NoSuchItemException
     */
    public function unlock(int $id): void;

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function clear(): bool;

    /**
     * Check the login attempts
     *
     * The attempt being checked is recorded before the others are counted, so attempts in flight
     * at the same time count against each other. Every caller must call release() once the
     * attempt has been decided, whether it succeeded or failed.
     *
     * @return bool True if the limit is exceeded, false otherwise
     * @throws Exception
     */
    public function checkTracking(TrackRequest $trackRequest): bool;

    /**
     * Withdraw what checkTracking() recorded for the attempts this request made
     *
     * A failed attempt is recorded by add(), so this leaves the count exactly as it would have
     * been had the attempts been made one at a time.
     */
    public function release(): void;

    /**
     * @throws ServiceException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function add(TrackRequest $trackRequest): int;

    /**
     * @return QueryResult<Track>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult;
}
