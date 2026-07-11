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

namespace SP\Infrastructure\Http\Ports;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * HTTP response abstraction backed by Symfony HttpFoundation.
 *
 * Mirrors the small, chainable response API the codebase relied on (previously
 * provided by the now-removed third-party router) so call sites only change by type-hint.
 */
interface ResponseService
{
    /**
     * Set a response header.
     */
    public function header(string $key, string $value): static;

    /**
     * Access the underlying response header bag (e.g. to ->set() a header).
     */
    public function headers(): ResponseHeaderBag;

    /**
     * Replace the response body.
     */
    public function body(string $body): static;

    /**
     * Return the current response body.
     */
    public function getBody(): string;

    /**
     * Append to the response body.
     */
    public function append(string $body): static;

    /**
     * Set the HTTP status code.
     */
    public function code(int $code): static;

    /**
     * Turn the response into a redirect to the given URL.
     */
    public function redirect(string $url, int $code = 302): static;

    /**
     * Send the response (headers — when not already sent — and body).
     */
    public function send(bool $flush = false): static;

    /**
     * Whether the response has already been sent.
     */
    public function isSent(): bool;

    /**
     * The underlying Symfony response.
     */
    public function getResponse(): Response;
}
