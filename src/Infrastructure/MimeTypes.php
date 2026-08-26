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

namespace SP\Infrastructure;

use SP\Domain\Core\File\MimeType;
use SP\Domain\Core\File\MimeTypesService;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Infrastructure\Storage\Ports\YamlFileStorageService;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\Core\Exceptions\SPException;

use function SP\logger;
use function SP\processException;

/**
 * Class Mime
 *
 * @package SP\Core
 */
final class MimeTypes implements MimeTypesService
{
    /**
     * Cache expire time
     */
    public const CACHE_EXPIRE = 86400;

    /**
     * @var MimeType[]
     */
    protected array $mimeTypes = [];

    /**
     * Mime constructor.
     *
     * @throws FileException
     */
    public function __construct(
        private readonly FileCacheService       $fileCache,
        private readonly YamlFileStorageService $yamlFileStorageService
    ) {
        $this->loadCache();
    }

    /**
     * Loads MIME types from cache file
     *
     * @throws FileException
     */
    private function loadCache(): void
    {
        if (!$this->fileCache->exists()
            || $this->fileCache->isExpired(self::CACHE_EXPIRE)
            || $this->fileCache->isExpiredDate($this->yamlFileStorageService->getFileTime())
        ) {
            $this->mapAndSave();

            return;
        }

        try {
            // MimeType[]: an array of objects, so the class is named. Without it every entry
            // came back as __PHP_Incomplete_Class and failed far from here, where a closure in
            // ConfigManager\IndexController takes a MimeType.
            $this->mimeTypes = $this->fileCache->load(null, MimeType::class);

            logger('Loaded MIME types cache', 'INFO');
        } catch (SPException $e) {
            // Same reason as Actions::loadCache(), and there was no catch here at all: a readable
            // but corrupt cache — a reader landing mid-write — comes back from Serde as a plain
            // SPException and took this down too. MimeTypes is what the file upload and the
            // config manager read. Around the load alone, so a rebuild that fails on its own
            // terms still reports.
            processException($e);

            $this->mapAndSave();
        }
    }

    /**
     * @return void
     * @throws FileException
     */
    private function mapAndSave(): void
    {
        logger('MIME TYPES CACHE MISS', 'INFO');

        $this->map();
        $this->saveCache();
    }

    /**
     * Sets an array of mime types
     *
     * @throws FileException
     */
    private function map(): void
    {
        $this->mimeTypes = array_map(
            static fn($item) => new MimeType($item['type'], $item['description'], $item['extension']),
            $this->yamlFileStorageService->load()['mimetypes']
        );
    }

    /**
     * Saves MIME types into cache file
     */
    private function saveCache(): void
    {
        try {
            $this->fileCache->save($this->mimeTypes);

            logger('Saved MIME types cache', 'INFO');
        } catch (FileException $e) {
            processException($e);
        }
    }

    /**
     * @throws FileException
     */
    public function reset(): void
    {
        logger('Reset MIME types cache', 'INFO');

        $this->fileCache->delete();

        $this->loadCache();
    }

    /**
     * @return MimeType[]
     */
    public function getMimeTypes(): array
    {
        return $this->mimeTypes;
    }
}
