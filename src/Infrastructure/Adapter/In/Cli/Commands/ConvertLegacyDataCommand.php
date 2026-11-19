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

namespace SP\Infrastructure\Adapter\In\Cli\Commands;

use Exception;
use Psr\Log\LoggerInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Upgrade\Services\LegacySerializedData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function SP\__;

/**
 * Converts stored settings that sysPass 3.2 wrote into the current format
 *
 * The upgrade does this on its own. This is for a 3.2 database restored into an installation that
 * has already upgraded, where no upgrade runs to do it. Rows already converted are left alone, so
 * it is safe to run again.
 */
#[AsCommand(name: 'sp:convertLegacyData')]
final class ConvertLegacyDataCommand extends CommandBase
{
    public function __construct(
        private readonly LegacySerializedData $legacySerializedData,
        LoggerInterface $logger,
        ConfigFileService $config
    ) {
        parent::__construct($logger, $config);
    }

    protected function configure(): void
    {
        $this->setDescription(__('Convert sysPass 3.2 stored settings'))
            ->setHelp(__('Converts user profiles, preferences and item presets restored from sysPass 3.2'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        try {
            $result = $this->legacySerializedData->convert();

            $message = sprintf(
                __('%d converted, %d could not be converted (see the log)'),
                $result['converted'],
                $result['failed']
            );

            if ($result['failed'] > 0) {
                $style->warning($message);

                return self::FAILURE;
            }

            $style->success($message);

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            $style->error(__($e->getMessage()));
        }

        return self::FAILURE;
    }
}
