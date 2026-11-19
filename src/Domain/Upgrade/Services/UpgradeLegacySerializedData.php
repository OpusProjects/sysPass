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

namespace SP\Domain\Upgrade\Services;

use SP\Application\Application;
use SP\Domain\Common\Attributes\UpgradeVersion;
use SP\Domain\Common\Services\Service;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;

use function SP\__u;

/**
 * Converts what sysPass 3.2 stored with PHP serialization into JSON
 *
 * See LegacySerializedData. Without it an installation upgraded from 3.2 could read none of its
 * user profiles, preferences or item presets.
 */
#[UpgradeVersion('400.26111901')]
final class UpgradeLegacySerializedData extends Service implements UpgradeHandlerService
{
    public function __construct(Application $application, private readonly LegacySerializedData $legacy)
    {
        parent::__construct($application);
    }

    public function apply(string $version, ConfigDataInterface $configData): bool
    {
        $result = $this->legacy->convert();

        $this->eventDispatcher->notify(new Event(
            'upgrade.legacySerializedData.process',
            $this,
            EventMessage::build()
                        ->addDescription(__u('Stored settings converted to the current format'))
                        ->addDetail(__u('Version'), $version)
                        ->addDetail(__u('Converted'), (string)$result['converted'])
                        ->addDetail(__u('Not converted'), (string)$result['failed'])
        ));

        // A row that could not be converted is logged and left as it was, which is no worse than
        // before; it does not stop the rest of the upgrade.
        return true;
    }
}
