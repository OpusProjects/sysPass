<?php
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

declare(strict_types=1);

namespace SP\Tests\Support\Stubs;

use SP\Domain\Common\Attributes\UpgradeVersion;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;

/**
 * A version newer than the application, as a migration a later release adds would be.
 */
#[UpgradeVersion('500.00000001')]
class FutureMigrationStub implements UpgradeHandlerService
{
    public function apply(string $version, ConfigDataInterface $configData): bool
    {
        return true;
    }
}
