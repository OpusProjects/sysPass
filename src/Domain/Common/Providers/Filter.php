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

namespace SP\Domain\Common\Providers;

/**
 * Class Filter
 */
final class Filter
{
    private const UNSAFE_CHARS = ['/', '[', '\\', ']', '%', '{', '}', '*', '$'];

    /**
     * Strip out characters used in regular expressions from a search string
     */
    public static function safeSearchString(string $string): string
    {
        return str_replace(self::UNSAFE_CHARS, '', $string);
    }

    public static function getEmail(string $value): string
    {
        return filter_var(trim($value), FILTER_SANITIZE_EMAIL);
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<int|string, int|float|string|null>
     */
    public static function getArray(array $array): array
    {
        return array_map(
            static function ($value) {
                // Decided by type rather than by `is_numeric()` alone. `getInt()` takes
                // `int|string` and `getString()` takes `?string`, under strict types, so anything
                // else — a bool, a float, a nested array, an object — used to reach one of them and
                // throw a `TypeError`. That is not a hypothetical shape: a JSON body can carry
                // `[true]` or `[1.5]`, and a form field named `x[a][]` makes one element an array.
                // Nothing in between caught it, so it surfaced as a 500 whose body named the class,
                // the method and the server's absolute path. An element this cannot represent is
                // answered as null, the same as a missing one.
                if (is_int($value) || (is_string($value) && is_numeric($value))) {
                    return Filter::getInt($value);
                }

                if (is_string($value)) {
                    return Filter::getString($value);
                }

                return null;
            },
            $array
        );
    }

    /**
     * @param  int|string  $value
     *
     * @return int|null
     */
    public static function getInt(int|string $value): ?int
    {
        $filterVar = filter_var($value, FILTER_SANITIZE_NUMBER_INT);

        return is_numeric($filterVar) ? (int)$filterVar : null;
    }

    /**
     * A submitted string, normalised — and nothing more.
     *
     * This used to also run `htmlspecialchars()`, which meant every value the application stored
     * was stored HTML-escaped: a category named `Q&A <b>notes</b>` became, in the database and in
     * the REST answer, `Q&amp;A &lt;b&gt;notes&lt;/b&gt;`. Escaping is about where a value is
     * *rendered*, and a request is not a page — the same value goes to a JSON client, into an
     * export, into a mail, into a filename, and into a `LIKE` comparison, and it was wrong in all
     * of them. The view escapes what it renders now, which is where the decision belongs.
     *
     * It was never much of a guard either: `ENT_NOQUOTES` left both quote characters alone, so it
     * did nothing for a value interpolated into an attribute.
     *
     * What remains is the part that is genuinely about accepting input: the surrounding whitespace
     * goes, and a byte sequence that is not valid UTF-8 is scrubbed rather than passed to a UTF-8
     * column — `ENT_SUBSTITUTE` used to do that as a side effect.
     */
    public static function getString(?string $value): string
    {
        return mb_scrub(trim($value ?? ''), 'UTF-8');
    }

    public static function getRaw(string $value): string
    {
        return filter_var(trim($value), FILTER_UNSAFE_RAW);
    }
}
