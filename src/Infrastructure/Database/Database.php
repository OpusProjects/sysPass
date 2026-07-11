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

namespace SP\Infrastructure\Database;

use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryInterface;
use Exception;
use PDO;
use PDOException;
use PDOStatement;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Model;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Infrastructure\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Database\Ports\DbStorageHandler;
use SP\Infrastructure\Database\Ports\QueryDataInterface;

use function SP\__u;
use function SP\logger;
use function SP\processException;

/**
 * Class Database
 */
final class Database implements DatabaseInterface
{
    private ?int $lastId = null;

    /**
     * DB constructor.
     *
     * @param DbStorageHandler $dbStorageHandler
     * @param EventDispatcher $eventDispatcher
     */
    public function __construct(
        private readonly DbStorageHandler         $dbStorageHandler,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * Perform any type of query
     *
     * The row class is chosen at runtime via QueryData::getMapClassName(): string (a plain
     * string, not class-string<T>), so QueryResult's T genuinely cannot be bound here — this
     * return type has to stay bare QueryResult. See DatabaseInterface::runQuery() (same, by
     * contract).
     *
     * @throws QueryException
     * @throws ConstraintException
     */
    public function runQuery(QueryDataInterface $queryData, bool $fullCount = false): QueryResult
    {
        try {
            $query = $queryData->getQuery();

            if (empty($query->getStatement())) {
                throw QueryException::error($queryData->getOnErrorMessage(), __u('Blank query'));
            }

            $stmt = $this->prepareAndRunQuery($query);

            $this->eventDispatcher->notify(new Event('database.query', $this, EventMessage::build()->addDescription($query->getStatement())));

            if ($query instanceof SelectInterface) {
                if ($fullCount === true) {
                    return QueryResult::withTotalNumRows(
                        $this->fetch($stmt, $queryData->getMapClassName()),
                        $this->getFullRowCount($queryData)
                    );
                }

                return new QueryResult($this->fetch($stmt, $queryData->getMapClassName()));
            }

            return new QueryResult(null, $stmt->rowCount(), $this->lastId);
        } catch (ConstraintException|QueryException $e) {
            processException($e);

            throw $e;
        }
    }

    /**
     * Bind the query parameters using the appropriate type
     *
     * @param QueryInterface $query The query data
     * @param array<int, mixed> $options
     *
     * @return PDOStatement
     * @throws ConstraintException
     * @throws QueryException
     */
    private function prepareAndRunQuery(QueryInterface $query, array $options = []): PDOStatement
    {
        try {
            $connection = $this->dbStorageHandler->getConnection();

            $sql = $query->getStatement();
            // Aura's QueryInterface::getBindValues() promises array only in its docblock —
            // the method is untyped, so keep the null guard (PHPStan sees the docblock).
            $bindValues = $query->getBindValues() ?? [];
            $expandedBinds = [];

            foreach ($bindValues as $param => $value) {
                if (is_array($value)) {
                    $placeholders = [];
                    foreach (array_values($value) as $i => $v) {
                        $key = $param . '_' . $i;
                        $placeholders[] = ':' . $key;
                        $expandedBinds[$key] = $v;
                    }
                    // Word-boundary guarded: a plain str_replace would also match a longer
                    // bind name that has this param as a strict prefix (e.g. :id vs :idOwner),
                    // corrupting it. Same negative-lookahead guard as the final bind filter below.
                    $sql = preg_replace(
                        '/:' . preg_quote($param, '/') . '(?![a-zA-Z0-9_])/',
                        addcslashes(implode(', ', $placeholders), '\\$'),
                        $sql
                    );
                } else {
                    $expandedBinds[$param] = $value;
                }
            }

            // PDO native prepared statements don't support the same named
            // parameter appearing more than once.  Deduplicate by replacing
            // the 2nd+ occurrence of :param with :param__2, :param__3, etc.
            // and binding each copy to the same value.
            foreach ($expandedBinds as $param => $value) {
                $token = ':' . $param;
                $count = substr_count($sql, $token);
                if ($count > 1) {
                    $offset = 0;
                    $occurrence = 0;
                    while (($pos = strpos($sql, $token, $offset)) !== false) {
                        $endPos = $pos + strlen($token);
                        $nextChar = $sql[$endPos] ?? '';
                        if (ctype_alnum($nextChar) || $nextChar === '_') {
                            $offset = $endPos;
                            continue;
                        }
                        $occurrence++;
                        if ($occurrence > 1) {
                            $newParam = $param . '__' . $occurrence;
                            $newToken = ':' . $newParam;
                            $sql = substr_replace($sql, $newToken, $pos, strlen($token));
                            $expandedBinds[$newParam] = $value;
                            $offset = $pos + strlen($newToken);
                        } else {
                            $offset = $endPos;
                        }
                    }
                }
            }

            $expandedBinds = array_filter(
                $expandedBinds,
                static fn($param) => preg_match('/:'.$param.'(?![a-zA-Z0-9_])/', $sql),
                ARRAY_FILTER_USE_KEY
            );

            $stmt = $connection->prepare($sql, $options);

            foreach ($expandedBinds as $param => $value) {
                $type = match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    default => PDO::PARAM_STR
                };

                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();

            $this->lastId = (int)$connection->lastInsertId();

            return $stmt;
        } catch (Exception $e) {
            processException($e);

            if ((int)$e->getCode() === 23000) {
                throw ConstraintException::error(
                    self::constraintMessage($e),
                    $e->getMessage(),
                    (int)$e->getCode(),
                    $e
                );
            }

            throw QueryException::critical($e->getMessage(), (string)$e->getCode(), (int)$e->getCode(), $e);
        }
    }

    /**
     * A friendlier message per MySQL constraint (SQLSTATE 23000). The raw driver
     * detail is kept as the exception hint; a non-PDO error keeps the generic one.
     */
    private static function constraintMessage(Exception $e): string
    {
        $driverCode = $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null;

        return match ($driverCode) {
            1062 => __u('Duplicate entry'),
            1451 => __u('The record is in use'),
            1452 => __u('Referenced record not found'),
            default => __u('Integrity constraint'),
        };
    }

    /**
     * $class always resolves to a real class (QueryData::getMapClassName() defaults to
     * Simple::class, never empty/null), so PDO::FETCH_CLASS is always used and every row is
     * hydrated as a Model instance (every class ever passed to QueryData::setMapClassName()/
     * buildWithMapper() is a Model subclass).
     *
     * @return array<int, Model&object>
     */
    private function fetch(PDOStatement $stmt, ?string $class = null): array
    {
        $fetchArgs = [PDO::FETCH_DEFAULT];

        if ($class) {
            $fetchArgs = [PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $class];
        }

        return $stmt->fetchAll(...$fetchArgs);
    }

    /**
     * Get the number of rows from a performed query
     *
     * @param QueryDataInterface $queryData
     * @return int Number of rows in the query
     * @throws ConstraintException
     * @throws QueryException
     */
    private function getFullRowCount(QueryDataInterface $queryData): int
    {
        return (int)$this->prepareAndRunQuery($queryData->getQueryCount())->fetchColumn();
    }

    /**
     * Don't fetch records and return prepared statement
     *
     * @param QueryData $queryData
     * @param array<int, mixed> $options
     * @param int $mode Fech mode
     * @param bool|null $buffered Set buffered behavior (useful for big datasets)
     *
     * @return PDOStatement
     * @throws ConstraintException
     * @throws QueryException
     */
    public function doFetchWithOptions(
        QueryDataInterface $queryData,
        array              $options = [],
        int                $mode = PDO::FETCH_DEFAULT,
        ?bool              $buffered = true
    ): iterable {
        if ($this->dbStorageHandler->getDriver() === DbStorageDriver::mysql) {
            $options += [\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => $buffered];
        }

        $stmt = $this->prepareAndRunQuery($queryData->getQuery(), $options);

        while (($row = $stmt->fetch($mode)) !== false) {
            yield $row;
        }
    }

    /**
     * Execute a raw query
     *
     * @param string $query
     * @throws QueryException
     * @throws DatabaseException
     */
    public function runQueryRaw(string $query): void
    {
        if ($this->dbStorageHandler->getConnection()->exec($query) === false) {
            throw QueryException::error(__u('Error executing the query'));
        }
    }

    /**
     * Start a transaction
     *
     * @throws DatabaseException
     */
    public function beginTransaction(): bool
    {
        $conn = $this->dbStorageHandler->getConnection();

        if (!$conn->inTransaction()) {
            $result = $conn->beginTransaction();

            $this->eventDispatcher->notify(new Event(
                'database.transaction.begin',
                $this,
                EventMessage::build()->addExtra('result', $result)
            ));

            return $result;
        }

        logger('beginTransaction: already in transaction');

        return true;
    }

    /**
     * Finish a transaction
     *
     * @throws DatabaseException
     */
    public function endTransaction(): bool
    {
        $conn = $this->dbStorageHandler->getConnection();

        $result = $conn->inTransaction() && $conn->commit();

        $this->eventDispatcher->notify(new Event(
            'database.transaction.end',
            $this,
            EventMessage::build()->addExtra('result', $result)
        ));

        return $result;
    }

    /**
     * Rollback a transaction
     *
     * @throws DatabaseException
     */
    public function rollbackTransaction(): bool
    {
        $conn = $this->dbStorageHandler->getConnection();

        $result = $conn->inTransaction() && $conn->rollBack();

        $this->eventDispatcher->notify(new Event(
            'database.transaction.rollback',
            $this,
            EventMessage::build()->addExtra('result', $result)
        ));

        return $result;
    }
}
