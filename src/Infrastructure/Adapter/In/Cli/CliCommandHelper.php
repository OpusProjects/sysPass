<?php
/*
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

namespace SP\Infrastructure\Adapter\In\Cli;

use SP\Infrastructure\Adapter\In\Cli\Commands\BackupCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\CommandBase;
use SP\Infrastructure\Adapter\In\Cli\Commands\Crypt\UpdateMasterPasswordCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;

/**
 * A helper to instantiate CLI commands
 */
final class CliCommandHelper
{
    /**
     * @var CommandBase[]
     */
    private array $commands;

    public function __construct(
        InstallCommand $installCommand,
        BackupCommand $backupCommand,
        UpdateMasterPasswordCommand $updateMasterPasswordCommand
    ) {
        $this->commands = [
            $installCommand,
            $backupCommand,
            $updateMasterPasswordCommand,
        ];
    }

    /**
     * @return CommandBase[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}
