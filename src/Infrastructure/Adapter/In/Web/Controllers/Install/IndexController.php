<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Install;

use SP\Core\Application;
use SP\Core\Bootstrap\Path;
use SP\Core\Bootstrap\PathsContext;
use SP\Core\Language;
use SP\Core\PhpExtensionChecker;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Common\Providers\Environment;
use SP\Domain\Core\LanguageInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\Components\SelectItemAdapter;

/**
 * Class IndexController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class IndexController extends ControllerBase
{
    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        private readonly LanguageInterface $language,
        private readonly PathsContext $pathsContext,
    ) {
        parent::__construct($application, $webControllerHelper);
    }

    #[Action(ResponseType::PLAIN_TEXT)]
    public function indexAction(): ActionResponse
    {
        // skipInstalled is a dev/testing override — honor it only under DEBUG.
        // On an installed instance it would otherwise render the requirements page
        // (PHP version, absolute paths, extension inventory) to an anonymous
        // visitor; the install/checkConnection actions already refuse when
        // installed, so it never enabled any actual action anyway.
        $skipInstalled = (defined('DEBUG') && DEBUG === true)
                         && $this->request->analyzeBool('skipInstalled', false);

        if ($skipInstalled === false && $this->configData->isInstalled()) {
            $this->router->response()->redirect('index.php?r=login')->send();

            return ActionResponse::ok('');
        }

        $this->layoutHelper->getPublicLayout('index', 'install');

        // Module warnings render as a checklist in the wizard's Requirements step
        $this->view->assign('errors', []);
        $this->view->assign('phpVersion', PHP_VERSION);
        $this->view->assign('phpVersionOk', Environment::checkPhpVersion());
        $missing = $this->extensionChecker->getMissing();
        $missingRequired = array_keys(array_filter($missing, fn(bool $required) => $required));
        $missingOptional = array_keys(array_filter($missing, fn(bool $required) => !$required));
        $this->view->assign('missingRequired', $missingRequired);
        $this->view->assign('missingOptional', $missingOptional);

        $available = array_diff(array_keys(PhpExtensionChecker::EXTENSIONS), array_keys($missing));
        $this->view->assign(
            'availableRequired',
            array_values(array_filter($available, fn(string $ext) => PhpExtensionChecker::EXTENSIONS[$ext] === true))
        );
        $this->view->assign(
            'availableOptional',
            array_values(array_filter($available, fn(string $ext) => PhpExtensionChecker::EXTENSIONS[$ext] === false))
        );
        $configDir = $this->pathsContext[Path::CONFIG];
        $this->view->assign('configWritable', is_writable($configDir));
        $this->view->assign('configDir', $configDir);

        $varDirs = [
            'backup' => $this->pathsContext[Path::BACKUP],
            'cache'  => $this->pathsContext[Path::CACHE],
            'tmp'    => $this->pathsContext[Path::TMP],
        ];
        $this->view->assign('varDirs', $varDirs);
        $this->view->assign('varDirsWritable', array_reduce(
            $varDirs,
            static fn(bool $carry, string $path) => $carry && is_writable($path),
            true
        ));

        $this->view->assign('sessionStrictMode', (bool) ini_get('session.use_strict_mode'));

        // An explicit ?lang= choice (from the wizard's language step) overrides browser
        // detection. On a reload without the param (the wizard strips it from the URL),
        // a previously chosen language survives in the session
        $requestLang = $this->request->analyzeString('lang');
        $langChanged = $requestLang !== null
                       && array_key_exists($requestLang, Language::getAvailableLanguages());

        $lang = $langChanged
            ? $requestLang
            : ($this->session->getLocale()
               ?: Language::resolveLanguage($this->request->getHeader('Accept-Language')));

        $this->language->setLocales($lang);
        // Remember the choice so the JS string catalog (bootstrap/getEnvironment)
        // is served in the same language
        $this->session->setLocale($lang);

        $this->view->assign(
            'langs',
            SelectItemAdapter::factory(Language::getAvailableLanguages())
                ->getItemsFromArraySelected([$lang])
        );
        // The language selector lives on the first step, so a language-switch reload
        // resumes there — same as a fresh start.
        $this->view->assign('startStep', 1);

        return ActionResponse::ok($this->render());
    }
}
