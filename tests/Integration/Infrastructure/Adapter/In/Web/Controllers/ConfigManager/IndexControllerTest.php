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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigManager;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class IndexControllerTest
 */
#[Group('integration')]
class IndexControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIndex')]
    public function index()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'configManager/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    protected function getUserProfile(): ProfileData
    {
        return new ProfileData(
            [
                'configGeneral' => true,
                'configEncryption' => true,
                'configBackup' => true,
                'configImport' => true,
                'mgmPublicLinks' => true
            ]
        );
    }

    /**
     * The information panel reports the server it is running on, and has to do so without
     * assuming SERVER_SOFTWARE is set — not every SAPI advertises one, and the panel is rendered
     * here from the console, where it is absent. Reading it unguarded warned three times in the
     * suite and would have written the warning into the page it was describing.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWebServer')]
    public function theWebServerRowSurvivesAnAbsentServerSoftware()
    {
        unset($_SERVER['SERVER_SOFTWARE']);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'configManager/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerIndex(string $output): void
    {
        $crawler = new Crawler($output);

        $tabs = $crawler->filterXPath('//div[contains(@id, \'tabs-\')]');

        self::assertCount(12, $tabs);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerWebServer(string $output): void
    {
        $crawler = new Crawler($output);

        // The cell itself, not the page: under the bug the row renders empty, and every other
        // assertion available here passes just as well on an empty one. Asserting against the
        // whole output would find 'cli' elsewhere in the markup and prove nothing.
        $value = $crawler->filterXPath('//td[normalize-space()=\'Web Server\']/following-sibling::td[1]');

        self::assertCount(1, $value);
        self::assertSame(php_sapi_name(), trim($value->text()));
    }
}
