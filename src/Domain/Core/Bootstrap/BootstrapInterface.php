<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Core\Bootstrap;

/**
 * Interface BootstrapInterface
 *
 * Both members are what run() needs from the instance the container hands it. They belong on
 * the contract: reaching for them on a bare BootstrapInterface is only legal when the runtime
 * class happens to match the calling scope, which is not something the type system enforces.
 */
interface BootstrapInterface
{
    /**
     * Bind the module whose controllers this bootstrap will dispatch to.
     */
    public function initializeModule(ModuleInterface $module): void;

    /**
     * Dispatch the current request through the configured router.
     */
    public function handleRequest(): void;
}
