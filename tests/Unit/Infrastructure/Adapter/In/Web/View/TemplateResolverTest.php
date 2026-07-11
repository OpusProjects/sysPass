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
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Exceptions\FileNotFoundException;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Infrastructure\Adapter\In\Web\View\TemplateResolver;

/**
 * Class TemplateResolverTest
 */
#[Group('unitary')]
class TemplateResolverTest extends TestCase
{
    private ThemeInterface|MockObject $theme;
    private TemplateResolver          $templateResolver;

    /**
     * @throws FileNotFoundException
     */
    public function testGetTemplateFor()
    {
        // Build under the bootstrap vfs root — vfsStream::setup() here would replace
        // the shared root and break every later test relying on TEST_ROOT/TMP_PATH.
        $viewsPath = TMP_PATH . '/template_dir';
        mkdir($viewsPath . '/base_dir', 0755, true);
        file_put_contents($viewsPath . '/base_dir/test_template.inc', 'a_content');

        $this->theme
            ->expects($this->once())
            ->method('getViewsPath')
            ->willReturn($viewsPath);

        $out = $this->templateResolver->getTemplateFor('base_dir', 'test_template');

        $this->assertEquals($viewsPath . '/base_dir/test_template.inc', $out);
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->theme = $this->createMock(ThemeInterface::class);
        $this->templateResolver = new TemplateResolver($this->theme);
    }

    /**
     * @throws FileNotFoundException
     */
    public function testGetTemplateForWithNoPermissions()
    {
        $viewsPath = TMP_PATH . '/root_dir';
        mkdir($viewsPath . '/base_dir', 0755, true);

        $this->theme
            ->expects($this->once())
            ->method('getViewsPath')
            ->willReturn($viewsPath);

        $this->expectException(FileNotFoundException::class);

        $this->templateResolver->getTemplateFor('base_dir', 'test_template');
    }
}
