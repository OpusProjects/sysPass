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

namespace SP\Tests\Unit\Domain\Upgrade;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Upgrade\Services\UpgradeConfigText;
use SP\Domain\Upgrade\Services\UpgradeDatabase;
use SP\Domain\Common\Attributes\UpgradeVersion;
use SP\Infrastructure\Database\MysqlFileParser;
use SP\Infrastructure\File\FileHandler;

/**
 * An upgrade is the one thing a user runs on data they cannot afford to lose, on an installation
 * that is already broken enough to need it. Both of these guard a way it stopped being repeatable.
 *
 * The migration rule comes from `40024210101.sql`, which gave `CustomFieldData` its natural
 * primary key as two statements. DDL commits as it goes, so the drop stood alone when the key was
 * refused on a duplicate, and the retry then died on `Can't DROP COLUMN id` before it could reach
 * the statement that had failed — `UpgradeDatabase::apply()` writes the new version only after
 * every statement succeeds, so the version stayed old and the file was re-run from the top for
 * ever. It is one `ALTER` now, and `MigrationIsAtomicTest` holds it to failing as a whole against
 * a real server. This holds the *next* migration to being written the same way.
 *
 * The ordering rule is about how those files are reached. `Upgrade::getTargetUpgradeHandlers()`
 * yields a handler's versions in the order `getAttributes()` returns them, which is the order they
 * are written in the class — nothing sorts them. Today they ascend, so the schema change runs
 * before the data migration that needs it. A version added at the top of the list would run first,
 * and out-of-order migrations are not something an installation reports; they are something it
 * survives or does not.
 */
#[Group('unitary')]
class UpgradePathCannotRegressTest extends TestCase
{
    private const SCHEMAS = REAL_APP_ROOT . '/schemas';

    /**
     * Every handler that carries version attributes.
     *
     * @return array<string, array{class-string}>
     */
    public static function handlerProvider(): array
    {
        return [
            'UpgradeDatabase' => [UpgradeDatabase::class],
            'UpgradeConfigText' => [UpgradeConfigText::class],
        ];
    }

    /**
     * A handler's versions are written in the order they must be applied.
     */
    #[Test]
    #[DataProvider('handlerProvider')]
    public function versionsAreDeclaredInTheOrderTheyApply(string $handler): void
    {
        $versions = self::versionsOf($handler);

        self::assertNotEmpty($versions, sprintf('%s carries no version, so it can never run', $handler));

        $sorted = $versions;
        usort(
            $sorted,
            static fn(string $a, string $b) => version_compare(
                (string)Version::normalizeVersionForCompare($a),
                (string)Version::normalizeVersionForCompare($b)
            )
        );

        self::assertSame(
            $sorted,
            $versions,
            sprintf(
                '%s applies its versions in the order they are written, because nothing sorts them. '
                . 'Written out of order they are applied out of order, and a schema change that '
                . 'runs after the data migration needing it does not announce itself.',
                $handler
            )
        );
    }

    /**
     * No two handlers claim the same version twice over in a way that hides which runs first.
     *
     * They may share a version — `400.24240101` is both a row migration and a config one — and the
     * order between handlers is then registration order in `CoreDefinitions`. What must not happen
     * is a handler claiming the same version more than once, where the repeat is silently applied
     * twice.
     */
    #[Test]
    #[DataProvider('handlerProvider')]
    public function noHandlerClaimsAVersionTwice(string $handler): void
    {
        $versions = self::versionsOf($handler);

        self::assertSame(
            array_values(array_unique($versions)),
            $versions,
            sprintf('%s would apply a repeated version more than once', $handler)
        );
    }

