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

namespace SP\Application\Import\Services;

use SP\Domain\Import\Services\XmlFormat;
use SP\Domain\Import\Services\ImportException;

use DOMDocument;
use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Application\Import\Ports\XmlFileService;
use SP\Domain\Core\Exceptions\FileException;
use ValueError;

use function SP\__u;
use function SP\logger;

/**
 * Class XmlFile
 */
final readonly class XmlFile implements XmlFileService
{
    private DOMDocument $document;

    public function __construct()
    {
        $this->document = new DOMDocument();
        $this->document->formatOutput = false;
        $this->document->preserveWhiteSpace = false;
    }

    /**
     * @throws ImportException
     * @throws FileException
     */
    public function builder(FileHandlerInterface $fileHandler): XmlFileService
    {
        $fileHandler->checkIsReadable();

        $self = new self();
        $self->readXMLFile($fileHandler->getFile());

        return $self;
    }

    /**
     * Read the file into an XML object.
     *
     * @throws ImportException
     */
    protected function readXMLFile(string $file): void
    {
        libxml_use_internal_errors(true);

        // Parsed under libxml's own limits. LIBXML_PARSEHUGE was passed here, and what it relaxes
        // includes the entity expansion limit — the protection against a file whose entities refer
        // to each other until they fill memory. Five hundred bytes expanded to three million
        // characters with it, and libxml refused the same file without it.
        if ($this->document->load($file) === false) {
            foreach (libxml_get_errors() as $error) {
                logger(__METHOD__ . ' - ' . $error->message);
            }

            throw ImportException::error(__u('Internal error'), __u('Unable to process the XML file'));
        }

        // And no document type at all. sysPass's own export declares none and neither do the
        // formats it imports, so refusing one costs nothing and removes entity expansion and
        // external entities as a class — rather than resting on libxml's limits and defaults
        // staying where they are, which is what went wrong above.
        if ($this->document->doctype !== null) {
            throw ImportException::error(
                __u('Unable to process the XML file'),
                __u('The file declares a document type, which is not accepted')
            );
        }
    }

    /**
     * Detect the application that generated the XML.
     *
     * @throws ImportException
     */
    public function detectFormat(): XmlFormat
    {
        $nodes = $this->document->getElementsByTagName('Generator');

        try {
            return XmlFormat::from(strtolower($nodes->item(0)->nodeValue ?? ''));
        } catch (ValueError $e) {
            throw ImportException::error(
                __u('XML file not supported'),
                __u('Unable to guess the application which data was exported from'),
                $e->getCode()
            );
        }
    }

    public function getDocument(): DOMDocument
    {
        return $this->document;
    }
}
