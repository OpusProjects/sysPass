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
use SP\Core\Language;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\LanguageInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\Components\SelectItemAdapter;

use function SP\__;

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
    ) {
        parent::__construct($application, $webControllerHelper);
    }

    #[Action(ResponseType::PLAIN_TEXT)]
    public function indexAction(): ActionResponse
    {
        $skipInstalled = $this->request->analyzeBool('skipInstalled', false);

        if ($skipInstalled === false && $this->configData->isInstalled()) {
            $this->router->response()->redirect('index.php?r=login')->send();

            return ActionResponse::ok('');
        }

        $this->layoutHelper->getPublicLayout('index', 'install');

        $errors = [];

        foreach ($this->extensionChecker->getMissing() as $module) {
            $errors[] = [
                'type'        => SPException::WARNING,
                'description' => sprintf('%s (%s)', __('Module unavailable'), $module),
                'hint'        => __('Without this module the application could not run correctly'),
            ];
        }

        $this->view->assign('errors', $errors);

        $defaultLang = Language::resolveLanguage(
            $this->request->getHeader('Accept-Language')
        );
        $this->language->setLocales($defaultLang);

        $this->view->assign(
            'langs',
            SelectItemAdapter::factory(Language::getAvailableLanguages())
                ->getItemsFromArraySelected([$defaultLang])
        );

        return ActionResponse::ok($this->render());
    }
}
