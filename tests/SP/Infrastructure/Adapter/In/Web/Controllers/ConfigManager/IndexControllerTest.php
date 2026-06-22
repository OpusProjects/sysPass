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

namespace Controllers\ConfigManager;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Tests\BodyChecker;
use SP\Tests\IntegrationTestCase;
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
     * @param string $output
     * @return void
     */
    private function outputCheckerIndex(string $output): void
    {
        $crawler = new Crawler($output);

        // One tab panel is rendered per config section the profile unlocks. Acl groups
        // CONFIG_ACCOUNT/WIKI/LDAP/MAIL under isConfigGeneral(), so this profile
        // (configGeneral + encryption + backup + import) renders 9 tabs:
        // general, accounts, wiki, ldap, mail, encryption, backup, import and info.
        $tabs = $crawler->filterXPath('//div[contains(@id, \'tabs-\')]');

        self::assertCount(9, $tabs);
    }
}
