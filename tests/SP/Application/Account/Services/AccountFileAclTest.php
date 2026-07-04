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

namespace SP\Tests\Application\Account\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Services\AccountFileAcl;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\UnitaryTestCase;

/**
 * Class AccountFileAclTest
 *
 * Proves the object-level authorization guard denies file operations on accounts the current user
 * can't access, and allows them when the user has the required account access.
 */
#[Group('unitary')]
class AccountFileAclTest extends UnitaryTestCase
{
    private AccountService|MockObject    $accountService;
    private AccountAclService|MockObject $accountAclService;
    private AccountFileAcl               $accountFileAcl;

    public static function actionsProvider(): array
    {
        return [
            'view' => ['requireView', AclActionsInterface::ACCOUNT_VIEW],
            'edit' => ['requireEdit', AclActionsInterface::ACCOUNT_EDIT],
        ];
    }

    #[DataProvider('actionsProvider')]
    public function testRequireThrowsWhenAccessDenied(string $method, int $expectedActionId): void
    {
        $accountId = self::$faker->randomNumber();

        $permission = $this->createMock(AccountPermission::class);
        $permission->expects(self::once())
                   ->method('checkAccountAccess')
                   ->with($expectedActionId)
                   ->willReturn(false);

        $this->setUpAclFor($accountId, $expectedActionId, $permission);

        $this->expectException(AccountPermissionException::class);

        $this->accountFileAcl->{$method}($accountId);
    }

    #[DataProvider('actionsProvider')]
    public function testRequirePassesWhenAccessGranted(string $method, int $expectedActionId): void
    {
        $accountId = self::$faker->randomNumber();

        $permission = $this->createMock(AccountPermission::class);
        $permission->expects(self::once())
                   ->method('checkAccountAccess')
                   ->with($expectedActionId)
                   ->willReturn(true);

        $this->setUpAclFor($accountId, $expectedActionId, $permission);

        // No exception thrown: the guard lets the authorized user through. The mock
        // expectations (getAcl / checkAccountAccess called once) verify the path.
        $this->accountFileAcl->{$method}($accountId);
    }

    /**
     * Wires the account-enrichment + ACL lookup for the given account and asserts the ACL is
     * requested for the expected action id against the target account.
     */
    private function setUpAclFor(int $accountId, int $expectedActionId, AccountPermission $permission): void
    {
        $accountView = new AccountView(['id' => $accountId, 'userId' => 1, 'userGroupId' => 1]);
        $enrichedDto = new AccountEnrichedDto($accountView);

        $this->accountService
            ->expects(self::once())
            ->method('getByIdEnriched')
            ->with($accountId)
            ->willReturn($accountView);
        $this->accountService->method('withUsers')->willReturn($enrichedDto);
        $this->accountService->method('withUserGroups')->willReturn($enrichedDto);

        $this->accountAclService
            ->expects(self::once())
            ->method('getAcl')
            ->with(
                $expectedActionId,
                self::callback(
                    static fn(AccountAclDto $dto): bool => $dto->getAccountId() === $accountId
                )
            )
            ->willReturn($permission);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountService = $this->createMock(AccountService::class);
        $this->accountAclService = $this->createMock(AccountAclService::class);

        $this->accountFileAcl = new AccountFileAcl($this->accountService, $this->accountAclService);
    }
}
