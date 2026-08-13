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
use SP\Application\Install\Services\InstallThrottle;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Database\DatabaseConnectionData;
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
     * The throttle refuses before a host, valid or not, is ever dialed — so a caller that has
     * exhausted its attempts gets the same fixed message regardless of what it sends.
     *
     * InstallThrottle is `final readonly`, so it cannot be doubled: a real instance is built
     * against an isolated cache directory (never the app's own var/cache, which is a real,
     * persistent directory shared by every test and by the app itself) and pre-filled directly,
     * rather than by issuing ten throwaway HTTP requests that would work just as well but would
     * leave real attempts recorded against a shared file this test does not own.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function attemptsExceededRefusesBeforeConnecting()
    {
        $installThrottle = $this->freshInstallThrottle();

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue(
                $installThrottle->isAllowed('198.51.100.1'),
                'attempt ' . $i . ' should still be allowed'
            );
        }

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'install/checkConnection'],
                ['dbhost' => 'localhost', 'dbuser' => 'root', 'dbpass' => 'secret']
            ),
            [InstallThrottle::class => $installThrottle]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Attempts exceeded","data":null}'
        );
    }

    /**
     * DatabaseHost::parse() throws for a port outside 1-65535. That is not a PDOException, so
     * it is the one case reaching the generic catch instead of the DB-specific one — and it is
     * refused before any connection is attempted.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anOutOfRangePortIsRejectedWithoutAttemptingAConnection()
    {
        $this->whenChecking(['dbhost' => 'localhost:99999', 'dbuser' => 'root', 'dbpass' => 'secret']);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Invalid database port","data":null}'
        );
    }

    /**
     * The "unix:socket" host form takes a different branch to build the DSN than "host:port"
     * does; a socket path that does not exist fails the same way a bad TCP host does.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerConnectionFailed')]
    public function aUnixSocketPathThatDoesNotExistIsAlsoReported()
    {
        $this->whenChecking(
            ['dbhost' => 'unix:/tmp/no-such-syspass-test.sock', 'dbuser' => 'root', 'dbpass' => 'secret']
        );
    }

    /**
     * The happy path: valid credentials against a host that is actually reachable (the compose
     * stack's own DB service) report success rather than an error.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function validCredentialsAgainstAReachableHostSucceed()
    {
        $connection = DatabaseConnectionData::getFromEnvironment();

        $this->whenChecking(
            [
                // The suite's own database, wherever it is: 'db' in the compose network, an
                // address on CI. Hardcoding either makes this pass in one place and fail in the
                // other.
                'dbhost' => $connection->getDbHost(),
                'dbuser' => $connection->getDbUser(),
                'dbpass' => $connection->getDbPass(),
            ]
        );

        $this->expectOutputString(
            '{"status":"OK","description":"Connection successful","data":null}'
        );
    }

    /**
     * The driver message is passed straight through to the caller. Reaching a real server with
     * the wrong credentials answers with an "Access denied" message, quite unlike the
     * getaddrinfo failure an unreachable host produces (see anUnreachableHostIsReported above) —
     * this pre-auth, unauthenticated endpoint tells a caller not just "it failed" but whether a
     * MySQL server is actually listening at the address it tried.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerAccessDenied')]
    public function wrongCredentialsAgainstAReachableHostRevealMoreThanAnUnreachableHost()
    {
        $connection = DatabaseConnectionData::getFromEnvironment();

        $this->whenChecking(
            [
                'dbhost' => $connection->getDbHost(),
                'dbuser' => $connection->getDbUser(),
                'dbpass' => 'definitely-wrong-password',
            ]
        );
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $definitionsOverride
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenChecking(array $fields, array $definitionsOverride = []): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'install/checkConnection'],
                $fields
            ),
            array_merge([InstallThrottle::class => $this->freshInstallThrottle()], $definitionsOverride)
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A real InstallThrottle instance, but pointed at its own isolated, throwaway cache
     * directory and its own fake address rather than the app's real var/cache — which is a
     * real, persistent directory on disk, not per-test vfs. Every test in this file goes
     * through this rather than the real throttle (InstallThrottle::class is bound to a fresh
     * one of these by default in whenChecking(), and attemptsExceededRefusesBeforeConnecting()
     * pre-fills its own before handing it to the same request-building path) so that repeated
     * runs stay independent of each other and never leave real rate-limit attempts recorded
     * against the app's own state.
     */
    private function freshInstallThrottle(): InstallThrottle
    {
        // Not faker: the harness seeds it, so two tests in the same class draw the same value and
        // the second mkdir warns that the directory is already there.
        $cacheDir = sys_get_temp_dir() . '/syspass-check-connection-throttle-' . bin2hex(random_bytes(8));
        mkdir($cacheDir, 0777, true);

        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::CACHE, $cacheDir);

        $request = $this->createStub(RequestService::class);
        $request->method('getServer')->willReturn('198.51.100.1');

        return new InstallThrottle($pathsContext, $request);
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

    /**
     * Pins the specific driver detail that reaches the caller for a reachable host with bad
     * credentials, as opposed to the generic failure asserted for an unreachable one.
     */
    private function outputCheckerAccessDenied(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertStringContainsString('Access denied', $json->description);
    }
}