    /**
     * Every version file, and the statements it holds.
     *
     * @return array<string, array{string}>
     */
    public static function migrationProvider(): array
    {
        $cases = [];

        foreach (glob(self::SCHEMAS . '/*.sql') ?: [] as $path) {
            // dbstructure.sql builds a database rather than upgrading one: nothing has been
            // applied when it runs, so a failure leaves nothing half-done.
            if (basename($path) === 'dbstructure.sql') {
                continue;
            }

            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    /**
     * A migration that is refused part way leaves the operator something to run again.
     *
     * Three ways to satisfy it, and each is a real answer rather than a formality:
     *
     * - one statement, which the server applies whole;
     * - every statement inside one transaction, which only works while none of them is DDL,
     *   because DDL commits and would end the transaction under the ones that follow;
     * - DDL written so that re-running it is harmless (`IF NOT EXISTS`, `IF EXISTS`).
     */
    #[Test]
    #[DataProvider('migrationProvider')]
    public function aMigrationCanBeRunAgainAfterItIsRefused(string $path): void
    {
        $statements = iterator_to_array((new MysqlFileParser(new FileHandler($path)))->parse('$$'), false);

        self::assertNotEmpty($statements, sprintf('%s holds no statements', basename($path)));

        if (count($statements) === 1) {
            return;
        }

        $ddl = array_values(
            array_filter(
                $statements,
                static fn(string $s) => preg_match('/^\s*(alter|create|drop|rename|truncate)\b/i', $s) === 1
            )
        );

        if ($ddl === []) {
            self::assertTrue(
                self::isWrappedInATransaction($statements),
                sprintf(
                    '%s applies several statements and none of them is DDL, so it can be one '
                    . 'transaction — and has to be, or a failure part way leaves the rows half '
                    . 'migrated with the version unchanged.',
                    basename($path)
                )
            );

            return;
        }

        foreach ($ddl as $statement) {
            self::assertMatchesRegularExpression(
                '/\bIF\s+(NOT\s+)?EXISTS\b/i',
                $statement,
                sprintf(
                    "%s applies more than one statement and one of them is DDL, which commits on "
                    . "its own — so a later failure cannot be undone and the retry meets a change "
                    . "that is already there. Make it a single statement, or write the DDL so that "
                    . "running it twice is harmless.\n\n%s",
                    basename($path),
                    $statement
                )
            );
        }
    }

    /**
     * @param string[] $statements
     */
    private static function isWrappedInATransaction(array $statements): bool
    {
        $first = strtolower(trim((string)reset($statements)));
        $last = strtolower(trim((string)end($statements)));

        return (str_starts_with($first, 'start transaction') || str_starts_with($first, 'begin'))
               && str_starts_with($last, 'commit');
    }

    /**
     * @param class-string $handler
     *
     * @return string[]
     */
    /**
     * Versions declared above the application's own, which therefore cannot be reached.
     *
     * `ModuleBase::checkUpgradeNeeded()` asks whether the *stored* version is behind the version
     * the code reports, and `Version::getVersionStringNormalized()` builds that from
     * `AppInfoInterface::APP_VERSION` and `APP_BUILD` alone. Those constants have not moved since
     * the rewrite was imported, so an installation stamped with them — which is every installation
     * this codebase performs, since `Installer` stamps the same value — is never behind, no upgrade
     * is ever triggered, and a handler declared above that version never runs.
     *
     * Both entries below are in that state. They are listed rather than merely tolerated so that a
     * migration added tomorrow cannot quietly join them: the test asserts these two are *still*
     * unreachable, and that nothing else is.
     *
     * Resolving them is a deployment decision rather than a test change. Bumping APP_BUILD makes
     * both reachable, but `40024210101.sql` drops a column that `dbstructure.sql` already ships
     * without — so it would then run against schemas that already have it applied, and fail. The
     * migration has to be made conditional first, or these two accepted as applying only to
     * installations arriving from 3.2.
     */
    private const VERSIONS_THE_APPLICATION_CANNOT_REACH = ['400.24210101', '400.24240101'];

    /**
     * A declared version above the application's own can never be the reason an upgrade runs.
     *
     * This is not a property of any one migration, which is why it is asserted over all of them:
     * the version is what decides whether the upgrade happens at all, so declaring a handler above
     * it writes a migration nothing will ever ask for. It fails silently and looks exactly like an
     * installation that had nothing to do.
     */
    #[Test]
    #[DataProvider('handlerProvider')]
    public function everyDeclaredVersionIsOneTheApplicationCanReach(string $handler): void
    {
        $current = Version::getVersionStringNormalized();

        foreach (self::versionsOf($handler) as $version) {
            $unreachable = Version::checkVersion($current, $version);

            if (in_array($version, self::VERSIONS_THE_APPLICATION_CANNOT_REACH, true)) {
                self::assertTrue(
                    $unreachable,
                    sprintf(
                        '%s is listed as unreachable but the application now reports %s. Remove it '
                        . 'from VERSIONS_THE_APPLICATION_CANNOT_REACH.',
                        $version,
                        $current
                    )
                );

                continue;
            }

            self::assertFalse(
                $unreachable,
                sprintf(
                    '%s declares %s, which is above the application\'s own %s. checkUpgradeNeeded() '
                    . 'compares the stored version against that, so this migration can never run. '
                    . 'Bump APP_BUILD, or lower the declared version.',
                    $handler,
                    $version,
                    $current
                )
            );
        }
    }

    private static function versionsOf(string $handler): array
    {
        return array_map(
            static fn(\ReflectionAttribute $attribute) => $attribute->newInstance()->version,
            (new ReflectionClass($handler))->getAttributes(UpgradeVersion::class)
        );
    }
}
