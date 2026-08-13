<?php
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

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Security\Models;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Security\Models\Track;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A track is one recorded failed attempt against a login. They are what the brute-force counter
 * adds up, and what the tracks listing shows an administrator who is deciding whether an address
 * should stay locked out.
 *
 * The row is read back through these accessors, so a getter reading the wrong column would lock out
 * the wrong address, or show an unlock time that has already passed.
 */
#[Group('unitary')]
class TrackTest extends UnitaryTestCase
{
    /**
     * A stored attempt, with a distinct value per column.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'id' => 1,
        'userId' => 2,
        'source' => 'login',
        'time' => 1700000000,
        'timeUnlock' => 1700000600,
        'ipv4' => '192.168.1.50',
        'ipv6' => '2001:db8::1',
        'dateTime' => '2023-11-14 22:13:20',
        'dateTimeUnlock' => '2023-11-14 22:23:20',
        'tracked' => 3,
    ];

    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnColumn(string $accessor, string $column)
    {
        self::assertSame(self::ROW[$column], (new Track(self::ROW))->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getId' => 'id',
            'getUserId' => 'userId',
            'getSource' => 'source',
            'getTime' => 'time',
            'getTimeUnlock' => 'timeUnlock',
            'getIpv4' => 'ipv4',
            'getIpv6' => 'ipv6',
            'getDateTime' => 'dateTime',
            'getDateTimeUnlock' => 'dateTimeUnlock',
            'getTracked' => 'tracked',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $column) {
            $cases[$accessor] = [$accessor, $column];
        }

        return $cases;
    }

    /**
     * An attempt is recorded before it is known which address family it came from, and one of the
     * two address columns is always empty — so both have to read as nothing rather than raising.
     */
    #[Test]
    public function anAttemptFromOneAddressFamilyLeavesTheOtherEmpty()
    {
        $fromIpv4 = new Track(['source' => 'login', 'ipv4' => '192.168.1.50']);

        self::assertSame('192.168.1.50', $fromIpv4->getIpv4());
        self::assertNull($fromIpv4->getIpv6());
    }

    /**
     * An attempt that has not been counted yet, or that carries no unlock time, reads as nothing
     * rather than as zero — the counter distinguishes "never tracked" from "tracked zero times".
     */
    #[Test]
    public function anUnrecordedAttemptReadsAsNothing()
    {
        $track = new Track();

        self::assertNull($track->getTracked());
        self::assertNull($track->getTimeUnlock());
        self::assertNull($track->getUserId());
    }
}
