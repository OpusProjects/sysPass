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

namespace SP\Tests\Unit\Application\Import\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SP\Domain\Import\Services\ImportException;
use SP\Application\Import\Services\XmlFile;
use SP\Domain\Import\Services\XmlFormat;
use SP\Domain\Core\Exceptions\FileException;
use SP\Infrastructure\File\FileHandler;
use SP\Tests\Support\UnitaryTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Class XmlFileTest
 *
 */
#[Group('unitary')]
class XmlFileTest extends UnitaryTestCase
{
    private const KEEPASS_FILE = RESOURCE_PATH . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR .
                                 'data_keepass.xml';
    private const SYSPASS_FILE = RESOURCE_PATH . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR .
                                 'data_syspass.xml';

    public static function fileFormatProvider(): array
    {
        return [
            [self::KEEPASS_FILE, XmlFormat::Keepass],
            [self::SYSPASS_FILE, XmlFormat::Syspass],
        ];
    }

    /**
     * @throws ImportException
     * @throws FileException
     */
    #[DataProvider('fileFormatProvider')]
    public function testDetectFormat(string $file, XmlFormat $format)
    {
        $fileHandler = new FileHandler($file);

        $xmlFile = new XmlFile();
        $out = $xmlFile->builder($fileHandler)->detectFormat();

        $this->assertEquals($format, $out);
    }

    /**
     * @throws ImportException
     * @throws FileException
     */
    public function testDetectFormatWithException()
    {
        $fileHandler = new FileHandler(self::$faker->filePath(), 'w');
        $fileHandler->write('<?xml version="1.0" encoding="utf-8" standalone="yes"?><Test></Test>');

        $xmlFile = new XmlFile();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('XML file not supported');

        $xmlFile->builder($fileHandler)->detectFormat();
    }

    /**
     * @throws ImportException
     * @throws FileException
     */
    public function testBuilder()
    {
        $fileHandler = new FileHandler(self::$faker->filePath(), 'w');
        $fileHandler->write('<?xml version="1.0" encoding="utf-8" standalone="yes"?><Test></Test>');

        $xmlFile = new XmlFile();
        $out = $xmlFile->builder($fileHandler);

        $this->assertFalse(spl_object_id($xmlFile) === spl_object_id($out));
    }

    /**
     * A file whose entities refer to one another until they fill memory is refused.
     *
     * `LIBXML_PARSEHUGE` was passed when loading an imported file, and what it relaxes includes
     * libxml's entity expansion limit — the protection against exactly this. Five hundred bytes
     * expanded to three million characters with the flag, and libxml refused the same file
     * without it. Nine levels rather than six would be some gigabytes from under a kilobyte.
     *
     * @throws ImportException
     * @throws FileException
     */
    public function testAnEntityExpansionBombIsRefused()
    {
        $entities = '<!ENTITY lol "lol">';

        for ($i = 1; $i <= 6; $i++) {
            $previous = $i === 1 ? 'lol' : 'lol' . ($i - 1);
            $entities .= sprintf('<!ENTITY lol%d "%s">', $i, str_repeat("&$previous;", 10));
        }

        $fileHandler = new FileHandler(self::$faker->filePath(), 'w');
        $fileHandler->write(
            sprintf('<?xml version="1.0"?><!DOCTYPE lolz [%s]><Root><Meta>&lol6;</Meta></Root>', $entities)
        );

        $xmlFile = new XmlFile();

        $this->expectException(ImportException::class);

        $xmlFile->builder($fileHandler);
    }

    /**
     * No document type at all, bomb or not. sysPass's own export declares none and neither do the
     * formats it imports, so refusing one removes entity expansion and external entities as a
     * class rather than resting on libxml's limits and defaults staying where they are.
     *
     * @throws ImportException
     * @throws FileException
     */
    public function testADocumentTypeIsRefused()
    {
        $fileHandler = new FileHandler(self::$faker->filePath(), 'w');
        $fileHandler->write('<?xml version="1.0"?><!DOCTYPE Root><Root><Meta>a</Meta></Root>');

        $xmlFile = new XmlFile();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Unable to process the XML file');

        $xmlFile->builder($fileHandler);
    }

    /**
     * @throws ImportException
     * @throws FileException
     */
    public function testBuilderWithException()
    {
        $fileHandler = new FileHandler(self::$faker->filePath(), 'w');
        $fileHandler->write('<?xml version="1.0" encoding="utf-8" standalone="yes"?><Test>');

        $xmlFile = new XmlFile();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Internal error');

        $xmlFile->builder($fileHandler);
    }

    public function testGetDocument()
    {
        $xmlFile = new XmlFile();
        $out = $xmlFile->getDocument();

        $this->assertFalse($out->formatOutput);
        $this->assertFalse($out->preserveWhiteSpace);
    }
}
