<?php
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Account;

use SP\Application\Application;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Infrastructure\Http\Providers\Uri;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;

/**
 * Class SaveRequestController
 */
final class SaveRequestController extends AccountControllerBase
{
    use ItemTrait;

    public function __construct(
        Application                     $application,
        WebControllerHelper             $webControllerHelper,
        private readonly AccountService $accountService,
        private readonly UserService    $userService
    ) {
        parent::__construct($application, $webControllerHelper);
    }

    /**
     * Saves a request action
     *
     * @param int $id Account's ID
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function saveRequestAction(int $id): ActionResponse
    {
        $description = $this->request->analyzeString('description');

        if (empty($description)) {
            throw new ValidationException(__u('A description is needed'));
        }

        $accountView = $this->accountService->getByIdEnriched($id);

        $baseUrl = ($this->configData->getApplicationUrl() ?: $this->uriContext->getWebUri()) .
                   $this->uriContext->getSubUri();

        $deepLink = new Uri($baseUrl);
        $deepLink->addParam('r', $this->acl->getRouteFor(AclActionsInterface::ACCOUNT_VIEW) . '/' . $id);

        $usersId = [$accountView->getUserId(), $accountView->getUserEditId()];

        $this->eventDispatcher->notify(new Event(
            'request.account',
            $this,
            EventMessage::build(__u('Request'))
                            ->addDetail(
                                __u('Requester'),
                                sprintf('%s (%s)', $this->userDto->name, $this->userDto->login)
                            )
                            ->addDetail(__u('Account'), $accountView->getName())
                            ->addDetail(__u('Client'), $accountView->getClientName())
                            ->addDetail(__u('Description'), $description)
                            ->addDetail(
                                __u('Link'),
                                $deepLink->getUriSigned($this->configData->getPasswordSalt() ?? '')
                            )
                            ->addExtra('accountId', $id)
                            ->addExtra('whoId', $this->userDto->id)
                            ->setExtra('userId', $usersId)
                            ->setExtra(
                                'email',
                                array_map(
                                    static fn($value) => $value->getEmail(),
                                    $this->userService->getUserEmailById($usersId)
                                )
                            )
        ));

        return ActionResponse::ok(
            __u('Request done'),
            [
                'itemId' => $id,
                'nextAction' => $this->acl->getRouteFor(AclActionsInterface::ACCOUNT),
            ]
        );
    }
}
