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

namespace SP\Tests\Unit\Infrastructure\Html\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Infrastructure\Html\Services\MinifyFile;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class MinifyFileTest
 *
 * MinifyFile is the per-file wrapper that CssController/JsController's Minify builder
 * concatenates: getName() is what ends up in the "/* FILE: ... *\/" comment written into the
 * response, getContent() is what ends up in the response body, and needsMinify() decides
 * whether the JS compressor runs on it at all. What each of them is actually built on top of
 * matters for how a request for a bad or unexpected file behaves, so these tests exercise the
 * FileHandlerInterface seam directly rather than only through a concrete FileHandler.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class MinifyFileTest extends UnitaryTestCase
{
    /**
     * getHash() is what Minify::getEtag() sums across every added file, so it has to be an
     * exact pass-through of the file handler's own hash rather than something MinifyFile
     * recomputes itself.
     *
     * @throws Exception
     */
    public function testGetHashDelegatesToTheFileHandler(): void
    {
        $hash = self::$faker->sha1();

        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getHash')->willReturn($hash);

        $minifyFile = new MinifyFile($fileHandler, true);

        self::assertSame($hash, $minifyFile->getHash());
    }

    /**
     * getContent() is what MinifyCss/MinifyJs write into the response body; it has to be
     * exactly what the underlying handler reads, unmodified.
     *
     * @throws Exception
     * @throws FileException
     */
    public function testGetContentDelegatesToTheFileHandler(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('app.min.js');
        $fileHandler->method('getBase')
                    ->willReturn(REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js');
        $fileHandler->method('readToString')->willReturn('body { color: red; }');

        $minifyFile = new MinifyFile($fileHandler, true);

        self::assertSame('body { color: red; }', $minifyFile->getContent());
    }

    /**
     * A file that becomes unreadable between checkFileExists() and the actual read (e.g.
     * removed mid-request) must surface as the application's own exception rather than a
     * half-written response body.
     *
     * @throws Exception
     */
    public function testGetContentPropagatesAFileException(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('app.min.js');
        $fileHandler->method('getBase')
                    ->willReturn(REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js');
        $fileHandler->method('readToString')->willThrowException(FileException::error('gone'));

        $minifyFile = new MinifyFile($fileHandler, true);

        $this->expectException(FileException::class);

        $minifyFile->getContent();
    }

    /**
     * The same check that decides the label decides whether the file is read at all. It used not
     * to: the name was blanked and the content served anyway, which is how a request naming a
     * path outside the application was answered with that file's contents under an empty label.
     *
     * @throws Exception
     */
    public function testGetContentRefusesAFileOutsideTheApplication(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('../../../../etc/passwd');
        $fileHandler->method('getBase')->willReturn('/some/unrelated/dir');
        $fileHandler->expects(self::never())->method('readToString');

        $minifyFile = new MinifyFile($fileHandler, true);

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('File not found');

        $minifyFile->getContent();
    }

    /**
     * A file directly inside a directory literally named "css" or "js" under the app is the
     * ordinary case (CssController/JsController's own bundled file lists) and must resolve to
     * its own basename, not be blanked out.
     */
    public function testGetNameReturnsTheBasenameWhenTheDirectoryIsAllowed(): void
    {
        $base = REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js';

        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('app.min.js');
        $fileHandler->method('getBase')->willReturn($base);

        $minifyFile = new MinifyFile($fileHandler, false);

        self::assertSame('app.min.js', $minifyFile->getName());
    }

    /**
     * getName() forwards to Request::getSecureAppFile(), which only accepts a base directory
     * literally named "css" or "js" (see Request::SECURE_DIRS). A file sitting anywhere else
     * — even a perfectly real, readable file — gets its label blanked to an empty string
     * rather than raising an error or falling back to some other name.
     */
    public function testGetNameIsBlankedWhenTheDirectoryIsNotASecureDirectory(): void
    {
        $base = REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'tests';

        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('bootstrap.php');
        $fileHandler->method('getBase')->willReturn($base);

        $minifyFile = new MinifyFile($fileHandler, false);

        self::assertSame('', $minifyFile->getName());
    }

    /**
     * A traversal-shaped file name is blanked the same way, even when the base directory
     * itself is one of the allowed ones: resolving base + name walks outside base, and the
     * "starts with base" check in Request::getSecureAppPath() rejects it.
     */
    public function testGetNameIsBlankedForATraversalAttemptEvenInsideAnAllowedDirectory(): void
    {
        $base = REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js';

        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('../../../../../../../../etc/passwd');
        $fileHandler->method('getBase')->willReturn($base);

        $minifyFile = new MinifyFile($fileHandler, false);

        self::assertSame('', $minifyFile->getName());
    }

    /**
     * needsMinify() is false outright when the caller passed minify=false, regardless of the
     * file's own name — that flag is how CssController/JsController mark their own bundled,
     * already-minified files so no compressor runs on them.
     */
    public function testNeedsMinifyIsFalseWhenTheMinifyFlagIsFalse(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('style.css');

        $minifyFile = new MinifyFile($fileHandler, false);

        self::assertFalse($minifyFile->needsMinify());
    }

    /**
     * A file already named *.min.css/*.min.js is skipped even when minify=true — running the
     * compressor a second time over an already-minified vendor file would be pointless work at
     * best.
     */
    public function testNeedsMinifyIsFalseForAnAlreadyMinifiedFile(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('jquery-ui.structure.min.css');

        $minifyFile = new MinifyFile($fileHandler, true);

        self::assertFalse($minifyFile->needsMinify());
    }

    /**
     * The same skip applies to a "*pack.js" style name, the other suffix the exclusion regex
     * recognises as already compressed.
     */
    public function testNeedsMinifyIsFalseForAPackedFile(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('select2.pack.js');

        $minifyFile = new MinifyFile($fileHandler, true);

        self::assertFalse($minifyFile->needsMinify());
    }

    /**
     * An ordinary, not-yet-minified source file with minify=true is exactly the case the
     * compressor exists for.
     */
    public function testNeedsMinifyIsTrueForAnOrdinarySourceFile(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->method('getName')->willReturn('app-triggers.js');

        $minifyFile = new MinifyFile($fileHandler, true);

        self::assertTrue($minifyFile->needsMinify());
    }
}
