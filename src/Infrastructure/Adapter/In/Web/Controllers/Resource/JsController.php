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

use SP\Core\Bootstrap\Path;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Http\Services\Request as HttpRequest;
use SP\Infrastructure\File\FileHandler;
use SP\Infrastructure\File\FileSystem;

/**
 * Class JsController
 */
final class JsController extends ResourceBase
{

    private const JS_MIN_FILES = [
        'jquery.min.js',
        'clipboard.min.js',
        'selectize.min.js',
        'jsencrypt.min.js',
        'spark-md5.min.js',
        'moment.min.js',
        'moment-timezone.min.js',
        'jquery.magnific-popup.min.js',
    ];
    private const JS_APP_MIN_FILES = [
        'app.min.js',
        'app-config.min.js',
        // Selectize plugin glue (app-authored, not a library dist) — must load after
        // selectize.min.js (JS_MIN_FILES, previous <script> tag) and before app-triggers.min.js,
        // which registers a Selectize control using the "clear_selection" plugin it defines.
        'selectize-plugins.min.js',
        'app-triggers.min.js',
        'app-actions.min.js',
        'app-requests.min.js',
        // Lazily loads vendor/js/zxcvbn.min.js on window "load" (app-authored glue, not a
        // library dist); placed before app-util.min.js, which is the first to call zxcvbn().
        'zxcvbn-async.min.js',
        'app-util.min.js',
        // Hand-rolled toast module (app-authored, not a library dist) — must load before
        // app-main.min.js, whose msg wrapper calls window.toasts.
        'toasts.min.js',
        'app-main.min.js',
    ];

    /**
     * Return JS resources
     */
    #[Action(ResponseType::CALLBACK)]
    public function jsAction(): ActionResponse
    {
        $file = $this->request->analyzeString('f');
        $base = $this->request->analyzeString('b');

        if ($file && $base) {
            $minify = $this->minify->builder()
                                   ->addFiles($this->buildFiles(urldecode($base), explode(',', urldecode($file)), true));
        } elseif ($this->request->analyzeInt('g', 0) === 1) {
            $minify = $this->minify->builder()
                                   ->addFiles(
                                       $this->buildFiles(
                                           FileSystem::buildPath($this->pathsContext[Path::PUBLIC], 'js'),
                                           self::JS_APP_MIN_FILES
                                       ),
                                       false
                                   );
        } else {
            $minify = $this->minify->builder()
                                   ->addFiles(
                                       $this->buildFiles(
                                           FileSystem::buildPath($this->pathsContext[Path::PUBLIC], 'vendor', 'js'),
                                           self::JS_MIN_FILES
                                       ),
                                       false
                                   );
        }

        // getMinified() sets the body + the application/javascript content type on the response.
        return new ActionResponse(
            ResponseStatus::OK,
            function () use ($minify) {
                $minify->getMinified();
            }
        );
    }

    /**
     * @param string $base
     * @param array $files
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
