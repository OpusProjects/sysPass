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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Cli\Commands;

use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Adapter\In\Cli\Commands\ConvertLegacyDataCommand;
use SP\Tests\Integration\Infrastructure\Adapter\In\Cli\CliTestCase;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;

/**
 * Converting what sysPass 3.2 stored, after the fact
 *
 * A 3.2 database restored into an installation that has already upgraded runs no upgrade, so its
 * profiles stay in 3.2's format — which this version cannot read. The command converts them, and
 * running it again changes nothing.
 */
#[Group('integration')]
class ConvertLegacyDataCommandTest extends CliTestCase
{
    use DatabaseTrait;

    /**
     * A profile exactly as 3.2 stored it: its own class name, its protected property markers.
     */
    private const LEGACY_PROFILE = "O:24:\"SP\\DataModel\\ProfileData\":2:{s:10:\"\0*\0accView\";b:1;s:10:\"\0*\0accEdit\";b:1;}";

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testARestored32ProfileIsConvertedAndReadable(): void
    {
        $this->setupDatabase();
        $this->writeProfile(self::LEGACY_PROFILE);

        // Every row still in PHP serialization is converted, not only this one: the fixtures carry
        // others, written under the rewrite's own class names.
        $legacyRows = $this->countLegacyRows();
        $this->assertGreaterThanOrEqual(1, $legacyRows);

        $first = $this->executeCommandTest(ConvertLegacyDataCommand::class, []);

        $this->assertStringContainsString(
            sprintf('%d converted, 0 could not be converted', $legacyRows),
            $first->getDisplay()
        );
        $this->assertSame(0, $this->countLegacyRows(), 'nothing is left in PHP serialization');

        $stored = $this->readProfile();
        $this->assertStringStartsWith('{', $stored);

        $profile = (new UserProfile(['profile' => $stored]))->hydrate(ProfileData::class);
        $this->assertTrue($profile->isAccView());
        $this->assertTrue($profile->isAccEdit());

        $second = $this->executeCommandTest(ConvertLegacyDataCommand::class, []);

        $this->assertStringContainsString('0 converted, 0 could not be converted', $second->getDisplay());
        $this->assertSame($stored, $this->readProfile(), 'a converted row is left alone');
    }

    private function countLegacyRows(): int
    {
        $count = 0;

        foreach (['UserProfile' => 'profile', 'User' => 'preferences', 'ItemPreset' => 'data'] as $table => $column) {
            $statement = getDbHandler()->getConnection()->query(sprintf(
                "SELECT COUNT(*) FROM `%s` WHERE `%s` IS NOT NULL AND `%s` <> '' AND LEFT(`%s`, 1) NOT IN ('{', '[')",
                $table,
                $column,
                $column,
                $column
            ));
            $count += (int)$statement->fetchColumn();
        }

        return $count;
    }

    private function writeProfile(string $value): void
    {
        getDbHandler()->getConnection()
            ->prepare('UPDATE `UserProfile` SET `profile` = :profile WHERE `id` = 1')
            ->execute(['profile' => $value]);
    }

    private function readProfile(): string
    {
        $statement = getDbHandler()->getConnection()->prepare('SELECT `profile` FROM `UserProfile` WHERE `id` = 1');
        $statement->execute();

        return (string)$statement->fetchColumn();
    }

    /**
     * The conversion touched the shared fixtures, which every later test reads; put them back.
     */
    protected function tearDown(): void
    {
        self::loadFixtures();

        parent::tearDown();
    }
}
