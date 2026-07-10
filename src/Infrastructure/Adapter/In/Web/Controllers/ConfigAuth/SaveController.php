<?php
declare(strict_types=1);

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigAuth;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Traits\ConfigTrait;

use function SP\__u;

final class SaveController extends SimpleControllerBase
{
    use ConfigTrait;

    #[Action(ResponseType::JSON)]
    public function saveAction(): ActionResponse
    {
        $configData = $this->config->getConfigData();
        $eventMessage = EventMessage::build();

        $this->handleAuthConfig($configData, $eventMessage);

        return $this->saveConfig(
            $configData,
            $this->config,
            fn() => $this->eventDispatcher->notify(new Event('save.config.auth', $this, $eventMessage))
        );
    }

    /**
     * @param EventMessage<mixed> $eventMessage
     */
    private function handleAuthConfig(ConfigDataInterface $configData, EventMessage $eventMessage): void
    {
        $authBasicEnabled = $this->request->analyzeBool('authbasic_enabled', false);
        $authBasicAutologinEnabled = $this->request->analyzeBool('authbasicautologin_enabled', false);
        $authBasicDomain = $this->request->analyzeString('authbasic_domain');
        $authSsoDefaultGroup = $this->request->analyzeInt('sso_defaultgroup');
        $authSsoDefaultProfile = $this->request->analyzeInt('sso_defaultprofile');

        if ($authBasicEnabled) {
            if ($configData->isAuthBasicEnabled() === false) {
                $eventMessage->addDescription(__u('Auth Basic enabled'));
            }

            $configData->setAuthBasicEnabled(true);
            $configData->setAuthBasicAutoLoginEnabled($authBasicAutologinEnabled);
            $configData->setAuthBasicDomain($authBasicDomain);
            $configData->setSsoDefaultGroup($authSsoDefaultGroup);
            $configData->setSsoDefaultProfile($authSsoDefaultProfile);
        } elseif ($configData->isAuthBasicEnabled()) {
            $configData->setAuthBasicEnabled(false);
            $configData->setAuthBasicAutoLoginEnabled(false);

            $eventMessage->addDescription(__u('Auth Basic disabled'));
        }
    }

    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_GENERAL);
    }
}
