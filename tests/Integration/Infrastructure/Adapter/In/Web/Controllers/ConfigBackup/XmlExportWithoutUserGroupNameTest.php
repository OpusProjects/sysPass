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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserGroup;
use SP\Tests\Support\Generators\UserGroupGenerator;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The session's UserDto is built from UserRepository::getByLogin(), which selects only the User
 * table's own columns — so userGroupName is null in production. IntegrationTestCase's default DTO
 * fills it in, which is why the ordinary xmlExport test passed while every real export failed its
 * own schema validation with "Invalid XML schema" on an empty <Group>.
 *
 * This case reproduces the production shape.
 */
#[Group('integration')]
class XmlExportWithoutUserGroupNameTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function xmlExportWithoutUserGroupNameInSession()
    {
        // Production always resolves: User.userGroupId is NOT NULL and getByLogin() inner-joins
        // UserGroup, so the group behind the session's id necessarily exists.
        $this->addDatabaseMapperResolver(
            UserGroup::class,
            new QueryResult([UserGroupGenerator::factory()->buildUserGroupData()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'configBackup/xmlExport'])
        );

        $this->expectOutputString('{"status":"OK","description":"Export process finished","data":null}');

        IntegrationTestCase::runApp($container);
    }

    protected function getUserDataDto(): UserDto
    {
        return UserDto::fromModel(UserDataGenerator::factory()->buildUserData())
                      ->mutate([
                                   'isAdminApp' => false,
                                   'isAdminAcc' => false,
                                   'userGroupName' => null,
                               ]);
    }

    protected function getConfigData(): array
    {
        $configData = parent::getConfigData();
        $configData['getBackupHash'] = $this->passwordSalt;
        $configData['getExportHash'] = $this->passwordSalt;

        return $configData;
    }
}
