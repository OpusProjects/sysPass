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

namespace SP\Infrastructure\Adapter\In\Web\Forms;

use SP\Core\Messages\NotificationMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Notification\Models\Notification;

use function SP\__u;

/**
 * Class NotificationForm
 *
 * @package SP\Infrastructure\Adapter\In\Web\Forms
 */
final class NotificationForm extends FormBase implements FormInterface
{
    protected ?Notification $notificationData = null;

    /**
     * Validate the form
     *
     * @param  int  $action
     * @param  int|null  $id
     *
     * @return NotificationForm|FormInterface
     * @throws ValidationException
     */
    public function validateFor(int $action, ?int $id = null): FormInterface
    {
        if ($id !== null) {
            $this->itemId = $id;
        }

        switch ($action) {
            case AclActionsInterface::NOTIFICATION_CREATE:
            case AclActionsInterface::NOTIFICATION_EDIT:
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
        $description = NotificationMessage::factory()
            ->addDescription($this->request->analyzeString('notification_description'));

        $userId = $this->request->analyzeInt('notification_user');

        $data = [
            'id' => $this->itemId,
            'type' => $this->request->analyzeString('notification_type'),
            'component' => $this->request->analyzeString('notification_component'),
            'description' => $description->composeHtml(),
            'userId' => $userId,
            'checked' => $this->request->analyzeBool('notification_checkout', false),
        ];

        if ($userId === 0 && $this->context->getUserData()->isAdminApp) {
            $data['onlyAdmin'] = $this->request->analyzeBool('notification_onlyadmin', false);
            $data['sticky'] = $this->request->analyzeBool('notification_sticky', false);
        }

        $this->notificationData = new Notification($data);
    }

    /**
     * @throws ValidationException
     */
    private function checkCommon(): void
    {
        if (!$this->notificationData->getComponent()) {
            throw new ValidationException(__u('A component is needed'));
        }

        if (!$this->notificationData->getType()) {
            throw new ValidationException(__u('A type is needed'));
        }

        if (!$this->notificationData->getDescription()) {
            throw new ValidationException(__u('A description is needed'));
        }

        if (!$this->notificationData->getUserId()
            && !$this->notificationData->isOnlyAdmin()
            && !$this->notificationData->isSticky()) {
            throw new ValidationException(__u('A target  is needed'));
        }
    }

    public function getItemData(): ?Notification
    {
        return $this->notificationData;
    }
}
