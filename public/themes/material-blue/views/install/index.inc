<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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
 * @var ThemeIconsInterface $icons
 * @var ConfigDataInterface $configData
 * @var callable $_getvar
 * @var TemplateInterface $this
 */

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Mvc\View\Components\SelectItem;
use SP\Mvc\View\TemplateInterface;

use function SP\__;

?>
<div id="actions" class="installer" align="center">

    <?php
    include $this->includePartial('error-list'); ?>

    <form id="frmInstall" class="form-action" data-onsubmit="main/install"
          data-route="install/install">

        <div class="installer-card">

            <div class="installer-card-header">
                <h1><?php
                    printf('%s %s — %s', $_getvar('app_name'), $_getvar('app_version'), __('Installation')); ?></h1>
            </div>

            <input type="hidden" name="install" value="true"/>
            <input type="hidden" name="isAjax" value="1"/>

            <div class="installer-section" id="adminaccount">
                <h2 class="installer-section-title"><?php
                    echo __('sysPass Admin'); ?></h2>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="adminlogin" name="adminlogin" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="admin" maxlength="80" autocomplete="off"
                           tabindex="0"
                           autofocus>
                    <label class="mdl-textfield__label"
                           for="adminlogin"><?php
                        echo __('sysPass admin user'), ':'; ?></label>
                </div>

                <div id="help-login"
                     class="icon material-icons <?php
                     echo $icons->help()->getClass(); ?>"
                     role="button" aria-label="<?php echo __('Help'); ?>">
                    <?php
                    echo $icons->help()->getIcon(); ?>
                </div>
                <div class="mdl-tooltip" for="help-login">
                    <?php
                    echo __('sysPass administrator\'s login'); ?>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="adminpass" name="adminpass" type="password" required
                           class="mdl-textfield__input mdl-color-text--indigo-400 passwordfield__input"
                           value="" maxlength="255" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="adminpass"><?php
                        echo __('Password'), ':'; ?></label>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="adminpass_repeat" name="adminpassr" type="password"
                           required
                           class="mdl-textfield__input mdl-color-text--indigo-400 passwordfield__input-show"
                           value="" maxlength="255" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="adminpass_repeat"><?php
                        echo __('Password (repeat)'), ':'; ?></label>
                </div>
            </div>

            <hr class="installer-divider">

            <div class="installer-section" id="masterpwd">
                <h2 class="installer-section-title"><?php
                    echo __('Master Password'); ?></h2>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="masterpassword" name="masterpassword" type="password"
                           required
                           class="mdl-textfield__input mdl-color-text--indigo-400 passwordfield__input"
                           value="" maxlength="255" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="masterpassword"><?php
                        echo __('Master Password'), ':'; ?></label>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="masterpassword_repeat" name="masterpasswordr"
                           type="password" required
                           class="mdl-textfield__input mdl-color-text--indigo-400 passwordfield__input-show"
                           value="" maxlength="255" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="masterpassword_repeat"><?php
                        echo __('Password (repeat)'), ':'; ?></label>
                </div>
            </div>

            <hr class="installer-divider">

            <div class="installer-section" id="databaseField">
                <h2 class="installer-section-title"><?php
                    echo __('DB Configuration'), ' (MySQL)'; ?></h2>

                <input type='hidden' id='hasMySQL' value='true'/>
                <input type="hidden" id="dbtype" name="dbtype" value="mysql"/>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="dbuser" name="dbuser" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="root" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="dbuser"><?php
                        echo __('DB access user'), ':'; ?></label>
                </div>

                <div id="help-dblogin"
                     class="icon material-icons <?php
                     echo $icons->help()->getClass(); ?>"
                     role="button" aria-label="<?php echo __('Help'); ?>">
                    <?php
                    echo $icons->help()->getIcon(); ?>
                </div>
                <div class="mdl-tooltip" for="help-dblogin">
                    <?php
                    echo __('An user with MySQL admin rights'); ?>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="dbpass" name="dbpass" type="password" required
                           class="mdl-textfield__input mdl-color-text--indigo-400 passwordfield__input-show"
                           value="" autocomplete="off">
                    <label class="mdl-textfield__label"
                           for="dbpass"><?php
                        echo __('DB access password'), ':'; ?></label>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="dbname" name="dbname" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="syspass" autocomplete="off"
                           pattern="[0-9a-zA-Z$_\-]+">
                    <label class="mdl-textfield__label"
                           for="dbname"><?php
                        echo __('sysPass database name'), ':'; ?></label>
                </div>

                <div id="help-dbname"
                     class="icon material-icons <?php
                     echo $icons->help()->getClass(); ?>"
                     role="button" aria-label="<?php echo __('Help'); ?>">
                    <?php
                    echo $icons->help()->getIcon(); ?>
                </div>
                <div class="mdl-tooltip" for="help-dbname">
                    <?php
                    echo __('sysPass database name'); ?>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="dbhost" name="dbhost" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="localhost" autocomplete="off"
                           pattern="([0-9a-zA-Z._\-]+|([0-9]{1,3}.)+)(:[0-9]{1,5})?|unix:.*">
                    <label class="mdl-textfield__label"
                           for="dbhost"><?php
                        echo __('sysPass database server'), ':'; ?></label>
                </div>

                <div id="help-dbhost"
                     class="icon material-icons <?php
                     echo $icons->help()->getClass(); ?>"
                     role="button" aria-label="<?php echo __('Help'); ?>">
                    <?php
                    echo $icons->help()->getIcon(); ?>
                </div>
                <div class="mdl-tooltip" for="help-dbhost">
                    <?php
                    echo __('Server name to install sysPass database'); ?>
                </div>
            </div>

            <hr class="installer-divider">

            <div class="installer-section" id="site">
                <h2 class="installer-section-title"><?php
                    echo __('General'); ?></h2>

                <div class="form-field">
                    <label for="sel-sitelang"><?php
                        echo __('Language'); ?>:</label>
                    <select name="sitelang" id="sel-sitelang" size="1"
                            class="select-box"
                            title="<?php
                            echo __('Language'); ?>">
                        <?php
                        /** @var SelectItem $lang */
                        foreach ($_getvar('langs') as $lang): ?>
                            <option value="<?php
                            echo $lang->getId(); ?>" <?php
                            echo $lang->isSelected() ? 'selected' : ''; ?>><?php
                                echo $lang->getName(); ?></option>
                        <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="hostingmode"><?php echo __('Install Mode'); ?>:</label>
                    <select name="hostingmode" id="hostingmode" size="1"
                            class="select-box"
                            title="<?php echo __('Install Mode'); ?>">
                        <option value="0" selected><?php echo __('Standard'); ?></option>
                        <option value="1"><?php echo __('Hosting'); ?></option>
                    </select>
                    <span id="hostingmode-desc" class="form-field-hint"><?php
                        echo __('Creates the database and the DB user with the provided credentials'); ?></span>
                </div>

                <script type="text/javascript">
                    (function() {
                        var msgs = {
                            '0': '<?php echo __('Creates the database and the DB user with the provided credentials'); ?>',
                            '1': '<?php echo __('Uses the provided credentials directly — the database must already exist'); ?>'
                        };
                        var poll = setInterval(function() {
                            var el = document.getElementById('hostingmode');
                            if (el && el.selectize) {
                                clearInterval(poll);
                                el.selectize.on('change', function(value) {
                                    document.getElementById('hostingmode-desc').textContent = msgs[value] || msgs['0'];
                                });
                            }
                        }, 100);
                    })();
                </script>
            </div>

        </div>

        <div class="installer-footer">
            <button class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent"
                    type="submit">
                <?php
                echo __('Install'); ?>
                <i class="material-icons"
                   title="<?php
                   echo __('Install'); ?>"><?php
                    echo $icons->play()->getIcon(); ?></i>
            </button>
        </div>
    </form>

    <script type="text/javascript">
        (function() {
            var form = document.getElementById('frmInstall');
            form.addEventListener('submit', function() {
                var btn = form.querySelector('.installer-footer .mdl-button');
                btn.disabled = true;
                btn.style.opacity = '0.6';
            });
        })();
    </script>
</div>
