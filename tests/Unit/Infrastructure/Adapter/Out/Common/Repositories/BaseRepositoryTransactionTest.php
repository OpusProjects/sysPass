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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out\Common\Repositories;

use Aura\SqlQuery\QueryFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Tests\Support\UnitaryTestCase;

/**
 * What escapes a transaction, and what is left of it
 */
#[Group('unitary')]
class BaseRepositoryTransactionTest extends UnitaryTestCase
{
    private DatabaseInterface|MockObject $db;
    private BaseRepository               $repository;

    /**
     * An application exception arrives as itself. It used to be rewrapped as a plain
     * ServiceException, so "User not found" stopped being a NoSuchItemException on its way out of
     * any transaction — and the services that needed the type had to unwrap getPrevious().
     */
    public function testAnApplicationExceptionLeavesTheTransactionUnchanged(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->expects(self::once())->method('rollbackTransaction');
        $this->db->expects(self::never())->method('endTransaction');

        $thrown = NoSuchItemException::info('User not found');

        try {
            $this->repository->transactionAware(fn() => throw $thrown, $this);
            self::fail('the exception was swallowed');
        } catch (NoSuchItemException $e) {
            self::assertSame($thrown, $e);
        }
    }

    /**
     * Anything else still arrives as a ServiceException carrying its message and the original.
     */
    public function testAnyOtherExceptionArrivesAsAServiceException(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->expects(self::once())->method('rollbackTransaction');

        $thrown = new RuntimeException('disk full');

        try {
            $this->repository->transactionAware(fn() => throw $thrown, $this);
            self::fail('the exception was swallowed');
        } catch (ServiceException $e) {
            self::assertSame('disk full', $e->getMessage());
            self::assertSame($thrown, $e->getPrevious());
        }
    }

    public function testASuccessfulClosureCommitsAndAnswersItsResult(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->expects(self::once())->method('endTransaction');
        $this->db->expects(self::never())->method('rollbackTransaction');

        self::assertSame(42, $this->repository->transactionAware(fn(): int => 42, $this));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(DatabaseInterface::class);

        $this->repository = new class(
            $this->db,
            $this->context,
            $this->application->getEventDispatcher(),
            new QueryFactory('mysql')
        ) extends BaseRepository {
        };
    }
}
