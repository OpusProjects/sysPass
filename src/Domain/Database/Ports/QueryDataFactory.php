<?php

declare(strict_types=1);

namespace SP\Domain\Database\Ports;

interface QueryDataFactory
{
    /**
     * @param array<int|string, mixed> $values
     */
    public function fromRawSql(string $sql, array $values = []): QueryDataInterface;
}
