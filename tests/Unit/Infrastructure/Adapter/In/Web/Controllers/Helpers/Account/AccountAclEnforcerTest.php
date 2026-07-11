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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountAclEnforcer;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

use function PHPUnit\Framework\once;

/**
 * Class AccountAclEnforcerTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class AccountAclEnforcerTest extends UnitaryTestCase
{
    private AccountService|MockObject    $accountService;
    private AccountAclService|MockObject $accountAclService;
    private AccountAclEnforcer           $accountAclEnforcer;

    /**
     * A user without access to the account must be denied.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckAccountAccessThrowsWhenNotAllowed(): void
    {
        $accountId = self::$faker->randomNumber(3);
        $enriched = new AccountEnrichedDto(AccountDataGenerator::factory()->buildAccountDataView());

        $this->accountService->method('getByIdEnriched')->willReturn($enriched->getAccountView());
        $this->accountService->method('withUsers')->willReturn($enriched);
        $this->accountService->method('withUserGroups')->willReturn($enriched);

        // compiledAccountAccess defaults to false, so checkAccountAccess() returns false
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_DELETE);

        $this->accountAclService
            ->expects(once())
            ->method('getAcl')
            ->with(
                AclActionsInterface::ACCOUNT_DELETE,
                self::callback(static fn(AccountAclDto $dto) => $dto->getAccountId() === $enriched->getId())
            )
            ->willReturn($permission);

        $this->expectException(AccountPermissionException::class);

        $this->accountAclEnforcer->checkAccountAccess(AclActionsInterface::ACCOUNT_DELETE, $accountId);
    }

    /**
     * A user with edit access to the account must be allowed.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckAccountAccessPassesWhenAllowed(): void
    {
        $accountId = self::$faker->randomNumber(3);
        $enriched = new AccountEnrichedDto(AccountDataGenerator::factory()->buildAccountDataView());

        $this->accountService->method('getByIdEnriched')->willReturn($enriched->getAccountView());
        $this->accountService->method('withUsers')->willReturn($enriched);
        $this->accountService->method('withUserGroups')->willReturn($enriched);

        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT))
            ->setCompiledAccountAccess(true)
            ->setResultEdit(true);

        $this->accountAclService
            ->expects(once())
            ->method('getAcl')
            ->with(AclActionsInterface::ACCOUNT_EDIT, self::isInstanceOf(AccountAclDto::class))
            ->willReturn($permission);

        $this->accountAclEnforcer->checkAccountAccess(AclActionsInterface::ACCOUNT_EDIT, $accountId);
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->accountService = $this->createMock(AccountService::class);
        $this->accountAclService = $this->createMock(AccountAclService::class);

        $this->accountAclEnforcer = new AccountAclEnforcer($this->accountService, $this->accountAclService);
    }
}
