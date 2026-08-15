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

namespace SP\Tests\Unit\Infrastructure\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Html\Html;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class HtmlTest
 *
 */
#[Group('unitary')]
class HtmlTest extends UnitaryTestCase
{

    public static function urlProvider(): array
    {
        return [
            ['https://foo.com/<script>alert("TEST");</script>'],
            ['https://foo.com/><script>alert("TEST");</script>'],
            ['https://foo.com/"><script>alert("TEST");</script>'],
            ['https://foo.com/"%20onClick="alert(\'TEST\'")'],
            ['https://foo.com/" onClick="alert(\'TEST\')"'],
            ['mongodb+srv://cluster.foo.mongodb.net/bar'],
        ];
    }

    public function testGetSafeUrlOk()
    {
        $url = self::$faker->url();

        $this->assertEquals($url, Html::getSafeUrl($url));
    }

    #[DataProvider('urlProvider')]
    public function testGetSafeUrlEncoded(string $url)
    {
        $this->assertEquals(0, preg_match('/["<>\']+/', Html::getSafeUrl($url)));
    }

    /**
     * Every character that can end a tag or an attribute. Both quote styles matter: the templates
     * put escaped values inside `"…"` and inside `'…'`, and a helper that only handled one would
     * be safe in half the theme.
     *
     * @return array<string, array{string, string}>
     */
    public static function escapeProvider(): array
    {
        return [
            'a tag' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'a double quote' => ['" onmouseover="x', '&quot; onmouseover=&quot;x'],
            'a single quote' => ["' onmouseover='x", '&#039; onmouseover=&#039;x'],
            'an ampersand' => ['Q&A', 'Q&amp;A'],
            'nothing to do' => ['an ordinary name', 'an ordinary name'],
        ];
    }

    #[DataProvider('escapeProvider')]
    public function testEscape(string $text, string $expected): void
    {
        $this->assertSame($expected, Html::escape($text));
    }

    /**
     * Most model getters are nullable, so null has to mean "nothing" rather than be a TypeError at
     * the one call site that forgot a `?? ''`.
     */
    public function testEscapeAcceptsNull(): void
    {
        $this->assertSame('', Html::escape(null));
    }

    /**
     * A byte sequence that is not valid UTF-8 comes back as the replacement character rather than
     * as the empty string. htmlspecialchars() drops the whole value without ENT_SUBSTITUTE, which
     * would silently blank a field instead of showing that something is wrong with it.
     */
    public function testEscapeKeepsInvalidUtf8Visible(): void
    {
        $escaped = Html::escape("before \xC3\x28 after");

        $this->assertNotSame('', $escaped);
        $this->assertStringContainsString('before', $escaped);
        $this->assertStringContainsString('after', $escaped);
    }

    /**
     * A value written into a `<script>` element carries its own quotes and cannot end the element.
     * The tag sequence is the one that matters: `</script>` inside a JavaScript string still closes
     * the element, because the HTML parser never looks at the JavaScript.
     */
    public function testJsValueCannotEndTheScriptElement(): void
    {
        $written = Html::jsValue('</script><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('</script>', $written);
        $this->assertStringStartsWith('"', $written);
        $this->assertStringEndsWith('"', $written);
    }

    /**
     * And it is still the same value once JavaScript has read it.
     */
    public function testJsValueRoundTrips(): void
    {
        $value = 'a "quoted" & <tagged> \'value\'';

        $this->assertSame($value, json_decode(Html::jsValue($value), false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Escaping is reversible: what the page shows is what was stored. This is what makes escaping
     * at output different from escaping at input, where the entities become the stored value.
     */
    public function testEscapeIsReversible(): void
    {
        $text = 'Q&A <b>notes</b> "quoted" \'and\' more';

        $this->assertSame($text, html_entity_decode(Html::escape($text), ENT_QUOTES, 'UTF-8'));
    }
}
