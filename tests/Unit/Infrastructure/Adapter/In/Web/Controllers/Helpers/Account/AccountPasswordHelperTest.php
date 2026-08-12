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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use SP\Application\Crypt\Ports\MasterPassService;
use SP\Domain\Account\Adapters\AccountPassItemWithIdAndName;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Image\Ports\ImageService;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountPasswordHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\HelperException;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * This is the last thing standing between a stored account and a password on screen, so what it
 * refuses matters more than what it shows.
 *
 * The refusals cannot be reached from the integration suite — its ACL is stubbed open — so they are
 * covered here, and each one asserts the decryption never happened rather than only that an
 * exception came back.
 */
#[Group('unitary')]
class AccountPasswordHelperTest extends UnitaryTestCase
{
    private AclInterface|MockObject $acl;
    private MasterPassService|Stub $masterPassService;
    private CryptInterface|MockObject $crypt;
    private ImageService|Stub $imageUtil;
    private TemplateInterface|Stub $view;
    private AccountPasswordHelper $helper;

    /** @var array<string, mixed> What the template was handed, by name */
    private array $assigned = [];

    /**
     * Without the view-password permission the account's password is not decrypted at all — the
     * check comes first, so the plaintext never exists to be leaked by a later mistake.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function aUserWithoutThePermissionIsRefused()
    {
        $this->givenTheUserMay(false);

        $this->crypt->expects(self::never())->method('decrypt');

        $this->expectException(HelperException::class);
        $this->expectExceptionMessage('You don\'t have permission to access this account');

        $this->helper->getPasswordClear($this->buildAccountPass());
    }

    /**
     * The rendered view is refused for the same reason, and before the template is touched.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function theViewIsRefusedWithoutThePermissionToo()
    {
        $this->givenTheUserMay(false);

        $this->crypt->expects(self::never())->method('decrypt');

        $view = $this->createMock(TemplateInterface::class);
        $view->expects(self::never())->method('render');

        $this->expectException(HelperException::class);

        $this->buildHelper($view)->getPasswordView($this->buildAccountPass(), false);
    }

    /**
     * A session that predates a master password change cannot decrypt anything — its session key
     * belongs to the old master password — so the user is told to sign in again rather than being
     * shown whatever the old key produces.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function aStaleSessionIsRefused()
    {
        $this->givenTheUserMay(true);
        $this->masterPassService->method('checkUserUpdateMPass')->willReturn(false);

        $this->crypt->expects(self::never())->method('decrypt');

        $this->expectException(HelperException::class);
        $this->expectExceptionMessage('Please, restart the session for update it');

        $this->helper->getPasswordClear($this->buildAccountPass());
    }

    /**
     * The stored password is decrypted with the account's own key and the session key, and comes
     * back trimmed — a stored value padded with whitespace would otherwise be copied with it.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function anAllowedUserGetsTheDecryptedPassword()
    {
        $this->givenTheUserMay(true);
        $this->masterPassService->method('checkUserUpdateMPass')->willReturn(true);

        $this->crypt
            ->expects(self::once())
            ->method('decrypt')
            ->with('encrypted-pass', 'account-key', 'session-key')
            ->willReturn("  the secret  ");

        self::assertSame('the secret', $this->helper->getPasswordClear($this->buildAccountPass()));
    }

    /**
     * The password reaches the page escaped. It is arbitrary user-stored text on a page that is
     * built by hand, so an account whose password contains markup must not be able to inject any.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function theViewEscapesWhatItShows()
    {
        $this->givenTheUserMay(true);
        $this->masterPassService->method('checkUserUpdateMPass')->willReturn(true);
        $this->crypt->expects(self::once())->method('decrypt')->willReturn('<script>alert(1)</script>');

        $this->helper->getPasswordView($this->buildAccountPass('<b>login</b>'), false);

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $this->assigned['pass']);
        self::assertSame('&lt;b&gt;login&lt;/b&gt;', $this->assigned['login']);
    }

    /**
     * Asked for an image, the page gets the rendered image instead of the text — that is the whole
     * point of the setting, so the plaintext must not be assigned as well.
     *
     * @throws CryptException
     * @throws HelperException
     */
    #[Test]
    public function theImageViewShowsNoPlaintext()
    {
        $this->givenTheUserMay(true);
        $this->masterPassService->method('checkUserUpdateMPass')->willReturn(true);
        $this->crypt->expects(self::once())->method('decrypt')->willReturn('the secret');
        $this->imageUtil->method('convertText')->willReturn('<img src="data:image/png;base64,...">');

        $result = $this->helper->getPasswordView($this->buildAccountPass(), true);

        self::assertTrue($result['useimage']);
        self::assertStringNotContainsString('the secret', (string)$this->assigned['pass']);
        self::assertStringContainsString('<img', (string)$this->assigned['pass']);
    }

    /**
     * The context has to be session-backed — the helper reads the signed-in user off it.
     */
    protected function buildContext(): Context
    {
        $context = self::createStub(SessionContext::class);
        $context->method('getUserData')->willReturn(new UserDto(id: 1, lastUpdateMPass: 0, login: 'someone'));

        return $context;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->acl = $this->createMock(AclInterface::class);
        $this->masterPassService = $this->createStub(MasterPassService::class);
        $this->crypt = $this->createMock(CryptInterface::class);
        $this->imageUtil = $this->createStub(ImageService::class);
        $this->view = $this->createStub(TemplateInterface::class);
        $this->view
             ->method('assign')
             ->willReturnCallback(function (string $name, mixed $value) {
                 $this->assigned[$name] = $value;
             });

        $this->helper = $this->buildHelper($this->view);
    }

    private function buildHelper(TemplateInterface $view): AccountPasswordHelper
    {
        $sessionKeyService = $this->createStub(SessionKeyService::class);
        $sessionKeyService->method('getSessionKey')->willReturn('session-key');

        return new AccountPasswordHelper(
            $this->application,
            $view,
            $this->createStub(RequestService::class),
            $this->acl,
            $this->imageUtil,
            $this->masterPassService,
            $this->crypt,
            $sessionKeyService
        );
    }

    private function givenTheUserMay(bool $may): void
    {
        $this->acl
            ->expects(self::atLeastOnce())
            ->method('checkUserAccess')
            ->with(AclActionsInterface::ACCOUNT_VIEW_PASS)
            ->willReturn($may);
    }

    private function buildAccountPass(string $login = 'someone'): AccountPassItemWithIdAndName
    {
        return new AccountPassItemWithIdAndName(
            ['id' => 1, 'name' => 'an account', 'login' => $login, 'pass' => 'encrypted-pass', 'key' => 'account-key']
        );
    }
}
