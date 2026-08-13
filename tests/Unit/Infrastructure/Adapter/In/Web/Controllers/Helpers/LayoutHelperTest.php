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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserPreferences;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Covers the page shell every web view is rendered into. It had no tests: nothing exercised
 * getFullLayout(), initBody(), getResourcesLinks(), getSessionBar() or getMenu() at all.
 *
 * The behaviour that matters is what the shell includes or leaves out — the session bar and the
 * management menu only for a signed-in visitor, the top menu's items only for the access an ACL
 * actually grants, and which theme/asset bundle gets linked depending on configuration.
 */
#[Group('unitary')]
class LayoutHelperTest extends UnitaryTestCase
{
    private RecordingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = new RecordingTemplate();
    }

    /**
     * A signed-in visitor gets the session bar's data; a signed-out one does not, since there is no
     * user to describe.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theSessionBarIsPopulatedOnlyForASignedInVisitor(): void
    {
        $loggedOut = $this->buildHelper(loggedIn: false);
        $loggedOut->getFullLayout('index');

        self::assertArrayNotHasKey('ctx_userLogin', $this->template->assigned);

        $this->template = new RecordingTemplate();
        $loggedIn = $this->buildHelper(loggedIn: true, login: 'jdoe');
        $loggedIn->getFullLayout('index');

        self::assertSame('JDOE', $this->template->assigned['ctx_userLogin']);
    }

    /**
     * The management menu is built only when the caller hands the layout an ACL — the public
     * (unauthenticated) pages call getFullLayout() without one.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theMenuIsBuiltOnlyWhenAnAclIsGiven(): void
    {
        $withoutAcl = $this->buildHelper(loggedIn: true);
        $withoutAcl->getFullLayout('index');

        self::assertArrayNotHasKey('useMenu', $this->template->assigned);
        self::assertArrayNotHasKey('actions', $this->template->assigned);

        $this->template = new RecordingTemplate();

        $acl = $this->createStub(AclInterface::class);
        $withAcl = $this->buildHelper(loggedIn: true, acl: $acl);
        $withAcl->getFullLayout('index', $acl);

        self::assertTrue($this->template->assigned['useMenu']);
        self::assertNotEmpty($this->template->assigned['actions']);
    }

    /**
     * A visitor with none of the manager grants sees only the search entry — the one item that
     * needs no permission of its own.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aVisitorWithNoGrantsIsOfferedOnlyTheSearchMenuItem(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);

        $helper = $this->buildHelper(loggedIn: true, acl: $acl);
        $helper->getMenu($acl);

        self::assertCount(1, $this->template->assigned['actions']);
        self::assertSame(AclActionsInterface::ACCOUNT, (int)$this->template->assigned['actions'][0]->getId());
    }

    /**
     * Each manager grant adds exactly its own entry — the case worth checking is that a refused
     * grant does not leak its entry onto the menu.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function onlyTheGrantedManagerEntriesAreOfferedOnTheMenu(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturnCallback(
            static fn(int $actionId) => $actionId === AclActionsInterface::ACCESS_MANAGE
        );

        $helper = $this->buildHelper(loggedIn: true, acl: $acl);
        $helper->getMenu($acl);

        $ids = array_map(
            static fn($action) => (int)$action->getId(),
            $this->template->assigned['actions']
        );

        self::assertContains(AclActionsInterface::ACCOUNT, $ids, 'search is always offered');
        self::assertContains(AclActionsInterface::ACCESS_MANAGE, $ids, 'the one granted entry is offered');
        self::assertNotContains(AclActionsInterface::ACCOUNT_CREATE, $ids);
        self::assertNotContains(AclActionsInterface::ITEMS_MANAGE, $ids);
        self::assertNotContains(AclActionsInterface::SECURITY_MANAGE, $ids);
        self::assertNotContains(AclActionsInterface::PLUGIN, $ids);
        self::assertCount(2, $ids, 'search plus exactly the one granted entry');
    }

    /**
     * A full grant set adds every entry on top of search.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aFullGrantSetOffersEveryManagerEntry(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);

        $helper = $this->buildHelper(loggedIn: true, acl: $acl);
        $helper->getMenu($acl);

        // Search, plus the six permission-gated entries (new account, access manager, items
        // manager, security manager, plugins, configuration).
        self::assertCount(7, $this->template->assigned['actions']);
    }

    /**
     * The base URL used to build every asset link prefers an explicitly configured application URL
     * over the request's own detected URI.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function initBodyPrefersTheConfiguredApplicationUrlOverTheDetectedUri(): void
    {
        $helper = $this->buildHelper(loggedIn: false, applicationUrl: 'https://configured.example');
        $helper->initBody();

        self::assertStringStartsWith(
            'https://configured.example',
            $this->template->assigned['logo_icon']
        );
    }

    /**
     * Without a configured application URL, the base falls back to the request's own detected URI.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function initBodyFallsBackToTheDetectedUriWhenNoApplicationUrlIsConfigured(): void
    {
        $helper = $this->buildHelper(loggedIn: false, applicationUrl: null, webUri: 'https://detected.example');
        $helper->initBody();

        self::assertStringStartsWith(
            'https://detected.example',
            $this->template->assigned['logo_icon']
        );
    }

    /**
     * The public key is refreshed into the session on every page, but a failure to do so (a broken
     * PKI store, for instance) must not stop the page from rendering — it is logged and swallowed.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aFailingPublicKeyLookupDoesNotStopThePageFromRendering(): void
    {
        $cryptPki = $this->createStub(CryptPKIHandler::class);
        $cryptPki->method('getPublicKey')->willThrowException(SPException::error('key store unavailable'));

        $helper = $this->buildHelper(loggedIn: false, cryptPki: $cryptPki);

        // Would throw and abort before reaching the resource links if the exception were not
        // swallowed.
        $helper->initBody();

        self::assertNotEmpty($this->template->assigned['jsLinks']);
    }

    /**
     * The theme's own JS/CSS bundle is linked only when the active theme declares one; a theme with
     * neither gets only the application's own bundles.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theThemeBundleLinksAreIncludedOnlyWhenTheThemeDeclaresThem(): void
    {
        $bare = $this->buildHelper(loggedIn: false, themeInfo: []);
        $bare->initBody();

        self::assertCount(2, $this->template->assigned['jsLinks'], 'app + vendor bundles only');
        self::assertCount(1, $this->template->assigned['cssLinks'], 'app bundle only');

        $this->template = new RecordingTemplate();

        // Also drives resultsAsCards to true and Dokuwiki on, so the theme's own CSS list picks
        // up both the "cards" results bundle and the wiki stylesheet, alongside its declared JS.
        $themed = $this->buildHelper(
            loggedIn: false,
            configResultsAsCards: true,
            dokuwikiEnabled: true,
            themeInfo: ['js' => ['a.js'], 'css' => ['a.css']]
        );
        $themed->initBody();

        self::assertCount(3, $this->template->assigned['jsLinks'], 'app + vendor + theme bundles');
        self::assertCount(2, $this->template->assigned['cssLinks'], 'app + theme bundles');

        $this->template = new RecordingTemplate();

        // A theme CSS bundle with the results layout left at its "grid" default (no cards, no
        // Dokuwiki) is otherwise indistinguishable from the block above from a link count alone,
        // but it exercises the other side of the results-layout choice.
        $grid = $this->buildHelper(
            loggedIn: false,
            configResultsAsCards: false,
            themeInfo: ['css' => ['a.css']]
        );
        $grid->initBody();

        self::assertCount(2, $this->template->assigned['cssLinks'], 'app + theme bundles');
    }

    /**
     * The session bar's icon follows the signed-in user's admin role: application administrators
     * and account administrators each get their own icon, and a plain user gets none.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theSessionBarIconMatchesTheSignedInUsersAdminRole(): void
    {
        $appAdmin = $this->buildHelper(loggedIn: true, isAdminApp: true);
        $appAdmin->getSessionBar();

        self::assertNotNull($this->template->assigned['ctx_userType']);

        $this->template = new RecordingTemplate();

        $accAdmin = $this->buildHelper(loggedIn: true, isAdminAcc: true);
        $accAdmin->getSessionBar();

        self::assertNotNull($this->template->assigned['ctx_userType']);

        $this->template = new RecordingTemplate();

        $plainUser = $this->buildHelper(loggedIn: true);
        $plainUser->getSessionBar();

        self::assertNull($this->template->assigned['ctx_userType']);
    }

    /**
     * A public page (e.g. login) always gets a fixed header, whatever the caller asks for.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function getPublicLayoutAlwaysForcesAFixedHeaderAndAddsTheGivenTemplate(): void
    {
        $helper = $this->buildHelper(loggedIn: false);
        $result = $helper->getPublicLayout('login', 'login');

        self::assertSame($helper, $result, 'fluent, for chaining onto the response');
        self::assertSame('main', $this->template->layout);
        self::assertContains('login', $this->template->contentTemplates);
        self::assertTrue($this->template->assigned['useFixedHeader']);
        self::assertSame('login', $this->template->assigned['page']);
    }

    /**
     * A custom layout page does not force the fixed header the public layout does.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function getCustomLayoutDoesNotForceAFixedHeader(): void
    {
        $helper = $this->buildHelper(loggedIn: false);
        $helper->getCustomLayout('custom', 'custom-page');

        self::assertSame('main', $this->template->layout);
        self::assertContains('custom', $this->template->contentTemplates);
        self::assertArrayNotHasKey('useFixedHeader', $this->template->assigned);
        self::assertSame('custom-page', $this->template->assigned['page']);
    }

    /**
     * Which of the two search-results CSS bundles is linked depends on the "results as cards"
     * preference — driven by the signed-in user's own preference when they have one recorded, and
     * by the instance-wide default otherwise. The bundle choice is baked into a version hash on the
     * asset link, so the two are told apart by that hash rather than by a filename.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theSignedInUsersPreferenceOverridesTheConfigDefaultForTheResultsLayout(): void
    {
        $version = Version::getVersionStringNormalized();

        $preferences = new UserPreferences(['user_id' => 5, 'resultsAsCards' => true]);
        $helper = $this->buildHelper(
            loggedIn: true,
            preferences: $preferences,
            configResultsAsCards: false
        );
        $helper->initBody();

        self::assertStringContainsString(
            'v=' . sha1($version . true),
            $this->template->assigned['cssLinks'][0],
            'the preference (cards) wins over the config default (grid)'
        );
    }

    /**
     * With no recorded preference (a fresh account, or a signed-out visitor), the instance-wide
     * default drives the results layout.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function theConfigDefaultDrivesTheResultsLayoutWithoutASignedInPreference(): void
    {
        $version = Version::getVersionStringNormalized();

        $helper = $this->buildHelper(loggedIn: false, preferences: null, configResultsAsCards: true);
        $helper->initBody();

        self::assertStringContainsString(
            'v=' . sha1($version . true),
            $this->template->assigned['cssLinks'][0]
        );
    }

    /**
     * @throws Exception
     * @throws SPException
     */
    private function buildHelper(
        bool                $loggedIn,
        ?string             $login = null,
        ?string             $name = null,
        bool                $isAdminApp = false,
        bool                $isAdminAcc = false,
        bool                $isLdapUser = false,
        bool                $ldapEnabled = false,
        ?UserPreferences    $preferences = null,
        bool                $configResultsAsCards = false,
        bool                $dokuwikiEnabled = false,
        ?string             $applicationUrl = null,
        string              $webUri = 'https://syspass.invalid',
        ?AclInterface       $acl = null,
        ?CryptPKIHandler    $cryptPki = null,
        array               $themeInfo = [],
    ): LayoutHelper {
        $userDto = new UserDto(
            id: 7,
            userGroupId: 2,
            login: $login ?? 'jdoe',
            name: $name,
            isAdminApp: $isAdminApp,
            isAdminAcc: $isAdminAcc,
            isLdap: $isLdapUser,
            preferences: $preferences,
        );

        $session = $this->createStub(SessionContext::class);
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getAuthCompleted')->willReturn(true);
        $session->method('getUserData')->willReturn($userDto);

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isInstalled')->willReturn(true);
        $configData->method('getApplicationUrl')->willReturn($applicationUrl);
        $configData->method('isResultsAsCards')->willReturn($configResultsAsCards);
        $configData->method('isDokuwikiEnabled')->willReturn($dokuwikiEnabled);
        $configData->method('isLdapEnabled')->willReturn($ldapEnabled);
        $configData->method('getPasswordSalt')->willReturn('the-password-salt');

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $application = new Application($config, $eventDispatcher, $session);

        $request = $this->createStub(RequestService::class);
        $request->method('isHttps')->willReturn(false);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getInfo')->willReturn($themeInfo);
        $theme->method('getPath')->willReturn('material-blue');

        $cryptPki ??= $this->createStub(CryptPKIHandler::class);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebUri')->willReturn($webUri);
        $uriContext->method('getSubUri')->willReturn('');

        $acl ??= $this->createStub(AclInterface::class);

        return new LayoutHelper(
            $application,
            $this->template,
            $request,
            $theme,
            $cryptPki,
            $uriContext,
            $acl
        );
    }
}

