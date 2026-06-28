<?php
declare(strict_types=1);

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigProxy;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
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

        $this->handleProxyConfig($configData, $eventMessage);

        return $this->saveConfig(
            $configData,
            $this->config,
            fn() => $this->eventDispatcher->notify(new Event('save.config.proxy', $this, $eventMessage))
        );
    }

    private function handleProxyConfig(ConfigDataInterface $configData, EventMessage $eventMessage): void
    {
        $proxyEnabled = $this->request->analyzeBool('proxy_enabled', false);
        $proxyServer = $this->request->analyzeString('proxy_server');
        $proxyPort = $this->request->analyzeInt('proxy_port', 8080);
        $proxyUser = $this->request->analyzeString('proxy_user');
        $proxyPass = $this->request->analyzeEncrypted('proxy_pass');

        if ($proxyEnabled && (!$proxyServer || !$proxyPort)) {
            throw new ValidationException(__u('Missing Proxy parameters'));
        }

        if ($proxyEnabled) {
            if ($configData->isProxyEnabled() === false) {
                $eventMessage->addDescription(__u('Proxy enabled'));
            }

            $configData->setProxyEnabled(true);
            $configData->setProxyServer($proxyServer);
            $configData->setProxyPort($proxyPort);
            $configData->setProxyUser($proxyUser);

            if ($proxyPass !== '***') {
                $configData->setProxyPass($proxyPass);
            }
        } elseif ($configData->isProxyEnabled()) {
            $configData->setProxyEnabled(false);

            $eventMessage->addDescription(__u('Proxy disabled'));
        }
    }

    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_GENERAL);
    }
}
