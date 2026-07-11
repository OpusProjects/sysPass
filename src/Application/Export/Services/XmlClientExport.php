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
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Application\Client\Ports\ClientService;
use SP\Domain\Common\Services\ServiceException;
use SP\Application\Export\Ports\XmlClientExportService;

use function SP\__u;

/**
 * Class XmlClientExport
 */
final class XmlClientExport extends XmlExportEntityBase implements XmlClientExportService
{
    public function __construct(
        Application                    $application,
        private readonly ClientService $clientService
    ) {
        parent::__construct($application);
    }

    /**
     * Build the node with the data
     *
     * @throws ServiceException
     * @throws ServiceException
     */
    public function export(): DOMElement
    {
        try {
            $this->eventDispatcher->notify(new Event('run.export.process.client', $this, EventMessage::build()->addDescription(__u('Exporting clients'))));

            $clients = $this->clientService->getAll();

            $nodeClients = $this->document->createElement('Clients');

            if (empty($clients)) {
                return $nodeClients;
            }

            foreach ($clients as $client) {
                $nodeClient = $this->document->createElement('Client');
                $nodeClients->appendChild($nodeClient);

                $nodeClient->setAttribute('id', (string)$client->getId());
                $nodeClient->appendChild($this->createTextElement('name', $client->getName() ?? ''));
                $nodeClient->appendChild($this->createTextElement('description', $client->getDescription() ?? ''));
            }

            return $nodeClients;
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }
}
