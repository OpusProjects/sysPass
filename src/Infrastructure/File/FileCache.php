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

namespace SP\Infrastructure\File;

use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Storage\Ports\FileCacheService;

use function SP\__u;

/**
 * Class FileCache
 */
class FileCache extends FileCacheBase
{
    /**
     * @throws FileException
     * @throws SPException
     */
    public function load(?string $path = null, string ...$allowed): mixed
    {
        $this->checkOrInitializePath($path);

        // Data only. Nothing here named a class, and Serde::deserialize() allows every class when
        // it is not told which to expect — so a write into the cache directory became objects of
        // the attacker's choosing on the next read. Callers that cache an object use loadWith(),
        // which says what it may be.
        return Serde::deserializeData($this->path->checkIsReadable()->readToString(), ...$allowed);
    }

    /**
     * @throws FileException
     */
    public function save(mixed $data, ?string $path = null): FileCacheService
    {
        $this->checkOrInitializePath($path);
        $this->createPath();

        $this->path->save(Serde::serialize($data));

        return $this;
    }

    /**
     * @inheritDoc
     * @throws InvalidClassException
     */
    public function loadWith(string $class, string ...$nested): object
    {
        // The nested classes matter: an object that holds other objects — ThemeIcons holds a
        // FontIcon per entry — would otherwise come back with every one of them replaced by
        // __PHP_Incomplete_Class, while still passing the instanceof check below.
        $data = unserialize(
            $this->path->checkIsReadable()->readToString(),
            ['allowed_classes' => [$class, ...$nested]]
        );

        if (!class_exists($class) || !($data instanceof $class)) {
            throw InvalidClassException::error(
                sprintf(__u('Either class does not exist or file data cannot unserialized into: %s'), $class)
            );
        }

        return $data;
    }
}
