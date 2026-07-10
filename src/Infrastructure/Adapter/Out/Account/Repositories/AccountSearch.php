<?php

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

namespace SP\Infrastructure\Adapter\Out\Account\Repositories;

use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryFactory;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Account\Models\AccountSearchView as AccountSearchViewModel;
use SP\Domain\Account\Ports\AccountFilterBuilder;
use SP\Domain\Account\Ports\AccountSearchConstants;
use SP\Domain\Account\Ports\AccountSearchRepository;
use SP\Domain\Common\Providers\Filter;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Database\QueryResult;

/**
 * Class AccountSearch
 */
final class AccountSearch extends BaseRepository implements AccountSearchRepository
{
    private readonly SelectInterface $query;

    public function __construct(
        DatabaseInterface        $database,
        Context                  $session,
        EventDispatcherInterface $eventDispatcher,
        QueryFactory             $queryFactory,
        private readonly AccountFilterBuilder $accountFilterUser
    ) {
        parent::__construct($database, $session, $eventDispatcher, $queryFactory);

        $cols = [
            'id',
            'clientId',
            'categoryId',
            'name',
            'login',
            'url',
            'notes',
            'userId',
            'userGroupId',
            'otherUserEdit',
            'otherUserGroupEdit',
            'isPrivate',
            'isPrivateGroup',
            'passDate',
            'passDateChange',
            'parentId',
            'countView',
            'dateEdit',
            'userName',
            'userLogin',
            'userGroupName',
            'categoryName',
            'clientName',
            'num_files',
            'publicLinkHash',
            'publicLinkDateExpire',
            'publicLinkTotalCountViews',
        ];
        $this->query = $this->queryFactory
            ->newSelect()
            ->cols($cols)
            ->from(sprintf('%s AS Account', AccountSearchViewModel::TABLE))
            ->distinct();
    }

    /**
     * Get the accounts matching a search.
     *
     * @param AccountSearchFilterDto $accountSearchFilter
     *
     * @template T of AccountSearchViewModel
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getByFilter(AccountSearchFilterDto $accountSearchFilter): QueryResult
    {
        // The ACL access filter and the favorites filter are always ANDed — they
        // constrain what the user may see and must never be relaxed by the operator.
        $this->accountFilterUser->buildFilter($accountSearchFilter->getGlobalSearch(), $this->query);
        $this->filterByFavorite($accountSearchFilter);

        // The user's search dimensions (text, category, client, tags) are chained
        // with the chosen operator: AND (default) — match all — or OR — match any.
        $this->filterByDimensions($accountSearchFilter);

        $this->setOrder($accountSearchFilter);

        if ($accountSearchFilter->getLimitCount() > 0) {
            $this->query->limit($accountSearchFilter->getLimitCount());
            $this->query->offset($accountSearchFilter->getLimitStart());
        }

        return $this->db->runQuery(
            QueryData::build($this->query)->setMapClassName(AccountSearchViewModel::class),
            true
        );
    }

    /**
     * Combine the search-dimension filters with the filter operator (AND/OR).
     */
    private function filterByDimensions(AccountSearchFilterDto $accountSearchFilter): void
    {
        $conditions = array_filter([
            $this->buildTextFilter($accountSearchFilter),
            $this->buildCategoryFilter($accountSearchFilter),
            $this->buildClientFilter($accountSearchFilter),
            $this->buildTagsFilter($accountSearchFilter),
        ]);

        if (empty($conditions)) {
            return;
        }

        // Pick the glue from the constants (never the raw value) so it can't inject
        $glue = AccountSearchConstants::FILTER_CHAIN_OR === $accountSearchFilter->getFilterOperator()
            ? AccountSearchConstants::FILTER_CHAIN_OR
            : AccountSearchConstants::FILTER_CHAIN_AND;

        $sql = [];
        $params = [];

        foreach ($conditions as [$condition, $conditionParams]) {
            $sql[] = $condition;
            $params += $conditionParams;
        }

        $this->query->where(sprintf('(%s)', implode(sprintf(' %s ', $glue), $sql)), $params);
    }

