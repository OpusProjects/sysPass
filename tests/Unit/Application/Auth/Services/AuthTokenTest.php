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

namespace SP\Tests\Unit\Application\Auth\Services;

use PHPUnit\Framework\MockObject\Stub;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Core\Acl\ActionNotFoundException;
use SP\Domain\Core\Acl\ActionsInterface;
use Defuse\Crypto\Exception\CryptoException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Auth\Ports\AuthTokenRepository;
use SP\Application\Auth\Services\AuthToken;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\Generators\AuthTokenGenerator;
use SP\Tests\Support\Generators\ItemSearchDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AuthTokenTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class AuthTokenTest extends UnitaryTestCase
{

    private AuthTokenRepository|MockObject $authTokenRepository;
    private CryptInterface|MockObject      $crypt;
    private AuthToken $authToken;

    public static function secureActionDataProvider(): array
    {
        return [
            [AclActionsInterface::ACCOUNT_VIEW_PASS],
            [AclActionsInterface::ACCOUNT_EDIT_PASS],
            [AclActionsInterface::ACCOUNT_CREATE],
            [AclActionsInterface::ACCOUNT_VIEW],
            [AclActionsInterface::CATEGORY_VIEW],
            [AclActionsInterface::CLIENT_VIEW],
        ];
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    public function testDeleteByIdBatch()
    {
        $ids = array_map(fn() => self::$faker->randomNumber(), range(0, 4));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('deleteByIdBatch')
            ->with($ids)
            ->willReturn(new QueryResult(null, count($ids)));

        $this->authToken->deleteByIdBatch($ids);
    }

    /**
     * A batch of five ids of which one was already gone is not a removal of five.
     *
     * This service refused only when *nothing* matched, so an administrator selecting several
     * tokens while a colleague deleted one of them was told the whole selection had been
     * removed. Nine of the thirteen services already compare the count against the ids they were
     * given; these four did not.
     *
     * @throws ServiceException
     */
    public function testDeleteByIdBatchWithOneAlreadyGone()
    {
        $ids = array_map(fn() => self::$faker->numberBetween(1, 1000), range(0, 4));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('deleteByIdBatch')
            ->with($ids)
            ->willReturn(new QueryResult(null, count($ids) - 1));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while removing the tokens');

        $this->authToken->deleteByIdBatch($ids);
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    public function testDeleteByIdBatchError()
    {
        $ids = array_map(fn() => self::$faker->randomNumber(), range(0, 4));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('deleteByIdBatch')
            ->with($ids)
            ->willReturn(new QueryResult(null, 0));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while removing the tokens');

        $this->authToken->deleteByIdBatch($ids);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testSearch()
    {
        $itemSearch = ItemSearchDataGenerator::factory()->buildItemSearchData();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('search')
            ->with($itemSearch);

        $this->authToken->search($itemSearch);
    }

    /**
     * @throws ConstraintException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     * @throws QueryException
     */
    public function testCreateWithExistingToken()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([$authToken]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('create')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getVault() === $authToken->getVault()
                           && $current->getToken() === $authToken->getToken()
                           && $current->getHash() === null
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(new QueryResult(null, 0, 100));

        $out = $this->authToken->create($authToken);

        $this->assertEquals(100, $out);
    }

    /**
     * @throws ConstraintException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     * @throws QueryException
     */
    public function testCreateWithNoExistingToken()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('create')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getVault() === $authToken->getVault()
                           && $current->getToken() !== $authToken->getToken()
                           && $current->getHash() === null
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(new QueryResult(null, 0, 100));

        $out = $this->authToken->create($authToken);

        $this->assertEquals(100, $out);
    }

    /**
     * @param int $action
     * @throws ConstraintException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws QueryException
     * @throws SPException
     * @throws ContextException
     */
    #[DataProvider('secureActionDataProvider')]
    public function testCreateWithSecureAction(int $action)
    {
        $this->context->setTrasientKey('_masterpass', 'test_pass');

        $authToken = AuthTokenGenerator::factory()->buildAuthToken()->mutate(['actionId' => $action]);

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([$authToken]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('create')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getToken() === $authToken->getToken()
                           && $current->getVault() !== $authToken->getVault()
                           && $current->getHash() !== $authToken->getHash()
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(new QueryResult(null, 0, 100));

        $password = $authToken->getHash() . $authToken->getToken();

        $this->crypt
            ->expects(self::once())
            ->method('makeSecuredKey')
            ->with($password)
            ->willReturn('key');

        $this->crypt
            ->expects(self::once())
            ->method('encrypt')
            ->with('test_pass', 'key', $password)
            ->willReturn('secure_data');

        $out = $this->authToken->create($authToken);

        $this->assertEquals(100, $out);
    }

    /**
     * @throws ConstraintException
     * @throws SPException
     * @throws QueryException
     */
    public function testUpdateRaw()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->with($authToken);

        $this->authToken->updateRaw($authToken);
    }

    /**
     * @throws NoSuchItemException
     */
    public function testGetTokenByToken()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($authToken->getActionId(), $authToken->getToken())
            ->willReturn(new QueryResult([$authToken]));

        $out = $this->authToken->getTokenByToken($authToken->getActionId(), $authToken->getToken());

        $this->assertEquals($authToken, $out);
    }

    /**
     * @throws NoSuchItemException
     */
    public function testGetTokenByTokenWithNoFound()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($authToken->getActionId(), $authToken->getToken())
            ->willReturn(new QueryResult([]));

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Token not found');

        $this->authToken->getTokenByToken($authToken->getActionId(), $authToken->getToken());
    }

    /**
     * @throws CryptException
     * @throws SPException
     * @throws QueryException
     * @throws EnvironmentIsBrokenException
     * @throws ServiceException
     * @throws DuplicatedItemException
     * @throws ConstraintException
     */
    public function testUpdate()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([$authToken]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getVault() === $authToken->getVault()
                           && $current->getToken() === $authToken->getToken()
                           && $current->getHash() === null
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(true);

        $this->authToken->update($authToken);
    }

    /**
     * @throws ConstraintException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     * @throws QueryException
     */
    public function testUpdateWithNoExistingToken()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getVault() === $authToken->getVault()
                           && $current->getToken() !== $authToken->getToken()
                           && $current->getHash() === null
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(true);

        $this->authToken->update($authToken);
    }

    /**
     * @param int $action
     * @throws ConstraintException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws QueryException
     * @throws SPException
     * @throws ContextException
     */
    #[DataProvider('secureActionDataProvider')]
    public function testUpdateWithSecureAction(int $action)
    {
        $this->context->setTrasientKey('_masterpass', 'test_pass');

        $authToken = AuthTokenGenerator::factory()->buildAuthToken()->mutate(['actionId' => $action]);

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getTokenByUserId')
            ->with($authToken->getUserId())
            ->willReturn(new QueryResult([$authToken]));

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getToken() === $authToken->getToken()
                           && $current->getVault() !== $authToken->getVault()
                           && $current->getHash() !== $authToken->getHash()
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(true);

        $password = $authToken->getHash() . $authToken->getToken();

        $this->crypt
            ->expects(self::once())
            ->method('makeSecuredKey')
            ->with($password)
            ->willReturn('key');

        $this->crypt
            ->expects(self::once())
            ->method('encrypt')
            ->with('test_pass', 'key', $password)
            ->willReturn('secure_data');

        $this->authToken->update($authToken);
    }

    public function testGetById()
    {
        $id = self::$faker->randomNumber();

        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getById')
            ->with($id)
            ->willReturn(new QueryResult([$authToken]));

        $out = $this->authToken->getById($id);

        $this->assertEquals($authToken, $out);
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testGetByIdWithUnknownId()
    {
        $id = self::$faker->randomNumber();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getById')
            ->with($id)
            ->willReturn(new QueryResult([]));

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Token not found');

        $this->authToken->getById($id);
    }

    public function testGetAll()
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('getAll')
            ->willReturn(new QueryResult([$authToken]));

        $out = $this->authToken->getAll();

        $this->assertEquals([$authToken], $out);
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testDelete()
    {
        $id = self::$faker->randomNumber();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('delete')
            ->with($id)
            ->willReturn(new QueryResult(null, 1));

        $this->authToken->delete($id);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testDeleteWithNotFound()
    {
        $id = self::$faker->randomNumber();

        $this->authTokenRepository
            ->expects(self::once())
            ->method('delete')
            ->with($id)
            ->willReturn(new QueryResult([]));

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Token not found');

        $this->authToken->delete($id);
    }

    /**
     * @throws ContextException
     * @throws Exception
     */
    public function testRefreshAndUpdate()
    {
        $this->context->setTrasientKey('_masterpass', 'test_pass');

        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->authTokenRepository
            ->expects(self::never())
            ->method('getTokenByUserId');

        $this->authTokenRepository
            ->expects(self::once())
            ->method('transactionAware')
            ->with(self::withResolveCallableCallback())
            ->willReturn(true);

        $this->authTokenRepository
            ->expects(self::once())
            ->method('refreshTokenByUserId')
            ->with(
                $authToken->getUserId(),
                new Callback(function (string $token) use ($authToken) {
                    return $token !== $authToken->getToken();
                })
            );

        $this->authTokenRepository
            ->expects(self::once())
            ->method('refreshVaultByUserId')
            ->with(
                $authToken->getUserId(),
                new Callback(function (string $vault) use ($authToken) {
                    return $vault !== $authToken->getVault();
                }),
                new Callback(function (string $hash) use ($authToken) {
                    return $hash !== $authToken->getHash();
                })
            );

        $this->crypt
            ->expects(self::once())
            ->method('makeSecuredKey')
            ->with(self::anything())
            ->willReturn('key');

        $this->crypt
            ->expects(self::once())
            ->method('encrypt')
            ->with('test_pass', 'key', self::anything())
            ->willReturn('secure_data');

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->with(
                new Callback(function (AuthTokenModel $current) use ($authToken) {
                    return $current->getId() === $authToken->getId()
                           && $current->getUserId() === $authToken->getUserId()
                           && $current->getStartDate() === $authToken->getStartDate()
                           && $current->getActionId() === $authToken->getActionId()
                           && $current->getVault() === $authToken->getVault()
                           && $current->getToken() !== $authToken->getToken()
                           && $current->getHash() === null
                           && $current->getCreatedBy() === $this->context->getUserData()->id;
                })
            )
            ->willReturn(true);

        $this->authToken->refreshAndUpdate($authToken);
    }

    /**
     * Refreshing a token another session has since deleted matches no row, and used to be answered
     * with "Authorization updated" anyway — update() made this check, refreshAndUpdate() did not.
     *
     * @throws Exception
     */
    public function testRefreshAndUpdateThrowsWhenTheTokenIsGone()
    {
        $this->context->setTrasientKey('_masterpass', 'test_pass');

        $this->authTokenRepository
            ->expects(self::once())
            ->method('transactionAware')
            ->willReturnCallback(static fn(callable $callable) => $callable());

        $this->crypt->method('makeSecuredKey')->willReturn('key');
        $this->crypt->method('encrypt')->willReturn('secure_data');

        $this->authTokenRepository
            ->expects(self::once())
            ->method('update')
            ->willReturn(false);

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Token not found');

        $this->authToken->refreshAndUpdate(AuthTokenGenerator::factory()->buildAuthToken());
    }

    private UserService|MockObject $userService;
    private ActionsInterface|Stub  $actions;

    /**
     * A token is looked up by its action, so one for an action the application does not have can
     * never authenticate anything. The API stored one for `actionId` 0, or any other number.
     */
    public function testATokenForAnActionTheApplicationDoesNotHaveIsRefused(): void
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken()->mutate(['actionId' => 0]);

        $this->actions = $this->createStub(ActionsInterface::class);
        $this->actions->method('getActionById')->willThrowException(new ActionNotFoundException('no such action'));

        $this->authToken = new AuthToken(
            $this->application,
            $this->authTokenRepository,
            $this->crypt,
            $this->userService,
            $this->actions
        );

        $this->authTokenRepository->expects(self::never())->method('create');
        $this->authTokenRepository->expects(self::never())->method('update');

        $this->authTokenRepository->expects(self::never())->method('transactionAware');

        foreach (['create', 'update', 'refreshAndUpdate'] as $method) {
            try {
                $this->authToken->{$method}($authToken);
                self::fail($method . ' stored a token for an action that does not exist');
            } catch (ValidationException $e) {
                self::assertSame('Action not set', $e->getMessage());
                self::assertSame(400, $e->getCode());
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->authTokenRepository = $this->createMock(AuthTokenRepository::class);
        $this->crypt = $this->createMock(CryptInterface::class);

        $this->userService = $this->createMock(UserService::class);

        // Every action exists unless a test says otherwise.
        $this->actions = $this->createStub(ActionsInterface::class);

        $this->authToken = new AuthToken(
            $this->application,
            $this->authTokenRepository,
            $this->crypt,
            $this->userService,
            $this->actions
        );
    }

    /**
     * A token acts as the user it is issued for. A token manager who is not an administrator
     * could issue one for an administrator and act as one; issuing, editing and refreshing a token
     * are refused wherever acting on its user would be.
     */
    public function testATokenIsNotWrittenForAUserTheCallerCannotManage(): void
    {
        $authToken = AuthTokenGenerator::factory()->buildAuthToken();

        $this->userService
            ->expects(self::exactly(3))
            ->method('assertManageable')
            ->with($authToken->getUserId())
            ->willThrowException(new NoSuchItemException('User not found'));

        $this->authTokenRepository->expects(self::never())->method('create');
        $this->authTokenRepository->expects(self::never())->method('update');
        $this->authTokenRepository->expects(self::never())->method('transactionAware');

        foreach (['create', 'update', 'refreshAndUpdate'] as $method) {
            try {
                $this->authToken->{$method}($authToken);
                self::fail($method . ' wrote a token for a user the caller cannot manage');
            } catch (NoSuchItemException) {
                // refused, as expected
            }
        }
    }
}
