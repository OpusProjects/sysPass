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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the wizard's connection check. It is unauthenticated by necessity and it opens an
 * outbound connection to a host the caller supplies, so its refusals are the interesting part.
 */
#[Group('integration')]
class CheckConnectionControllerTest extends IntegrationTestCase
{
    private bool $installed = false;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isInstalled' => $this->installed]);
    }

    /**
     * Without a host PDO would connect to localhost and report a success that means nothing, so
     * an empty host is refused before any connection is attempted.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anEmptyHostIsRefusedBeforeConnecting()
    {
        $this->whenChecking(['dbhost' => '  ', 'dbuser' => 'root', 'dbpass' => 'secret']);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Please, enter the database server","data":null}'
        );
    }

    /**
     * A host that cannot be reached is reported rather than raising, so the wizard can show it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerConnectionFailed')]
    public function anUnreachableHostIsReported()
    {
        $this->whenChecking(
            ['dbhost' => 'no-such-host.invalid:3306', 'dbuser' => 'root', 'dbpass' => 'secret']
        );
    }

    /**
     * Once installed the endpoint is closed, like the install submit itself — it takes no
     * credentials, so nothing else would stop a caller using it to probe hosts.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anInstalledInstanceRefusesTheCheck()
    {
        $this->installed = true;

        $this->whenChecking(['dbhost' => 'localhost', 'dbuser' => 'root', 'dbpass' => 'secret']);

        $this->expectOutputString(
            '{"status":"ERROR","description":"sysPass is already installed","data":null}'
        );
    }

    /**
     * @param array<string, string> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenChecking(array $fields): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'install/checkConnection'],
                $fields
            )
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The failure is reported as an error the wizard can display; the message comes from the
     * driver, so only the status is fixed here.
     */
    private function outputCheckerConnectionFailed(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertNotSame('Connection successful', $json->description);
    }
}
