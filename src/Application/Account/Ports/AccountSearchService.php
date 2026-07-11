<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Account\Models\AccountSearchView as AccountSearchViewModel;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Common\Dtos\QueryResult;

/**
 * Class AccountSearchService for managing account searches
 */
interface AccountSearchService
{
    /**
     * Processes the search results and builds the variable that holds the data of each account
     * to be displayed.
     *
     * @return QueryResult<AccountSearchViewModel>
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function getByFilter(AccountSearchFilterDto $accountSearchFilter): QueryResult;
}
