<?php
declare(strict_types=1);

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigAuth;

use SP\Application\User\Ports\UserProfileService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigBackupService;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
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

    public function __construct(
        Application            $application,
        SimpleControllerHelper $simpleControllerHelper,
        private readonly ConfigBackupService $configBackup,
        private readonly UserProfileService $userProfileService
    ) {
        parent::__construct($application, $simpleControllerHelper);
    }

    #[Action(ResponseType::JSON)]
    public function saveAction(): ActionResponse
    {
        $configData = $this->config->getConfigData();
        $eventMessage = EventMessage::build();

        $this->handleAuthConfig($configData, $eventMessage);

        return $this->saveConfig(
            $configData,
            $this->config,
            $this->configBackup,
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
            // The same authorisation question ConfigLdap\SaveController asks about its own
            // defaults, and this door asked neither half of it. These decide the group and profile
            // every user auto-provisioned on their first SSO sign-in receives —
            // User::createOnLogin() reads them — so setting them is a user-management decision
            // reached here with isConfigGeneral(), an independent bit from the isMgmUsers() that
            // USER_CREATE answers. Only when they change, so an administrator of the rest of this
            // page can still save it.
            if ($authSsoDefaultGroup !== $configData->getSsoDefaultGroup()
                || $authSsoDefaultProfile !== $configData->getSsoDefaultProfile()
            ) {
                $this->checkAccess(AclActionsInterface::USER_CREATE);
                $this->userProfileService->assertAssignableBy($authSsoDefaultProfile ?? 0);
            }

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
