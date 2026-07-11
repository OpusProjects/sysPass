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

use function SP\getFromEnv;

/**
 * Class CommandBase
 *
 * @package SP\Infrastructure\Adapter\In\Cli\Commands
 */
abstract class CommandBase extends Command
{
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
