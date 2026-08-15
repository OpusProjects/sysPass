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
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Infrastructure\Adapter\In\Web\View\OutputHandler;
use SP\Infrastructure\Adapter\In\Web\View\Template;
use SP\Infrastructure\Adapter\In\Web\View\TemplateResolverInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The one page where escaping is not a precaution.
 *
 * An account's password is arbitrary bytes that a user chose, it never passes through
 * `Filter::getString()` — a password has to keep exactly what was typed — and it is shown on a page
 * assembled by hand. So it is the field that proves whether escaping-at-output actually works,
 * rather than whether the input filter happened to have removed the dangerous characters first.
 *
 * The other tests in this area check the parts: that the helper hands the plaintext over untouched,
 * and that the template calls `$_e()`. This one renders the real template through a real Template,
 * which is the only place those parts are shown to add up.
 */
#[Group('unitary')]
class ViewpassEscapesTest extends UnitaryTestCase
{
    private const HOSTILE = '</td><script>alert(1)</script>';

    /**
     * @throws \SP\Domain\Core\Exceptions\FileNotFoundException
     */
    #[Test]
    public function aPasswordCannotPutMarkupOnThePage(): void
    {
        $html = $this->render(['pass' => self::HOSTILE, 'login' => 'someone', 'isImage' => 0]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * And neither can the login beside it, which is ordinary stored text and reaches the same page.
     *
     * @throws \SP\Domain\Core\Exceptions\FileNotFoundException
     */
    #[Test]
    public function aLoginCannotEither(): void
    {
        $html = $this->render(['pass' => 'a-password', 'login' => self::HOSTILE, 'isImage' => 0]);

        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * A password of ordinary text still shows as that text — the guard above would also be
     * satisfied by a page that rendered nothing at all.
     *
     * @throws \SP\Domain\Core\Exceptions\FileNotFoundException
     */
    #[Test]
    public function anOrdinaryPasswordIsStillShown(): void
    {
        $html = $this->render(['pass' => 'correct horse battery', 'login' => 'someone', 'isImage' => 0]);

        self::assertStringContainsString('correct horse battery', $html);
        self::assertStringContainsString('someone', $html);
    }

    /**
     * Characters an entity would swallow survive as themselves once the page is read back: the
     * escaping has to be reversible, not lossy. A password of `a&b<c>"d"` is a password somebody
     * may well have.
     *
     * @throws \SP\Domain\Core\Exceptions\FileNotFoundException
     */
    #[Test]
    public function aPasswordOfMetacharactersSurvivesIntact(): void
    {
        $password = 'a&b<c>"d"\'e';

        $html = $this->render(['pass' => $password, 'login' => 'someone', 'isImage' => 0]);

        self::assertStringContainsString($password, html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param array<string, mixed> $vars
     * @throws \SP\Domain\Core\Exceptions\FileNotFoundException
     */
    private function render(array $vars): string
    {
        $resolver = self::createStub(TemplateResolverInterface::class);
        $resolver
            ->method('getTemplateFor')
            ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue/views/account/viewpass.inc');

        $template = new Template(
            new OutputHandler(),
            $resolver,
            self::createStub(ThemeIconsInterface::class),
            self::createStub(UriContextInterface::class),
            $this->config->getConfigData(),
            'test'
        );

        $template->addTemplate('viewpass');

        foreach ($vars + ['header' => 'Account Password'] as $name => $value) {
            $template->assign($name, $value);
        }

        return $template->render();
    }
}
