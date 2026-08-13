<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Application\User\Ports\UserService;
use SP\Domain\User\Models\User as UserModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\UserHelp;

abstract class UserBase extends ControllerBase
{
    protected UserService $userService;

    public function __construct(
        Application  $application,
        Router       $router,
        ApiService   $apiService,
        AclInterface $acl,
        UserService  $userService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->userService = $userService;

        $this->apiService->setHelpClass(UserHelp::class);
    }

    /**
     * A user row read back from the database carries the credential material — the password hash
     * and its salt, and the user's master password and the key it is sealed with. A token holding
     * USER_VIEW is not a token holding the vault, so none of that goes out in an answer.
     *
     * @return array<string, mixed>
     */
    protected static function withoutCredentials(UserModel $user): array
    {
        return $user->toArray(null, UserModel::CREDENTIAL_COLS, true);
    }
}
