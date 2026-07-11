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

namespace SP\Tests\Unit\Application\Account\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Account\Ports\AccountToUserGroupRepository;
use SP\Domain\Account\Ports\AccountToUserRepository;
use SP\Application\Account\Services\AccountPreset;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\NoSuchPropertyException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\ItemPreset\Models\ItemPreset;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Infrastructure\Adapter\In\Web\Validators\PasswordValidator;
use SP\Infrastructure\Adapter\In\Web\Validators\ValidatorInterface;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\Generators\ItemPresetDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountPresetServiceTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class AccountPresetTest extends UnitaryTestCase
{

    private ItemPresetService|MockObject            $itemPresetService;
    private AccountPreset                           $accountPreset;
    private ValidatorInterface|MockObject           $passwordValidator;
    private MockObject|AccountToUserGroupRepository $accountToUserGroupRepository;
    private AccountToUserRepository|MockObject      $accountToUserRepository;

    /**
     * @throws QueryException
     * @throws ConstraintException
     * @throws ValidationException
     * @throws NoSuchPropertyException
     * @throws SPException
     */
    public function testCheckPasswordPreset(): void
    {
        $this->config->getConfigData()->setAccountExpireEnabled(true);

        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($itemPresetDataGenerator->buildPassword())
                                              ->mutate(['fixed' => 1]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::once())
            ->method('validate')
            ->with(self::callback(static fn($password) => $password instanceof Password));

        $this->accountPreset->checkPasswordPreset(AccountDataGenerator::factory()->buildAccountCreateDto());
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckPasswordPresetThrowsValidatorException(): void
    {
        $this->config->getConfigData()->setAccountExpireEnabled(true);

        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($itemPresetDataGenerator->buildPassword())
                                              ->mutate(['fixed' => 1]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::once())
            ->method('validate')
            ->with(self::callback(static fn($password) => $password instanceof Password))
            ->willThrowException(new ValidationException('test'));

        $this->expectException(ValidationException::class);

        $this->accountPreset->checkPasswordPreset(AccountDataGenerator::factory()->buildAccountCreateDto());
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckPasswordPresetWithoutFixed(): void
    {
        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($itemPresetDataGenerator->buildPassword())
                                              ->mutate(['fixed' => 0]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::never())
            ->method('validate');

        $this->accountPreset->checkPasswordPreset(AccountDataGenerator::factory()->buildAccountCreateDto());
    }

    /**
     * A "fixed" preset's expiry acts as a CEILING on the rotation deadline: an
     * account with no deadline set yet (null or 0) must have one set to the
     * policy's limit (now + preset expire time).
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    #[TestWith([null])]
    #[TestWith([0])]
    public function testCheckPasswordPresetWithUnsetPassDateChangeIsSetToPolicyLimit(?int $passDateChange): void
    {
        $expireDays = self::$faker->numberBetween(1, 30);
        $expireTimePreset = $expireDays * Password::EXPIRE_TIME_MULTIPLIER;

        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $passwordPreset = $this->buildPasswordPresetWithExpireDays($expireDays);
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($passwordPreset)->mutate(['fixed' => 1]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::once())
            ->method('validate');

        $accountDto = AccountDataGenerator::factory()->buildAccountCreateDto()
                                           ->mutate(['passDateChange' => $passDateChange]);

        $before = time();
        $out = $this->accountPreset->checkPasswordPreset($accountDto);
        $after = time();

        self::assertGreaterThanOrEqual($before + $expireTimePreset, $out->passDateChange);
        self::assertLessThanOrEqual($after + $expireTimePreset, $out->passDateChange);
    }

    /**
     * A "fixed" preset's expiry acts as a CEILING: a deadline set later than the
     * policy's limit (now + preset expire time) must be clamped DOWN to that limit.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckPasswordPresetWithLaterPassDateChangeIsClampedToPolicyLimit(): void
    {
        $expireDays = self::$faker->numberBetween(1, 30);
        $expireTimePreset = $expireDays * Password::EXPIRE_TIME_MULTIPLIER;

        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $passwordPreset = $this->buildPasswordPresetWithExpireDays($expireDays);
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($passwordPreset)->mutate(['fixed' => 1]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::once())
            ->method('validate');

        // A user-chosen deadline further away than the policy allows (e.g. the
        // policy mandates rotation every 90 days, but this account is set to
        // rotate in a year).
        $accountDto = AccountDataGenerator::factory()->buildAccountCreateDto()
                                           ->mutate(['passDateChange' => time() + $expireTimePreset + 31536000]);

        $before = time();
        $out = $this->accountPreset->checkPasswordPreset($accountDto);
        $after = time();

        self::assertGreaterThanOrEqual($before + $expireTimePreset, $out->passDateChange);
        self::assertLessThanOrEqual($after + $expireTimePreset, $out->passDateChange);
    }

    /**
     * A "fixed" preset's expiry acts as a CEILING, not a floor: a deadline that is
     * already earlier (stricter) than the policy's limit must be left untouched.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckPasswordPresetWithEarlierPassDateChangeIsUnchanged(): void
    {
        $expireDays = self::$faker->numberBetween(10, 30);
        $expireTimePreset = $expireDays * Password::EXPIRE_TIME_MULTIPLIER;

        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $passwordPreset = $this->buildPasswordPresetWithExpireDays($expireDays);
        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($passwordPreset)->mutate(['fixed' => 1]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::once())
            ->method('validate');

        // A user-chosen deadline stricter than the policy requires (e.g. the
        // policy allows up to 30 days, but this account rotates in a week).
        $passDateChange = time() + Password::EXPIRE_TIME_MULTIPLIER * 7;
        $accountDto = AccountDataGenerator::factory()->buildAccountCreateDto()
                                           ->mutate(['passDateChange' => $passDateChange]);

        $out = $this->accountPreset->checkPasswordPreset($accountDto);

        self::assertSame($accountDto, $out);
        self::assertSame($passDateChange, $out->passDateChange);
    }

    /**
     * A "fixed" preset whose serialized data is NULL (or fails to deserialize) must be
     * treated as if no preset applied at all: the block is skipped and the validator is
     * never invoked, rather than dereferencing a null hydrated preset.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testCheckPasswordPresetWithNullData(): void
    {
        $this->config->getConfigData()->setAccountExpireEnabled(true);

        $itemPreset = new ItemPreset([
                                          'id' => self::$faker->randomNumber(3),
                                          'type' => self::$faker->colorName(),
                                          'userId' => self::$faker->randomNumber(3),
                                          'userGroupId' => self::$faker->randomNumber(3),
                                          'userProfileId' => self::$faker->randomNumber(3),
                                          'fixed' => 1,
                                          'priority' => self::$faker->randomNumber(3),
                                          'data' => null,
                                      ]);

        $this->itemPresetService
            ->expects(self::once())
            ->method('getForCurrentUser')
            ->with(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD)
            ->willReturn($itemPreset);
        $this->passwordValidator
            ->expects(self::never())
            ->method('validate');

        $accountDto = AccountDataGenerator::factory()->buildAccountCreateDto();

        $out = $this->accountPreset->checkPasswordPreset($accountDto);

        self::assertSame($accountDto, $out);
    }

    /**
     * @throws ConstraintException
     * @throws SPException
     * @throws QueryException
     */
    #[TestWith([0])]
    #[TestWith([1])]
    public function testAddPresetPermissions(int $fixed)
    {
        $itemPresetDataGenerator = ItemPresetDataGenerator::factory();
        $accountPermission = $itemPresetDataGenerator->buildAccountPermission();

        $itemPreset = $itemPresetDataGenerator->buildItemPresetData($accountPermission)->mutate(['fixed' => $fixed]);

        $this->itemPresetService->expects($this->once())
                                ->method('getForCurrentUser')
                                ->with('account.permission')
                                ->willReturn($itemPreset);

        if ($fixed === 1) {
            // The service excludes the current user/group from the preset lists
            // (array_diff against the logged-in user's id/userGroupId), so the
            // expectations must apply the same exclusion. Reading the raw preset
            // lists made the test flaky: it failed whenever the faker-generated
            // user id/userGroupId happened to collide with a value in the list.
            $userData = $this->context->getUserData();
            $usersView = array_diff($accountPermission->getUsersView(), [$userData->id]);
            $usersEdit = array_diff($accountPermission->getUsersEdit(), [$userData->id]);
            $userGroupsView = array_diff($accountPermission->getUserGroupsView(), [$userData->userGroupId]);
            $userGroupsEdit = array_diff($accountPermission->getUserGroupsEdit(), [$userData->userGroupId]);

            $this->accountToUserRepository
                ->expects($this->exactly(2))
                ->method('addByType')
                ->with(
                    ...self::withConsecutive(
                    [100, $usersView, false],
                    [100, $usersEdit, true]
                )
                );

            $this->accountToUserGroupRepository
                ->expects($this->exactly(2))
                ->method('addByType')
                ->with(
                    ...self::withConsecutive(
                    [100, $userGroupsView, false],
                    [100, $userGroupsEdit, true]
                )
                );
        } else {
            $this->accountToUserRepository
                ->expects($this->never())
                ->method('addByType');

            $this->accountToUserGroupRepository
                ->expects($this->never())
                ->method('addByType');
        }

        $this->accountPreset->addPresetPermissions(100);
    }

    /**
     * @throws ConstraintException
     * @throws SPException
     * @throws QueryException
     */
    public function testAddPresetPermissionsWithNull()
    {
        $this->itemPresetService->expects($this->once())
                                ->method('getForCurrentUser')
                                ->with('account.permission')
                                ->willReturn(null);

        $this->accountToUserRepository
            ->expects($this->never())
            ->method('addByType');

        $this->accountToUserGroupRepository
            ->expects($this->never())
            ->method('addByType');

        $this->accountPreset->addPresetPermissions(100);
    }

    /**
     * A "fixed" preset whose serialized data is NULL (or fails to deserialize) must be
     * treated as if no preset applied at all: the block is skipped rather than
     * dereferencing a null hydrated preset.
     *
     * @throws ConstraintException
     * @throws SPException
     * @throws QueryException
     */
    public function testAddPresetPermissionsWithNullData()
    {
        $itemPreset = new ItemPreset([
                                          'id' => self::$faker->randomNumber(3),
                                          'type' => self::$faker->colorName(),
                                          'userId' => self::$faker->randomNumber(3),
                                          'userGroupId' => self::$faker->randomNumber(3),
                                          'userProfileId' => self::$faker->randomNumber(3),
                                          'fixed' => 1,
                                          'priority' => self::$faker->randomNumber(3),
                                          'data' => null,
                                      ]);

        $this->itemPresetService->expects($this->once())
                                ->method('getForCurrentUser')
                                ->with('account.permission')
                                ->willReturn($itemPreset);

        $this->accountToUserRepository
            ->expects($this->never())
            ->method('addByType');

        $this->accountToUserGroupRepository
            ->expects($this->never())
            ->method('addByType');

        $this->accountPreset->addPresetPermissions(100);
    }

    private function buildPasswordPresetWithExpireDays(int $expireDays): Password
    {
        return new Password(
            self::$faker->numberBetween(1, 12),
            self::$faker->boolean(),
            self::$faker->boolean(),
            self::$faker->boolean(),
            self::$faker->boolean(),
            self::$faker->boolean(),
            self::$faker->boolean(),
            $expireDays,
            self::$faker->randomNumber(3),
            self::$faker->regexify('abc123')
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $configData = $this->config->getConfigData();
        $configData->setAccountExpireEnabled(true);

        $this->itemPresetService = $this->createMock(ItemPresetService::class);
        $this->passwordValidator = $this->createMock(PasswordValidator::class);
        $this->accountToUserGroupRepository = $this->createMock(AccountToUserGroupRepository::class);
        $this->accountToUserRepository = $this->createMock(AccountToUserRepository::class);

        $this->accountPreset =
            new AccountPreset(
                $this->application,
                $this->itemPresetService,
                $this->accountToUserGroupRepository,
                $this->accountToUserRepository,
                $configData,
                $this->passwordValidator
            );
    }
}
