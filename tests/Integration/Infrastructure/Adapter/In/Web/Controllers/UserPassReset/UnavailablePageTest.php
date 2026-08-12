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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Password recovery is only offered when mail is configured, since the whole flow is a link sent by
 * mail. With mail off, both of its pages have to say so rather than showing a form that could not
 * work.
 *
 * These also pin that the refusal page is sent once. It used to be sent twice — the error was
 * echoed and then returned as the response body — which is only visible on a real request, and
 * neither page had a test.
 */
#[Group('integration')]
class UnavailablePageTest extends IntegrationTestCase
{
    private bool $mailEnabled = false;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isMailEnabled' => $this->mailEnabled]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUnavailable')]
    public function theRequestPageSaysSoWhenMailIsOff()
    {
        $this->whenAsking(['r' => 'userPassReset/index']);
    }

    /**
     * The reset page is reached from the mailed link, so it is unavailable for the same reason.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUnavailable')]
    public function theResetPageSaysSoWhenMailIsOff()
    {
        $this->whenAsking(['r' => 'userPassReset/reset/' . self::$faker->sha1()]);
    }

    /**
     * A reset link with no hash on it is unavailable too, whatever the mail setting — there is
     * nothing to reset.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUnavailable')]
    public function aResetLinkWithoutAHashIsUnavailable()
    {
        $this->mailEnabled = true;

        $this->whenAsking(['r' => 'userPassReset/reset']);
    }

    /**
     * With mail configured the request page is the form. Without this the refusals above would be
     * satisfied by a page that was always unavailable.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerAvailable')]
    public function theRequestPageIsTheFormWhenMailIsOn()
    {
        $this->mailEnabled = true;

        $this->whenAsking(['r' => 'userPassReset/index']);
    }

    /**
     * @param array<string, string> $params
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenAsking(array $params): void
    {
        // The page has to come back as the response body and nothing else. It used to be echoed as
        // well, which is what sent it twice, and a checker only ever sees the body — so the extra
        // copy is caught here, as output the action should not have produced.
        $this->expectOutputString('');

        $container = $this->buildContainer(IntegrationTestCase::buildRequest('get', 'index.php', $params));

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerUnavailable(string $output): void
    {
        self::assertStringContainsString('Option unavailable', $output);
        self::assertSame(1, substr_count(strtolower($output), '<html'));
    }

    private function outputCheckerAvailable(string $output): void
    {
        self::assertStringNotContainsString('Option unavailable', $output);
        self::assertStringContainsString('userPassReset/saveRequest', $output);
        self::assertSame(1, substr_count(strtolower($output), '<html'));
    }
}
