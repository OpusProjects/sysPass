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

namespace SP\Domain\File;

/**
 * Decides which MIME type an upload is stored under.
 *
 * The type the caller declares is only ever consulted when the server could not identify the
 * content itself. Accepting it whenever server detection merely failed the allow-list let a
 * caller store anything — a script, a document — by declaring a permitted type alongside it,
 * which made the allow-list advisory rather than enforced.
 */
final class AllowedMimeType
{
    /**
     * What libmagic reports when it cannot positively identify the content.
     *
     * These are the cases where the caller genuinely knows better: a password manager's
     * attachments are often keystores, certificates and other opaque blobs that have no
     * signature to match, and they all come back as a generic type.
     */
    private const INCONCLUSIVE = [
        'application/octet-stream',
        'text/plain',
    ];

    /**
     * The type to store the upload under, or null when it is not allowed.
     *
     * @param string $detected The type the server determined from the content
     * @param string $declared The type the caller claims
     * @param string[] $allowed The configured allow-list
     */
    public static function resolve(string $detected, string $declared, array $allowed): ?string
    {
        if (in_array($detected, $allowed, true)) {
            return $detected;
        }

        if (in_array($detected, self::INCONCLUSIVE, true) && in_array($declared, $allowed, true)) {
            return $declared;
        }

        return null;
    }
}
