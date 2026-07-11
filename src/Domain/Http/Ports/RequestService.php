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

namespace SP\Domain\Http\Ports;

use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Method;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface RequestService
 */
interface RequestService
{
    public function getClientAddress(bool $fullForwarded = false): string;

    /**
     * @return string[]|null
     */
    public function getForwardedFor(): ?array;

    /**
     * Check whether the page is being reloaded
     */
    public function checkReload(): bool;

    public function analyzeEmail(string $param, ?string $default = null): ?string;

    /**
     * Analyze an encrypted value and return it decrypted
     */
    public function analyzeEncrypted(string $param): ?string;

    public function analyzeString(string $param, ?string $default = null): ?string;

    public function analyzeUnsafeString(string $param, ?string $default = null): ?string;

    /**
     * @param string $param
     * @param callable|null $mapper
     * @param mixed $default
     *
     * @return mixed[]|null
     */
    public function analyzeArray(string $param, ?callable $mapper = null, mixed $default = null): ?array;

    /**
     * Check whether the request is in JSON format
     */
    public function isJson(): bool;

    /**
     * Check whether the request is an Ajax request
     */
    public function isAjax(): bool;

    public function analyzeInt(string $param, ?int $default = null): ?int;

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int|null}|mixed[]|null
     */
    public function getFile(string $file): ?array;

    public function analyzeBool(string $param, ?bool $default = null): bool;

    /**
     * @param string $key
     * @param string|null $param Checks the signature only for the given param
     *
     * @throws SPException
     */
    public function verifySignature(string $key, ?string $param = null): void;

    /**
     * Returns the URI used by the browser and checks for the protocol used
     *
     * @see https://tools.ietf.org/html/rfc7239#section-7.5
     */
    public function getHttpHost(): string;

    /**
     * Return forward data per RFC 7239
     *
     * @return array{host: string, proto: string, for: string[]|null}|null
     * @see https://tools.ietf.org/html/rfc7239#section-7.5
     */
    public function getForwardedData(): ?array;

    public function getHeader(string $header): string;

    /**
     * Return x-forward data
     *
     * @return array{host: string, proto: string, for: string[]|null}|null
     */
    public function getXForwardedData(): ?array;

    public function getMethod(): Method;

    public function isHttps(): ?bool;

    public function getServerPort(): int;

    public function getRequest(): Request;

    public function getServer(string $key): string;
}
