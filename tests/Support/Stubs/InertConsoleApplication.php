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

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A Console\Application whose run() does nothing.
 *
 * SP\Infrastructure\Adapter\In\Cli\Init::initCli() -- called from initialize(), after event
 * handlers are attached -- ends by dispatching whatever command the CLI was invoked with. A test
 * that wants to exercise initialize() itself, without actually running a command (and whatever
 * that command does to the database or filesystem), swaps this in for the real Application via
 * the container. run() is not final on the real class, so overriding it here is enough to make
 * the rest of Init::initialize() safe to call directly.
 */
final class InertConsoleApplication extends Application
{
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return 0;
    }
}
