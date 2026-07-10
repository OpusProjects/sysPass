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

namespace SP\Application\Import\Services;

use SP\Application\Account\Ports\AccountService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\Config\Ports\ConfigService;
use SP\Application\Import\Ports\ImportHelperInterface;
use SP\Application\Tag\Ports\TagService;
use SP\Domain\Category\Models\Category as CategoryModel;

/**
 * A helper class to provide the needed services.
 */
readonly class ImportHelper implements ImportHelperInterface
{
    /**
     * @param CategoryService<CategoryModel> $categoryService
     */
    public function __construct(
        private AccountService  $accountService,
        private CategoryService $categoryService,
        private ClientService   $clientService,
        private TagService      $tagService,
        private ConfigService   $configService
    ) {
    }

    public function getAccountService(): AccountService
    {
        return $this->accountService;
    }

    /**
     * @return CategoryService<CategoryModel>
     */
    public function getCategoryService(): CategoryService
    {
        return $this->categoryService;
    }

    public function getClientService(): ClientService
    {
        return $this->clientService;
    }

    public function getTagService(): TagService
    {
        return $this->tagService;
    }

    public function getConfigService(): ConfigService
    {
        return $this->configService;
    }
}
