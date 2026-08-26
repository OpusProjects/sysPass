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

namespace SP\Tests\Unit\Application\User\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\User\Ports\UserGroupRepository;
use SP\Application\User\Ports\UserToUserGroupService;
use SP\Application\User\Services\UserGroup;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Tests\Support\Generators\UserGroupGenerator;
use SP\Tests\Support\Stubs\UserGroupRepositoryStub;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class UserGroupTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class UserGroupTest extends UnitaryTestCase
{

    private MockObject|UserGroupRepository    $userGroupRepository;
    private UserToUserGroupService|MockObject $userToUserGroupService;
    private UserGroup                         $userGroup;

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testGetById()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('getById')
            ->with(100)
            ->willReturn(new QueryResult([new UserGroupModel()]));

        $this->userToUserGroupService
            ->expects($this->once())
            ->method('getUsersByGroupId')
            ->with(100)
            ->willReturn([1, 2, 3]);

        $out = $this->userGroup->getById(100);

        $this->assertEquals([1, 2, 3], $out->getUsers());
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testGetByIdWithNoGroup()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('getById')
            ->with(100)
            ->willReturn(new QueryResult([]));

        $this->userToUserGroupService
            ->expects($this->never())
            ->method('getUsersByGroupId');

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Group not found');

        $this->userGroup->getById(100);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testGetUsage()
    {
        $queryResult = new QueryResult([new UserGroupModel()]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('getUsage')
            ->with(100)
            ->willReturn($queryResult);

        $out = $this->userGroup->getUsage(100);

        $this->assertEquals($queryResult->getDataAsArray(), $out);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testGetUsageByUsers()
    {
        $queryResult = new QueryResult([new UserGroupModel()]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('getUsageByUsers')
            ->with(100)
            ->willReturn($queryResult);

        $out = $this->userGroup->getUsageByUsers(100);

        $this->assertEquals($queryResult->getDataAsArray(), $out);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdate()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData();

        $this->userGroupRepository
            ->expects($this->once())
            ->method('update')
            ->with($userGroup)
            ->willReturn(1);

        $this->userToUserGroupService
            ->expects($this->once())
            ->method('update')
            ->with($userGroup->getId(), $userGroup->getUsers());

        $this->userGroup->update($userGroup);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdateWithNoUsers()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData()->mutate(['users' => null]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('update')
            ->with($userGroup)
            ->willReturn(1);

        $this->userToUserGroupService
            ->expects($this->never())
            ->method('update');

        $this->userGroup->update($userGroup);
    }

    /**
     * A group that is no longer there cannot be edited, and saying so beats reporting a save that
     * touched nothing — the members are not written either, since the whole thing is one
     * transaction.
     *
     * @throws ServiceException
     */
    public function testUpdateOfAGroupThatIsNotThereIsReported()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData();

        $this->userGroupRepository
            ->expects($this->once())
            ->method('update')
            ->with($userGroup)
            ->willReturn(0);

        $this->userToUserGroupService->expects($this->never())->method('update');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while updating the group');

        $this->userGroup->update($userGroup);
    }

    /**
     * @throws NoSuchItemException
     */
    public function testGetByName()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData();

        $this->userGroupRepository
            ->expects($this->once())
            ->method('getByName')
            ->with('a_group')
            ->willReturn(new QueryResult([$userGroup]));

        $out = $this->userGroup->getByName('a_group');

        $this->assertEquals($userGroup, $out);
    }

    /**
     * @throws NoSuchItemException
     */
    public function testGetByNameWithNoGroup()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('getByName')
            ->with('a_group')
            ->willReturn(new QueryResult([]));

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Group not found');

        $this->userGroup->getByName('a_group');
    }

    /**
     * @throws ServiceException
     */
    public function testCreate()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData();

        $this->userGroupRepository
            ->expects($this->once())
            ->method('create')
            ->with($userGroup)
            ->willReturn(new QueryResult([$userGroup], 1, 100));

        $this->userToUserGroupService
            ->expects($this->once())
            ->method('add')
            ->with(100, $userGroup->getUsers());

        $out = $this->userGroup->create($userGroup);

        $this->assertEquals(100, $out);
    }

    /**
     * @throws ServiceException
     */
    public function testCreateWithNoUsers()
    {
        $userGroup = UserGroupGenerator::factory()->buildUserGroupData()->mutate(['users' => null]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('create')
            ->with($userGroup)
            ->willReturn(new QueryResult([$userGroup], 1, 100));

        $this->userToUserGroupService
            ->expects($this->never())
            ->method('add');

        $out = $this->userGroup->create($userGroup);

        $this->assertEquals(100, $out);
    }

    /**
     * @throws ConstraintException
     * @throws ServiceException
     * @throws QueryException
     */
    public function testDeleteByIdBatch()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('deleteByIdBatch')
            ->with([100, 200, 300])
            ->willReturn(new QueryResult(null, 3));

        $out = $this->userGroup->deleteByIdBatch([100, 200, 300]);

        $this->assertEquals(3, $out);
    }

    /**
     * @throws ConstraintException
     * @throws ServiceException
     * @throws QueryException
     */
    public function testDeleteByIdBatchWithException()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('deleteByIdBatch')
            ->with([100, 200, 300])
            ->willReturn(new QueryResult(null, 1));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while deleting the groups');

        $this->userGroup->deleteByIdBatch([100, 200, 300]);
    }


    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testDelete()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('getUsage')
            ->with(100)
            ->willReturn(new QueryResult([]));

        $this->userGroupRepository
            ->expects($this->once())
            ->method('delete')
            ->with(100)
            ->willReturn(new QueryResult(null, 1));

        $this->userGroup->delete(100);
    }

    /**
     * The group new directory users are created into cannot be deleted.
     *
     * `ldapDefaultGroup` and `ssoDefaultGroup` are plain ints in config.xml with nothing pointing
     * at UserGroup, so no foreign key refuses this — and the RESTRICT on User.userGroupId only
     * catches a group somebody currently holds, which is precisely not the case for one being
     * tidied up because its members have moved on. Deleting it succeeded cleanly, and then every
     * auto-provisioned login afterwards died on the NOT NULL foreign key in
     * User::createOnLogin(), reported as "Internal error, check the event log".
     *
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testDeleteRefusesTheDirectoryDefaultGroup()
    {
        $this->config->getConfigData()->setLdapDefaultGroup(100);

        $this->userGroupRepository->expects($this->never())->method('getUsage');
        $this->userGroupRepository->expects($this->never())->method('delete');

        try {
            $this->userGroup->delete(100);
            self::fail('Expected a ServiceException');
        } catch (ServiceException $e) {
            self::assertSame('Group in use', $e->getMessage());
            self::assertSame('It is the default group for LDAP users', $e->getHint());
        }
    }

    /**
     * And the batch does not get round it. It goes to the repository directly, so the single
     * delete's guard is not in front of it.
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testDeleteByIdBatchRefusesTheDirectoryDefaultGroup()
    {
        $this->config->getConfigData()->setSsoDefaultGroup(200);

        $this->userGroupRepository->expects($this->never())->method('deleteByIdBatch');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Group in use');

        $this->userGroup->deleteByIdBatch([100, 200, 300]);
    }

    /**
     * A group something still holds is refused, and the refusal says what holds it.
     *
     * Three foreign keys refuse this delete: a user whose main group it is, an account owned by
     * it, and a history row, which outlives the account it describes. The refusal used to come
     * back from MySQL as error 1451 and be rendered as "The record is in use", which names
     * nothing — and the group's own "Used by" panel lists only its users, so a group with no
     * members but a hundred accounts looked entirely safe to delete right up until it was not.
     *
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testDeleteRefusesAGroupInUseAndSaysWhatHoldsIt()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('getUsage')
            ->with(100)
            ->willReturn(
                new QueryResult([
                    new Simple(['id' => 100, 'ref' => 'Account']),
                    new Simple(['id' => 100, 'ref' => 'Account']),
                    new Simple(['id' => 100, 'ref' => 'AccountHistory']),
                ])
            );

        $this->userGroupRepository->expects($this->never())->method('delete');

        try {
            $this->userGroup->delete(100);
            self::fail('Expected a ServiceException');
        } catch (ServiceException $e) {
            self::assertSame('Group in use', $e->getMessage());
            self::assertSame('Accounts: 2 - Accounts in history: 1', $e->getHint());
        }
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testDeleteWithException()
    {
        $this->userGroupRepository
            ->expects($this->once())
            ->method('delete')
            ->with(100)
            ->willReturn(new QueryResult());

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Group not found');

        $this->userGroup->delete(100);
    }

    public function testSearch()
    {
        $itemSearchData = new ItemSearchDto('test', 1, 10);

        $queryResult = new QueryResult([1]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('search')
            ->with($itemSearchData)
            ->willReturn($queryResult);

        $out = $this->userGroup->search($itemSearchData);

        $this->assertEquals($queryResult, $out);
    }

    public function testGetAll()
    {
        $queryResult = new QueryResult([UserGroupGenerator::factory()->buildUserGroupData()]);

        $this->userGroupRepository
            ->expects($this->once())
            ->method('getAll')
            ->willReturn($queryResult);

        $out = $this->userGroup->getAll();

        $this->assertEquals($queryResult->getDataAsArray(), $out);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $userGroupRepositoryMethods = array_filter(
            get_class_methods(UserGroupRepositoryStub::class),
            static fn(string $method) => $method != 'transactionAware'
        );

        $this->userGroupRepository = $this->createPartialMock(
            UserGroupRepositoryStub::class,
            $userGroupRepositoryMethods
        );
        $this->userToUserGroupService = $this->createMock(UserToUserGroupService::class);

        $this->userGroup = new UserGroup($this->application, $this->userGroupRepository, $this->userToUserGroupService);
    }


}
