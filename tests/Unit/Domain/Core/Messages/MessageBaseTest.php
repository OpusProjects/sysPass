<?php
declare(strict_types=1);
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

namespace SP\Tests\Unit\Domain\Core\Messages;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Core\Messages\FormatterInterface;
use SP\Domain\Core\Messages\MessageBase;
use SP\Domain\Core\Messages\NotificationMessage;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class MessageBaseTest
 *
 * MessageBase is the title/description/footer bag that NotificationMessage and MailMessage both
 * build their composeHtml()/composeText() on top of (NotificationMessageTest already covers the
 * composed output through NotificationMessage). This suite is about MessageBase's own bag
 * behaviour in isolation: the fluent setters read back through their getters, addDescription()
 * accumulates lines rather than replacing them the way setDescription() does, and factory()
 * builds an instance of whichever concrete subclass it was called on.
 */
#[Group('unitary')]
class MessageBaseTest extends UnitaryTestCase
{
    /**
     * MessageBase itself only declares the title/description/footer bag and its accessors; the
     * abstract getDescription() and the MessageInterface compose*() methods are left for each
     * concrete subclass to decide (NotificationMessage and MailMessage each render differently).
     * This stand-in implements them minimally, just enough to inspect the bag through, so the
     * bag behaviour can be tested without pulling in either concrete subclass's own formatting.
     */
    private function newMessage(): MessageBase
    {
        return new class extends MessageBase {
            public function getDescription(FormatterInterface $formatter, bool $translate = false): string
            {
                return $formatter->formatDescription($this->description, $translate);
            }

            public function composeText(string $delimiter = PHP_EOL): string
            {
                return implode($delimiter, $this->description);
            }

            public function composeHtml(): string
            {
                return implode('', $this->description);
            }
        };
    }

    public function testTitleDefaultsToAnEmptyString(): void
    {
        self::assertSame('', $this->newMessage()->getTitle());
    }

    /**
     * setTitle() has to both return $this (NotificationMessage/MailMessage build messages via a
     * fluent chain) and make the title visible again through getTitle() — MailEvent reads the
     * title back via getTitle() to use it as the email subject.
     */
    public function testSetTitleIsFluentAndReadableThroughGetTitle(): void
    {
        $message = $this->newMessage();
        $result = $message->setTitle('Alert Title');

        self::assertSame($message, $result);
        self::assertSame('Alert Title', $message->getTitle());
    }

    public function testFooterDefaultsToAnEmptyArray(): void
    {
        self::assertSame([], $this->newMessage()->getFooter());
    }

    /**
     * setFooter() is fluent the same way setTitle() is, and getFooter() must hand back exactly
     * what was set — composeHtml()/composeText() both read the footer lines back through this
     * getter/property pair.
     */
    public function testSetFooterIsFluentAndReadableThroughGetFooter(): void
    {
        $message = $this->newMessage();
        $result = $message->setFooter(['Footer A', 'Footer B']);

        self::assertSame($message, $result);
        self::assertSame(['Footer A', 'Footer B'], $message->getFooter());
    }

    /**
     * addDescription() is how MailEvent builds up a message line by line (performed-by, then
     * IP address, then a blank separator line): each call must append, not overwrite, or a
     * message built from several addDescription() calls would only ever keep the last one.
     *
     * @throws Exception
     */
    public function testAddDescriptionAppendsEachCallRatherThanReplacingPriorLines(): void
    {
        $message = $this->newMessage();
        $message->addDescription('first line')->addDescription('second line');

        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->expects(self::once())
                  ->method('formatDescription')
                  ->with(['first line', 'second line'], false)
                  ->willReturn('n/a');

        $message->getDescription($formatter, false);
    }

    /**
     * setDescription(), unlike addDescription(), replaces the whole description wholesale — this
     * is what lets a caller that built up lines with addDescription() (or received a stale
     * message from factory reuse) start over with a fixed set of lines instead of appending onto
     * whatever was already there.
     *
     * @throws Exception
     */
    public function testSetDescriptionReplacesAnyPriorDescriptionLines(): void
    {
        $message = $this->newMessage();
        $message->addDescription('will be discarded');
        $message->setDescription(['only', 'these', 'remain']);

        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->expects(self::once())
                  ->method('formatDescription')
                  ->with(['only', 'these', 'remain'], true)
                  ->willReturn('n/a');

        $message->getDescription($formatter, true);
    }

    /**
     * factory() is `new static()`, not `new self()` — every caller (NotificationMessage::factory(),
     * MailMessage via NotificationForm et al.) relies on getting back an instance of the concrete
     * subclass it was called on, not of MessageBase itself.
     */
    public function testFactoryBuildsAnInstanceOfTheConcreteSubclassItWasCalledOn(): void
    {
        self::assertInstanceOf(NotificationMessage::class, NotificationMessage::factory());
    }
}
