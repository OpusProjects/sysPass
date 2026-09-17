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
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Common\Providers\EventsTrait;
use SP\Domain\Common\Providers\Provider;
use SP\Domain\Core\Events\EventReceiver;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Security\Models\Eventlog;
use SP\Application\Security\Ports\EventlogService;
use Throwable;

use function SP\formatStackTrace;
use function SP\processException;

/**
 * Class DatabaseHandler
 */
final class DatabaseHandler extends Provider implements EventReceiver
{
    use EventsTrait;

    private readonly string $events;

    public function __construct(
        Application                        $application,
        private readonly EventlogService   $eventlogService,
        private readonly LanguageInterface $language
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
        $eventName = $event->getName();

        if (str_contains($eventName, 'database.')) {
            return;
        }

        $this->language->setAppLocales();

        $properties = ['action' => $eventName, 'level' => 'INFO'];

        $source = $event->getSource();

        if ($source instanceof Throwable) {
            $properties['level'] = 'ERROR';

            // PHP's default `Exception::__toString()` embeds `getTraceAsString()`, which prints
            // each frame's **argument values** rather than their types — the first 15 characters of
            // every string on the stack. The chains that throw into this sink include the crypt and
            // database layers and the LDAP providers, so a master password, an account password or
            // a bind credential can be an argument on the way to the throw point. This row is
            // readable by anyone whose profile has `isEvl()`, and the event log can be searched and
            // exported.
            //
            // The header is kept as each exception renders it and only the trace is replaced, with
            // `formatStackTrace()` — the same trace reduced to argument *types*, which
            // `processException()` has always used for exactly this reason.
            //
            // Worth knowing while reading this: `SPException::__toString()` overrides PHP's and
            // emits no trace at all, so the application's own exception type was never the leaky
            // one. What reaches here carrying a trace is a `RuntimeException`, a `PDOException`, a
            // `TypeError` or a library's own — which is precisely the set that fails inside crypt
            // and database calls.
            $rendered = (string)$source;
            [$head] = explode("\nStack trace:\n", $rendered, 2);

            $properties['description'] = $head === $rendered
                ? $rendered
                : sprintf("%s\n%s", $head, formatStackTrace($source));
        } else {
            $properties['description'] = $event->getEventMessage()?->composeText();
        }

        try {
            $this->eventlogService->create(new Eventlog($properties));
        } catch (Exception $e) {
            processException($e);
        }

        $this->language->unsetAppLocales();
    }

    /**
     * Returns the events implemented by the observer as a string
     *
     * @return string|null
     */
    public function getEvents(): ?string
    {
        return $this->events;
    }
}
