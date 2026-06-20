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

namespace SP\Domain\Html\Services;

use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Domain\Html\Ports\MinifyService;
use SP\Domain\Http\Header;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Http\Ports\ResponseService;
use SP\Infrastructure\File\FileException;
use SplObjectStorage;

/**
 * Class Minify
 */
abstract class Minify implements MinifyService
{
    private const OFFSET = 3600 * 24 * 30;


    /**
     * @var SplObjectStorage<MinifyFile>
     */
    private SplObjectStorage $files;

    public function __construct(
        private readonly ResponseService $response,
        private readonly RequestService $request
    ) {
        $this->files = new SplObjectStorage();
    }

    /**
     * Return compressed CSS and JS files to the browser
     * Method that returns a compressed CSS or JS resource. If the ETAG matches,
     * the HTTP/304 code is returned
     */
    public function getMinified(): void
    {
        if ($this->files->count() === 0) {
            return;
        }

        $this->setHeaders();

        if (!$this->response->isSent()) {
            $this->response->body($this->minify($this->files));
        }
    }

    /**
     * Sets HTTP headers
     */
    private function setHeaders(): void
    {
        if (($etag = $this->checkEtag()) === null) {
            return;
        }

        $this->response->header(Header::ETAG->value, $etag);
        $this->response->header(
            Header::CACHE_CONTROL->value,
            sprintf('public, max-age={%d}, must-revalidate', self::OFFSET)
        );
        $this->response->header(Header::PRAGMA->value, sprintf('public; maxage={%d}', self::OFFSET));
        $this->response->header(Header::EXPIRES->value, gmdate('D, d M Y H:i:s \G\M\T', time() + self::OFFSET));
        $this->response->header(Header::CONTENT_TYPE->value, $this->getContentTypeHeader());
    }

    private function checkEtag(): ?string
    {
        $etag = $this->getEtag();

        // Return code 304 if the version is the same and no refresh is requested
        if ($etag === $this->request->getHeader(Header::IF_NONE_MATCH->value)
            && !($this->request->getHeader(Header::CACHE_CONTROL->value) === 'no-cache'
                 || $this->request->getHeader(Header::CACHE_CONTROL->value) === 'max-age=0'
                 || $this->request->getHeader(Header::PRAGMA->value) === 'no-cache')
        ) {
            $this->response->header($this->request->getServer('SERVER_PROTOCOL'), '304 Not Modified');
            $this->response->send();

            return null;
        }

        return $etag;
    }

    /**
     * Calculate the hash of several files.
     *
     * @return string With the hash
     */
    private function getEtag(): string
    {
        $etag = '';

        foreach ($this->files as $file) {
            $etag .= $file->getHash();
        }

        return sha1($etag);
    }

    abstract protected function getContentTypeHeader(): string;

    abstract protected function minify(SplObjectStorage $files): string;

    /**
     * @param FileHandlerInterface[] $files
     * @param bool $minify
     * @return MinifyService
     * @throws FileException
     */
    public function addFiles(array $files, bool $minify = true): MinifyService
    {
        array_walk($files, fn(FileHandlerInterface $fileHandler) => $this->addFile($fileHandler));

        return $this;
    }

    /**
     * Add a file
     *
     * @param FileHandlerInterface $fileHandler
     * @param bool $minify Whether minification is needed
     *
     * @return MinifyService
     * @throws FileException
     */
    public function addFile(FileHandlerInterface $fileHandler, bool $minify = true): MinifyService
    {
        $fileHandler->checkFileExists();

        $this->files->offsetSet(new MinifyFile($fileHandler, $minify));

        return $this;
    }

    public function builder(): MinifyService
    {
        return clone $this;
    }
}
