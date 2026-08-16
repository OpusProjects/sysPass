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

namespace SP\Application\Account\Ports;

use SP\Domain\Common\Models\Item;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;

/**
 * Class AccountToTagService
 *
 * @package SP\Domain\Account\Services
 */
interface AccountToTagService
{
    /**
     * @return Item[]
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getTagsByAccountId(int $id): array;

    /**
     * The tags of several accounts at once, grouped by account id.
     *
     * Asking per account is a query per account, which the export — the one caller that walks
     * every account there is — paid in full. Accounts with no tags are absent from the result
     * rather than present and empty.
     *
     * @param int[] $ids
     *
     * @return array<int, Item[]>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getTagsByAccountIds(array $ids): array;
}
