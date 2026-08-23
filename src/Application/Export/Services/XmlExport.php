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

use SP\Domain\Export\Services\XmlTrait;

use DOMDocument;
use DOMElement;
use DOMException;
use DOMNode;
use Exception;
use SP\Application\Application;
use SP\Domain\Crypt\Hash;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\CheckException;
use SP\Domain\Core\PhpExtensionCheckerService;
use SP\Domain\Export\Dtos\BackupFile as BackupFileDto;
use SP\Domain\Export\Dtos\BackupType;
use SP\Application\Export\Ports\XmlAccountExportService;
use SP\Application\Export\Ports\XmlCategoryExportService;
use SP\Application\Export\Ports\XmlClientExportService;
use SP\Application\Export\Ports\XmlExportService;
use SP\Application\Export\Ports\XmlTagExportService;
use SP\Domain\User\Dtos\UserDto;
use SP\Application\User\Ports\UserGroupService;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\File\Ports\DirectoryHandlerService;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\File\FileSystem;

use function SP\__u;
use function SP\processException;

/**
 * Class XmlExport
 */
final class XmlExport extends Service implements XmlExportService
{
    use XmlTrait;

    private ConfigDataInterface $configData;
    private DOMDocument $document;

    /**
     * @param UserGroupService<UserGroupModel> $userGroupService
     * @throws ServiceException
     */
    public function __construct(
        Application                                 $application,
        private readonly PhpExtensionCheckerService $extensionChecker,
        private readonly XmlClientExportService     $xmlClientExportService,
        private readonly XmlAccountExportService    $xmlAccountExportService,
        private readonly XmlCategoryExportService   $xmlCategoryExportService,
        private readonly XmlTagExportService        $xmlTagExportService,
        private readonly CryptInterface             $crypt,
        private readonly UserGroupService           $userGroupService
    ) {
        parent::__construct($application);

        $this->configData = $this->config->getConfigData();

        $this->createDocument();
    }

