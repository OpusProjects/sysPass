<?php
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Resource;

use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Common\Enums\ResponseType;
use SP\Infrastructure\Http\Services\Request as HttpRequest;
use SP\Infrastructure\File\FileHandler;
use SP\Domain\File\FileSystem;

/**
 * Class CssController
 */
final class CssController extends ResourceBase
{
    private const CSS_MIN_FILES = [
        'reset.min.css',
        'jquery-ui.min.css',
        'jquery-ui.structure.min.css',
        'material-icons.min.css',
        'magnific-popup.min.css',
    ];

    /**
     * Return CSS resources
     */
    #[Action(ResponseType::CALLBACK)]
    public function cssAction(): ActionResponse
    {
        $file = $this->request->analyzeString('f');
        $base = $this->request->analyzeString('b');

        if ($file && $base) {
            $minify = $this->minify->builder()
                                   ->addFiles($this->buildFiles(urldecode($base), explode(',', urldecode($file)), true));
        } else {
            $minify = $this->minify->builder()
                                   ->addFiles(
                                       $this->buildFiles(
                                           FileSystem::buildPath($this->pathsContext[Path::PUBLIC], 'vendor', 'css'),
                                           self::CSS_MIN_FILES
                                       ),
                                       false
                                   );

            // fonts.min.css ships with the theme, not public/css; only add it if present
            // (FileHandler opens eagerly and would throw on a missing file).
            $fonts = FileSystem::buildPath($this->pathsContext[Path::PUBLIC], 'css', 'fonts.min.css');

            if (is_file($fonts)) {
                $minify->addFile(new FileHandler($fonts), false);
            }
        }

        // getMinified() sets the body + the text/css content type on the shared response.
        return new ActionResponse(
            ResponseStatus::OK,
            function () use ($minify) {
                $minify->getMinified();
            }
        );
    }

    /**
     * @param string $base
     * @param string[] $files
     * @param bool $insecure
     * @return FileHandler[]
     */
    private function buildFiles(string $base, array $files, bool $insecure = false): array
    {
        $base = $insecure ? HttpRequest::getSecureAppPath($base) : $base;

        return array_map(
            fn(string $file) => new FileHandler(FileSystem::buildPath($base, $file)),
            $files
        );
    }
}
