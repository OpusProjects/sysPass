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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ManagerViews;

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
 * Covers the management landing pages — items, security and user settings — plus the user
 * preferences save. None of them had tests.
 *
 * These views are assembled from the tabs the current profile is allowed to see, so what they
 * render is a function of the permissions, not a fixed page.
 */
#[Group('integration')]
class ManagerViewsTest extends IntegrationTestCase
{
    /** Tabs the page under test is expected to render for the profile below. */
    private int $expectedTabs = 0;

    /**
     * A profile allowed to manage every item type, so each tab is expected to appear.
     */
    protected function getUserProfile(): ProfileData
    {
        return new ProfileData(
            [
                'mgmCategories' => true,
                'mgmCustomers' => true,
                'mgmTags' => true,
                'mgmCustomFields' => true,
                'mgmAccounts' => true,
                'mgmItemsPresets' => true,
                'evl' => true,
            ]
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerTabs')]
    public function itemManagerIndex()
    {
        $this->expectedTabs = 7;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemManager/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerTabs')]
    public function securityManagerIndex()
    {
        $this->expectedTabs = 1;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'securityManager/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerTabs')]
    public function userSettingsManagerIndex()
    {
        $this->expectedTabs = 1;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userSettingsManager/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Saving one's own preferences is not a privileged operation, so it goes through for the
     * ordinary profile above.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveUserSettings()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userSettingsGeneral/save'],
                [
                    'userlang' => 'en_US',
                    'accountlink' => 'on',
                    'resultsperpage' => 20,
                ]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Preferences updated","data":null}');
    }

    /**
     * The landing pages are tab shells assembled from what the profile may reach, so the count
     * is asserted exactly: a page that rendered nothing, or that ignored permissions and
     * rendered everything, both fail.
     */
    private function outputCheckerTabs(string $output): void
    {
        $crawler = new Crawler($output);

        self::assertCount($this->expectedTabs, $crawler->filterXPath('//div[contains(@id, "tabs-")]'));
    }
}
