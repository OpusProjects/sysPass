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
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Html\Services\Minify;
use SP\Infrastructure\Html\Services\MinifyCss;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Tests\Support\UnitaryTestCase;
use SplObjectStorage;

/**
 * Class MinifyTest
 *
 * Minify is the abstract base that CssController's and JsController's resource routes ride on:
 * it owns the ETAG/cache-header dance (already covered per concrete subclass in MinifyCssTest
 * and MinifyJsTest) and the orchestration around adding files and handing them to the concrete
 * minify() implementation. What neither of those existing suites checks is what actually ends
 * up in the response body — they assert that body() is called, not with what — so that is the
 * focus here, together with the file-list bookkeeping (order, existence checks) that is
 * entirely Minify's own and not the concrete subclass's.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class MinifyTest extends UnitaryTestCase
{
    private ResponseService|MockObject $response;
    private RequestService|MockObject  $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = $this->createMock(ResponseService::class);
        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * The concatenation is what the browser actually receives for a multi-file request (e.g.
     * the bundled reset/jquery-ui/... CSS files CssController serves in one response): each
     * file contributes its own "/* FILE: name *\/" header followed by its content, back to
     * back, in the order the files were added — not just "some content of the right length".
     *
     * @throws Exception
     * @throws FileException
     */
    public function testGetMinifiedWritesThePerFileHeaderAndContentForEachFileInOrder(): void
    {
        $base = REAL_APP_ROOT
                . DIRECTORY_SEPARATOR . 'public'
                . DIRECTORY_SEPARATOR . 'vendor'
                . DIRECTORY_SEPARATOR . 'css';

        $first = $this->createMock(FileHandlerInterface::class);
        $first->method('getHash')->willReturn('hash-one');
        $first->method('getName')->willReturn('reset.min.css');
        $first->method('getBase')->willReturn($base);
        $first->method('readToString')->willReturn('body{margin:0}');

        $second = $this->createMock(FileHandlerInterface::class);
        $second->method('getHash')->willReturn('hash-two');
        $second->method('getName')->willReturn('jquery-ui.min.css');
        $second->method('getBase')->willReturn($base);
        $second->method('readToString')->willReturn('.ui{display:block}');

        $minify = new MinifyCss($this->response, $this->request);
        $minify->addFiles([$first, $second], false);

        // A header value that can never equal the computed etag, so setHeaders() proceeds
        // instead of short-circuiting to a 304.
        $this->request->method('getHeader')->willReturn('');
        $this->response->method('isSent')->willReturn(false);

        $body = null;
        $this->response->expects(self::once())
                       ->method('body')
                       ->willReturnCallback(function (string $content) use (&$body) {
                           $body = $content;

                           return $this->response;
                       });

        $minify->getMinified();

        self::assertSame(
            PHP_EOL . '/* FILE: reset.min.css */' . PHP_EOL . 'body{margin:0}'
            . PHP_EOL . '/* FILE: jquery-ui.min.css */' . PHP_EOL . '.ui{display:block}',
            $body
        );
    }

    /**
     * addFile() checks the file exists before wrapping it, and the existence check runs
     * against the handler that was actually passed in — a handler for a file that is not there
     * (or not readable) must stop the file from ever being added, surfacing the application's
     * own exception rather than failing later inside minify().
     *
     * @throws Exception
     * @throws FileException
     */
    public function testAddFileThrowsWhenTheUnderlyingFileDoesNotExist(): void
    {
        $fileHandler = $this->createMock(FileHandlerInterface::class);
        $fileHandler->expects(self::once())
                    ->method('checkFileExists')
                    ->willThrowException(FileException::error('File not found'));

        $minify = new MinifyCss($this->response, $this->request);

        $this->expectException(FileException::class);

        $minify->addFile($fileHandler);
    }

    /**
     * Files are stored in an SplObjectStorage, not a plain array — this only matters in
     * practice if iteration still yields them in the order they were added. A CSS request that
     * depends on cascade order (reset before the theme, the theme before overrides) would
     * otherwise render inconsistently depending on internal storage order.
     *
     * @throws Exception
     * @throws FileException
     */
    public function testFilesAreHandedToTheConcreteMinifyImplementationInInsertionOrder(): void
    {
        $minify = new class ($this->response, $this->request) extends Minify {
            /** @var string[] */
            public array $seenInOrder = [];

            protected function getContentTypeHeader(): string
            {
                return 'text/plain';
            }

            protected function minify(SplObjectStorage $files): string
            {
                foreach ($files as $file) {
                    $this->seenInOrder[] = $file->getName();
                }

                return implode(',', $this->seenInOrder);
            }
        };

        $base = REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js';

        $names = ['app.min.js', 'app-config.min.js', 'app-util.min.js'];

        foreach ($names as $name) {
            $fileHandler = $this->createMock(FileHandlerInterface::class);
            $fileHandler->method('getHash')->willReturn(self::$faker->sha1());
            $fileHandler->method('getName')->willReturn($name);
            $fileHandler->method('getBase')->willReturn($base);

            $minify->addFile($fileHandler, false);
        }

        $this->request->method('getHeader')->willReturn('');
        $this->response->method('isSent')->willReturn(false);

        $minify->getMinified();

        self::assertSame($names, $minify->seenInOrder);
    }
}