/**
 * A minimal in-memory TemplateInterface that records what LayoutHelper assigns to it, so tests can
 * inspect the resulting view state directly instead of rendering real template files.
 */
final class RecordingTemplate implements TemplateInterface
{
    /** @var array<string, mixed> */
    public array $assigned = [];

    /** @var string[] */
    public array $contentTemplates = [];

    public ?string $layout = null;

    public function addContentTemplate(string $name, ?string $base = null): void
    {
        $this->contentTemplates[] = $name;
    }

    public function remove(string $name): void
    {
    }

    public function addTemplate(string $name, ?string $base = null): void
    {
    }

    public function includePartial(string $name): string
    {
        return '';
    }

    public function includeTemplate(string $name, ?string $base = null): string
    {
        return '';
    }

    public function render(): string
    {
        return '';
    }

    public function append(string $name, mixed $value): void
    {
        $this->assigned[$name][] = $value;
    }

    public function reset(): void
    {
        $this->assigned = [];
    }

    public function getBase(): string
    {
        return '';
    }

    public function getContentTemplates(): array
    {
        return [];
    }

    public function upgrade(): void
    {
    }

    public function assign(string $name, mixed $value): void
    {
        $this->assigned[$name] = $value;
    }

    public function setLayout(string $name): void
    {
        $this->layout = $name;
    }

    public function addPartial(string $name): void
    {
    }

    public function assignWithScope(string $name, mixed $value, string $scope): void
    {
        $this->assigned[sprintf('%s_%s', $scope, $name)] = $value;
    }
}
