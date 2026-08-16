<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\ProfileHelp;

abstract class ProfileBase extends ControllerBase
{
    protected UserProfileService $profileService;

    public function __construct(
        Application        $application,
        Router             $router,
        ApiService         $apiService,
        AclInterface       $acl,
        UserProfileService $profileService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->profileService = $profileService;

        $this->apiService->setHelpClass(ProfileHelp::class);
    }

    /**
     * The permissions the caller asked for, with anything they do not themselves hold removed.
     *
     * The web form has always done this — see UserProfileForm — and the API did not, so the same
     * delegate could grant through one door what the other refused. An application administrator
     * is not constrained; they hold everything by definition.
     *
     * Hydrating and dehydrating also normalises what is stored: the parameter arrives as a
     * serialized profile from the caller, and it is now written back as one this application
     * produced.
     *
     * @throws \SP\Domain\Core\Exceptions\SPException
     */
    protected function constrainToOwnPermissions(UserProfile $userProfile): ProfileData
    {
        $profileData = $userProfile->hydrate(ProfileData::class) ?? new ProfileData();

        if ($this->context->getUserData()->isAdminApp) {
            return $profileData;
        }

        return $profileData->constrainedTo($this->context->getUserProfile());
    }
}