    /**
     * @param AccountSearchFilterDto $accountSearchFilter
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildTextFilter(AccountSearchFilterDto $accountSearchFilter): ?array
    {
        // Sets the search text depending on whether special search filters are being used
        $searchText = $accountSearchFilter->getCleanTxtSearch();

        if (empty($searchText)) {
            return null;
        }

        $searchTextLike = '%' . $searchText . '%';

        return [
            '(Account.name LIKE :name OR Account.login LIKE :login OR Account.url LIKE :url OR Account.notes LIKE :notes)',
            [
                'name' => $searchTextLike,
                'login' => $searchTextLike,
                'url' => $searchTextLike,
                'notes' => $searchTextLike,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildCategoryFilter(AccountSearchFilterDto $accountSearchFilter): ?array
    {
        if ($accountSearchFilter->getCategoryId() === null) {
            return null;
        }

        return [
            'Account.categoryId = :categoryId',
            ['categoryId' => $accountSearchFilter->getCategoryId()],
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildClientFilter(AccountSearchFilterDto $accountSearchFilter): ?array
    {
        if ($accountSearchFilter->getClientId() === null) {
            return null;
        }

        return [
            'Account.clientId = :clientId',
            ['clientId' => $accountSearchFilter->getClientId()],
        ];
    }

    /**
     * @param AccountSearchFilterDto $accountSearchFilter
     * @return void
     */
    private function filterByFavorite(AccountSearchFilterDto $accountSearchFilter): void
    {
        if ($accountSearchFilter->isSearchFavorites() === true) {
            $this->query
                ->join(
                    'INNER',
                    'AccountToFavorite',
                    'AccountToFavorite.accountId = Account.id AND AccountToFavorite.userId = :userId',
                    [
                        'userId' => $this->context->getUserData()->id,
                    ]
                );
        }
    }

    /**
     * A correlated subquery (not a JOIN) so the tag match is a single boolean that
     * can be OR-combined with the other dimensions. AND requires all of the tags;
     * OR requires any of them.
     *
     * @param AccountSearchFilterDto $accountSearchFilter
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildTagsFilter(AccountSearchFilterDto $accountSearchFilter): ?array
    {
        if (!$accountSearchFilter->hasTags()) {
            return null;
        }

        $tagsId = $accountSearchFilter->getTagsId();

        if (AccountSearchConstants::FILTER_CHAIN_AND === $accountSearchFilter->getFilterOperator()) {
            return [
                '(SELECT COUNT(DISTINCT AccountToTag.tagId) FROM AccountToTag'
                . ' WHERE AccountToTag.accountId = Account.id AND AccountToTag.tagId IN (:tagId)) = :tagsCount',
                ['tagId' => $tagsId, 'tagsCount' => count($tagsId)],
            ];
        }

        return [
            'EXISTS (SELECT 1 FROM AccountToTag'
            . ' WHERE AccountToTag.accountId = Account.id AND AccountToTag.tagId IN (:tagId))',
            ['tagId' => $tagsId],
        ];
    }

    /**
     * Returns the ordering clause for the query
     */
    private function setOrder(AccountSearchFilterDto $filter): void
    {
        $orderKey = match ($filter->getSortKey()) {
            AccountSearchConstants::SORT_NAME => 'Account.name',
            AccountSearchConstants::SORT_CATEGORY => 'Account.categoryName',
            AccountSearchConstants::SORT_LOGIN => 'Account.login',
            AccountSearchConstants::SORT_URL => 'Account.url',
            AccountSearchConstants::SORT_CLIENT => 'Account.clientName',
            default => 'Account.clientName, Account.name',
        };

        if ($filter->isSortViews() && !$filter->getSortKey()) {
            $this->query->orderBy(['Account.countView DESC']);
        } else {
            $sortOrder = match ($filter->getSortOrder()) {
                AccountSearchConstants::SORT_DIR_DESC => 'DESC',
                default => 'ASC',
            };

            $this->query->orderBy([
                                      sprintf('%s %s', $orderKey, $sortOrder),
                                  ]);
        }
    }

    /**
     * @param int $userId
     * @param int $userGroupId
     *
     * @return SelectInterface
     */
    public function withFilterForUser(int $userId, int $userGroupId): SelectInterface
    {
        $where = [
            'Account.userId = :userId',
            'Account.userGroupId = :userGroupId',
            'Account.id IN (SELECT AccountToUser.accountId FROM AccountToUser WHERE AccountToUser.accountId = Account.id AND AccountToUser.userId = :userId
                                    UNION
                                    SELECT AccountToUserGroup.accountId FROM AccountToUserGroup WHERE AccountToUserGroup.accountId = Account.id AND AccountToUserGroup.userGroupId = :userGroupId)',
        ];

