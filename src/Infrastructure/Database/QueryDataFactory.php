<?php

declare(strict_types=1);

namespace SP\Infrastructure\Database;

use SP\Domain\Database\Ports\QueryDataFactory as QueryDataFactoryInterface;
use SP\Domain\Database\Ports\QueryDataInterface;
use SP\Infrastructure\Adapter\Out\Common\Repositories\Query;

final class QueryDataFactory implements QueryDataFactoryInterface
{
    public function fromRawSql(string $sql, array $values = []): QueryDataInterface
    {
        return QueryData::build(Query::buildForMySQL($sql, $values));
    }
}
