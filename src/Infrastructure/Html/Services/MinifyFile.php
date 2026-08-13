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

namespace SP\Infrastructure\Html\Services;

use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Infrastructure\Http\Services\Request as HttpRequest;
use SP\Domain\Core\Exceptions\FileException;

use function SP\__u;

/**
 * Class MinifyFile
 */
final readonly class MinifyFile
{
    public function __construct(
        private FileHandlerInterface $fileHandler,
        private bool                 $minify
    ) {
    }

    public function getHash(): string
    {
        return $this->fileHandler->getHash();
    }

    public function needsMinify(): bool
    {
        return $this->minify === true && !preg_match('/(?:\.min|pack)\.(css|js)$/', $this->fileHandler->getName());
    }

    public function getName(): string
    {
        return HttpRequest::getSecureAppFile($this->fileHandler->getName(), $this->fileHandler->getBase());
    }

    /**
     * @throws FileException
     */
    public function getContent(): string
    {
        // getName() comes back empty when the file resolved outside the application, or outside a
        // css/js directory within it. That check was only labelling the concatenated output: the
        // content was read and served regardless, so a request naming ../../../../etc/passwd was
        // answered with the file under an empty label. Nothing outside those directories is
        // servable, so an unnamed file is not read at all.
        if ($this->getName() === '') {
            throw FileException::error(
                __u('File not found'),
                __u('The file is not within the application\'s resource directories')
            );
        }

        return $this->fileHandler->readToString();
    }
}