        return $this->query
            ->where(sprintf('(%s)', join(sprintf(' %s ', AccountSearchConstants::FILTER_CHAIN_OR), $where)))
            ->bindValues([
                             'userId' => $userId,
                             'userGroupId' => $userGroupId,
                         ]);
    }

    /**
     * @param int $userGroupId
     *
     * @return SelectInterface
     */
    public function withFilterForGroup(int $userGroupId): SelectInterface
    {
        return $this->query
            ->where(
                '(Account.userGroupId = :userGroupId OR Account.id IN (SELECT AccountToUserGroup.accountId FROM AccountToUserGroup WHERE AccountToUserGroup.accountId = id AND AccountToUserGroup.userGroupId = :userGroupId))'
            )
            ->bindValues([
                             'userGroupId' => $userGroupId,
                         ]);
    }

    /**
     * @param string $userGroupName
     *
     * @return SelectInterface
     */
    public function withFilterForMainGroup(string $userGroupName): SelectInterface
    {
        $userGroupNameLike = '%' . Filter::safeSearchString($userGroupName) . '%';

        return $this->query
            ->where('Account.userGroupName LIKE :userGroupName')
            ->bindValues([
                             'userGroupName' => $userGroupNameLike,
                         ]);
    }

    /**
     * @param string $owner
     *
     * @return SelectInterface
     */
    public function withFilterForOwner(string $owner): SelectInterface
    {
        $ownerLike = '%' . Filter::safeSearchString($owner) . '%';

        return $this->query
            ->where('(Account.userLogin LIKE :userLogin OR Account.userName LIKE :userName)')
            ->bindValues([
                             'userLogin' => $ownerLike,
                             'userName' => $ownerLike,
                         ]);
    }

    /**
     * @param string $fileName
     *
     * @return SelectInterface
     */
    public function withFilterForFile(string $fileName): SelectInterface
    {
        $fileNameLike = '%' . Filter::safeSearchString($fileName) . '%';

        return $this->query
            ->where(
                '(Account.id IN (SELECT AccountFile.accountId FROM AccountFile WHERE AccountFile.name LIKE :fileName))'
            )
            ->bindValues([
                             'fileName' => $fileNameLike,
                         ]);
    }

    /**
     * @param int $accountId
     *
     * @return SelectInterface
     */
    public function withFilterForAccountId(int $accountId): SelectInterface
    {
        return $this->query
            ->where('Account.id = :accountId')
            ->bindValues([
                             'accountId' => $accountId,
                         ]);
    }

    /**
     * @param string $clientName
     *
     * @return SelectInterface
     */
    public function withFilterForClient(string $clientName): SelectInterface
    {
        $clientNameLike = '%' . Filter::safeSearchString($clientName) . '%';

        return $this->query
            ->where('Account.clientName LIKE :clientName')
            ->bindValues([
                             'clientName' => $clientNameLike,
                         ]);
    }

    /**
     * @param string $categoryName
     *
     * @return SelectInterface
     */
    public function withFilterForCategory(string $categoryName): SelectInterface
    {
        $categoryNameLike = '%' . Filter::safeSearchString($categoryName) . '%';

        return $this->query
            ->where('Account.categoryName LIKE :categoryName')
            ->bindValues([
                             'categoryName' => $categoryNameLike,
                         ]);
    }

    /**
     * @param string $accountName
     *
     * @return SelectInterface
     */
    public function withFilterForAccountNameRegex(string $accountName): SelectInterface
    {
        return $this->query
            ->where('Account.name REGEXP :name')
            ->bindValues([
                             'name' => $accountName,
                         ]);
    }

    public function withFilterForIsExpired(): SelectInterface
    {
        return $this->query
            ->where('(Account.passDateChange > 0 AND UNIX_TIMESTAMP() > Account.passDateChange)');
    }

    public function withFilterForIsNotExpired(): SelectInterface
    {
        return $this->query
            ->where(
                '(Account.passDateChange = 0 OR Account.passDateChange IS NULL OR UNIX_TIMESTAMP() < Account.passDateChange)'
            );
    }

    /**
     * @param int $userId
     * @param int $userGroupId
     *
     * @return SelectInterface
     */
    public function withFilterForIsPrivate(int $userId, int $userGroupId): SelectInterface
    {
        return $this->query
            ->where(
                '((Account.isPrivate = 1 AND Account.userId = :userId) OR (Account.isPrivateGroup = 1 AND Account.userGroupId = :userGroupId))'
            )
            ->bindValues([
                             'userId' => $userId,
                             'userGroupId' => $userGroupId,
                         ]);
    }

    public function withFilterForIsNotPrivate(): SelectInterface
    {
        return $this->query
            ->where(
                '(Account.isPrivate = 0 OR Account.isPrivate IS NULL) AND (Account.isPrivateGroup = 0 OR Account.isPrivateGroup IS NULL)'
            );
    }
}
