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

/**
 * @var  PluginModel $plugin
 * @var ThemeIconsInterface $icons
 * @var ConfigDataInterface $configData
 * @var callable $_getvar
 * @var TemplateInterface $this
 */

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Plugin\Models\Plugin as PluginModel;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

$plugin = $_getvar('plugin');
?>

<div id="box-popup">
    <h2 class="center"><?php
        echo $_getvar('header'); ?><i
                class="btn-popup-close material-icons">close</i></h2>

    <form method="post" name="frmCategories" id="frmPlugins">
        <table class="popup-data">
            <tbody>
            <tr>
                <td class="descField"><?php
                    echo __('Name'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="name" name="name" type="text"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               value="<?php
                               echo htmlspecialchars($plugin->getName() ?? '', ENT_QUOTES); ?>" <?php
                        echo $_getvar('readonly'); ?>>
                        <label class="mdl-textfield__label"
                               for="name"><?php
                            echo __('Plugin name'); ?></label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="descField"><?php
                    echo __('Version'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="version" name="version" type="text"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               value="<?php
                               echo htmlspecialchars($plugin->getVersionLevel() ?? '', ENT_QUOTES); ?>" <?php
                        echo $_getvar('readonly'); ?>>
                        <label class="mdl-textfield__label"
                               for="version"><?php
                            echo __('Plugin version'); ?></label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="descField"><?php
                    echo __('Enabled'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="enabled" name="enabled" type="text"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               value="<?php
                               echo $plugin->getEnabled() ? __('Yes') : __('No'); ?>" <?php
                        echo $_getvar('readonly'); ?>>
                        <label class="mdl-textfield__label"
                               for="enabled"><?php
                            echo __('Plugin enabled'); ?></label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="descField"><?php
                    echo __('Available'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="available" name="available" type="text"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               value="<?php
                               echo $plugin->getAvailable() ? __('Yes') : __('No'); ?>" <?php
                        echo $_getvar('readonly'); ?>>
                        <label class="mdl-textfield__label"
                               for="available"><?php
                            echo __('Plugin available'); ?></label>
                    </div>
                </td>
            </tr>
        </table>
    </form>
</div>
