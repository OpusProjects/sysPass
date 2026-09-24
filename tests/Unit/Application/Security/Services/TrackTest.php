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

namespace SP\Tests\Unit\Application\Security\Services;

use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Domain\Security\Models\Track as TrackModel;
use SP\Domain\Security\Ports\TrackRepository;
use SP\Application\Security\Services\Track;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class TrackTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class TrackTest extends UnitaryTestCase
{

    private TrackRepository|MockObject $trackRepository;
    private RequestService|MockObject  $request;
    private Track                      $track;

    public function testSearch()
    {
        $itemSearchData = new ItemSearchDto('test');

        $this->trackRepository
            ->expects($this->once())
            ->method('search')
            ->with($itemSearchData, self::anything())
            ->willReturn(new QueryResult());

        $this->track->search($itemSearchData);
    }

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testCheckTracking()
    {
        $trackRequest = $this->getTrackRequest();
        $track = new TrackModel([
                                    'ipv4' => $trackRequest->getIpv4(),
                                    'ipv6' => $trackRequest->getIpv6(),
                                    'source' => $trackRequest->getSource(),
                                    'userId' => $trackRequest->getUserId(),
                                    'time' => $trackRequest->getTime()
                                ]);

        $this->trackRepository
            ->expects($this->once())
            ->method('getTracksForClientFromTime')
            ->with($track)
            ->willReturn(new QueryResult([1]));

        $this->assertFalse($this->track->checkTracking($trackRequest));
    }

    /**
     * The attempt is recorded before the others are counted.
     *
     * Counting first and recording only once an attempt had failed left the whole attempt — a
     * bcrypt verify on a login — between the guard and the change it guards, so a burst of attempts
     * sent together all counted the same rows and all passed. With this attempt's row in place
     * first, whichever of two attempts counts second sees the other one.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testTheAttemptIsRecordedBeforeTheOthersAreCounted()
    {
        $calls = [];

        $this->trackRepository
            ->expects($this->once())
            ->method('add')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'add';

                return new QueryResult(null, 0, 7);
            });

        $this->trackRepository
            ->expects($this->once())
            ->method('getTracksForClientFromTime')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'count';

                return new QueryResult([1]);
            });

        $this->track->checkTracking($this->getTrackRequest());

        $this->assertSame(['add', 'count'], $calls);
    }

    /**
     * And its own row is not held against it: nine earlier attempts and this one is still under the
     * limit of ten, as it was when the count came first.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testTheAttemptsOwnRowDoesNotCountAgainstIt()
    {
        $this->trackRepository->method('add')->willReturn(new QueryResult(null, 0, 7));
        $this->trackRepository
            ->method('getTracksForClientFromTime')
            ->willReturn(new QueryResult(range(1, 10)));

        $this->assertFalse($this->track->checkTracking($this->getTrackRequest()));
    }

    /**
     * Releasing withdraws exactly the rows this request's checks recorded, and only once.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testReleaseWithdrawsWhatTheChecksRecorded()
    {
        $lastIds = [41, 42];

        $this->trackRepository
            ->method('add')
            ->willReturnCallback(function () use (&$lastIds) {
                return new QueryResult(null, 0, array_shift($lastIds));
            });
        $this->trackRepository->method('getTracksForClientFromTime')->willReturn(new QueryResult([1]));

        $deleted = [];

        $this->trackRepository
            ->expects($this->once())
            ->method('deleteByIdBatch')
            ->willReturnCallback(function (array $ids) use (&$deleted) {
                $deleted[] = $ids;

                return new QueryResult();
            });

        $this->track->checkTracking($this->getTrackRequest());
        $this->track->checkTracking($this->getTrackRequest());

        $this->track->release();
        $this->track->release();

        $this->assertSame([[41, 42]], $deleted);
    }

    /**
     * A release that fails is logged rather than raised: it runs as a request finishes, and
     * throwing there would replace the answer the attempt earned.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testAFailedReleaseDoesNotReplaceTheAnswer()
    {
        $this->trackRepository->method('add')->willReturn(new QueryResult(null, 0, 7));
        $this->trackRepository->method('getTracksForClientFromTime')->willReturn(new QueryResult([1]));
        $this->trackRepository
            ->expects($this->once())
            ->method('deleteByIdBatch')
            ->willThrowException(new RuntimeException('test'));

        $this->track->checkTracking($this->getTrackRequest());
        $this->track->release();
    }

    /**
     * @return TrackRequest
     * @throws InvalidArgumentException
     */
    private function getTrackRequest(): TrackRequest
    {
        return new TrackRequest(time(), 'test', self::$faker->ipv4(), self::$faker->randomNumber(3));
    }

    /**
     * A blocked client is told so at once, however many attempts stand against it.
     *
     * The check used to sleep for a quarter of a second per attempt recorded before returning, in
     * the request. Every caller throws the moment it returns true, so that changed nothing about
     * what the client was told — it only held a worker, for a time the client itself chose by
     * failing more often. The count is unbounded inside the ten-minute window, so a thousand
     * attempts from one address bought four minutes of a worker per request, and a handful at once
     * took the application away from everybody it was meant to protect.
     *
     * Ten thousand attempts is the shape of that: it would have been forty minutes.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testABlockedClientIsRefusedWithoutHoldingTheRequest()
    {
        $trackRequest = $this->getTrackRequest();

        $this->trackRepository
            ->method('getTracksForClientFromTime')
            ->willReturn(new QueryResult(range(0, 10000)));

        $started = microtime(true);

        $this->assertTrue($this->track->checkTracking($trackRequest));

        $this->assertLessThan(
            1,
            microtime(true) - $started,
            'the check held the request instead of answering'
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testCheckTrackingWithMaxAttempts()
    {
        $trackRequest = $this->getTrackRequest();
        $track = new TrackModel([
                                    'ipv4' => $trackRequest->getIpv4(),
                                    'ipv6' => $trackRequest->getIpv6(),
                                    'source' => $trackRequest->getSource(),
                                    'userId' => $trackRequest->getUserId(),
                                    'time' => $trackRequest->getTime()
                                ]);

        $this->trackRepository
            ->expects($this->once())
            ->method('getTracksForClientFromTime')
            ->with($track)
            ->willReturn(new QueryResult(range(0, 10)));

        $this->assertTrue($this->track->checkTracking($trackRequest));
    }

    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function testCheckTrackingWithException()
    {
        $trackRequest = $this->getTrackRequest();
        $track = new TrackModel([
                                    'ipv4' => $trackRequest->getIpv4(),
                                    'ipv6' => $trackRequest->getIpv6(),
                                    'source' => $trackRequest->getSource(),
                                    'userId' => $trackRequest->getUserId(),
                                    'time' => $trackRequest->getTime()
                                ]);

        $this->trackRepository
            ->expects($this->once())
            ->method('getTracksForClientFromTime')
            ->with($track)
            ->willThrowException(new RuntimeException('test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test');

        $this->track->checkTracking($trackRequest);
    }

    /**
     * @throws ConstraintException
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function testAdd()
    {
        $trackRequest = $this->getTrackRequest();
        $track = new TrackModel([
                                    'ipv4' => $trackRequest->getIpv4(),
                                    'ipv6' => $trackRequest->getIpv6(),
                                    'source' => $trackRequest->getSource(),
                                    'userId' => $trackRequest->getUserId(),
                                    'time' => $trackRequest->getTime()
                                ]);

        $this->trackRepository
            ->expects($this->once())
            ->method('add')
            ->with($track)
            ->willReturn(new QueryResult(null, 0, 100));

        $this->assertEquals(100, $this->track->add($trackRequest));
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testClear()
    {
        $this->trackRepository
            ->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $this->assertTrue($this->track->clear());
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testClearWithFalse()
    {
        $this->trackRepository
            ->expects($this->once())
            ->method('clear')
            ->willReturn(false);

        $this->assertFalse($this->track->clear());
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testUnlock()
    {
        $this->trackRepository
            ->expects($this->once())
            ->method('unlock')
            ->with(100)
            ->willReturn(1);

        $this->track->unlock(100);
    }

    /**
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testUnlockWithException()
    {
        $this->trackRepository
            ->expects($this->once())
            ->method('unlock')
            ->with(100)
            ->willReturn(0);

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('Track not found');

        $this->track->unlock(100);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testBuildTrackRequest()
    {
        // Must key on the unspoofable REMOTE_ADDR, never the client-controlled
        // getClientAddress() (Forwarded / X-Forwarded-For).
        $this->request
            ->expects($this->never())
            ->method('getClientAddress');
        $this->request
            ->expects($this->once())
            ->method('getServer')
            ->with('REMOTE_ADDR')
            ->willReturn(self::$faker->ipv4());

        $out = $this->track->buildTrackRequest('test');

        $this->assertTrue($out->getTime() < time());
        $this->assertEquals('test', $out->getSource());
        $this->assertNotNull($out->getIpv4());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->trackRepository = $this->createMock(TrackRepository::class);
        $this->request = $this->createMock(RequestService::class);

        $this->track = new Track($this->application, $this->trackRepository, $this->request);
    }
}
