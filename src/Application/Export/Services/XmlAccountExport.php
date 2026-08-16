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

namespace SP\Application\Export\Services;

use DOMElement;
use Exception;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\AccountToTagService;
use SP\Domain\Common\Services\ServiceException;
use SP\Application\Export\Ports\XmlAccountExportService;

use function SP\__u;

/**
 * Class XmlAccountExport
 */
final class XmlAccountExport extends XmlExportEntityBase implements XmlAccountExportService
{

    public function __construct(
        Application                          $application,
        private readonly AccountService      $accountService,
        private readonly AccountToTagService $accountToTagService
    ) {
        parent::__construct($application);
    }

    /**
     * Build the node with the data
     *
     * @throws ServiceException
     */
    public function export(): DOMElement
    {
        try {
            $this->eventDispatcher->notify(new Event('run.export.process.account', $this, EventMessage::build()->addDescription(__u('Exporting accounts'))));

            $accounts = $this->accountService->getAllBasic();

            // Build the accounts node
            $nodeAccounts = $this->document->createElement('Accounts');

            if (empty($accounts)) {
                return $nodeAccounts;
            }

            // Every account's tags in one query. This loop runs over the whole installation, so
            // asking per account meant one query per account exported — thousands of them on a
            // large one, for data a single pass answers.
            $tagsByAccount = $this->accountToTagService->getTagsByAccountIds(
                array_map(static fn($account) => $account->getId() ?? 0, $accounts)
            );

            foreach ($accounts as $account) {
                $accountName = $this->createTextElement('name', $account->getName() ?? '');
                $accountCustomerId = $this->document->createElement('clientId', (string)$account->getClientId());
                $accountCategoryId = $this->document->createElement('categoryId', (string)$account->getCategoryId());
                $accountLogin = $this->createTextElement('login', $account->getLogin() ?? '');
                $accountUrl = $this->createTextElement('url', $account->getUrl() ?? '');
                $accountNotes = $this->createTextElement('notes', $account->getNotes() ?? '');
                $accountPass = $this->createTextElement('pass', $account->getPass() ?? '');
                $accountIV = $this->createTextElement('key', $account->getKey() ?? '');
                $tags = $this->document->createElement('tags');

                foreach ($tagsByAccount[$account->getId() ?? 0] ?? [] as $itemData) {
                    $tag = $this->document->createElement('tag');
                    $tags->appendChild($tag);

                    $tag->setAttribute('id', (string)$itemData->getId());
                }

                // Build the account node
                $nodeAccount = $this->document->createElement('Account');
                $nodeAccount->setAttribute('id', (string)$account->getId());
                $nodeAccount->appendChild($accountName);
                $nodeAccount->appendChild($accountCustomerId);
                $nodeAccount->appendChild($accountCategoryId);
                $nodeAccount->appendChild($accountLogin);
                $nodeAccount->appendChild($accountUrl);
                $nodeAccount->appendChild($accountNotes);
                $nodeAccount->appendChild($accountPass);
                $nodeAccount->appendChild($accountIV);
                $nodeAccount->appendChild($tags);

                // Add the account to the accounts node
                $nodeAccounts->appendChild($nodeAccount);
            }

            return $nodeAccounts;
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }
}
