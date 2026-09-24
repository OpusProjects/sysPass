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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file the user chose reaches a toast as text, not markup.
 *
 * `toasts.min.js` renders every message with `innerHTML`, deliberately, because messages carry
 * `<br>` separators. So whatever is concatenated into one has to be escaped first, and the file
 * upload's two rejection messages — "too large" and "type not allowed" — concatenated the dropped
 * `File`'s `name` and `type` raw. A file named `<img src=x onerror=…>` ran its handler.
 *
 * Worth being exact about the grade: it is the user's own local file, so on its own this is
 * self-XSS, reachable only by persuading somebody to upload a crafted name. It is pinned anyway
 * because the sink is real, the fix is one expression, and the next message to interpolate a
 * value into a toast would inherit the same mistake.
 *
 * `$("<div/>").text(value).html()` sets the value as text and reads it back escaped.
 */
#[Group('unitary')]
class FileNamesReachToastsAsTextTest extends TestCase
{
    private const FILE = REAL_APP_ROOT . '/public/js/app-util.min.js';

    #[Test]
    public function aRejectedFilesNameAndTypeAreEscapedBeforeTheToastRendersThem(): void
    {
        $source = (string)file_get_contents(self::FILE);

        // Every place the upload code puts the chosen file's name or type into a toast message.
        // Checked by what surrounds each occurrence rather than by matching the whole call: the
        // escape itself contains parentheses, so a pattern that ends at the first `)` stops inside
        // it and never sees the `.html()`.
        preg_match_all(
            '/sysPassApp\.msg\.\w+\([^;]*?(?<value>\bc\.(?:name|type)\b)/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        self::assertNotEmpty(
            $matches['value'],
            'the upload rejection messages were not found; this test is looking at the wrong thing'
        );

        foreach ($matches['value'] as [$value, $offset]) {
            $before = substr($source, $offset - strlen('.text('), strlen('.text('));
            $after = substr($source, $offset + strlen($value), strlen(').html()'));

            self::assertSame(
                ['.text(', ').html()'],
                [$before, $after],
                sprintf('%s reaches a toast unescaped, at byte %d', $value, $offset)
            );
        }
    }
}
