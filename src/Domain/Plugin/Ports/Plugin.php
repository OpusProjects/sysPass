<?php
declare(strict_types=1);
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

namespace SP\Domain\Plugin\Ports;

use SP\Domain\Core\Events\EventReceiver;

/**
 * Interface PluginInterface
 */
interface Plugin extends EventReceiver
{
    /**
     * Returns the plugin's base directory
     *
     * @return string|null
     */
    public function getBase(): ?string;

    /**
     * Returns the directory of the theme in use
     *
     * @return string|null
     */
    public function getThemeDir(): ?string;

    /**
     * Returns the plugin's author
     *
     * @return string|null
     */
    public function getAuthor(): ?string;

    /**
     * Returns the plugin's version
     *
     * @return array<int|string>|null Version segments
     */
    public function getVersion(): ?array;

    /**
     * Returns the compatible sysPass version
     *
     * @return array<int|string>|null Version segments
     */
    public function getCompatibleVersion(): ?array;

    public function getName(): ?string;

    public function getData(): mixed;

    public function saveData(int $id, object $data): void;

    public function onLoad();

    public function onUpgrade(string $version);
}
