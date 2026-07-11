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

namespace SP\Infrastructure\Http\Services;

use SP\Infrastructure\Http\Ports\ResponseService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Class Response
 *
 * Thin, chainable wrapper over Symfony's HttpFoundation Response, exposing the
 * subset of the response API the application used through the now-removed router.
 */
class Response implements ResponseService
{
    private bool $sent = false;

    public function __construct(private readonly SymfonyResponse $response = new SymfonyResponse())
    {
    }

    public function header(string $key, string $value): static
    {
        $this->response->headers->set($key, $value);

        return $this;
    }

    public function headers(): ResponseHeaderBag
    {
        return $this->response->headers;
    }

    public function body(string $body): static
    {
        $this->response->setContent($body);

        return $this;
    }

    public function getBody(): string
    {
        return (string)$this->response->getContent();
    }

    public function append(string $body): static
    {
        $this->response->setContent($this->getBody() . $body);

        return $this;
    }

    public function code(int $code): static
    {
        $this->response->setStatusCode($code);

        return $this;
    }

    public function redirect(string $url, int $code = 302): static
    {
        $this->response->setStatusCode($code);
        $this->response->headers->set('Location', $url);

        return $this;
    }

    public function send(bool $flush = false): static
    {
        // Send headers (a no-op when already sent, e.g. under the test runner) and
        // echo the body. We deliberately avoid Symfony Response::send(), which closes
        // all output buffers — that would discard content streamed afterwards (e.g. a
        // controller calling readChunked() right after send()) and tear down the test
        // runner's output capture.
        $this->response->sendHeaders();
        $this->response->sendContent();
        $this->sent = true;

        return $this;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function getResponse(): SymfonyResponse
    {
        return $this->response;
    }
}
