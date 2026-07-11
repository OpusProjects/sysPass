<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Forms\UserProfileForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Tests that UserProfileForm's profile-bit constraint works:
 * a non-admin actor cannot grant permission bits they don't already hold.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class UserProfileFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);

        // Default actor profile from UnitaryTestCase::buildContext(): new ProfileData() — all bits false.
    }

    /**
     * Every single permission bit is blocked when the actor holds none.
     * This exhaustively verifies that the reflection-based constraint covers ALL
     * 30 bits in ProfileData — not a hand-picked subset.
     */
    public function testNonAdminWithNoOwnBitsGrantsNoBitsAtAll(): void
    {
        // Actor profile: all false (default). Request: all true.
        $this->mockRequestAllBitsTrue();

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::PROFILE_CREATE, null);

        $profileData = $this->extractProfileData($form);

        $reflection = new ReflectionClass(ProfileData::class);
        $checkedCount = 0;

        foreach ($reflection->getMethods() as $method) {
            $getter = $method->getName();
            if (!str_starts_with($getter, 'is') || $method->getNumberOfParameters() > 0) {
                continue;
            }
            $this->assertFalse(
                $profileData->$getter(),
                "bit '$getter' must be false when actor holds none"
            );
            $checkedCount++;
        }

        // Sanity: we must have iterated ALL 30 bits (catches additions to ProfileData).
        $this->assertGreaterThanOrEqual(30, $checkedCount, 'expected at least 30 profile bits');
    }

    /**
     * When the actor's profile DOES hold a bit (configEncryption), submitting it
     * as true must be honoured; bits the actor lacks remain blocked.
     */
    public function testNonAdminCanGrantBitPresentInOwnProfile(): void
    {
        // Actor profile: only configEncryption = true.
        $this->context->setUserProfile(new ProfileData(['configEncryption' => true]));
        $this->mockRequestAllBitsTrue();

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::PROFILE_CREATE, null);

        $profileData = $this->extractProfileData($form);

        $this->assertTrue(
            $profileData->isConfigEncryption(),
            'non-admin with configEncryption in their own profile may grant it'
        );
        // A bit they do NOT hold must still be blocked.
        $this->assertFalse(
            $profileData->isMgmUsers(),
            'non-admin must not grant mgmUsers they do not hold'
        );
        $this->assertFalse(
            $profileData->isAccAdd(),
            'non-admin must not grant accAdd they do not hold'
        );
    }

    /**
     * An app-admin actor gets every requested bit honoured regardless of their own
     * profile contents.
     */
    public function testAdminGrantsAllRequestedBits(): void
    {
        // Make the actor an admin; their own profile remains all-false (irrelevant for admins).
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => true])
        );
        $this->mockRequestAllBitsTrue();

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::PROFILE_CREATE, null);

        $profileData = $this->extractProfileData($form);

        $reflection = new ReflectionClass(ProfileData::class);
        $checkedCount = 0;

        foreach ($reflection->getMethods() as $method) {
            $getter = $method->getName();
            if (!str_starts_with($getter, 'is') || $method->getNumberOfParameters() > 0) {
                continue;
            }
            $this->assertTrue(
                $profileData->$getter(),
                "admin must be able to grant '$getter'"
            );
            $checkedCount++;
        }

        $this->assertGreaterThanOrEqual(30, $checkedCount, 'expected at least 30 profile bits');
    }

    /**
     * Submitting a bit as false must remain false even when the actor holds that bit
     * (the constraint is an AND, it cannot force bits on).
     */
    public function testFalseBitRemainsEvenIfActorHoldsIt(): void
    {
        $this->context->setUserProfile(new ProfileData(['configEncryption' => true]));

        // All bits submitted as false.
        $this->request->method('analyzeString')->willReturn('test-profile');
        $this->request->method('analyzeBool')->willReturn(false);

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::PROFILE_CREATE, null);

        $profileData = $this->extractProfileData($form);

        $this->assertFalse($profileData->isConfigEncryption());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildForm(): UserProfileForm
    {
        return new UserProfileForm($this->application, $this->request);
    }

    private function mockRequestAllBitsTrue(): void
    {
        $this->request->method('analyzeString')->willReturn('test-profile');
        $this->request->method('analyzeBool')->willReturn(true);
    }

    /**
     * Extract the ProfileData blob from the saved UserProfile (dehydrate stores it there).
     */
    private function extractProfileData(UserProfileForm $form): ProfileData
    {
        $userProfile = $form->getItemData();
        /** @var ProfileData $pd */
        $pd = $userProfile->hydrate(ProfileData::class);

        $this->assertInstanceOf(ProfileData::class, $pd);

        return $pd;
    }
}
