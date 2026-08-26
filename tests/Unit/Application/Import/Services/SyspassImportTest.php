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

namespace SP\Tests\Unit\Application\Import\Services;

use DOMDocument;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use RuntimeException;
use SP\Infrastructure\Crypt\Crypt;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Category\Models\Category;
use SP\Application\Category\Ports\CategoryService;
use SP\Domain\Client\Models\Client;
use SP\Application\Client\Ports\ClientService;
use SP\Application\Config\Ports\ConfigService;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Import\Dtos\ImportParamsDto;
use SP\Domain\Import\Services\ImportException;
use SP\Application\Import\Services\ImportHelper;
use SP\Application\Import\Services\SyspassImport;
use SP\Domain\Tag\Models\Tag;
use SP\Application\Tag\Ports\TagService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class SyspassImportTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class SyspassImportTest extends UnitaryTestCase
{

    private const SYSPASS_FILE = RESOURCE_PATH . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR .
                                 'data_syspass.xml';

    private const SYSPASS_ENCRYPTED_FILE = RESOURCE_PATH . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR .
                                           'data_syspass_encrypted.xml';

    private AccountService|MockObject  $accountService;
    private MockObject|CategoryService $categoryService;
    private ClientService|MockObject   $clientService;
    private TagService|MockObject      $tagService;
    private CryptInterface|MockObject  $crypt;
    private SyspassImport              $sysPassImport;
    private ConfigService|MockObject   $configService;
    private AccountPresetService|MockObject     $accountPresetService;

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithNoMasterPassword()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::exactly(4))
            ->method('create')
            ->with(self::callback(static fn(Client $client) => !empty($client->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 3)));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('create')
            ->with(
                self::callback(
                    static fn(Category $category) => !empty($category->getName()) &&
                                                     empty($category->getDescription())
                )
            )
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 4)));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Debian'], ['JBoss'], ['MySQL'], ['server'], ['SSH'], ['www'])
            )
            ->willThrowException(NoSuchItemException::error('test'));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('create')
            ->with(self::callback(static fn(Tag $tag) => !empty($tag->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 6)));

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('A valid master password is required to import encrypted accounts.');

        $this->sysPassImport->doImport($importParamsDto);
    }

    /**
     * @param InvokedCount $accountCounter
     * @param AccountCreateDto $dto
     * @return bool
     */
    protected function getCommonAccountMatcher(InvokedCount $accountCounter, AccountCreateDto $dto): bool
    {
        $tagsCount = count(array_filter($dto->tags ?? [], static fn($value) => is_int($value)));

        return match ($accountCounter->numberOfInvocations()) {
            1 => $tagsCount === 3
                 && $dto->name === 'Google'
                 && $dto->login === 'admin'
                 && $dto->url === 'https://google.com'
                 && empty($dto->notes),
            2 => $tagsCount === 3
                 && $dto->name === 'Google'
                 && $dto->login === 'admin'
                 && $dto->url === 'https://google.com'
                 && $dto->notes === 'blablacar',
            3 => $tagsCount === 0
                 && $dto->name === 'Test CSV 1'
                 && $dto->login === 'csv_login1'
                 && $dto->url === 'http://test.me'
                 && $dto->notes === 'CSV Notes',
            4 => $tagsCount === 0
                 && $dto->name === 'Test CSV 2'
                 && $dto->login === 'csv_login2'
                 && $dto->url === 'http://linux.org'
                 && str_starts_with($dto->notes, 'CSV Notes 2'),
            5 => $tagsCount === 0
                 && $dto->name === 'Test CSV 3'
                 && $dto->login === 'csv_login2'
                 && $dto->url === 'http://apple.com'
                 && $dto->notes === 'CSV Notes 3',
        };
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithMasterPassword()
    {
        $importParamsDto = $this->createMock(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);
        $importParamsDto->expects(self::atLeast(7))
                        ->method('getMasterPassword')
                        ->willReturn('a_password');

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::exactly(4))
            ->method('create')
            ->with(self::callback(static fn(Client $client) => !empty($client->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 3)));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('create')
            ->with(
                self::callback(
                    static fn(Category $category) => !empty($category->getName()) &&
                                                     empty($category->getDescription())
                )
            )
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 4)));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Debian'], ['JBoss'], ['MySQL'], ['server'], ['SSH'], ['www'])
            )
            ->willThrowException(NoSuchItemException::error('test'));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('create')
            ->with(self::callback(static fn(Tag $tag) => !empty($tag->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 6)));

        $this->configService
            ->expects(self::once())
            ->method('getByParam')
            ->with('masterPwd')
            ->willReturn(password_hash('a_password', PASSWORD_BCRYPT));

        $this->crypt
            ->expects(self::exactly(5))
            ->method('decrypt')
            ->with(self::anything(), self::anything(), 'a_password')
            ->willReturn('super_secret');

        $accountCounter = new InvokedCount(5);

        $this->accountService
            ->expects($accountCounter)
            ->method('create')
            ->with(
                self::callback(function (AccountCreateDto $dto) use ($accountCounter) {
                    return $dto->clientId > 0
                           && $dto->categoryId > 0
                           && $dto->userId === 100
                           && $dto->userGroupId === 200
                           && $dto->pass === 'super_secret'
                           && empty($dto->key)
                           && $this->getCommonAccountMatcher($accountCounter, $dto);
                })
            );

        $out = $this->sysPassImport->doImport($importParamsDto);

        $this->assertEquals(5, $out->getCounter());
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithItemsByName()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);
        $importParamsDto->method('getMasterPassword')->willReturn('a_password');

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willReturn(...array_map(static fn() => new Client(['id' => self::$faker->randomNumber(3)]), range(0, 3)));

        $this->clientService
            ->expects(self::never())
            ->method('create');

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willReturn(
                ...array_map(static fn() => new Category(['id' => self::$faker->randomNumber(3)]), range(0, 4))
            );

        $this->categoryService
            ->expects(self::never())
            ->method('create');

        $this->tagService
            ->expects(self::exactly(7))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Debian'], ['JBoss'], ['MySQL'], ['server'], ['SSH'], ['www'])
            )
            ->willReturn(...array_map(static fn() => new Tag(['id' => self::$faker->randomNumber(3)]), range(0, 6)));

        $this->tagService
            ->expects(self::never())
            ->method('create');

        $this->configService
            ->expects(self::once())
            ->method('getByParam')
            ->with('masterPwd')
            ->willReturn(password_hash('a_password', PASSWORD_BCRYPT));

        $this->crypt
            ->expects(self::exactly(5))
            ->method('decrypt')
            ->with(self::anything(), self::anything(), 'a_password')
            ->willReturn('super_secret');

        $accountCounter = new InvokedCount(5);

        $this->accountService
            ->expects($accountCounter)
            ->method('create')
            ->with(
                self::callback(function (AccountCreateDto $dto) use ($accountCounter) {
                    return $dto->clientId > 0
                           && $dto->categoryId > 0
                           && $dto->userId === 100
                           && $dto->userGroupId === 200
                           && $dto->pass === 'super_secret'
                           && empty($dto->key)
                           && $this->getCommonAccountMatcher($accountCounter, $dto);
                })
            );

        $out = $this->sysPassImport->doImport($importParamsDto);

        $this->assertEquals(5, $out->getCounter());
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithMasterPasswordAndNoConfigHash()
    {
        $importParamsDto = $this->createMock(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);
        $importParamsDto->expects(self::atLeast(1))
                        ->method('getMasterPassword')
                        ->willReturn('a_password');

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::exactly(4))
            ->method('create')
            ->with(self::callback(static fn(Client $client) => !empty($client->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 3)));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('create')
            ->with(
                self::callback(
                    static fn(Category $category) => !empty($category->getName()) &&
                                                     empty($category->getDescription())
                )
            )
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 4)));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Debian'], ['JBoss'], ['MySQL'], ['server'], ['SSH'], ['www'])
            )
            ->willThrowException(NoSuchItemException::error('test'));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('create')
            ->with(self::callback(static fn(Tag $tag) => !empty($tag->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 6)));

        $this->configService
            ->expects(self::once())
            ->method('getByParam')
            ->with('masterPwd')
            ->willThrowException(NoSuchItemException::error('test'));

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('A valid master password is required to import encrypted accounts.');

        $this->sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithTagException()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willReturn(...array_map(static fn() => new Client(['id' => self::$faker->randomNumber(3)]), range(0, 3)));

        $this->clientService
            ->expects(self::never())
            ->method('create');

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willReturn(
                ...array_map(static fn() => new Category(['id' => self::$faker->randomNumber(3)]), range(0, 4))
            );

        $this->categoryService
            ->expects(self::never())
            ->method('create');

        $this->tagService
            ->expects(self::once(1))
            ->method('getByName')
            ->with('Apache')
            ->willThrowException(new RuntimeException('test'));

        $this->tagService
            ->expects(self::never())
            ->method('create');

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('test');

        $this->sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws Exception
     */
    public function testDoImportWithCategoryException()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::never())
            ->method('getByName');

        $this->clientService
            ->expects(self::never())
            ->method('create');

        $this->categoryService
            ->expects(self::once())
            ->method('getByName')
            ->with('CSV Category 1')
            ->willThrowException(new RuntimeException('test'));

        $this->categoryService
            ->expects(self::never())
            ->method('create');

        $this->tagService
            ->expects(self::never(1))
            ->method('getByName');

        $this->tagService
            ->expects(self::never())
            ->method('create');

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('test');

        $this->sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws Exception
     */
    public function testDoImportWithClientException()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::once())
            ->method('getByName')
            ->with('Apple')
            ->willThrowException(new RuntimeException('test'));

        $this->clientService
            ->expects(self::never())
            ->method('create');

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willReturn(
                ...array_map(static fn() => new Category(['id' => self::$faker->randomNumber(3)]), range(0, 4))
            );

        $this->categoryService
            ->expects(self::never())
            ->method('create');

        $this->tagService
            ->expects(self::never(1))
            ->method('getByName');

        $this->tagService
            ->expects(self::never())
            ->method('create');

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('test');

        $this->sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithEncryptedFile()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->load(self::SYSPASS_ENCRYPTED_FILE, LIBXML_NOBLANKS);

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('test_encrypt');
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::exactly(3))
            ->method('getByName')
            ->with(...self::withConsecutive(['Amazon'], ['Apple'], ['Google']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::exactly(3))
            ->method('create')
            ->with(self::callback(static fn(Client $client) => !empty($client->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 3)));

        $this->categoryService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['AWS'], ['GCP'], ['SSH'], ['Web']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::exactly(4))
            ->method('create')
            ->with(
                self::callback(
                    static fn(Category $category) => !empty($category->getName()) &&
                                                     empty($category->getDescription())
                )
            )
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 4)));

        $this->tagService
            ->expects(self::exactly(6))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Email'], ['JBoss'], ['SaaS'], ['SSH'], ['Tomcat'])
            )
            ->willThrowException(NoSuchItemException::error('test'));

        $this->tagService
            ->expects(self::exactly(6))
            ->method('create')
            ->with(self::callback(static fn(Tag $tag) => !empty($tag->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 6)));

        $this->crypt
            ->expects(self::exactly(4))
            ->method('decrypt')
            ->with(self::anything(), self::anything(), 'test_encrypt')
            ->willReturnCallback(static function (string $encrypted, string $key) {
                return (new Crypt())->decrypt($encrypted, $key, 'test_encrypt');
            });

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('A valid master password is required to import encrypted accounts.');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithEncryptedFileAndCryptoException()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->load(self::SYSPASS_ENCRYPTED_FILE, LIBXML_NOBLANKS);

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('test_encrypt');
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->clientService
            ->expects(self::never())
            ->method('getByName');

        $this->clientService
            ->expects(self::never())
            ->method('create');

        $this->categoryService
            ->expects(self::never())
            ->method('getByName');

        $this->categoryService
            ->expects(self::never())
            ->method('create');

        $this->tagService
            ->expects(self::never())
            ->method('getByName');

        $this->tagService
            ->expects(self::never())
            ->method('create');

        $this->crypt
            ->expects(self::exactly(4))
            ->method('decrypt')
            ->with(self::anything(), self::anything(), 'test_encrypt')
            ->willThrowException(CryptException::error('test'));

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $out = $sysPassImport->doImport($importParamsDto);

        $this->assertEquals(0, $out->getCounter());
    }

    /**
     * An encrypted export cannot be processed without its password: the <Data> blobs are
     * still ciphertext at this point. Without this upfront refusal, doImport() would carry
     * on into checkIntegrity()/processCategories()/etc. and try to read categories, clients
     * and account passwords out of what is still encrypted noise, rather than failing loudly
     * with a clear "you need the password" message.
     *
     * @throws Exception
     */
    public function testDoImportRefusesEncryptedFileWithNoPasswordSupplied()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->load(self::SYSPASS_ENCRYPTED_FILE, LIBXML_NOBLANKS);

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('');

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Encryption password not set');

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * sysPass switched how <Data> ciphertext is stored at 3.2.0: exports from >= 3.2.0 keep
     * the bytes as-is, while older exports (<= 3.1.x, still handled above 2.10) base64-wrap
     * them first. Running a >= 3.2.0 blob through the legacy base64_decode() path would
     * corrupt it before it ever reaches Crypt::decrypt(), turning a perfectly good backup
     * into one that can't be restored. Assert the raw bytes reach decrypt() unmodified.
     *
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportDecryptsRawDataForModernFormatWithoutBase64Decoding()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>320.24010101</Version></Meta>
                <Encrypted>
                    <Data key="somekey">raw-ciphertext-marker</Data>
                </Encrypted>
            </Root>'
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('a_password');
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->crypt
            ->expects(self::once())
            ->method('decrypt')
            ->with(self::identicalTo('raw-ciphertext-marker'), 'somekey', 'a_password')
            ->willReturn('<Dummy/>');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $out = $sysPassImport->doImport($importParamsDto);

        $this->assertEquals(0, $out->getCounter());
    }

    /**
     * Files exported by sysPass <= 2.10 used a since-abandoned encryption scheme. Neither
     * of the two supported decodings (base64-wrapped for 2.10-3.1.x, raw for >= 3.2.0) apply
     * to them, so decrypting the blob as either would hand Crypt::decrypt() garbage and
     * either fail confusingly or -- worse -- "succeed" into corrupted account passwords.
     * Refuse the file outright with a clear message instead.
     *
     * @throws Exception
     */
    public function testDoImportRejectsFilesEncryptedByOldSysPassVersions()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>0.1</Version></Meta>
                <Encrypted>
                    <Data key="somekey">whatever</Data>
                </Encrypted>
            </Root>'
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('a_password');

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The file was exported with an old sysPass version (<= 2.10).');

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * Crypt::decrypt() only throws CryptException when the underlying library detects
     * corruption (e.g. a bad HMAC); it can still return successfully for a wrong password
     * and hand back bytes that simply are not XML. If that garbage were then merged into
     * the document as-is, the rest of the import would silently process whatever DOM nodes
     * happened to survive rather than failing. Treat "decrypted but not XML" the same as a
     * wrong password.
     *
     * @throws Exception
     */
    public function testDoImportTreatsUndecodableDecryptedDataAsWrongPassword()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>320.24010101</Version></Meta>
                <Encrypted>
                    <Data key="somekey">ciphertext</Data>
                </Encrypted>
            </Root>'
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getPassword')->willReturn('a_password');

        $this->crypt
            ->expects(self::once())
            ->method('decrypt')
            ->willReturn('this is not xml');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Wrong encryption password');

        // DOMDocument::loadXML() reports the malformed input as a PHP warning rather than
        // throwing (it returns false instead); that warning is the exact condition under
        // test, so swallow it locally instead of letting it register as a stray suite issue.
        set_error_handler(static fn(): bool => true);

        try {
            $sysPassImport->doImport($importParamsDto);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An account whose <categoryId> points at a category id absent from the file's own
     * <Categories> catalog cannot be assigned a category at all -- getOrSetCache() has
     * nothing cached under that id and, called here with no builder callback, returns null.
     * ImportBase::addAccount() must refuse that account rather than create it with a missing
     * category (which the accounts table's schema disallows, but the failure should be a
     * clear import-time refusal, not a fallthrough to a DB constraint error).
     *
     * @throws Exception
     */
    public function testDoImportAbortsWhenAccountReferencesUnknownCategory()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>300.18071701</Version></Meta>
                <Accounts>
                    <Account id="1">
                        <name>Orphan Category Account</name>
                        <clientId>1</clientId>
                        <categoryId>999</categoryId>
                        <login>user</login>
                    </Account>
                </Accounts>
            </Root>',
            LIBXML_NOBLANKS
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Category Id not set. Unable to import account.');

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * Same refusal as the orphaned-category case above, but for <clientId>: an account
     * referencing a client id that was never declared in <Clients> must abort the import
     * rather than be created with no client.
     *
     * @throws Exception
     */
    public function testDoImportAbortsWhenAccountReferencesUnknownClient()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>300.18071701</Version></Meta>
                <Categories>
                    <Category id="1"><name>Cat1</name></Category>
                </Categories>
                <Accounts>
                    <Account id="1">
                        <name>Orphan Client Account</name>
                        <clientId>999</clientId>
                        <categoryId>1</categoryId>
                        <login>user</login>
                    </Account>
                </Accounts>
            </Root>',
            LIBXML_NOBLANKS
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);

        $this->categoryService
            ->expects(self::once())
            ->method('getByName')
            ->with('Cat1')
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::once())
            ->method('create')
            ->willReturn(1);

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Client Id not set. Unable to import account.');

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * A per-account password encrypted by sysPass <= 2.10 uses a format this importer does
     * not support decrypting (see ImportBase::addAccount()'s version guard). Without it, an
     * old export's still-encrypted ciphertext could be handed to Crypt::decrypt() as if it
     * were a supported format and produce corrupted plaintext saved as the account password
     * instead of a clear refusal.
     *
     * @throws Exception
     */
    public function testDoImportRejectsEncryptedAccountsFromOldSysPassVersions()
    {
        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $document = new DOMDocument();
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>
            <Root>
                <Meta><Version>0.1</Version></Meta>
                <Categories>
                    <Category id="1"><name>Cat1</name></Category>
                </Categories>
                <Clients>
                    <Client id="1"><name>Client1</name></Client>
                </Clients>
                <Accounts>
                    <Account id="1">
                        <name>Old Format Account</name>
                        <clientId>1</clientId>
                        <categoryId>1</categoryId>
                        <login>user</login>
                        <pass>ciphertext</pass>
                        <key>somekey</key>
                    </Account>
                </Accounts>
            </Root>',
            LIBXML_NOBLANKS
        );

        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);
        $importParamsDto->method('getMasterPassword')->willReturn('a_password');

        $this->categoryService
            ->expects(self::once())
            ->method('getByName')
            ->with('Cat1')
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::once())
            ->method('create')
            ->willReturn(1);

        $this->clientService
            ->expects(self::once())
            ->method('getByName')
            ->with('Client1')
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::once())
            ->method('create')
            ->willReturn(1);

        $this->configService
            ->expects(self::once())
            ->method('getByParam')
            ->with('masterPwd')
            ->willReturn(password_hash('a_password', PASSWORD_BCRYPT));

        $this->crypt
            ->expects(self::never())
            ->method('decrypt');

        $this->accountService
            ->expects(self::never())
            ->method('create');

        $sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The file was exported with an old sysPass version (<= 2.10).');

        $sysPassImport->doImport($importParamsDto);
    }

    /**
     * @throws ImportException
     * @throws Exception
     */
    public function testDoImportWithAccountException()
    {
        $importParamsDto = $this->createStub(ImportParamsDto::class);
        $importParamsDto->method('getDefaultUser')->willReturn(100);
        $importParamsDto->method('getDefaultGroup')->willReturn(200);
        $importParamsDto->method('getMasterPassword')->willReturn('a_password');

        $this->clientService
            ->expects(self::exactly(4))
            ->method('getByName')
            ->with(...self::withConsecutive(['Apple'], ['CSV Client 1'], ['Google'], ['KK']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->clientService
            ->expects(self::exactly(4))
            ->method('create')
            ->with(self::callback(static fn(Client $client) => !empty($client->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 3)));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('getByName')
            ->with(...self::withConsecutive(['CSV Category 1'], ['Linux'], ['SSH'], ['Test'], ['Web']))
            ->willThrowException(NoSuchItemException::error('test'));

        $this->categoryService
            ->expects(self::exactly(5))
            ->method('create')
            ->with(
                self::callback(
                    static fn(Category $category) => !empty($category->getName()) &&
                                                     empty($category->getDescription())
                )
            )
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 4)));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('getByName')
            ->with(
                ...self::withConsecutive(['Apache'], ['Debian'], ['JBoss'], ['MySQL'], ['server'], ['SSH'], ['www'])
            )
            ->willThrowException(NoSuchItemException::error('test'));

        $this->tagService
            ->expects(self::exactly(7))
            ->method('create')
            ->with(self::callback(static fn(Tag $tag) => !empty($tag->getName())))
            ->willReturn(...array_map(static fn() => self::$faker->randomNumber(3), range(0, 6)));

        $this->configService
            ->expects(self::once())
            ->method('getByParam')
            ->with('masterPwd')
            ->willReturn(password_hash('a_password', PASSWORD_BCRYPT));

        $this->crypt
            ->expects(self::exactly(5))
            ->method('decrypt')
            ->with(self::anything(), self::anything(), 'a_password')
            ->willReturn('super_secret');

        $this->accountService
            ->expects(self::exactly(5))
            ->method('create')
            ->willThrowException(new RuntimeException('test'));

        $out = $this->sysPassImport->doImport($importParamsDto);

        $this->assertEquals(0, $out->getCounter());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountService = $this->createMock(AccountService::class);
        $this->categoryService = $this->createMock(CategoryService::class);
        $this->clientService = $this->createMock(ClientService::class);
        $this->tagService = $this->createMock(TagService::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->accountPresetService = $this->createStub(AccountPresetService::class);
        // The lifetime clamp is applied on import; by default it changes nothing.
        $this->accountPresetService->method('checkPasswordExpiry')
                                  ->willReturnArgument(0);

        $importHelper = new ImportHelper(
            $this->accountService,
            $this->categoryService,
            $this->clientService,
            $this->tagService,
            $this->configService,
            $this->accountPresetService
        );

        $this->crypt = $this->createMock(CryptInterface::class);

        $document = new DOMDocument();
        $document->load(self::SYSPASS_FILE, LIBXML_NOBLANKS);

        $this->sysPassImport = new SyspassImport($this->application, $importHelper, $this->crypt, $document);
    }

}
