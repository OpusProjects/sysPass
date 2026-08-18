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

namespace SP\Domain\Core\Dtos;

use SP\Domain\Common\Providers\Filter;

/**
 * Class ItemSearchDto
 */
class ItemSearchDto
{
    private readonly int $limitStart;
    private readonly int $limitCount;

    public function __construct(
        private ?string       $searchString = null,
        ?int                  $limitStart = 0,
        ?int                  $limitCount = 0,
    ) {
        if (!empty($searchString)) {
            $this->searchString = Filter::safeSearchString($searchString);
        }

        // How far into a list to start, and how much of it to take, both come from the query
        // string — and a negative one reached the server as `LIMIT -1 OFFSET -5`, which is not
        // SQL: MariaDB answers `ERROR 1064 ... syntax error`, so the page a caller asked for came
        // back as a database failure rather than a page. Nothing was harmed, but nothing an
        // ordinary request can say should end up as a syntax error either.
        $this->limitStart = max(0, $limitStart ?? 0);
        $this->limitCount = max(0, $limitCount ?? 0);
    }

    public function getSearchString(): ?string
    {
        return $this->searchString;
    }

    public function getLimitStart(): int
    {
        return $this->limitStart;
    }

    public function getLimitCount(): int
    {
        return $this->limitCount;
    }
}
