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

namespace SP\Tests\Unit\Domain\Core\File;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\Core\File\MimeType;
use SP\Domain\Core\File\MimeTypesService;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Infrastructure\File\FileHandler;
use SP\Infrastructure\File\YamlFileStorage;
use SP\Infrastructure\MimeTypes;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class MimeTypesServiceTest
 *
 * MimeTypesService is what the file-upload paths consult to know which extensions are
 * acceptable and what content type they carry (ConfigManager's IndexController lists the same
 * data in the admin UI). tests/Unit/Infrastructure/MimeTypesTest.php already covers the caching
 * mechanics — when the cache is used, when it is rebuilt, how a rebuild failure surfaces — all
 * against a handful of fake, faker-generated entries. This suite covers the other half: the
 * actual bundled data. It wires the real implementation up to the project's own
 * resources/mimetypes.yaml (the same file the running application loads from, with the cache
 * forced to miss) and checks what a known extension resolves to and that an unknown one simply
 * has no entry.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class MimeTypesServiceTest extends UnitaryTestCase
{
    private MimeTypesService $mimeTypes;

    /**
     * @throws Exception
     * @throws FileException
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Cache reports as absent, so MimeTypes falls through to loading straight from the
        // bundled YAML file rather than short-circuiting on a (non-existent) cached copy.
        $fileCache = $this->createMock(FileCacheService::class);
        $fileCache->method('exists')->willReturn(false);

        $yamlPath = REAL_APP_ROOT
                    . DIRECTORY_SEPARATOR . 'resources'
                    . DIRECTORY_SEPARATOR . 'mimetypes.yaml';

        $this->mimeTypes = new MimeTypes($fileCache, new YamlFileStorage(new FileHandler($yamlPath)));
    }

    /**
     * A well-known extension bundled with the application (PDF) must resolve to its documented
     * MIME type — this is the data the file-upload validation and the download Content-Type
     * header both ultimately depend on.
     */
    public function testGetMimeTypesReturnsTheEntryForAKnownExtensionFromTheBundledFile(): void
    {
        $pdf = $this->findByExtension('pdf');

        self::assertNotNull($pdf);
        self::assertSame('application/pdf', $pdf->getType());
        self::assertSame('pdf', $pdf->getExtension());
    }

    /**
     * An extension that is not in resources/mimetypes.yaml simply has no entry at all —
     * getMimeTypes() does not synthesize a generic fallback (e.g. application/octet-stream) for
     * anything it does not recognise; callers have to handle "unknown" as its own case.
     */
    public function testGetMimeTypesHasNoEntryForAnUnknownExtension(): void
    {
        self::assertNull($this->findByExtension('this-extension-does-not-exist'));
    }

    /**
     * The list is sourced entirely from the YAML file rather than a hand-maintained PHP array —
     * every entry loaded is a MimeType value object built from that file's type/description/
     * extension columns, and there are more than a handful of them.
     */
    public function testGetMimeTypesReturnsMimeTypeInstancesLoadedFromTheYamlFile(): void
    {
        $mimeTypes = $this->mimeTypes->getMimeTypes();

        self::assertGreaterThan(10, count($mimeTypes));

        foreach ($mimeTypes as $mimeType) {
            self::assertInstanceOf(MimeType::class, $mimeType);
        }
    }

    private function findByExtension(string $extension): ?MimeType
    {
        foreach ($this->mimeTypes->getMimeTypes() as $mimeType) {
            if ($mimeType->getExtension() === $extension) {
                return $mimeType;
            }
        }

        return null;
    }
}
