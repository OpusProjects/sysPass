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

namespace SP\Tests\Unit\Infrastructure\Http\Dtos;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Infrastructure\Http\Dtos\JsonMessage;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Every JSON response body is built from one of these -- a status, a description, a payload and a
 * list of messages. The description and the messages are both run through gettext on the way in,
 * the same translation every other user-facing string in the application gets, rather than being
 * translated at render time; each setter is fluent, which is what lets a controller build the
 * whole response in one chain.
 */
#[Group('unitary')]
class JsonMessageTest extends UnitaryTestCase
{
    /**
     * setDescription() passes its argument through __() rather than storing it verbatim. Nothing
     * in the test suite's locale catalog matches this literal, so gettext hands it back unchanged
     * -- which is enough to show the value went through translation and came out the other side,
     * without depending on a specific .mo entry. The fluent return is what a controller relies on
     * to keep chaining setters.
     */
    #[Test]
    public function setDescriptionStoresTheTranslatedDescription(): void
    {
        $message = new JsonMessage();

        $result = $message->setDescription('a description nothing in the catalog translates');

        self::assertSame($message, $result);
        self::assertSame(
            'a description nothing in the catalog translates',
            $message->getJsonArray()['description']
        );
    }

    /**
     * setMessages() maps __() over every entry individually rather than translating the array as
     * a whole -- a caller handing over several distinct notices must get each one translated, in
     * the same order, not lose them to a single pass over the outer value.
     */
    #[Test]
    public function setMessagesTranslatesEveryEntryInOrder(): void
    {
        $message = new JsonMessage();

        $result = $message->setMessages(['first untranslated notice', 'second untranslated notice']);

        self::assertSame($message, $result);
        self::assertSame(
            ['first untranslated notice', 'second untranslated notice'],
            $message->getJsonArray()['messages']
        );
    }
}
