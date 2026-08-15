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

namespace SP\Domain\Core\Html;

/**
 * Class Html
 */
final class Html
{
    /**
     * Render a value as HTML text rather than as markup.
     *
     * This is the application's single answer to "somebody typed this, and it is about to become
     * part of a page". `ENT_QUOTES` because the result is as likely to land inside an attribute as
     * in a text node, and a value that escapes only `<` is still able to end an attribute;
     * `ENT_SUBSTITUTE` because a byte sequence that is not valid UTF-8 must come back as the
     * replacement character rather than as the empty string, which would silently drop the whole
     * value instead of showing something wrong.
     *
     * Null is accepted and rendered as nothing: most model getters are nullable, and requiring a
     * `?? ''` at every call site is how one gets forgotten.
     */
    public static function escape(?string $text): string
    {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a value as a JavaScript literal, for the handful of places a template writes one
     * inline.
     *
     * A `<script>` element is raw text: the HTML parser does not decode character references
     * inside it, so {@see self::escape()} is the wrong tool there twice over — its entities reach
     * the script as the literal characters `&quot;`, corrupting the value, and it is only by
     * accident that they also fail to close the string. What actually ends the element is the
     * text `</script>` appearing anywhere in it, which is why JSON_HEX_TAG is not optional.
     *
     * The result carries its own quotes, so it is written bare: `foo(<?php echo $_j($x); ?>)`.
     */
    public static function jsValue(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Truncate a text to a given length.
     *
     * @param string $text the string to truncate
     * @param int $limit the maximum length of the string
     * @param string $ellipsis
     *
     * @return string with the truncated text
     *
     * @link http://www.pjgalbraith.com/truncating-text-html-with-php/
     */
    public static function truncate(
        string $text,
        int    $limit,
        string $ellipsis = '...'
    ): string {
        if (mb_strlen($text) > $limit) {
            return sprintf('%s%s', trim(mb_substr($text, 0, $limit)), $ellipsis);
        }

        return $text;
    }

    /**
     * Return an HTML link.
     *
     * @param string $text with the text string
     * @param string|null $link with the link destination
     * @param string|null $title with the link title
     * @param string $attribs with the link attributes
     *
     * @return string
     */
    public static function anchorText(
        string  $text,
        ?string $link = null,
        ?string $title = null,
        string  $attribs = ''
    ): string {
        return sprintf(
            '<a href="%s" title="%s" %s>%s</a>',
            $link ?? $text,
            $title ?? $text,
            $attribs,
            $text
        );
    }

    /**
     * Strips out HTML tags preserving some spaces
     */
    public static function stripTags(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // Replace tags, then new lines, tabs and return chars, and then 2 or more spaces
        return trim(
            preg_replace(
                ['/<[^>]*>/', '/[\n\t\r]+/', '/\s{2,}/'],
                ' ',
                $text
            )
        );
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public static function getSafeUrl(string $url): string
    {
        $urlParts = parse_url($url);

        if ($urlParts === false) {
            return 'malformed_url';
        }

        return preg_replace_callback(
            '/["<>\']+/u',
            static fn($matches) => urlencode($matches[0]),
            strip_tags($url)
        );
    }
}
