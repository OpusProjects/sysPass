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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whatever a template renders, it escapes.
 *
 * The application currently also escapes on the way *in* — `Filter::getString()` runs
 * `htmlspecialchars()` over every web form field and every REST parameter — which means a template
 * that forgets to escape is usually saved by the value having been escaped already. That is why
 * this can be written now and why it must be: the input escaping is what is being removed, and the
 * moment it goes, every unescaped interpolation becomes an injection. This is the check that says
 * the view layer is ready to stand on its own.
 *
 * The rule is one helper, `$_e()`, bound into the template scope by
 * {@see \SP\Infrastructure\Adapter\In\Web\View\Template::includeTemplates()}. Anything a template
 * echoes that could carry text somebody typed goes through it. Two kinds of thing are exempt, and
 * both are recognised by shape rather than by a list of files:
 *
 *  - values that cannot carry text — an id, a count, a boolean, an icon's CSS class, a route, a
 *    string literal chosen by a ternary, a translated literal;
 *  - values that are deliberately markup, which are named here explicitly, because escaping one is
 *    just as broken as failing to escape a name — it renders the tags as visible text.
 */
#[Group('unitary')]
class ThemeEscapesWhatItRendersTest extends TestCase
{
    private const THEME = REAL_APP_ROOT . '/public/themes/material-blue/views';

    /**
     * Every `<?php echo …` / `<?= …` in a template.
     */
    private const ECHO = '/<\?(?:php\s+)?(?:echo|print)\s+(.*?)(?:;\s*)?\?>|<\?=\s*(.*?)(?:;\s*)?\?>/s';

    /**
     * Reads that return text a user or an administrator typed. Deliberately by name: these are the
     * getters whose value has been through the request, and they are what an injection arrives in.
     */
    private const TEXT_GETTER =
        '/->get\w*(Name|Description|Notes|Login|Url|Email|Title|Text|Value|Component|Hash|Pass'
        . '|Address|Extension|Attribute|Server)\(/i';

    /**
     * The methods that compose markup themselves, escaping the parts they interpolate. Escaping
     * their result would show the tags to the user instead of rendering them.
     *
     * @see \SP\Domain\Account\Adapters\AccountSearchItem::getAccesses()
     * @see \SP\Domain\Account\Adapters\AccountSearchItem::getShortNotes()
     */
    private const RETURNS_MARKUP = [
        'getAccesses(',
        'getShortNotes(',
    ];

    /**
     * Everything a template renders that could carry typed text goes through `$_e()`.
     */
    #[Test]
    #[DataProvider('templates')]
    public function aTemplateEscapesTheTextItRenders(string $template): void
    {
        $unescaped = [];

        foreach (self::echoesIn($template) as $line => $expression) {
            $carriesText = preg_match(self::TEXT_GETTER, $expression)
                || str_contains($expression, '$_getvar(');

            if (self::isExempt($expression) || !$carriesText) {
                continue;
            }

            if (!str_contains($expression, '$_e(')) {
                $unescaped[] = sprintf('%s:%d  %s', basename($template), $line, $expression);
            }
        }

        self::assertSame([], $unescaped, "unescaped text in template:\n" . implode("\n", $unescaped));
    }

    /**
     * Inside a `<script>` element the rule is different, and `$_e()` is the wrong answer.
     *
     * A script element is raw text: the HTML parser does not decode character references in it, so
     * an escaped value arrives at the script as the literal characters `&quot;` — the value is
     * corrupted, and it only fails to break the string by accident. What ends the element is the
     * text `</script>` appearing anywhere inside it, which no amount of `htmlspecialchars` is
     * aimed at. `$_j()` writes a JSON literal, quotes included, with the tag sequence encoded.
     */
    #[Test]
    #[DataProvider('templates')]
    public function aTemplateWritesJavaScriptValuesAsJavaScript(string $template): void
    {
        $wrong = [];

        foreach (self::interpolationsInScripts($template) as $line => $expression) {
            if (str_contains($expression, '$_j(') || self::isArithmetic($expression)) {
                continue;
            }

            // A bare loop counter or a literal-picking ternary is a number in the output.
            if (preg_match('/^\$\w+$/', $expression) || self::picksBetweenLiterals($expression)) {
                continue;
            }

            $wrong[] = sprintf('%s:%d  %s', basename($template), $line, $expression);
        }

        self::assertSame([], $wrong, "not written for a script context:\n" . implode("\n", $wrong));
    }