    /**
     * @throws ServiceException
     */
    private function createDocument(): void
    {
        try {
            $this->document = new DOMDocument('1.0', 'UTF-8');
            $this->document->formatOutput = true;
            $this->document->preserveWhiteSpace = false;

            $this->document->appendChild($this->document->createElement('Root'));
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }

    /**
     * @inheritDoc
     * @throws CheckException
     */
    public function export(DirectoryHandlerService $exportPath, ?string $password = null): string
    {
        // Null and the empty string both mean "no password, write it in the clear", which is a
        // supported way to export. A password of "0" does not mean that — but empty() says it
        // does, and every decision below used to ask empty(). An admin who typed 0 got an
        // unencrypted export, signed with the installation's salt rather than their password,
        // and nothing said so. Settling it here means the rest of this class asks one question.
        $password = ($password === null || $password === '') ? null : $password;

        set_time_limit(0);

        $exportPath->checkOrCreate();

        $file = new BackupFileDto(
            BackupType::export,
            $this->buildAndSaveHashForFile(),
            $exportPath->getPath(),
            'xml'
        );

        $this->buildAndSaveXml((string)$file, $password);

        // Only once there is a new export to keep. Deleting first meant a run that failed
        // part-way — an unreadable account, a failure writing the file — took the last good
        // export with it and left the installation with none at all.
        self::deleteExportFiles($exportPath->getPath(), (string)$file);

        return (string)$file;
    }

    private static function deleteExportFiles(string $path, string $keep): void
    {
        $path = FileSystem::buildPath($path, AppInfoInterface::APP_NAME);

        array_map(
            static fn($file) => @unlink($file),
            array_filter(
                array_merge(glob($path . '_export-*'), glob($path . '*.xml')),
                static fn(string $file) => $file !== $keep
            )
        );
    }

    /**
     * @throws FileException
     */
    private function buildAndSaveHashForFile(): string
    {
        $hash = bin2hex(random_bytes(20));
        $this->configData->setExportHash($hash);
        $this->config->save($this->configData);

        return $hash;
    }

    /**
     * @throws ServiceException
     */
    private function buildAndSaveXml(string $file, ?string $password = null): void
    {
        try {
            $this->appendMeta();
            $this->appendNode($this->xmlCategoryExportService->export(), $password);
            $this->appendNode($this->xmlClientExportService->export(), $password);
            $this->appendNode($this->xmlTagExportService->export(), $password);
            $this->appendNode($this->xmlAccountExportService->export(), $password);
            $this->appendHash($password);

            if (!$this->document->save($file)) {
                throw ServiceException::error(__u('Error while creating the XML file'));
            }

            // The backup archives are restricted to their owner; this was not, and it holds the
            // same installation. Every account's encrypted secret and its key are in here, and
            // when no export password was given so is everything around them in the clear — the
            // name, the login, the URL and the notes of every account. At the default umask that
            // is a world-readable file, which on a shared host is every local user.
            @chmod($file, 0600);
        } catch (ServiceException $e) {
            throw $e;
        } catch (Exception $e) {
            throw ServiceException::error(
                __u('Error while exporting'),
                __u('Please check out the event log for more details'),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws ServiceException
     */
    private function appendMeta(): void
    {
        try {
            $userDto = $this->context->getUserData();

            $nodeUser = $this->document->createElement('User');
            $nodeUser->appendChild($this->document->createTextNode($userDto->login ?? ''));
            $nodeUser->setAttribute('id', (string)$userDto->id);

            $nodeUserGroup = $this->document->createElement('Group');
            $nodeUserGroup->appendChild($this->document->createTextNode($this->resolveUserGroupName($userDto)));
            $nodeUserGroup->setAttribute('id', (string)$userDto->userGroupId);


            $nodeMeta = $this->document->createElement('Meta');
            $nodeMeta->append(
                $this->document->createElement('Generator', 'sysPass'),
                $this->document->createElement('Version', Version::getVersionStringNormalized()),
                $this->document->createElement('Time', (string)time()),
                $nodeUser,
                $nodeUserGroup
            );

            $this->document->documentElement->appendChild($nodeMeta);
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }

    /**
     * The session's UserDto carries no userGroupName: it is built from UserRepository::getByLogin(),
     * which selects only the User table's own columns. Writing it produced an empty <Group>, and the
     * export then failed its own schema — syspass.xsd types that element as NonEmptyString — so every
     * export ended in "Invalid XML schema".
     *
     * Resolve the name from the id instead. It always yields something: User.userGroupId is NOT NULL,
     * UserGroup.name is NOT NULL, and getByLogin() inner-joins UserGroup, so a logged-in user's group
     * necessarily exists.
     *
     * @throws ServiceException
     */
    private function resolveUserGroupName(UserDto $userDto): string
    {
        if (!empty($userDto->userGroupName)) {
            return $userDto->userGroupName;
        }

        try {
            return $this->userGroupService->getById($userDto->userGroupId)->getName() ?? '';
        } catch (Exception $e) {
            processException($e);

            return '';
        }
    }

    /**
     * @throws ServiceException
     */
    private function appendNode(DOMElement $node, ?string $password = null): void
    {
        try {
            $selfNode = $this->document->importNode($node, true);

            if ($password !== null) {
                $securedKey = $this->crypt->makeSecuredKey($password, false);
                $encrypted = $this->crypt->encrypt(
                    $this->document->saveXML($selfNode),
                    $securedKey->unlockKey($password)
                );

                $encryptedData = $this->document->createElement('Data', $encrypted);
                $encryptedData->setAttribute('key', $securedKey->saveToAsciiSafeString());

                $newNode = $this->getEncryptedNode($password);

                $newNode->appendChild($encryptedData);

                $this->document->documentElement->appendChild($newNode);
            } else {
                $this->document->documentElement->appendChild($selfNode);
            }
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }

    /**
     * @param string $password
     * @return DOMElement|DOMNode|false|null
     * @throws DOMException
     */
    private function getEncryptedNode(string $password): DOMElement|null|false|DOMNode
    {
        $encryptedNode = $this->document->documentElement->getElementsByTagName('Encrypted');

        if ($encryptedNode->length === 0) {
            $node = $this->document->createElement('Encrypted');
            $node->setAttribute('hash', Hash::hashKey($password));

            return $node;
        }

        return $encryptedNode->item(0);
    }

    /**
     * @throws ServiceException
     */
    private function appendHash(?string $password = null): void
    {
        try {
            $hash = self::generateHashFromNodes($this->document);
            $key = $password ?? sha1($this->configData->getPasswordSalt() ?? '');

            $hashNode = $this->document->createElement('Hash', $hash);
            $hashNode->setAttribute('sign', Hash::signMessage($hash, $key));

            $this->document
                ->documentElement
                ->getElementsByTagName('Meta')
                ->item(0)
                ->appendChild($hashNode);
        } catch (Exception $e) {
            throw ServiceException::error($e->getMessage(), __u('Please check out the event log for more details'));
        }
    }
}
