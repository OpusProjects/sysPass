<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Core\Messages;

use SP\Domain\Core\Html\Html;

use function SP\__;

/**
 * Class HtmlFormatter
 */
final class HtmlFormatter implements FormatterInterface
{

    public function formatDetail(array $text, bool $translate = false): string
    {
        return implode(
            '',
            array_map(
                function ($value) use ($translate) {
                    $left = Html::escape($translate ? __($value[0]) : $value[0]);
                    $link = $this->buildLink($value[1]);

                    // A link is markup this class built and already escaped the parts of.
                    // Everything else is somebody's text — an account's name, a file's name, a
                    // login — and is rendered as text.
                    $right = $link ?? Html::escape($translate ? __($value[1]) : $value[1]);

                    return '<div class="detail">'
                           . '<span class="detail-left">' . $left . '</span>'
                           . '<span class="detail-right">' . $right . '</span>'
                           . '</div>';
                },
                $text
            )
        );
    }

    /**
     * An HTML link, when the value is an address; null when it is not.
     *
     * Answering null rather than the text back is what lets the caller tell the two apart. It used
     * to ask by looking for `<a` in the result, which is a question about the answer rather than
     * about the value, and a detail whose text merely contained `<a` was taken for a link and sent
     * out as markup.
     */
    private function buildLink(string $text): ?string
    {
        if (!preg_match('#^https?://.*$#', $text, $matches)) {
            return null;
        }

        $url = $matches[0];

        return sprintf(
            '<a href="%s">%s</a>',
            Html::escape($url),
            Html::escape(mb_strlen($url) > 30 ? trim(mb_substr($url, 0, 30)) . '...' : $url)
        );
    }

    public function formatDescription(
        array $text,
        bool  $translate = false
    ): string {
        return implode(
            '',
            array_map(
                static function ($value) use ($translate) {
                    return '<div class="description-line">'
                           . Html::escape($translate ? __($value) : $value)
                           . '</div>';
                },
                $text
            )
        );
    }
}
