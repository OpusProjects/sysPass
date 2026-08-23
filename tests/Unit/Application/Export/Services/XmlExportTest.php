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

namespace SP\Tests\Unit\Application\Export\Services;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Key;
use Defuse\Crypto\KeyProtectedByPassword;
use DOMDocument;
use DOMElement;
use DOMException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\CheckException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\PhpExtensionCheckerService;
use SP\Application\Export\Ports\XmlAccountExportService;
use SP\Application\Export\Ports\XmlCategoryExportService;
use SP\Application\Export\Ports\XmlClientExportService;
use SP\Application\Export\Ports\XmlTagExportService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\Export\Services\XmlExport;
use SP\Domain\File\Ports\DirectoryHandlerService;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\Core\Exceptions\FileException;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class XmlExportTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class XmlExportTest extends UnitaryTestCase
{
    use XmlTrait;

    private PhpExtensionCheckerService|MockObject $phpExtensionCheckerService;
    private MockObject|XmlClientExportService     $xmlClientExportService;
    private XmlAccountExportService|MockObject    $xmlAccountExportService;
    private XmlCategoryExportService|MockObject   $xmlCategoryExportService;
    private XmlTagExportService|MockObject        $xmlTagExportService;
    private UserGroupService|MockObject $userGroupService;
    private CryptInterface|MockObject             $crypt;
    private XmlExport                             $xmlExport;

    /**
     * @throws ServiceException
     * @throws Exception
     * @throws FileException
     * @throws CheckException
     * @throws EnvironmentIsBrokenException
     * @throws DOMException
     * @throws SPException
     */
    public function testExport()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $password = self::$faker->password();

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestTags'));

        $this->xmlAccountExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestAccounts'));

        $this->checkCrypt($password);

        $out = $this->xmlExport->export($exportPath, $password);

        $this->assertNotEmpty($this->config->getConfigData()->getExportHash());
        $this->assertFileExists($out);

        $xml = new DOMDocument();
        $xml->load($out, LIBXML_NOBLANKS);

        $meta = $xml->documentElement->getElementsByTagName('Meta')->item(0)->childNodes;

        $this->assertEquals(6, $meta->count());

        $this->checkNodes(
            $meta,
            [
                'Generator' => 'sysPass',
                'Version' => Version::getVersionStringNormalized(),
                'Time' => static fn(string $value) => self::assertTrue($value > 0),
                'User' => 'test_user',
                'Group' => 'test_group',
                'Hash' => static fn(string $value) => self::assertNotEmpty($value),
            ]
        );

        $this->assertNotEmpty($meta->item(5)->attributes->getNamedItem('sign')->nodeValue);

        $encrypted = $xml->documentElement->getElementsByTagName('Encrypted')->item(0);

        // bcrypt prefix only — the default cost is PHP-version dependent ($2y$10$ on <= 8.3, $2y$12$ on >= 8.4).
        $this->assertStringStartsWith('$2y$', $encrypted->attributes->getNamedItem('hash')->nodeValue);

        $this->assertEquals(4, $encrypted->childNodes->count());

        $this->assertEquals(
            'encrypted_data_categories',
            $encrypted->childNodes->item(0)->childNodes->item(0)->nodeValue
        );
        $this->assertNotEmpty($encrypted->childNodes->item(0)->attributes->getNamedItem('key')->nodeValue);
        $this->assertEquals(
            'encrypted_data_clients',
            $encrypted->childNodes->item(1)->childNodes->item(0)->nodeValue
        );
        $this->assertNotEmpty($encrypted->childNodes->item(1)->attributes->getNamedItem('key')->nodeValue);
        $this->assertEquals(
            'encrypted_data_tags',
            $encrypted->childNodes->item(2)->childNodes->item(0)->nodeValue
        );
        $this->assertNotEmpty($encrypted->childNodes->item(2)->attributes->getNamedItem('key')->nodeValue);
        $this->assertEquals(
            'encrypted_data_accounts',
            $encrypted->childNodes->item(3)->childNodes->item(0)->nodeValue
        );
        $this->assertNotEmpty($encrypted->childNodes->item(3)->attributes->getNamedItem('key')->nodeValue);
    }

    /**
     * @throws DOMException
     */
    private function createNode(string $nodeName): DOMElement
    {
        return new DOMElement($nodeName, self::$faker->text());
    }

    /**
     * @param string $password
     * @param int $times
     * @return void
     * @throws EnvironmentIsBrokenException
     */
    /**
     * A password of "0" is a password, and the export is encrypted with it.
     *
     * Whether one had been supplied was decided with empty(), and empty('0') is true — so an
     * admin who typed 0 got their whole export written in the clear, with the integrity hash
     * signed by the installation's salt instead of by their password, and nothing anywhere said
     * so. The confirmation box was skipped for the same reason, so the value was never even
     * checked against the one typed to confirm it.
     *
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     */
    #[Test]
    public function aPasswordOfZeroStillEncryptsTheExport()
    {
        $exportPath = $this->createStub(DirectoryHandlerService::class);
        $exportPath->method('getPath')->willReturn(TMP_PATH);

        $this->givenEveryEntityExports();
        $this->checkCrypt('0');

        $out = $this->xmlExport->export($exportPath, '0');

        $xml = new DOMDocument();
        $xml->load($out, LIBXML_NOBLANKS);

        self::assertNotNull(
            $xml->documentElement->getElementsByTagName('Encrypted')->item(0),
            'the export must carry an Encrypted node'
        );
        self::assertSame(
            0,
            $xml->documentElement->getElementsByTagName('TestCategories')->count(),
            'nothing may be written in the clear beside it'
        );
    }

    /**
     * An absent password still writes the export in the clear, which is a supported way to export.
     * Without this the test above is satisfied by encrypting unconditionally.
     *
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     */
    #[Test]
    public function anEmptyPasswordStillWritesTheExportInTheClear()
    {
        $exportPath = $this->createStub(DirectoryHandlerService::class);
        $exportPath->method('getPath')->willReturn(TMP_PATH);

        $this->givenEveryEntityExports();

        $this->crypt->expects(self::never())->method('makeSecuredKey');

        $out = $this->xmlExport->export($exportPath, '');

        $xml = new DOMDocument();
        $xml->load($out, LIBXML_NOBLANKS);

        self::assertNull($xml->documentElement->getElementsByTagName('Encrypted')->item(0));
        self::assertSame(1, $xml->documentElement->getElementsByTagName('TestCategories')->count());
    }

    /**
     * The four entity exporters, each answering with a node of its own name.
     */
    private function givenEveryEntityExports(): void
    {
        $this->xmlCategoryExportService->method('export')->willReturn($this->createNode('TestCategories'));
        $this->xmlClientExportService->method('export')->willReturn($this->createNode('TestClients'));
        $this->xmlTagExportService->method('export')->willReturn($this->createNode('TestTags'));
        $this->xmlAccountExportService->method('export')->willReturn($this->createNode('TestAccounts'));
    }

    private function checkCrypt(string $password, int $times = 4): void
    {
        $securedKey = KeyProtectedByPassword::createRandomPasswordProtectedKey($password);

        $this->crypt
            ->expects(self::exactly($times))
            ->method('makeSecuredKey')
            ->with($password, false)
            ->willReturn($securedKey);

        $this->crypt
            ->expects(self::exactly($times))
            ->method('encrypt')
            ->with(
                self::anything(),
                new Callback(static function (Key $key) use ($password, $securedKey) {
                    return $key->saveToAsciiSafeString() === $securedKey->unlockKey($password)->saveToAsciiSafeString();
                })
            )
            ->willReturn(
                'encrypted_data_categories',
                'encrypted_data_clients',
                'encrypted_data_tags',
                'encrypted_data_accounts'
            );
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws SPException
     */
    public function testExportWithCheckDirectoryException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroup.name' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate')
                   ->willThrowException(CheckException::error('test'));

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('test');

        $this->xmlExport->export($exportPath);
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws SPException
     */
    public function testExportWithExportCategoryException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $password = self::$faker->password();

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while exporting');

        $this->xmlExport->export($exportPath, $password);
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws DOMException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     */
    public function testAFailedExportLeavesThePreviousOneInPlace()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(['login' => 'test_user', 'userGroupName' => 'test_group'])
            )
        );

        // A real directory, not the harness's vfs one: the cleanup uses glob(), which does not
        // see a stream wrapper — against vfs this test would pass without proving anything.
        $directory = FileSystem::buildPath(sys_get_temp_dir(), 'syspass-export-ordering-' . bin2hex(random_bytes(6)));
        mkdir($directory);

        $previous = FileSystem::buildPath($directory, AppInfoInterface::APP_NAME . '_export-previous.xml');
        file_put_contents($previous, '<Root/>');

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())->method('checkOrCreate');
        $exportPath->method('getPath')->willReturn($directory);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('test'));

        try {
            $this->xmlExport->export($exportPath, self::$faker->password());
            self::fail('the export was expected to fail');
        } catch (ServiceException) {
            // The point of the test is what survives it.
        }

        self::assertFileExists($previous, 'a failed export must not take the last good one with it');

        unlink($previous);
        rmdir($directory);
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws DOMException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     */
    public function testExportWithExportClientException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('test'));

        $password = self::$faker->password();

        $this->checkCrypt($password, 1);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while exporting');

        $this->xmlExport->export($exportPath, $password);
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws DOMException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     */
    public function testExportWithExportTagException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('test'));

        $password = self::$faker->password();

        $this->checkCrypt($password, 2);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while exporting');

        $this->xmlExport->export($exportPath, $password);
    }

    /**
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws DOMException
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     */
    public function testExportWithExportAccountException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestTags'));

        $this->xmlAccountExportService
            ->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('test'));

        $password = self::$faker->password();

        $this->checkCrypt($password, 3);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while exporting');

        $this->xmlExport->export($exportPath, $password);
    }

    /**
     * @throws ServiceException
     * @throws Exception
     * @throws FileException
     * @throws CheckException
     * @throws DOMException
     * @throws SPException
     */
    public function testExportWithCryptException()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $password = self::$faker->password();

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->crypt
            ->expects(self::once())
            ->method('makeSecuredKey')
            ->willThrowException(CryptException::error('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('test');

        $this->xmlExport->export($exportPath, $password);
    }

    /**
     * The session's UserDto only carries a userGroupName when the login flow copied it in
     * explicitly; the common case is a session with just a userGroupId. If XmlExport wrote
     * that gap straight into the <Group> node the file would fail its own XSD (which types
     * the element as NonEmptyString), so every export from such a session would break with
     * "Invalid XML schema" until the user re-logs in. Resolving the name from the group
     * service instead is what keeps those exports working.
     *
     * @throws ServiceException
     * @throws Exception
     * @throws FileException
     * @throws CheckException
     * @throws DOMException
     * @throws SPException
     */
    public function testExportResolvesGroupNameFromServiceWhenSessionHasNoGroupName()
    {
        $userGroupId = self::$faker->randomNumber(3);

        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupId' => $userGroupId,
                                     ]
                                 )
            )
        );

        $this->userGroupService
            ->method('getById')
            ->willReturn(new UserGroupModel(['id' => $userGroupId, 'name' => 'service_group']));

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestTags'));

        $this->xmlAccountExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestAccounts'));

        $out = $this->xmlExport->export($exportPath);

        $xml = new DOMDocument();
        $xml->load($out, LIBXML_NOBLANKS);

        $groupNode = $xml->documentElement->getElementsByTagName('Group')->item(0);

        $this->assertSame('service_group', $groupNode->nodeValue);
    }

    /**
     * If the group service lookup itself fails (e.g. the user's group was deleted between
     * login and export), the export must not blow up: it degrades to an empty group name
     * (still an XSD violation on re-import, but the export -- and everything else in it,
     * the actual account data -- is not lost). Losing the whole backup over a cosmetic
     * <Group> field would be a worse outcome for someone relying on this export.
     *
     * @throws ServiceException
     * @throws Exception
     * @throws FileException
     * @throws CheckException
     * @throws DOMException
     * @throws SPException
     */
    public function testExportUsesEmptyGroupNameWhenServiceLookupFails()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupId' => self::$faker->randomNumber(3),
                                     ]
                                 )
            )
        );

        $this->userGroupService
            ->method('getById')
            ->willThrowException(new RuntimeException('user group service unavailable'));

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestTags'));

        $this->xmlAccountExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestAccounts'));

        $out = $this->xmlExport->export($exportPath);

        $xml = new DOMDocument();
        $xml->load($out, LIBXML_NOBLANKS);

        $groupNode = $xml->documentElement->getElementsByTagName('Group')->item(0);

        $this->assertSame('', $groupNode->nodeValue);
    }

    /**
     * DOMDocument::save() fails silently (returns false, no exception) when the target
     * path can't be opened -- e.g. the export directory doesn't actually exist because
     * DirectoryHandlerService::checkOrCreate() no-ops or a concurrent cleanup removed it.
     * Without this check that failure would be swallowed: export() would report success
     * (return the path) for a backup file that was never written, and the operator would
     * only discover the missing backup when they needed to restore from it.
     *
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws SPException
     */
    public function testExportThrowsWhenXmlFileCannotBeSaved()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(
                                     [
                                         'login' => 'test_user',
                                         'userGroupName' => 'test_group',
                                     ]
                                 )
            )
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        // A directory that checkOrCreate() never actually created on the virtual
        // filesystem: DOMDocument::save() then fails to open the target stream.
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH . '/does-not-exist');

        $this->xmlCategoryExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestCategories'));

        $this->xmlClientExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestClients'));

        $this->xmlTagExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestTags'));

        $this->xmlAccountExportService
            ->expects(self::once())
            ->method('export')
            ->willReturn($this->createNode('TestAccounts'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while creating the XML file');

        // DOMDocument::save() reports the failed stream open as a PHP warning (it does not
        // throw). That warning is expected here -- it is the exact condition under test --
        // so swallow it locally rather than letting it register as a stray suite issue.
        // set_error_handler() pushes onto PHPUnit's own handler stack, so it must be popped
        // with restore_error_handler() (not another set_error_handler() call) or PHPUnit
        // flags the test as risky for leaving the stack unbalanced.
        set_error_handler(static fn(): bool => true);

        try {
            $this->xmlExport->export($exportPath);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * appendMeta() has its own try/catch around building the <User>/<Group>/<Meta> nodes, separate
     * from the outer buildAndSaveXml() one -- so reaching it needs the one call inside appendMeta()
     * that can actually throw. The real Stateless context built for every test never fails
     * getUserData(), so this swaps in a context that does, rather than trying to make the real one
     * fail (nothing in the application ever calls setUserData() with something that would).
     *
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws SPException
     */
    public function testExportWrapsAFailureReadingTheSignedInUser()
    {
        $context = $this->createStub(Context::class);
        $context->method('getUserData')->willThrowException(new RuntimeException('session unavailable'));

        $application = new Application(
            $this->config,
            $this->createStub(EventDispatcherInterface::class),
            $context
        );

        $xmlExport = new XmlExport(
            $application,
            $this->phpExtensionCheckerService,
            $this->xmlClientExportService,
            $this->xmlAccountExportService,
            $this->xmlCategoryExportService,
            $this->xmlTagExportService,
            $this->crypt,
            $this->userGroupService
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('session unavailable');

        $xmlExport->export($exportPath);
    }

    /**
     * appendHash() has its own try/catch too, around computing and appending the closing <Hash>
     * node. The salt is only read when no export password was given -- $key = $password ?:
     * sha1($this->configData->getPasswordSalt() ?? '') -- so an encrypted export never takes this
     * path; this fails ConfigDataInterface::getPasswordSalt() directly to reach it without also
     * having to make the rest of the document-building sequence fail.
     *
     * @throws CheckException
     * @throws Exception
     * @throws FileException
     * @throws ServiceException
     * @throws SPException
     */
    public function testExportWrapsAFailureBuildingTheHash()
    {
        $this->context->setUserData(
            UserDto::fromModel(
                UserDataGenerator::factory()
                                 ->buildUserData()
                                 ->mutate(['login' => 'test_user', 'userGroupName' => 'test_group'])
            )
        );

        $configData = $this->createMock(ConfigDataInterface::class);
        $configData->method('getPasswordSalt')->willThrowException(new RuntimeException('salt unavailable'));

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        $application = new Application(
            $config,
            $this->createStub(EventDispatcherInterface::class),
            $this->context
        );

        $xmlExport = new XmlExport(
            $application,
            $this->phpExtensionCheckerService,
            $this->xmlClientExportService,
            $this->xmlAccountExportService,
            $this->xmlCategoryExportService,
            $this->xmlTagExportService,
            $this->crypt,
            $this->userGroupService
        );

        $exportPath = $this->createMock(DirectoryHandlerService::class);
        $exportPath->expects(self::once())
                   ->method('checkOrCreate');
        $exportPath->method('getPath')
                   ->willReturn(TMP_PATH);

        $this->xmlCategoryExportService->method('export')->willReturn($this->createNode('TestCategories'));
        $this->xmlClientExportService->method('export')->willReturn($this->createNode('TestClients'));
        $this->xmlTagExportService->method('export')->willReturn($this->createNode('TestTags'));
        $this->xmlAccountExportService->method('export')->willReturn($this->createNode('TestAccounts'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('salt unavailable');

        $xmlExport->export($exportPath);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     * @throws ContextException
     * @throws SPException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->phpExtensionCheckerService = $this->createStub(PhpExtensionCheckerService::class);
        $this->xmlClientExportService = $this->createMock(XmlClientExportService::class);
        $this->xmlAccountExportService = $this->createMock(XmlAccountExportService::class);
        $this->xmlCategoryExportService = $this->createMock(XmlCategoryExportService::class);
        $this->xmlTagExportService = $this->createMock(XmlTagExportService::class);
        $this->crypt = $this->createMock(CryptInterface::class);
        $this->userGroupService = $this->createStub(UserGroupService::class);

        $this->xmlExport = new XmlExport(
            $this->application,
            $this->phpExtensionCheckerService,
            $this->xmlClientExportService,
            $this->xmlAccountExportService,
            $this->xmlCategoryExportService,
            $this->xmlTagExportService,
            $this->crypt,
            $this->userGroupService
        );
    }
}
