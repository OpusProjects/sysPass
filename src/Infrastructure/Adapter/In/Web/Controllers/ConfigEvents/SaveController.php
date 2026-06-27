<?php
declare(strict_types=1);

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigEvents;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Config\Services\ConfigUtil;
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

        $this->handleEventsConfig($configData, $eventMessage);

        return $this->saveConfig(
            $configData,
            $this->config,
            fn() => $this->eventDispatcher->notify(new Event('save.config.events', $this, $eventMessage))
        );
    }

    private function handleEventsConfig(ConfigDataInterface $configData, EventMessage $eventMessage): void
    {
        $logEnabled = $this->request->analyzeBool('log_enabled', false);
        $syslogEnabled = $this->request->analyzeBool('syslog_enabled', false);
        $remoteSyslogEnabled = $this->request->analyzeBool('remotesyslog_enabled', false);
        $syslogServer = $this->request->analyzeString('remotesyslog_server');
        $syslogPort = $this->request->analyzeInt('remotesyslog_port', 0);

        $configData->setLogEnabled($logEnabled);
        $configData->setLogEvents(
            $this->request->analyzeArray(
                'log_events',
                fn($items) => ConfigUtil::eventsAdapter($items),
                []
            )
        );

        $configData->setSyslogEnabled($syslogEnabled);

        if ($remoteSyslogEnabled) {
            if (!$syslogServer || !$syslogPort) {
                throw new ValidationException(__u('Missing remote syslog parameters'));
            }

            $configData->setSyslogRemoteEnabled(true);
            $configData->setSyslogServer($syslogServer);
            $configData->setSyslogPort($syslogPort);

            if ($configData->isSyslogRemoteEnabled() === false) {
                $eventMessage->addDescription(__u('Remote syslog enabled'));
            }
        } elseif ($configData->isSyslogRemoteEnabled()) {
            $configData->setSyslogRemoteEnabled(false);

            $eventMessage->addDescription(__u('Remote syslog disabled'));
        }
    }

    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_GENERAL);
    }
}
