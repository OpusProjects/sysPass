<?php

declare(strict_types=1);
/**
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

namespace SP\Infrastructure\Log\Providers;

use Exception;
use Throwable;
use Psr\Log\LoggerInterface;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Common\Providers\EventsTrait;
use SP\Domain\Common\Providers\Provider;
use SP\Domain\Core\Events\EventReceiver;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;

use function SP\processException;
use function SP\__;
use function SP\getLastCaller;

/**
 * Class LoggerBase
 */
abstract class LoggerBase extends Provider implements EventReceiver
{
    use EventsTrait;

    protected readonly string $events;

    public function __construct(
        Application                          $application,
        protected readonly LoggerInterface $logger,
        protected readonly LanguageInterface $language,
        protected readonly RequestService  $request
    ) {
        parent::__construct($application);

        $configEvents = $this->config->getConfigData()->getLogEvents();

        if (empty($configEvents)) {
            $this->events = $this->parseEventsToRegex(LogInterface::EVENTS_FIXED);
        } else {
            $this->events = $this->parseEventsToRegex(array_merge($configEvents, LogInterface::EVENTS_FIXED));
        }
    }

    /**
     * Update event
     *
     * @param string $eventType Event name
     * @param Event $event Event object
     *
     * @throws InvalidClassException
     */
    public function update(Event $event): void
    {
        $this->language->setAppLocales();

        try {
            $this->writeEvent($event);
        } catch (Throwable $e) {
            // A log that cannot be written must not fail the thing it was reporting on.
            //
            // This receiver is attached on every request, before the install check and regardless
            // of any config flag, and it was the only one of the four with no guard —
            // `DatabaseHandler`, `MailEvent` and `NotificationEvent` all catch and hand to
            // `processException()`. Monolog's `StreamHandler` throws when `var/syspass.log` cannot
            // be opened or appended to, and `notify()` is always called *after* the work it
            // describes, so a full disk turned a completed operation into an error response: the
            // administrator is told a master-password rotation failed when it had already
            // finished, which is the one thing that must never be ambiguous.
            //
            // `processException()` is safe to call from here: `logger()` writes with a suppressed
            // `file_put_contents()` and falls back to `error_log()`, so it does not come back
            // through Monolog.
            //
            // `Throwable` rather than the siblings' `Exception`, because a stream failure can
            // surface as an `Error`; `processException()` accepts either.
            processException($e);
        } finally {
            $this->language->unsetAppLocales();
        }
    }

    /**
     * @throws InvalidClassException
     */
    private function writeEvent(Event $event): void
    {
        $eventName = $event->getName();
        $userLogin = 'N/A';

        if ($this->context->isInitialized()) {
            $userLogin = $this->context->getUserData()->login ?? 'N/A';
        }

        $source = $event->getSource();

        if ($source instanceof Exception) {
            $this->logger->error(
                $eventName,
                $this->formatContext(
                    __($source->getMessage()),
                    $this->request->getClientAddress(true),
                    $userLogin
                )
            );
        } elseif (($eventMessage = $event->getEventMessage()) !== null) {
            $this->logger->debug(
                $eventName,
                $this->formatContext(
                    $eventMessage->composeText(' | '),
                    $this->request->getClientAddress(true),
                    $userLogin
                )
            );
        } else {
            $this->logger->debug(
                $eventName,
                $this->formatContext(
                    'N/A',
                    $this->request->getClientAddress(true),
                    $userLogin
                )
            );
        }
    }

    /**
     * @param string $message
     * @param string $address
     * @param string $user
     *
     * @return array<string, string>
     */
    final protected function formatContext(string $message, string $address, string $user): array
    {
        return [
            'message' => trim($message),
            'user' => trim($user),
            'address' => trim($address),
            'caller' => getLastCaller(4),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getEvents(): ?string
    {
        return $this->events;
    }
}
