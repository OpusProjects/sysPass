<?php
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

namespace SP\Infrastructure\Adapter\In\Cli\Commands;

use Psr\Log\LoggerInterface;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Config\Services\ConfigFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\StyleInterface;

use function SP\__;
use function SP\getFromEnv;

/**
 * Class CommandBase
 *
 * @package SP\Infrastructure\Adapter\In\Cli\Commands
 */
abstract class CommandBase extends Command
{
    private const REDACTED = '***';

    /**
     * @var string[]
     */
    public static array $envVarsMapping = [];
    protected LoggerInterface     $logger;
    protected ConfigFile $config;
    protected ConfigDataInterface $configData;

    public function __construct(
        LoggerInterface $logger,
        ConfigFileService $config
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->configData = $this->config->getConfigData();

        parent::__construct();
    }

    /**
     * @return array|false|mixed|string
     */
    protected static function getEnvVarOrOption(
        string         $option,
        InputInterface $input
    ) {
        return static::getEnvVarForOption($option)
            ?: $input->getOption($option);
    }

    /**
     * Tell the operator that a secret they put on the command line was visible, and take it out of
     * what `ps` shows from here on.
     *
     * Called once at the start of a command rather than beside each password, so the value is gone
     * before the work starts — a master-password rotation re-encrypts every account, and the whole
     * of that is time another local user could be reading `/proc/<pid>/cmdline`.
     *
     * Nothing is said when the value came from the environment or from the prompt: those are the
     * two ways to give a command a secret that other users on the host cannot read, and they are
     * what the warning points at.
     */
    protected static function warnAboutSecretsOnTheCommandLine(
        InputInterface $input,
        StyleInterface $style,
        string         ...$options
    ): void {
        $given = array_values(
            array_filter($options, static fn(string $option): bool => !empty($input->getOption($option)))
        );

        if ($given === []) {
            return;
        }

        self::hideSecretsFromTheProcessTitle(...$options);

        $style->warning(
            sprintf(
                __('A password given as %s is visible to every local user while the command runs. Use the environment variable or let the command ask for it.'),
                implode(', ', array_map(static fn(string $option): string => '--' . $option, $given))
            )
        );
    }

    /**
     * Take the named options' values out of what `ps` shows for this process.
     *
     * A value passed as `--masterPassword=…` is in `argv`, and `argv` is `/proc/<pid>/cmdline`,
     * which every local user on the host can read for as long as the command runs — minutes, for a
     * rotation that re-encrypts every account. That is a different threat from the one the CLI is
     * otherwise exempt from: `sp:backup` needs no demo guard because whoever can run it already has
     * `config/config.xml`, but this is about the *other* users on a shared host, who have neither
     * that file nor any way to read the environment of a process they do not own.
     *
     * `cli_set_process_title()` rewrites that memory, so the value is gone from `ps` for the rest
     * of the run. The title is rebuilt from the real command line with only the secrets replaced,
     * rather than replaced wholesale, so the process still says what it is.
     *
     * This shrinks the window to the moment before the command starts; it cannot close it, and it
     * does nothing about shell history or a script with the password written into it. The warning
     * beside each call is the other half.
     */
    protected static function hideSecretsFromTheProcessTitle(string ...$options): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }

        /** @var string[] $argv */
        $argv = $_SERVER['argv'] ?? [];

        if ($argv === []) {
            return;
        }

        $masked = [];
        $maskNext = false;

        foreach ($argv as $argument) {
            if ($maskNext) {
                $masked[] = self::REDACTED;
                $maskNext = false;

                continue;
            }

            foreach ($options as $option) {
                // Both spellings Symfony accepts: --option=value and --option value.
                if (str_starts_with($argument, sprintf('--%s=', $option))) {
                    $argument = sprintf('--%s=%s', $option, self::REDACTED);

                    break;
                }

                if ($argument === sprintf('--%s', $option)) {
                    $maskNext = true;

                    break;
                }
            }

            $masked[] = $argument;
        }

        @cli_set_process_title(implode(' ', $masked));
    }

    /**
     * @return string|false
     */
    protected static function getEnvVarForOption(string $option)
    {
        // .env is loaded with Dotenv::createImmutable(), which populates $_ENV / $_SERVER
        // but not getenv(); getFromEnv() reads those first, falling back to getenv() for a
        // real environment variable. No $default is passed here: getFromEnv() would otherwise
        // type-coerce the value to match a non-null $default's type (e.g. a bool default runs
        // it through filter_var(FILTER_VALIDATE_BOOL), which would corrupt option values that
        // aren't password/path strings by turning them into false), and callers of this method
        // rely on getting the raw string back (some do their own Util::boolval() conversion).
        // getFromEnv() returns null for an unset/empty variable; coalesce that back to false to
        // preserve this method's original getenv()-based `string|false` contract, since callers
        // compare the result with `=== false` / `!== false` and with the falsy `?:` operator.
        return getFromEnv(static::$envVarsMapping[$option]) ?? false;
    }

    /**
     * @return array|false|mixed|string
     */
    protected static function getEnvVarOrArgument(
        string         $argument,
        InputInterface $input
    ) {
        return static::getEnvVarForOption($argument)
            ?: $input->getArgument($argument);
    }
}
