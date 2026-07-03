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

namespace SP\Application\Install\Services;

use SP\Core\Bootstrap\Path;
use SP\Core\Bootstrap\PathsContext;
use SP\Infrastructure\File\FileSystem;
use Throwable;

use function SP\processException;

/**
 * Per-address rate limit for the pre-auth install endpoints.
 *
 * While sysPass is not installed, the install and connection-check endpoints
 * accept unauthenticated requests that open outbound DB connections; this
 * throttle keeps them from being used to scan hosts from the server. No
 * database exists yet, so the attempts are tracked in a cache file.
 */
final readonly class InstallThrottle
{
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 60;
    private const STORE_FILE = 'install_throttle.json';

    public function __construct(private PathsContext $pathsContext)
    {
    }

    /**
     * Record an attempt for the address and tell whether it is still allowed.
     *
     * Fails open: a storage error must not block a legitimate installation.
     */
    public function isAllowed(string $address): bool
    {
        if ($address === '') {
            return true;
        }

        try {
            $file = FileSystem::buildPath($this->pathsContext[Path::CACHE], self::STORE_FILE);
            $now = time();

            $attempts = [];

            if (is_readable($file)) {
                $attempts = json_decode((string)file_get_contents($file), true) ?: [];
            }

            // Keep only the attempts within the window
            $attempts = array_filter(
                array_map(
                    static fn(array $times) => array_values(
                        array_filter($times, static fn($time) => $time > $now - self::WINDOW_SECONDS)
                    ),
                    $attempts
                ),
                static fn(array $times) => count($times) > 0
            );

            if (count($attempts[$address] ?? []) >= self::MAX_ATTEMPTS) {
                return false;
            }

            $attempts[$address][] = $now;

            file_put_contents($file, json_encode($attempts), LOCK_EX);

            return true;
        } catch (Throwable $e) {
            processException($e);

            return true;
        }
    }
}
