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

use SP\Infrastructure\Messages\HtmlFormatter;
use SP\Infrastructure\Messages\TextFormatter;
use SP\Domain\Core\Messages\FormatterInterface;
use SP\Domain\Core\Messages\MessageInterface;

/**
 * Class EventMessage
 *
 * @template T
 */
class EventMessage implements MessageInterface
{
    /**
     * @var array<array{string, string}> Action details in the format "detail : description"
     */
    private array $details            = [];
    private int   $descriptionCounter = 0;
    private int   $detailsCounter     = 0;
    /**
     * @var string[]
     */
    private array $description        = [];
    /**
     * @var array<string, array<mixed>>
     */
    private array $extra              = [];

    /**
     * @param string|null $description
     * @return EventMessage<mixed>
     */
    public static function build(?string $description = null): EventMessage
    {
        $eventMessage = new self();

        if ($description) {
            $eventMessage->addDescription($description);
        }

        return $eventMessage;
    }

    /**
     * Sets the description of the performed action
     *
     * @return EventMessage<T>
     */
    public function addDescription(string $description = ''): EventMessage
    {
        $this->description[] = $this->formatString($description);

        $this->descriptionCounter++;

        return $this;
    }

    /**
     * Formats a string for storing in the log
     */
    private function formatString(string $string): string
    {
        return strip_tags($string);
    }

    /**
     * Sets the details of the performed action
     *
     * @return EventMessage<T>
     */
    public function addDetail(string $key, string|int|null $value): EventMessage
    {
        if (empty($value) || empty($key)) {
            return $this;
        }

        $this->details[] = [$this->formatString($key), $this->formatString((string)$value)];

        $this->detailsCounter++;

        return $this;
    }

    /**
     * Composes a message in text format
     */
    public function composeText(string $delimiter = PHP_EOL): string
    {
        if ($this->descriptionCounter === 0 && $this->detailsCounter === 0) {
            return '';
        }

        $formatter = new TextFormatter($delimiter);

        return implode(
            $delimiter,
            array_filter([
                             $this->getDescription($formatter, true),
                             $this->getDetails($formatter, true)
                         ])
        );
    }

    /**
     * Returns the description of the performed action
     */
    public function getDescription(
        FormatterInterface $formatter,
        bool               $translate
    ): string {
        if ($this->descriptionCounter === 0) {
            return '';
        }

        return $formatter->formatDescription($this->description, $translate);
    }

    /**
     * Returns the details of the performed action
     */
    public function getDetails(
        FormatterInterface $formatter,
        bool               $translate = false
    ): string {
        if ($this->detailsCounter === 0) {
            return '';
        }

        return $formatter->formatDetail($this->details, $translate);
    }

    /**
     * Composes a message in HTML format
     */
    public function composeHtml(): string
    {
        $formatter = new HtmlFormatter();

        $message = '<div class="event-message">';
        $message .= '<div class="event-description">' . $this->getDescription($formatter, true) . '</div>';
        $message .= '<div class="event-details">' . $this->getDetails($formatter, true) . '</div>';
        $message .= '</div>';

        return $message;
    }

    public function getDescriptionCounter(): int
    {
        return $this->descriptionCounter;
    }

    public function getDetailsCounter(): int
    {
        return $this->detailsCounter;
    }

    /**
     * @param string $type
     * @return array<mixed>|null
     */
    public function getExtra(string $type): array|null
    {
        return $this->extra[$type] ?? null;
    }

    /**
     * @param string $type
     * @param array<mixed> $data
     * @return EventMessage<T>
     */
    public function setExtra(string $type, array $data): EventMessage
    {
        if (isset($this->extra[$type])) {
            $this->extra[$type] = array_merge($this->extra[$type], $data);
        } else {
            $this->extra[$type] = $data;
        }

        return $this;
    }

    /**
     * Extra data are stored as an array of values per key, thus each key is unique
     *
     * @param class-string<T> $type
     * @param array<T>|string|int|bool $data
     * @return EventMessage<T>
     */
    public function addExtra(string $type, array|string|int|bool|null $data): EventMessage
    {
        if (!isset($this->extra[$type]) || !in_array($data, $this->extra[$type], true)) {
            $this->extra[$type][] = $data;
        }

        return $this;
    }
}
