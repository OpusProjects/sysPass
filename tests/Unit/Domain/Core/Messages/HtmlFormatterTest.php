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
use SP\Domain\Core\Messages\HtmlFormatter;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class HtmlFormatterTest
 *
 * HtmlFormatter is what NotificationMessage, MailMessage and EventMessage all reach for to
 * turn their stored description lines and key/value details into the HTML that lands in the
 * notifications panel and in outgoing emails (NotificationMessageTest covers those composed
 * wrappers end to end). This suite isolates the formatter itself: how a description with
 * several lines and one with none each render, how a detail value that looks like a link is
 * turned into an anchor (and truncated when it is long), and how a plain value is left alone.
 */
#[Group('unitary')]
class HtmlFormatterTest extends UnitaryTestCase
{
    private HtmlFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new HtmlFormatter();
    }

    /**
     * Each description line becomes its own "description-line" div, back to back with no
     * separator — composeHtml() relies on that to render a multi-line description as stacked
     * blocks rather than one run-together paragraph.
     */
    public function testFormatDescriptionRendersEachLineInItsOwnDiv(): void
    {
        self::assertSame(
            '<div class="description-line">Line one</div><div class="description-line">Line two</div>',
            $this->formatter->formatDescription(['Line one', 'Line two'], false)
        );
    }

    /**
     * A message with no description lines at all (e.g. a notification composed with only a
     * title) must format to an empty string rather than an empty wrapper — composeHtml() checks
     * emptiness of the source array before wrapping the result, so a stray non-empty return here
     * would defeat that check.
     */
    public function testFormatDescriptionWithNoLinesReturnsAnEmptyString(): void
    {
        self::assertSame('', $this->formatter->formatDescription([], false));
    }

    /**
     * Several details render as one "detail" block per pair, each with a left (label) and right
     * (value) span — this is what an event's key/value details (e.g. "IP Address: 1.2.3.4")
     * look like once formatted for the notifications panel or an HTML email.
     */
    public function testFormatDetailRendersEachPairAsALabelValueBlock(): void
    {
        self::assertSame(
            '<div class="detail"><span class="detail-left">Login</span>'
            . '<span class="detail-right">admin</span></div>'
            . '<div class="detail"><span class="detail-left">IP Address</span>'
            . '<span class="detail-right">127.0.0.1</span></div>',
            $this->formatter->formatDetail([['Login', 'admin'], ['IP Address', '127.0.0.1']], false)
        );
    }

    /**
     * No details at all must format to an empty string, the same way an empty description does —
     * EventMessage::getDetails() and composeHtml() both depend on this to omit the details block
     * entirely rather than rendering an empty wrapper.
     */
    public function testFormatDetailWithNoDetailsReturnsAnEmptyString(): void
    {
        self::assertSame('', $this->formatter->formatDetail([], false));
    }

    /**
     * A detail value that is itself a plain http(s) URL is turned into a clickable link rather
     * than shown as inert text — e.g. a "Reset URL" detail in an account-notification email.
     * A short URL (at or under 30 characters) is used as its own link text, unshortened.
     */
    public function testFormatDetailBuildsAnAnchorWhenTheValueIsAShortHttpUrl(): void
    {
        $url = 'https://example.com/reset';

        self::assertSame(
            '<div class="detail"><span class="detail-left">Reset URL</span>'
            . '<span class="detail-right"><a href="' . $url . '">' . $url . '</a></span></div>',
            $this->formatter->formatDetail([['Reset URL', $url]], false)
        );
    }

    /**
     * A URL longer than 30 characters keeps its full address as the href (the link still goes
     * to the right place) but is shown truncated to 30 characters plus an ellipsis — otherwise a
     * long, deep link would blow out the width of the notification/detail row.
     */
    public function testFormatDetailTruncatesTheVisibleTextOfALongUrlButKeepsTheFullHref(): void
    {
        $url = 'https://example.com/' . str_repeat('a', 40);
        $expectedVisibleText = trim(mb_substr($url, 0, 30)) . '...';

        self::assertSame(
            '<div class="detail"><span class="detail-left">Link</span>'
            . '<span class="detail-right"><a href="' . $url . '">' . $expectedVisibleText . '</a></span></div>',
            $this->formatter->formatDetail([['Link', $url]], false)
        );
    }

    /**
     * A value that does not look like a URL (the common case — most details are things like
     * usernames, IP addresses or counts) is left as plain text rather than being wrapped in a
     * link that would go nowhere useful.
     */
    public function testFormatDetailLeavesANonUrlValueAsPlainText(): void
    {
        self::assertSame(
            '<div class="detail"><span class="detail-left">Browser</span>'
            . '<span class="detail-right">Firefox 128</span></div>',
            $this->formatter->formatDetail([['Browser', 'Firefox 128']], false)
        );
    }
}
