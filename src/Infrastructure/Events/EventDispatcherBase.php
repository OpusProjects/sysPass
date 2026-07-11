<?php
declare(strict_types=1);
/**
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

namespace SP\Infrastructure\Events;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Events\EventReceiver;
use SplObjectStorage;

use function SP\logger;

/**
 * Class EventDispatcherBase
 *
 * @package SP\Infrastructure\Events
 */
abstract class EventDispatcherBase implements EventDispatcherInterface
{
    /**
     * @var SplObjectStorage<EventReceiver, null>
     */
    protected SplObjectStorage $receivers;

    final public function __construct()
    {
        $this->receivers = new SplObjectStorage();
    }

    /**
     * Check whether an EventReceiver is attached
     *
     * @param EventReceiver $receiver
     * @return bool
     */
    final public function has(EventReceiver $receiver): bool
    {
        return $this->receivers->offsetExists($receiver);
    }

    /**
     * Attach an EventReceiver
     *
     * @param EventReceiver $receiver
     * @return void
     */
    final public function attach(EventReceiver $receiver): void
    {
        logger('Attach: ' . $receiver::class);

        $this->receivers->offsetSet($receiver);
    }

    /**
     * Detach an EventReceiver
     *
     * @param EventReceiver $receiver
     * @return void
     */
    final public function detach(EventReceiver $receiver): void
    {
        logger('Detach: ' . $receiver::class);

        $this->receivers->offsetUnset($receiver);
    }

    /**
     * Notify to receivers
     *
     * @param string $eventName event's name
     * @param Event $event event's object
     *
     */
    final public function notify(Event $event): void
    {
        $eventName = $event->getName();

        /** @var EventReceiver $receiver */
        foreach ($this->receivers as $receiver) {
            $events = $receiver->getEvents();

            if ($events === '*' || preg_match(sprintf('/%s/i', $events), $eventName)) {
                $receiver->update($event);
            }
        }
    }
}
