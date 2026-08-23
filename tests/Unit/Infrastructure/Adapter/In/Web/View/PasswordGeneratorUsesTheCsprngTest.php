<?php

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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "Generate" button behind every password field in the application — an account's password, a
 * user's, the master password, the administrator account the installer creates — is
 * `sysPass.Util.password.random()`, in `public/js/app-util.min.js`. It used to draw each character
 * with `Math.random()`, which is not a cryptographic generator: V8 seeds one xorshift128+ stream
 * per context and its state is recoverable from a modest run of outputs, so passwords generated in
 * a session were predictable from each other.
 *
 * These files are authored directly — there is no unminified source and no build step — so this
 * asserts against what actually ships. It is a source check rather than a behavioural one because
 * the alternative is asserting that output looks random, which is exactly the assertion that
 * passes for a broken generator.
 */
#[Group('unitary')]
class PasswordGeneratorUsesTheCsprngTest extends TestCase
{
    private const UTIL = REAL_APP_ROOT . '/public/js/app-util.min.js';

    /**
     * The generator draws from the platform CSPRNG.
     */
    #[Test]
    public function theGeneratorDrawsFromTheCryptoApi(): void
    {
        self::assertStringContainsString(
            'window.crypto.getRandomValues',
            self::generator(),
            'the password generator must draw from crypto.getRandomValues'
        );
    }

    /**
     * And from nothing else. Math.random() is still used elsewhere in this file to mint DOM element
     * ids, which is a fine use for it, so this is scoped to the generator rather than the file.
     */
    #[Test]
    public function theGeneratorDrawsFromNothingElse(): void
    {
        self::assertStringNotContainsString(
            'Math.random',
            self::generator(),
            'the password generator must not fall back to Math.random'
        );
    }

    /**
     * Every character of the alphabet can be produced.
     *
     * The index was `Math.floor(Math.random() * (c.length - 1))`, which is exclusive at both ends
     * of that multiplication — so the last character of the assembled charset could never appear.
     * Which character that was depended on which classes were enabled, so it silently shrank the
     * alphabet by one wherever the button was used.
     */
    #[Test]
    public function theWholeAlphabetCanBeDrawn(): void
    {
        $generator = self::generator();

        self::assertStringContainsString('d.randomIndex(c.length)', $generator);
        self::assertStringNotContainsString('c.length-1', $generator);
    }

    /**
     * The rejection loop cannot run forever.
     *
     * It regenerated the whole candidate until it satisfied every enabled character class. The
     * length is set in the UI from an input whose `min` is 1, and the default complexity requires
     * four classes, so any length below four made the condition unsatisfiable and the loop froze
     * the tab.
     */
    #[Test]
    public function theRejectionLoopIsBounded(): void
    {
        self::assertStringNotContainsString(
            'do e=f();while(!m(e));',
            self::generator(),
            'the rejection loop must not be unbounded'
        );
    }

    /**
     * `sysPass.Util.password`'s generator, from `randomIndex` through to the end of `random()`.
     */
    private static function generator(): string
    {
        $source = (string)file_get_contents(self::UTIL);

        $start = strpos($source, 'randomIndex:function');
        self::assertNotFalse($start, 'the generator must define randomIndex');

        $end = strpos($source, 'output:function', $start);
        self::assertNotFalse($end, 'the generator must be followed by output()');

        return substr($source, $start, $end - $start);
    }
}
