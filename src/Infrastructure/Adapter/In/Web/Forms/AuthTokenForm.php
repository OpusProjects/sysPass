<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Forms;

use SP\Domain\Auth\Models\AuthToken;
use SP\Application\Auth\Services\AuthToken as AuthTokenService;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__u;

/**
 * Class ApiTokenForm
 *
 * @package SP\Infrastructure\Adapter\In\Web\Forms
 */
final class AuthTokenForm extends FormBase implements FormInterface
{
    protected ?AuthToken $authTokenData = null;
    protected bool       $refresh       = false;

    /**
     * Validate the form
     *
     * @param int $action
     * @param int|null $id
     *
     * @return FormInterface
     * @throws ValidationException
     */
    public function validateFor(int $action, ?int $id = null): FormInterface
    {
        if ($id !== null) {
            $this->itemId = $id;
        }

        switch ($action) {
            case AclActionsInterface::AUTHTOKEN_CREATE:
            case AclActionsInterface::AUTHTOKEN_EDIT:
                $this->analyzeRequestData();
                $this->checkCommon();
                break;
        }

        return $this;
    }

    /**
     * Analyze the HTTP request data
     *
     * @return void
     */
    protected function analyzeRequestData(): void
    {
        $this->refresh = $this->request->analyzeBool('refreshtoken', false);

        $this->authTokenData = new AuthToken(
            [
                'id' => $this->itemId,
                'userId' => $this->request->analyzeInt('users'),
                'actionId' => $this->request->analyzeInt('actions'),
                'hash' => $this->request->analyzeEncrypted('pass'),
            ]
        );
    }

    /**
     * @throws ValidationException
     */
    protected function checkCommon(): void
    {
        // empty() (not `0 === `) so an absent `users`/`actions` field — which
        // analyzeInt() returns as null, and `0 === null` is false — is rejected
        // here instead of reaching the non-nullable int repo/ACL calls as a fatal.
        if (empty($this->authTokenData->getUserId())) {
            throw new ValidationException(__u('User not set'));
        }

        if (empty($this->authTokenData->getActionId())) {
            throw new ValidationException(__u('Action not set'));
        }

        if (empty($this->authTokenData->getHash())
            && (AuthTokenService::isSecuredAction($this->authTokenData->getActionId())
                || $this->isRefresh())
        ) {
            throw new ValidationException(__u('Password cannot be blank'));
        }
    }

    public function isRefresh(): bool
    {
        return $this->refresh;
    }

    public function getItemData(): ?AuthToken
    {
        return $this->authTokenData;
    }
}
