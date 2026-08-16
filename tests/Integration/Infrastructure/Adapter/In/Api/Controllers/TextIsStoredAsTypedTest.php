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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;

use function SP\Tests\getDbHandler;

/**
 * What was typed is what is stored, and what is stored is what comes back.
 *
 * The application used to escape HTML on the way in — `Filter::getString()` ran
 * `htmlspecialchars()` over every web form field and every REST parameter — so a category created
 * as `Q&A <b>notes</b>` was *stored*, and answered, as `Q&amp;A &lt;b&gt;notes&lt;/b&gt;`. A
 * request is not a page: the same value goes out to a JSON client, into an export, into a mail,
 * into a download's filename and into a `LIKE` comparison, and the entities were wrong in every
 * one of them. The templates then escaped it a second time, so the interface showed `Q&amp;A`.
 *
 * Escaping belongs where a value is rendered, and that is where it now happens. This is the check
 * on the other half of that: nothing rewrites the value on the way in.
 *
 * It is written against the REST API because that is the shortest path to a value that has been
 * through the request, into the database and back out again — the round trip is the assertion. The
 * row is read directly as well, so a store that escapes and a read that decodes could not cancel
 * out and pass.
 */
#[Group('integration')]
class TextIsStoredAsTypedTest extends ApiTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function textProvider(): array
    {
        return [
            'an ampersand' => ['Q&A'],
            'markup' => ['<b>notes</b>'],
            'both, as they were reported' => ['Q&A <b>notes</b>'],
            'quotes' => ['the "quoted" and \'apostrophed\' one'],
            'an entity somebody typed' => ['the &amp; entity, written out'],
            'a comparison' => ['a < b && c > d'],
            'not ASCII' => ['Café — ünïcode ☕'],
        ];
    }

    /**
     * @throws \Exception
     */
    #[Test]
    #[DataProvider('textProvider')]
    public function aNameComesBackAsItWasSent(string $name): void
    {
        $created = $this->callApi(AclActionsInterface::CATEGORY_CREATE, ['name' => $name]);

        self::assertSame(201, $created->status);
        self::assertSame($name, $created->body->data->name, 'the create echoed something else');

        $view = $this->callApi(AclActionsInterface::CATEGORY_VIEW, ['id' => $created->body->itemId]);

        self::assertSame($name, $view->body->data->data->name, 'reading it back gave something else');
    }

    /**
     * And the row itself holds it, so the two assertions above cannot both be satisfied by a store
     * that escapes and a read that decodes.
     *
     * @throws \Exception
     */
    #[Test]
    public function theStoredRowHoldsTheTextItself(): void
    {
        $name = 'Q&A <b>notes</b>';

        $created = $this->callApi(AclActionsInterface::CATEGORY_CREATE, ['name' => $name]);

        $statement = getDbHandler()->getConnection()->prepare('SELECT `name` FROM `Category` WHERE `id` = ?');
        $statement->execute([$created->body->itemId]);

        self::assertSame($name, $statement->fetchColumn());
    }

    /**
     * A description is the longer free-text field beside it, and takes the same route.
     *
     * @throws \Exception
     */
    #[Test]
    public function aDescriptionIsStoredAsTypedToo(): void
    {
        $description = "line one & <two>\nline \"three\"";

        $created = $this->callApi(
            AclActionsInterface::CATEGORY_CREATE,
            ['name' => 'a category', 'description' => $description]
        );

        $view = $this->callApi(AclActionsInterface::CATEGORY_VIEW, ['id' => $created->body->itemId]);

        self::assertSame($description, $view->body->data->data->description);
    }

    /**
     * Surrounding whitespace is still taken off — that part was never about escaping, and a name
     * somebody pasted with a trailing newline should not sort differently from the same name typed
     * by hand.
     *
     * @throws \Exception
     */
    #[Test]
    public function surroundingWhitespaceIsStillRemoved(): void
    {
        $created = $this->callApi(AclActionsInterface::CATEGORY_CREATE, ['name' => "  padded name \n"]);

        self::assertSame('padded name', $created->body->data->name);
    }
}
