<?php
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup;

use SP\Application\Application;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Infrastructure\Context\Session;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\Export\Ports\XmlExportService;
use SP\Application\Export\Ports\XmlVerifyService;
use SP\Domain\Import\Services\ImportException;
use SP\Infrastructure\File\ArchiveHandler;
use SP\Infrastructure\File\DirectoryHandler;
use SP\Domain\Core\Exceptions\FileException;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;

use function SP\__u;

/**
 * Class XmlExportController
 */
final class XmlExportController extends SimpleControllerBase
{
    /**
     * @throws SessionTimeout
     * @throws SPException
     * @throws UnauthorizedPageException
     */
    public function __construct(
        Application                       $application,
        SimpleControllerHelper            $simpleControllerHelper,
        private readonly XmlExportService $xmlExportService,
        private readonly XmlVerifyService $xmlVerifyService,
        private readonly PathsContext     $pathsContext
    ) {
        parent::__construct($application, $simpleControllerHelper);

        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_BACKUP);
    }

    /**
     * @return ActionResponse
     * @throws ServiceException
     * @throws ImportException
     * @throws FileException
     */
    #[Action(ResponseType::JSON)]
    public function xmlExportAction(): ActionResponse
    {
        // A demo instance does not export itself. Every other way of getting the installation out
        // of a demo already refuses — the backup and both of its downloads, and the REST export
        // since #831 — and this one did not, though what it produces is the same installation:
        // every account's encrypted secret and its key, and, when no export password is given, the
        // name, login, URL and notes of every account in the clear. A demo publishes its
        // administrator's credentials, so the ACL in front of this stops nobody and the demo check
        // is the whole boundary.
        if ($this->configData->isDemoEnabled()) {
            return ActionResponse::warning(__u('Ey, this is a DEMO!!'));
        }

        $exportPassword = $this->request->analyzeEncrypted('exportPwd');
        $exportPasswordR = $this->request->analyzeEncrypted('exportPwdR');

        // Not empty(): a password of "0" is a password, and skipping the confirmation for it let
        // an export be written with one value while the admin had typed another in the second box.
        if ($exportPassword !== null && $exportPassword !== '' && $exportPassword !== $exportPasswordR) {
            return ActionResponse::error(__u('Passwords do not match'));
        }

        $this->eventDispatcher->notify(new Event('run.export.start', $this, EventMessage::build(__u('sysPass XML export'))));

        Session::close();

        $file = $this->xmlExportService->export(
            new DirectoryHandler($this->pathsContext[Path::BACKUP]),
            $exportPassword
        );

        $this->eventDispatcher->notify(new Event('run.export.end', $this, EventMessage::build(__u('Export process finished'))));

        $verifyResult = $this->xmlVerifyService->verify($file, $exportPassword);

        $nodes = $verifyResult->getNodes();

        $this->eventDispatcher->notify(new Event(
            'run.export.verify',
            $this,
            EventMessage::build(__u('Verification of exported data finished'))
                            ->addDetail(__u('Version'), $verifyResult->getVersion())
                            ->addDetail(__u('Encrypted'), $verifyResult->isEncrypted() ? __u('Yes') : __u('No'))
                            ->addDetail(__u('Accounts'), $nodes['Account'])
                            ->addDetail(__u('Clients'), $nodes['Client'])
                            ->addDetail(__u('Categories'), $nodes['Category'])
                            ->addDetail(__u('Tags'), $nodes['Tag'])
        ));

        // Create the XML archive after verifying the export integrity
        $archive = new ArchiveHandler($file, $this->extensionChecker);
        $archive->compressFile($file);

        return ActionResponse::ok(__u('Export process finished'));
    }
}
