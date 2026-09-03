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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Cli\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SP\Infrastructure\Adapter\In\Cli\Commands\CommandBase;

/**
 * A password given to a command as an option is in `argv`, and `argv` is `/proc/<pid>/cmdline`,
 * which every other local user on the host can read for as long as the command runs.
 *
 * That is a different threat from the one the CLI is otherwise exempt from. `sp:backup` needs no
 * demo guard because whoever can run it already has `config/config.xml` — but the users this is
 * about have neither that file nor any way to read the environment of a process they do not own,
 * and `sp:updateMasterPassword` re-encrypts every account, so the window is minutes rather than an
 * instant.
 *
 * `cli_set_process_title()` rewrites that memory. This asserts it against `/proc` directly, because
 * the whole point is what another process can see, and because the platform could stop honouring it
 * without anything else noticing.
 */
#[Group('unitary')]
class SecretsAreNotLeftOnTheCommandLineTest extends TestCase
{
    private const SECRET = 'correct-horse-battery-staple';
    private const OTHER_SECRET = 'a-second-secret-value';

    /** @var string[] */
    private array $argv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('cli_set_process_title') || !is_readable('/proc/self/cmdline')) {
            self::markTestSkipped('needs a Linux CLI SAPI with a readable /proc');
        }

        $this->argv = $_SERVER['argv'] ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;

        // Put the runner's own title back, so a later `ps` still finds it by name.
        @cli_set_process_title(implode(' ', $this->argv));

        parent::tearDown();
    }

    /**
     * Both spellings the console accepts — `--option=value` and `--option value` — are taken out,
     * and the command still says what it is.
     */
    #[Test]
    public function aPasswordGivenAsAnOptionIsTakenOutOfTheProcessTitle(): void
    {
        $_SERVER['argv'] = [
            'bin/cli.php',
            'sp:updateMasterPassword',
            '--masterPassword=' . self::SECRET,
            '--currentMasterPassword',
            self::OTHER_SECRET,
            '--update',
        ];

        self::hide('masterPassword', 'currentMasterPassword');

        $cmdline = self::cmdline();

        self::assertStringNotContainsString(self::SECRET, $cmdline);
        self::assertStringNotContainsString(self::OTHER_SECRET, $cmdline);
        self::assertStringContainsString('sp:updateMasterPassword', $cmdline);
        self::assertStringContainsString('--update', $cmdline);
    }

    /**
     * An option that carries no secret is left alone — the title is rebuilt from the real command
     * line rather than replaced, so a process being looked at is still identifiable.
     */
    #[Test]
    public function everythingElseIsLeftAsItWas(): void
    {
        $_SERVER['argv'] = ['bin/cli.php', 'sp:backup', '--path=/var/backups/nightly'];

        self::hide('masterPassword');

        self::assertStringContainsString('--path=/var/backups/nightly', self::cmdline());
    }

    /**
     * And the check that shows the assertion above is worth something: without the call, the secret
     * is exactly where it was.
     */
    #[Test]
    public function withoutTheCallTheSecretIsVisible(): void
    {
        @cli_set_process_title('bin/cli.php sp:updateMasterPassword --masterPassword=' . self::SECRET);

        self::assertStringContainsString(self::SECRET, self::cmdline());
    }

    private static function hide(string ...$options): void
    {
        (new ReflectionMethod(CommandBase::class, 'hideSecretsFromTheProcessTitle'))->invoke(null, ...$options);
    }

    private static function cmdline(): string
    {
        clearstatcache();

        return str_replace("\0", ' ', (string)file_get_contents('/proc/self/cmdline'));
    }
}
