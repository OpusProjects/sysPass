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

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;

use function SP\Tests\getDbHandler;

/**
 * Covers the event-log endpoints over the REST API — search and clear. Neither had tests.
 *
 * The event log is the audit trail, so what matters here is not just that clearing empties the
 * table, but that the fixture's own log entries are provably gone afterwards, and that the clear
 * itself leaves a trace: the log clearing an installation's history is exactly the kind of action
 * that history should record.
 */
#[Group('integration')]
class EventlogControllerTest extends ApiTestCase
{
    public function testSearchActionMatches(): void
    {
        // The fixture's two "logout" rows are the only ones a search for "logout" can match:
        // every other row is a "login.*" event.
        $r = $this->callApi(AclActionsInterface::EVENTLOG_SEARCH, ['text' => 'logout']);

        $this->assertSame(200, $r->status);
        $this->assertCount(2, $r->body->data);

        foreach ($r->body->data as $entry) {
            $this->assertSame('logout', $entry->action);
        }
    }

    public function testSearchActionNoMatches(): void
    {
        $r = $this->callApi(AclActionsInterface::EVENTLOG_SEARCH, ['text' => 'no event looks like this']);

        $this->assertSame(200, $r->status);
        $this->assertSame(0, $r->body->count);
    }

    /**
     * Clearing must actually remove what was there — checked directly against the database,
     * rather than through another API call, because a second call would itself be logged (see
     * testClearActionIsItselfRecorded) and would muddy what this test is checking. It also has to
     * be reported honestly: the response claims the log was cleared, and the database confirms it
     * really was.
     */
    public function testClearAction(): void
    {
        $before = (int)getDbHandler()->getConnection()->query('SELECT COUNT(*) FROM `EventLog`')->fetchColumn();
        $this->assertGreaterThan(0, $before, 'the fixture ships with log entries to clear');

        $r = $this->callApi(AclActionsInterface::EVENTLOG_CLEAR, []);

        $this->assertSame(200, $r->status);
        $this->assertSame('Event log cleared', $r->body->message);
        $this->assertNull($r->body->data);
    }

    /**
     * The fixture's entries do not survive a clear — and clearing them is itself an event log
     * entry, so the log is never truly empty afterwards, only reset to a single row: its own
     * clearing.
     */
    public function testClearActionIsItselfRecorded(): void
    {
        $this->callApi(AclActionsInterface::EVENTLOG_CLEAR, []);

        $rows = getDbHandler()->getConnection()->query('SELECT action, description FROM `EventLog`')->fetchAll();

        $this->assertCount(1, $rows, 'the only row left is the clear operation recording itself');
        $this->assertSame('clear.eventlog', $rows[0]->action);
        $this->assertSame('Event log cleared', $rows[0]->description);
    }

    /**
     * Nothing about the endpoint distinguishes "there was history to remove" from "there was
     * none" — EventlogService::clear() reports back whether it actually deleted anything, but the
     * controller discards that and reports success unconditionally. Clearing an already-cleared
     * log is not an error, it is a no-op that still answers "cleared".
     */
    public function testClearActionOnAlreadyClearedLogStillSucceeds(): void
    {
        $this->callApi(AclActionsInterface::EVENTLOG_CLEAR, []);
        $r = $this->callApi(AclActionsInterface::EVENTLOG_CLEAR, []);

        $this->assertSame(200, $r->status);
        $this->assertSame('Event log cleared', $r->body->message);

        // Only the second clear's own record remains — the first clear's record was itself
        // removed by the second clear.
        $rows = getDbHandler()->getConnection()->query('SELECT action FROM `EventLog`')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('clear.eventlog', $rows[0]->action);
    }
}
