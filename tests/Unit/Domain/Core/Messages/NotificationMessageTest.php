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
use SP\Domain\Core\Messages\FormatterInterface;
use SP\Domain\Core\Messages\NotificationMessage;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class NotificationMessageTest
 *
 * NotificationMessage composes the actual text a user or operator reads: composeHtml() feeds the
 * in-app notifications panel (NotificationEvent) and admin-authored notices (NotificationForm),
 * and composeText() feeds outgoing email bodies (MailEvent) and log entries (LoggerBase). A wrong
 * separator or a stray/missing block here is not a cosmetic bug — it changes what shows up in a
 * mailbox or on screen, so these tests assert on the exact composed output rather than just that
 * a string comes back.
 */
#[Group('unitary')]
class NotificationMessageTest extends UnitaryTestCase
{
    /**
     * The title, description and footer are optional, independent blocks. When all three are set
     * (the common case for an admin-authored notice), the composed HTML must contain each block,
     * in order, with the description lines and footer lines each rendered as their own element —
     * otherwise a multi-line notice collapses into an unreadable run of text.
     */
    public function testComposeHtmlIncludesTitleDescriptionAndFooterInOrder(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Alert Title')
            ->setDescription(['Line one', 'Line two'])
            ->setFooter(['Footer A', 'Footer B']);

        self::assertSame(
            '<div class="notice-message" style="font-family: Helvetica, Arial, sans-serif">'
            . '<h3>Alert Title</h3>'
            . '<div class="notice-description">'
            . '<div class="description-line">Line one</div>'
            . '<div class="description-line">Line two</div>'
            . '</div>'
            . '<footer>Footer A<br>Footer B</footer>'
            . '</div>',
            $message->composeHtml()
        );
    }

    /**
     * A notification built without a title (e.g. NotificationForm's raw-description path) must
     * not render an empty '<h3></h3>' — the title block is conditional on the title actually
     * being set, not just present as an empty string.
     */
    public function testComposeHtmlOmitsTheTitleBlockWhenNoTitleIsSet(): void
    {
        $message = NotificationMessage::factory()
            ->setDescription(['Only a description'])
            ->setFooter(['Only a footer']);

        $html = $message->composeHtml();

        self::assertStringNotContainsString('<h3>', $html);
        self::assertStringContainsString('<div class="description-line">Only a description</div>', $html);
        self::assertStringContainsString('<footer>Only a footer</footer>', $html);
    }

    /**
     * With no description lines, the 'notice-description' wrapper must not appear at all — a
     * present-but-empty wrapper would render as blank vertical space in the notification.
     */
    public function testComposeHtmlOmitsTheDescriptionBlockWhenDescriptionIsEmpty(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Title only')
            ->setFooter(['Footer only']);

        $html = $message->composeHtml();

        self::assertStringNotContainsString('notice-description', $html);
        self::assertStringContainsString('<h3>Title only</h3>', $html);
    }

    /**
     * With no footer lines, the '<footer>' element must not appear — email/notification templates
     * rely on its absence to tell "no footer configured" apart from "footer is blank text".
     */
    public function testComposeHtmlOmitsTheFooterBlockWhenFooterIsEmpty(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Title only')
            ->setDescription(['Description only']);

        $html = $message->composeHtml();

        self::assertStringNotContainsString('<footer>', $html);
        self::assertStringContainsString('<h3>Title only</h3>', $html);
    }

    /**
     * A message nobody filled in (all three blocks empty) must still compose to just the bare
     * wrapper, not throw or produce stray markup — this is what NotificationMessage::factory()
     * returns before any setter is called.
     */
    public function testComposeHtmlIsJustTheEmptyWrapperWhenNothingIsSet(): void
    {
        $message = NotificationMessage::factory();

        self::assertSame(
            '<div class="notice-message" style="font-family: Helvetica, Arial, sans-serif"></div>',
            $message->composeHtml()
        );
    }

    /**
     * composeText() is what MailEvent sends as the plain-text alternative of a notification email
     * (with '<br>' as the delimiter) and what LoggerBase writes to the log (with ' | '): the three
     * blocks must be joined by the caller-supplied delimiter, and description/footer lines must be
     * joined by that same delimiter internally, or a log line/email body reads as one run-on string.
     */
    public function testComposeTextJoinsTitleDescriptionAndFooterWithTheGivenDelimiter(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Alert Title')
            ->setDescription(['Line one', 'Line two'])
            ->setFooter(['Footer A', 'Footer B']);

        self::assertSame(
            'Alert Title|Line one|Line two|Footer A|Footer B',
            $message->composeText('|')
        );
    }

    /**
     * Callers that don't pass a delimiter (e.g. DatabaseHandler logging an event's plain-text
     * description) get PHP_EOL between blocks, not some other implicit separator.
     */
    public function testComposeTextUsesPhpEolAsTheDefaultDelimiter(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Title')
            ->setDescription(['Description']);

        self::assertSame('Title' . PHP_EOL . 'Description', $message->composeText());
    }

    /**
     * Without a title, the composed text must not start with a stray leading delimiter — a caller
     * appending this into a log line or email body would otherwise get an empty first segment.
     */
    public function testComposeTextOmitsTheTitleWhenNoTitleIsSet(): void
    {
        $message = NotificationMessage::factory()
            ->setDescription(['Description only']);

        self::assertSame('Description only', $message->composeText('|'));
    }

    /**
     * An empty description must not contribute an empty segment between the title and footer —
     * otherwise every title-plus-footer notification would carry a stray double delimiter.
     */
    public function testComposeTextOmitsDescriptionWhenEmpty(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Title')
            ->setFooter(['Footer']);

        self::assertSame('Title|Footer', $message->composeText('|'));
    }

    /**
     * An empty footer must not contribute a trailing delimiter — a caller that always appends
     * composeText() output onto something else would otherwise get a dangling separator.
     */
    public function testComposeTextOmitsFooterWhenEmpty(): void
    {
        $message = NotificationMessage::factory()
            ->setTitle('Title')
            ->setDescription(['Description']);

        self::assertSame('Title|Description', $message->composeText('|'));
    }

    /**
     * A message with nothing set composes to an empty string, not a string full of stray
     * delimiters — this is the state a freshly-factoried NotificationMessage is in.
     */
    public function testComposeTextIsEmptyWhenNothingIsSet(): void
    {
        self::assertSame('', NotificationMessage::factory()->composeText('|'));
    }

    /**
     * getDescription() is the seam composeHtml() uses to render the description block; it must
     * forward both the stored description lines and the caller's translate flag to the formatter
     * unchanged; get either wrong and a caller either sees raw i18n keys instead of text, or gets
     * text translated when it explicitly asked not to (e.g. because it already translated it).
     */
    public function testGetDescriptionForwardsDescriptionAndTranslateFlagToTheFormatter(): void
    {
        $description = ['Raw line one', 'Raw line two'];

        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->expects($this->once())
                  ->method('formatDescription')
                  ->with($description, false)
                  ->willReturn('formatted-output');

        $message = NotificationMessage::factory()->setDescription($description);

        self::assertSame('formatted-output', $message->getDescription($formatter, false));
    }
}
