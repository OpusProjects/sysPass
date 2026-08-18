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

namespace SP\Tests\Integration\Application\Config;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Config\Ports\ConfigService;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * The counter behind a limit has to be kept by the server.
 *
 * `TemporaryMasterPass::checkKey()` counted a failed guess by reading `tempmaster_attempts`,
 * comparing it in PHP, and writing back `$attempts + 1`:
 *
 * ```php
 * $attempts = (int)$this->configService->getByParam(self::PARAM_ATTEMPTS);
 * ...
 * $this->configService->save(self::PARAM_ATTEMPTS, (string)($attempts + 1));
 * ```
 *
 * Guesses arriving together all read the same number and all write the same number back, so fifty
 * of them move the counter by one. The temporary master password is what an administrator issues
 * to let somebody re-key their vault, and its fifty-attempt cap is the limit that is supposed to
 * hold when the guessing comes from many places at once — which is exactly the case the
 * per-address tracker does not cover.
 *
 * These run against a real database, because the property being asserted is that the *server*
 * does the arithmetic: a mocked repository would count however the test told it to.
 */
#[Group('integration')]
final class AttemptCountingTest extends TestCase
{
    use DatabaseTrait;

    private const PARAM = 'tempmaster_attempts';
    private const LIMIT = 3;

    private string $root;
    private string $configPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-attempt-counting-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->backupPath()] as $dir) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail(sprintf('Directory "%s" was not created', $dir));
            }
        }

        file_put_contents(
            FileSystem::buildPath($this->configPath, 'config.xml'),
            getResource('config', 'config.xml')
        );

        $this->pdo = getDbHandler()->getConnection();
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * Every attempt counts, and the count is exactly how many were made.
     *
     * Nothing here tells the server what the new number should be — that is the point. The caller
     * that worked it out from a number it had read was the caller that could be raced.
     */
    #[Test]
    public function eachAttemptAdvancesTheCounterByOne(): void
    {
        $configService = $this->configService();
        $this->givenTheCounterIs(0);

        for ($attempt = 1; $attempt <= self::LIMIT; $attempt++) {
            self::assertTrue(
                $configService->incrementIfBelow(self::PARAM, self::LIMIT),
                sprintf('attempt %d is within the limit and must be counted', $attempt)
            );

            self::assertSame($attempt, $this->counter(), 'the counter must record every attempt');
        }
    }

    /**
     * Once the limit is reached nothing further is counted, and the counter does not run past it.
     */
    #[Test]
    public function anAttemptPastTheLimitIsRefusedAndChangesNothing(): void
    {
        $configService = $this->configService();
        $this->givenTheCounterIs(self::LIMIT);

        self::assertFalse($configService->incrementIfBelow(self::PARAM, self::LIMIT));
        self::assertSame(self::LIMIT, $this->counter(), 'a refused attempt must not move the counter');
    }

    /**
     * The limit is a number, not a piece of text.
     *
     * `Config.value` is a varchar, so if both sides arrive as strings the server compares them as
     * text and `'10' < '3'` is true — a counter would sail past its limit the moment it reached
     * double figures, which is where a limit of fifty starts to matter. Ten against three is the
     * smallest case that tells the two comparisons apart.
     *
     * Two things keep it numeric — the limit binds as `PDO::PARAM_INT`, and the column side is
     * forced with `+ 0` — so removing either alone leaves this passing. It fails when both go,
     * which is the state the behaviour actually depends on.
     */
    #[Test]
    public function aCounterInDoubleFiguresIsPastASingleFigureLimit(): void
    {
        $this->givenTheCounterIs(10);

        self::assertFalse(
            $this->configService()->incrementIfBelow(self::PARAM, 3),
            '10 is not below 3, however the two are spelled'
        );

        self::assertSame(10, $this->counter());
    }

    /**
     * A counter that has never been written starts from nothing rather than stopping.
     *
     * `Config.value` is nullable, and a NULL on either side of the comparison would answer NULL —
     * which reads as "at the limit", so the parameter would quietly stop counting.
     */
    #[Test]
    public function aCounterWithNoValueYetStillCounts(): void
    {
        $this->givenTheCounterIsNull();

        self::assertTrue($this->configService()->incrementIfBelow(self::PARAM, self::LIMIT));
        self::assertSame(1, $this->counter());
    }

    /**
     * A parameter that is not there counts nothing and reports as much, rather than creating one.
     *
     * The counter is written when the temporary password is issued, so its absence means there is
     * nothing to guess at.
     */
    #[Test]
    public function aCounterThatDoesNotExistIsNotCreated(): void
    {
        $this->pdo->prepare('DELETE FROM `Config` WHERE `parameter` = :parameter')
                  ->execute(['parameter' => self::PARAM]);

        self::assertFalse($this->configService()->incrementIfBelow(self::PARAM, self::LIMIT));

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `Config` WHERE `parameter` = :parameter');
        $statement->execute(['parameter' => self::PARAM]);

        self::assertSame(0, (int)$statement->fetchColumn(), 'counting must not conjure the parameter');
    }

    private function givenTheCounterIs(int $value): void
    {
        $this->writeCounter((string)$value);
    }

    private function givenTheCounterIsNull(): void
    {
        $this->writeCounter(null);
    }

    private function writeCounter(?string $value): void
    {
        $this->pdo->prepare('DELETE FROM `Config` WHERE `parameter` = :parameter')
                  ->execute(['parameter' => self::PARAM]);

        $this->pdo->prepare('INSERT INTO `Config` (`parameter`, `value`) VALUES (:parameter, :value)')
                  ->execute(['parameter' => self::PARAM, 'value' => $value]);
    }

    private function counter(): int
    {
        $statement = $this->pdo->prepare('SELECT `value` FROM `Config` WHERE `parameter` = :parameter');
        $statement->execute(['parameter' => self::PARAM]);

        return (int)$statement->fetchColumn();
    }

    private function configService(): ConfigService
    {
        return $this->buildContainer()->get(ConfigService::class);
    }

    private function buildContainer(): ContainerInterface
    {
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'cli');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        $coreDefinitions['paths'] = array_map(
            fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, $this->cachePath()],
                Path::TMP => [Path::TMP, $this->tmpPath()],
                Path::BACKUP => [Path::BACKUP, $this->backupPath()],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        $moduleDefinitions = FileSystem::require(
            FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Cli', 'module.php')
        );

        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            $coreDefinitions,
            $moduleDefinitions,
            [DbStorageHandler::class => getDbHandler()]
        );

        return $builder->build();
    }

    private function cachePath(): string
    {
        return FileSystem::buildPath($this->root, 'cache');
    }

    private function tmpPath(): string
    {
        return FileSystem::buildPath($this->root, 'tmp');
    }

    private function backupPath(): string
    {
        return FileSystem::buildPath($this->root, 'backup');
    }
}
