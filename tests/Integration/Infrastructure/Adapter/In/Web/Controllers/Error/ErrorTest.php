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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Error;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the error pages. None of them had tests.
 *
 * These are the pages shown when the application cannot serve a normal request — no database, a
 * broken database, or maintenance — so they run with the install and session checks skipped and
 * must render without depending on any of it.
 */
#[Group('integration')]
class ErrorTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerMaintenance')]
    public function maintenance()
    {
        $this->whenRequesting('error/maintenanceError');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDatabaseConnection')]
    public function databaseConnection()
    {
        $this->whenRequesting('error/databaseConnection');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotEmptyPage')]
    public function databaseError()
    {
        $this->whenRequesting('error/databaseError');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotEmptyPage')]
    public function index()
    {
        $this->whenRequesting('error/index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The maintenance page has to say what is happening, otherwise it is an empty shell during
     * the one window where a user most needs telling.
     */
    private function outputCheckerMaintenance(string $output): void
    {
        self::assertStringContainsString('Application on maintenance', $output);
        self::assertStringContainsString('It will be running shortly', $output);
    }

    /**
     * The no-database page names the problem rather than rendering a bare error.
     */
    private function outputCheckerDatabaseConnection(string $output): void
    {
        self::assertNotEmpty($output);
        self::assertMatchesRegularExpression('/(?i)database|connection/', $output);
    }

    private function outputCheckerNotEmptyPage(string $output): void
    {
        self::assertNotEmpty($output);
        self::assertStringContainsString('<html', $output);
    }
}
