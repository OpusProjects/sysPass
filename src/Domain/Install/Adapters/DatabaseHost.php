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

namespace SP\Domain\Install\Adapters;

use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__u;

/**
 * Parsed database host field of the install wizard.
 *
 * Accepted forms: "host", "host:port", "[ipv6]", "[ipv6]:port" and
 * "unix:/path/to/socket". A bare IPv6 address ("::1") is treated as a host
 * without a port.
 */
final readonly class DatabaseHost
{
    private function __construct(
        public ?string $host,
        public ?int    $port,
        public ?string $socket
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $spec): self
    {
        $spec = trim($spec);

        if (str_starts_with($spec, 'unix:')) {
            $socket = substr($spec, 5);

            if ($socket === '') {
                throw new InvalidArgumentException(
                    __u('Please, enter the database server'),
                    SPException::ERROR,
                    __u('Server where the database will be installed')
                );
            }

            return new self(null, null, $socket);
        }

        if (preg_match('/^\[(?<host>[^]]+)](?::(?<port>\d{1,5}))?$/', $spec, $match)) {
            return new self($match['host'], self::checkPort($match['port'] ?? null), null);
        }

        // A single colon separates host and port; more than one means a bare IPv6 address
        if (preg_match('/^(?<host>[^:]+):(?<port>\d{1,5})$/', $spec, $match)) {
            return new self($match['host'], self::checkPort($match['port']), null);
        }

        return new self($spec, null, null);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkPort(?string $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $number = (int)$port;

        if ($number < 1 || $number > 65535) {
            throw new InvalidArgumentException(
                __u('Invalid database port'),
                SPException::ERROR,
                __u('The port number must be between 1 and 65535')
            );
        }

        return $number;
    }

    public function isLocal(): bool
    {
        return $this->socket !== null
               || in_array($this->host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
