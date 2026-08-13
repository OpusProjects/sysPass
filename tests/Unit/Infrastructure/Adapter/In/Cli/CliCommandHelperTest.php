<?php

declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Cli;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SP\Infrastructure\Adapter\In\Cli\Commands\BackupCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\Crypt\UpdateMasterPasswordCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;
use SP\Infrastructure\Adapter\In\Cli\CliCommandHelper;

/**
 * This is what bin/cli.php registers on the Symfony Application: whatever it hands back is the
 * full set of `sp:*` commands an operator can run. A command dropped here silently disappears
 * from the CLI without any error -- there is nowhere else that would notice.
 *
 * InstallCommand, BackupCommand and UpdateMasterPasswordCommand are all `final`, so they cannot
 * be doubled with createMock(); their constructors also pull in the real DI graph (installer,
 * master password, account services...) that has nothing to do with what this class does.
 * newInstanceWithoutConstructor() gives real, distinguishable instances of the exact types
 * CliCommandHelper is wired against without any of that, which is all identity-based assertions
 * below need.
 */
#[Group('unitary')]
class CliCommandHelperTest extends TestCase
{
    #[Test]
    public function everyRegisteredCommandIsReturnedInTheOrderItWasWired(): void
    {
        $installCommand = (new ReflectionClass(InstallCommand::class))->newInstanceWithoutConstructor();
        $backupCommand = (new ReflectionClass(BackupCommand::class))->newInstanceWithoutConstructor();
        $updateMasterPasswordCommand = (new ReflectionClass(UpdateMasterPasswordCommand::class))
            ->newInstanceWithoutConstructor();

        $helper = new CliCommandHelper($installCommand, $backupCommand, $updateMasterPasswordCommand);

        self::assertSame(
            [$installCommand, $backupCommand, $updateMasterPasswordCommand],
            $helper->getCommands()
        );
    }
}