    /**
     * And a template does not reach for `htmlspecialchars()` itself. Not style: the flags decide
     * whether a quote is escaped, so a call that leaves them out — as most of these did, and as
     * `Filter::getString()` still does — protects a text node and not an attribute.
     */
    #[Test]
    #[DataProvider('templates')]
    public function aTemplateDoesNotEscapeByHand(string $template): void
    {
        $source = (string)file_get_contents($template);

        self::assertStringNotContainsString('htmlspecialchars', $source, basename($template));
        self::assertStringNotContainsString('htmlentities', $source, basename($template));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        $templates = [];

        $directory = new \RecursiveDirectoryIterator(self::THEME, \FilesystemIterator::SKIP_DOTS);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'inc') {
                $templates[basename(dirname($file->getPathname())) . '/' . $file->getBasename()] =
                    [$file->getPathname()];
            }
        }

        return $templates;
    }

    /**
     * @return array<int, string> line number => the echoed expression, whitespace collapsed
     */
    private static function echoesIn(string $template): array
    {
        $source = (string)file_get_contents($template);

        preg_match_all(self::ECHO, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $echoes = [];

        foreach ($matches as $match) {
            $expression = trim($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? '');
            $expression = implode(' ', preg_split('/\s+/', trim($expression)) ?: []);

            if ($expression !== '') {
                $echoes[substr_count($source, "\n", 0, (int)$match[0][1]) + 1] = $expression;
            }
        }

        return $echoes;
    }

    /**
     * The interpolations that land inside a `<script>` element.
     *
     * @return array<int, string> line number => the echoed expression
     */
    private static function interpolationsInScripts(string $template): array
    {
        $source = (string)file_get_contents($template);
        $found = [];

        preg_match_all('#<script\b[^>]*>(.*?)</script>#si', $source, $scripts, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($scripts as $script) {
            preg_match_all(self::ECHO, $script[1][0], $echoes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($echoes as $echo) {
                $expression = trim($echo[1][0] ?? '') !== '' ? $echo[1][0] : ($echo[2][0] ?? '');
                $expression = implode(' ', preg_split('/\s+/', trim($expression)) ?: []);

                if ($expression !== '') {
                    $offset = (int)$script[1][1] + (int)$echo[0][1];
                    $found[substr_count($source, "\n", 0, $offset) + 1] = $expression;
                }
            }
        }

        return $found;
    }

    /**
     * Shapes that cannot carry typed text, plus the values that are markup on purpose.
     */
    private static function isExempt(string $expression): bool
    {
        foreach (self::RETURNS_MARKUP as $markup) {
            if (str_contains($expression, $markup)) {
                return true;
            }
        }

        // Already through a helper that renders the value safe for where it is going.
        foreach (['$_e(', 'Html::getSafeUrl(', 'urlencode(', 'json_encode(', 'base64_encode('] as $helper) {
            if (str_contains($expression, $helper)) {
                return true;
            }
        }

        return (bool)preg_match('/^__[u]?\(/', $expression)          // a translated literal
            || (bool)preg_match('/->getIcon\(\)|->getClass|\$icons->/', $expression)
            || (bool)preg_match('/^\((?:int|bool|float)\)/', $expression)  // cast to a number
            || self::isArithmetic($expression)
            || self::picksBetweenLiterals($expression);
    }

    /**
     * A value the page computes rather than repeats — a difference, a division, a comparison.
     * There is no text in the result whatever the operands hold.
     */
    private static function isArithmetic(string $expression): bool
    {
        return (bool)preg_match('/[-+\/*]\s*\$|===|!==|\s>\s|\s<\s/', $expression)
            && !(bool)preg_match('/\.\s*\$|\$\w+\s*\./', $expression); // …but not concatenation
    }

    /**
     * A ternary that chooses between things the template itself wrote: a quoted string, a number,
     * or a translated literal. The condition may read anything; the output cannot carry it.
     */
    private static function picksBetweenLiterals(string $expression): bool
    {
        return (bool)preg_match(
            "/\?\s*(?:'[^']*'|\d+|__[u]?\(.*?\))\s*:\s*(?:'[^']*'|\d+|__[u]?\(.*?\))\s*$/",
            $expression
        );
    }
}
