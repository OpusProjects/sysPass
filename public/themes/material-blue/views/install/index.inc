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
use SP\Infrastructure\Adapter\In\Web\View\Components\SelectItem;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

?>
<div id="actions" class="installer" align="center">

    <?php
    include $this->includePartial('error-list'); ?>

    <form id="frmInstall" class="form-action" data-onsubmit="main/install"
          data-route="install/install"
          data-start-step="<?php echo (int)$_getvar('startStep', 1); ?>">

        <div class="installer-card">

            <div class="installer-card-header">
                <h1><?php
                    printf('%s %s — %s', $_getvar('app_name'), $_getvar('app_version'), __('Installation')); ?></h1>
            </div>

            <ol class="installer-stepper">
                <li data-step="1">
                    <span class="step-circle">1</span>
                    <span class="step-label"><?php echo __('Welcome'); ?></span>
                </li>
                <li data-step="2">
                    <span class="step-circle">2</span>
                    <span class="step-label"><?php echo __('Language'); ?></span>
                </li>
                <li data-step="3">
                    <span class="step-circle">3</span>
                    <span class="step-label"><?php echo __('Requirements'); ?></span>
                </li>
                <li data-step="4">
                    <span class="step-circle">4</span>
                    <span class="step-label"><?php echo __('Database'); ?></span>
                </li>
                <li data-step="5">
                    <span class="step-circle">5</span>
                    <span class="step-label"><?php echo __('sysPass Admin'); ?></span>
                </li>
                <li data-step="6">
                    <span class="step-circle">6</span>
                    <span class="step-label"><?php echo __('Master Password'); ?></span>
                </li>
            </ol>

            <input type="hidden" name="install" value="true"/>
            <input type="hidden" name="isAjax" value="1"/>
            <input type='hidden' id='hasMySQL' value='true'/>
            <input type="hidden" id="dbtype" name="dbtype" value="mysql"/>

            <div class="installer-section installer-step" data-step="1" id="welcome">
                <h2 class="installer-section-title"><?php
                    echo __('Welcome'); ?></h2>

                <div class="installer-welcome">
                    <p><?php
                        printf(
                            __('Welcome to %s! This wizard will guide you through the installation in a few simple steps.'),
                            $_getvar('app_name')
                        ); ?></p>

                    <p><?php
                        echo __('Before starting, you will need the following information:'); ?></p>

                    <ul>
                        <li><?php
                            echo __('The database server address'); ?></li>
                        <li><?php
                            echo __('A database user with administrative rights'); ?></li>
                        <li><?php
                            echo __('The name for the sysPass database'); ?></li>
                    </ul>

                    <p><?php
                        echo __('During the installation you will also:'); ?></p>

                    <ul>
                        <li><?php
                            echo __('Choose the application language'); ?></li>
                        <li><?php
                            echo __('Set up the administrator account'); ?></li>
                        <li><?php
                            echo __('Set the Master Password'); ?></li>
                    </ul>

                    <p><?php
                        echo __('All set? Press Next to begin.'); ?></p>
                </div>
            </div>

            <div class="installer-section installer-step" data-step="2" id="site">
                <h2 class="installer-section-title"><?php
                    echo __('Language'); ?></h2>

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
                    <span class="form-field-hint"><?php
                        echo __('The language has been detected from your browser. Changing it will reload this page.'); ?></span>
                </div>
            </div>

            <div class="installer-section installer-step" data-step="3" id="requirements">
                <h2 class="installer-section-title"><?php
                    echo __('Requirements'); ?></h2>

                <ul class="installer-requirements">
                    <li class="<?php echo $_getvar('phpVersionOk') ? 'req-ok' : 'req-fail'; ?>">
                        <i class="material-icons"><?php
                            echo $_getvar('phpVersionOk') ? 'check_circle' : 'cancel'; ?></i>
                        <span><?php
                            echo __('PHP version'), ' — ', $_getvar('phpVersion'); ?></span>
                    </li>

                    <?php if (!empty($_getvar('availableRequired'))): ?>
                        <li class="req-ok req-expandable">
                            <i class="material-icons">check_circle</i>
                            <span><?php printf(__('Required PHP extensions (%d)'), count($_getvar('availableRequired'))); ?></span>
                            <button type="button" class="req-expand-btn" aria-expanded="false">
                                <i class="material-icons">expand_more</i>
                            </button>
                            <ul class="req-extensions-list" style="display:none">
                                <?php foreach ($_getvar('availableRequired') as $module): ?>
                                    <li><?php echo htmlspecialchars($module); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if (!empty($_getvar('availableOptional'))): ?>
                        <li class="req-ok req-expandable">
                            <i class="material-icons">check_circle</i>
                            <span><?php printf(__('Optional PHP extensions (%d)'), count($_getvar('availableOptional'))); ?></span>
                            <button type="button" class="req-expand-btn" aria-expanded="false">
                                <i class="material-icons">expand_more</i>
                            </button>
                            <ul class="req-extensions-list" style="display:none">
                                <?php foreach ($_getvar('availableOptional') as $module): ?>
                                    <li><?php echo htmlspecialchars($module); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($_getvar('missingRequired'))): ?>
                        <li class="req-fail req-expandable">
                            <i class="material-icons">cancel</i>
                            <span><?php printf(__('Missing required PHP extensions (%d)'), count($_getvar('missingRequired'))); ?></span>
                            <button type="button" class="req-expand-btn" aria-expanded="false">
                                <i class="material-icons">expand_more</i>
                            </button>
                            <ul class="req-extensions-list" style="display:none">
                                <?php foreach ($_getvar('missingRequired') as $module): ?>
                                    <li><?php echo htmlspecialchars($module); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <?php if (!empty($_getvar('missingOptional'))): ?>
                        <li class="req-warn req-expandable">
                            <i class="material-icons">warning</i>
                            <span><?php printf(__('Missing optional PHP extensions (%d)'), count($_getvar('missingOptional'))); ?></span>
                            <button type="button" class="req-expand-btn" aria-expanded="false">
                                <i class="material-icons">expand_more</i>
                            </button>
                            <ul class="req-extensions-list" style="display:none">
                                <?php foreach ($_getvar('missingOptional') as $module): ?>
                                    <li><?php echo htmlspecialchars($module); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <li class="<?php echo $_getvar('configWritable') ? 'req-ok' : 'req-fail'; ?> req-expandable">
                        <i class="material-icons"><?php
                            echo $_getvar('configWritable') ? 'check_circle' : 'cancel'; ?></i>
                        <span><?php echo __('Configuration directory writable'); ?></span>
                        <button type="button" class="req-expand-btn" aria-expanded="false">
                            <i class="material-icons">expand_more</i>
                        </button>
                        <ul class="req-extensions-list" style="display:none">
                            <li><?php echo htmlspecialchars($_getvar('configDir')); ?></li>
                        </ul>
                    </li>

                    <li class="<?php echo $_getvar('varDirsWritable') ? 'req-ok' : 'req-fail'; ?> req-expandable">
                        <i class="material-icons"><?php
                            echo $_getvar('varDirsWritable') ? 'check_circle' : 'cancel'; ?></i>
                        <span><?php echo __('Runtime directories writable'); ?></span>
                        <button type="button" class="req-expand-btn" aria-expanded="false">
                            <i class="material-icons">expand_more</i>
                        </button>
                        <ul class="req-extensions-list" style="display:none">
                            <?php foreach ($_getvar('varDirs') as $path): ?>
                                <li><?php echo htmlspecialchars($path); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>

                    <li class="<?php echo $_getvar('sessionStrictMode') ? 'req-ok' : 'req-fail'; ?>">
                        <i class="material-icons"><?php
                            echo $_getvar('sessionStrictMode') ? 'check_circle' : 'cancel'; ?></i>
                        <span><?php echo __('session.use_strict_mode enabled'); ?></span>
                    </li>
                </ul>
            </div>

            <div class="installer-section installer-step" data-step="4" id="databaseField">
                <h2 class="installer-section-title"><?php
                    echo __('DB Configuration'), ' (MySQL)'; ?></h2>

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

                <hr class="installer-step-divider">

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
                <div class="mdl-tooltip" id="tooltip-dblogin" for="help-dblogin">
                    <?php
                    echo __('An user with MySQL admin rights'); ?>
                </div>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <?php // Not required: an empty DB admin password is supported (passwordless dev roots) ?>
                    <input id="dbpass" name="dbpass" type="password"
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
                    <?php // No pattern: host / host:port / [ipv6] / [ipv6]:port / unix:socket are all
                          // validated server-side by DatabaseHost::parse(); an HTML pattern here
                          // rejected every IPv6 form the installer accepts ?>
                    <input id="dbhost" name="dbhost" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="localhost" autocomplete="off">
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

                <div class="installer-test-connection">
                    <button id="btnTestDb" type="button"
                            class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent">
                        <i class="material-icons">link</i>
                        <?php
                        echo __('Test connection'); ?>
                    </button>
                </div>
            </div>

            <div class="installer-section installer-step" data-step="5" id="adminaccount">
                <h2 class="installer-section-title"><?php
                    echo __('sysPass Admin'); ?></h2>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="adminlogin" name="adminlogin" type="text" required
                           class="mdl-textfield__input mdl-color-text--indigo-400"
                           value="admin" maxlength="80" autocomplete="off"
                           tabindex="0">
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

            <div class="installer-section installer-step" data-step="6" id="masterpwd">
                <h2 class="installer-section-title"><?php
                    echo __('Master Password'); ?></h2>

                <span class="form-field-hint"><?php
                    echo __('The Master Password is used to encrypt the accounts passwords.'); ?></span>
                <span class="form-field-hint"><?php
                    echo __('Please, keep it safe — it cannot be recovered.'); ?></span>
                <span class="form-field-hint form-field-hint--gap"><?php
                    echo __('The Master Password length need to be at least 11 characters'); ?></span>

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

            <div class="installer-section installer-success" id="installer-success"
                 style="display:none">
                <i class="material-icons installer-success-icon">check_circle</i>
                <h2><?php
                    echo __('Installation finished'); ?></h2>
                <p><?php
                    echo __('You can now log in with your admin account.'); ?></p>
                <a href="index.php?r=login"
                   class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent">
                    <?php
                    echo __('Login'); ?>
                    <i class="material-icons">arrow_forward</i>
                </a>
            </div>

        </div>

        <div class="installer-footer">
            <button id="btnWizardBack" class="mdl-button mdl-js-button mdl-button--raised"
                    type="button">
                <i class="material-icons">arrow_back</i>
                <?php
                echo __('Back'); ?>
            </button>
            <button id="btnNext" class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent"
                    type="button">
                <?php
                echo __('Next'); ?>
                <i class="material-icons">arrow_forward</i>
            </button>
            <button id="btnInstall" class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent"
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
            var steps = Array.prototype.slice.call(form.querySelectorAll('.installer-step'));
            var marks = Array.prototype.slice.call(form.querySelectorAll('.installer-stepper li'));
            var btnWizardBack = document.getElementById('btnWizardBack');
            var btnNext = document.getElementById('btnNext');
            var btnInstall = document.getElementById('btnInstall');
            var total = steps.length;
            var current = parseInt(form.getAttribute('data-start-step'), 10) || 1;
            var finished = false;

            // ?lang= is a one-shot signal (resume on the language step right after
            // a switch): strip it from the URL so a later reload starts on the
            // welcome step again. The chosen language is kept in the session.
            if (window.history && window.history.replaceState
                && /[?&]lang=/.test(window.location.search)) {
                var cleanParams = new URLSearchParams(window.location.search);
                cleanParams.delete('lang');
                window.history.replaceState(
                    null,
                    '',
                    window.location.pathname + '?' + cleanParams.toString()
                );
            }

            var ADMIN_STEP = parseInt(document.getElementById('adminaccount').getAttribute('data-step'), 10);
            var MASTER_STEP = parseInt(document.getElementById('masterpwd').getAttribute('data-step'), 10);
            var REQUIREMENTS_STEP = parseInt(document.getElementById('requirements').getAttribute('data-step'), 10);
            var MASTER_MIN_LENGTH = 11; // mirrors Installer::checkData()

            var passwordsMismatchMsg = <?php echo json_encode(__('Passwords do not match')); ?>;
            var masterLengthMsg = <?php echo json_encode(__('The Master Password length need to be at least 11 characters')); ?>;

            function show(step) {
                current = step;

                steps.forEach(function(panel) {
                    panel.classList.toggle('is-active', parseInt(panel.getAttribute('data-step'), 10) === step);
                });
                marks.forEach(function(mark) {
                    var n = parseInt(mark.getAttribute('data-step'), 10);
                    mark.classList.toggle('is-active', n === step);
                    mark.classList.toggle('is-done', n < step);
                });

                // display (not visibility) so the lone Next button stays centered on step 1
                btnWizardBack.style.display = step === 1 ? 'none' : '';
                btnNext.style.display = step === total ? 'none' : '';
                btnInstall.style.display = step === total ? '' : 'none';

                // Only text fields — focusing selectize's inner input would pop its dropdown open
                var focusable = steps[step - 1].querySelector('.mdl-textfield__input');
                if (focusable) {
                    focusable.focus();
                }

                updateNextBtn();
            }

            function validateStep(step) {
                var fields = steps[step - 1].querySelectorAll('input, select');

                for (var i = 0; i < fields.length; i++) {
                    if (!fields[i].checkValidity()) {
                        fields[i].reportValidity();
                        return false;
                    }
                }

                return true;
            }

            function areFieldsFilled(step) {
                if (step === REQUIREMENTS_STEP) {
                    return steps[step - 1].querySelectorAll('.req-fail').length === 0;
                }
                var fields = steps[step - 1].querySelectorAll('input[required], select[required]');
                for (var i = 0; i < fields.length; i++) {
                    var val = fields[i].type === 'password'
                        ? (fields[i].dataset.pass || fields[i].value)
                        : fields[i].value;
                    if (!val.trim()) return false;
                }
                return true;
            }

            function updateNextBtn() {
                var btn = current === total ? btnInstall : btnNext;
                btn.classList.toggle('mdl-button--accent', areFieldsFilled(current));
            }

            // The raw password only lives in data-pass once the app PKI-encrypts
            // the input value on blur — keep it fresh on any input
            ['adminpass', 'adminpassr', 'masterpassword', 'masterpasswordr'].forEach(function(name) {
                var el = form.querySelector('[name="' + name + '"]');
                el.addEventListener('input', function() {
                    this.dataset.pass = this.value;
                    this.setCustomValidity('');
                });
            });

            function rawPass(input) {
                return input.getAttribute('data-length') !== null ? (input.dataset.pass || '') : input.value;
            }

            function checkPasswords(step, mainName, repeatName, minLength) {
                var main = form.querySelector('[name="' + mainName + '"]');
                var repeat = form.querySelector('[name="' + repeatName + '"]');

                // Clear any stale custom error before re-evaluating: the password
                // generator fills fields without firing the 'input' event that
                // would otherwise clear it, so a prior error could wedge submit
                main.setCustomValidity('');
                repeat.setCustomValidity('');

                if (minLength && rawPass(main).length < minLength) {
                    if (current !== step) {
                        show(step);
                    }
                    main.setCustomValidity(masterLengthMsg);
                    main.reportValidity();
                    return false;
                }

                if (rawPass(main) !== rawPass(repeat)) {
                    if (current !== step) {
                        show(step);
                    }
                    repeat.setCustomValidity(passwordsMismatchMsg);
                    repeat.reportValidity();
                    return false;
                }

                return true;
            }

            btnNext.addEventListener('click', function() {
                if (current >= total || !areFieldsFilled(current) || !validateStep(current)) {
                    return;
                }

                if (current === ADMIN_STEP && !checkPasswords(ADMIN_STEP, 'adminpass', 'adminpassr', 0)) {
                    return;
                }

                show(current + 1);
            });

            btnWizardBack.addEventListener('click', function() {
                if (current > 1) {
                    show(current - 1);
                }
            });

            // Completed stepper circles jump back to their step
            marks.forEach(function(mark) {
                mark.addEventListener('click', function() {
                    var n = parseInt(mark.getAttribute('data-step'), 10);

                    if (!finished && n < current && mark.classList.contains('is-done')) {
                        show(n);
                    }
                });
            });

            // Enter behaves like Next (or Install on the last step) instead of
            // reaching the app's global password-keypress handler, which submits
            // directly and bypasses checkPasswords(). preventDefault on keydown
            // also suppresses that handler's keypress.
            form.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') {
                    return;
                }

                if (!e.target.classList || !e.target.classList.contains('mdl-textfield__input')) {
                    return;
                }

                e.preventDefault();

                if (current < total) {
                    btnNext.click();
                } else {
                    btnInstall.click();
                }
            });

            btnInstall.addEventListener('click', function(e) {
                if (!checkPasswords(ADMIN_STEP, 'adminpass', 'adminpassr', 0)
                    || !checkPasswords(MASTER_STEP, 'masterpassword', 'masterpasswordr', MASTER_MIN_LENGTH)) {
                    e.preventDefault();
                }
            });

            form.addEventListener('input', updateNextBtn);
            form.addEventListener('change', updateNextBtn);

            // Password generator sets data-pass programmatically — no input event fires
            var passObserver = new MutationObserver(updateNextBtn);
            steps.forEach(function(panel) {
                panel.querySelectorAll('input[type="password"]').forEach(function(input) {
                    passObserver.observe(input, {attributes: true, attributeFilter: ['data-pass']});
                });
            });

            form.addEventListener('submit', function() {
                btnInstall.disabled = true;
                btnInstall.style.opacity = '0.6';
            });

            function showSuccess() {
                finished = true;

                steps.forEach(function(panel) {
                    panel.classList.remove('is-active');
                });
                marks.forEach(function(mark) {
                    mark.classList.add('is-done');
                    mark.classList.remove('is-active');
                });

                btnWizardBack.style.display = 'none';
                btnNext.style.display = 'none';
                btnInstall.style.display = 'none';

                document.getElementById('installer-success').style.display = '';
            }

            show(current);

            document.querySelectorAll('.req-expand-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var expanded = this.getAttribute('aria-expanded') === 'true';
                    this.setAttribute('aria-expanded', String(!expanded));
                    this.nextElementSibling.style.display = expanded ? 'none' : 'block';
                });
            });

            var hostingMsgs = {
                '0': <?php echo json_encode(__('Creates the database and the DB user with the provided credentials')); ?>,
                '1': <?php echo json_encode(__('Uses the provided credentials directly — the database must already exist')); ?>
            };

            var dbUserTips = {
                '0': <?php echo json_encode(__('An user with MySQL admin rights')); ?>,
                '1': <?php echo json_encode(__('The DB user provided by your hosting')); ?>
            };

            // The selects are enhanced with selectize by the app triggers — wait for it
            // (once selectize is up, jQuery is guaranteed to be loaded too)
            var poll = setInterval(function() {
                var langSel = document.getElementById('sel-sitelang');
                var hostSel = document.getElementById('hostingmode');

                if (langSel && langSel.selectize && hostSel && hostSel.selectize) {
                    clearInterval(poll);

                    // Owns the install response: success screen on OK (the legacy
                    // handler only understands numeric statuses, so it neither
                    // redirects nor toasts success properly); on error, re-enable
                    // the button so the attempt can be corrected and retried.
                    jQuery(document).ajaxComplete(function(event, xhr, options) {
                        if (!options.url || options.url.indexOf('install/install') === -1) {
                            return;
                        }

                        var response = xhr.responseJSON;

                        if (response && (response.status === 'OK' || response.status === 0)) {
                            if (window.toastr) {
                                toastr.remove();
                            }
                            // no auto-redirect — the success screen has the Login button
                            sysPassApp.util.redirect = function() {};
                            showSuccess();
                        } else {
                            btnInstall.disabled = false;
                            btnInstall.style.opacity = '';
                        }
                    });

                    langSel.selectize.on('change', function(value) {
                        if (value) {
                            var target = 'index.php?r=install/index&lang=' + encodeURIComponent(value);
                            // keep the dev/testing override across the reload
                            if (/[?&]skipInstalled=1/.test(window.location.search)) {
                                target += '&skipInstalled=1';
                            }
                            window.location.href = target;
                        }
                    });

                    hostSel.selectize.on('change', function(value) {
                        document.getElementById('hostingmode-desc').textContent = hostingMsgs[value] || hostingMsgs['0'];
                        document.getElementById('tooltip-dblogin').textContent = dbUserTips[value] || dbUserTips['0'];
                    });

                    document.getElementById('btnTestDb').addEventListener('click', function() {
                        var btn = this;
                        btn.disabled = true;

                        // Encrypt a copy of the password like the install submit does;
                        // the server falls back to the raw value if encryption fails
                        var dbpass = form.querySelector('[name="dbpass"]').value;
                        try {
                            if (dbpass !== ''
                                && sysPassApp.config.PKI
                                && dbpass.length <= sysPassApp.config.PKI.MAX_SIZE) {
                                dbpass = sysPassApp.config.PKI.CRYPTO.encrypt(dbpass);
                            }
                        } catch (e) {
                            // send it raw — the server accepts both
                        }

                        jQuery.post('index.php?r=install/checkConnection', {
                            isAjax: 1,
                            sitelang: document.getElementById('sel-sitelang').value,
                            dbhost: document.getElementById('dbhost').value,
                            dbuser: document.getElementById('dbuser').value,
                            dbpass: dbpass
                        }, null, 'json').done(function(json) {
                            if (json && typeof json.status === 'string') {
                                json.status = ({OK: 0, ERROR: 1, WARNING: 2})[json.status] ?? json.status;
                            }
                            sysPassApp.msg.out(json);
                        }).fail(function() {
                            sysPassApp.msg.error(sysPassApp.config.LANG[1]);
                        }).always(function() {
                            btn.disabled = false;
                        });
                    });

                    // Copy-to-clipboard icon next to the password menu — appears only
                    // after generating a random password, hides again on manual edits
                    jQuery('#frmInstall .password-actions').each(function() {
                        var $actions = jQuery(this);

                        if ($actions.find('.passGen').length === 0) {
                            return;
                        }

                        var input = $actions.prev('.mdl-textfield').find('input')[0];
                        var $copy = jQuery('<i/>', {
                            'class': 'material-icons installer-copy-pass',
                            text: 'content_copy',
                            role: 'button'
                        }).hide();

                        $actions.append($copy);

                        $copy.on('mouseenter', function() {
                            this.title = (sysPassApp.config.LANG || {})[34] || 'Copy';
                        });

                        $actions.find('.passGen').on('click', function() {
                            // the generator fills the field asynchronously — reveal once done
                            var watch = setInterval(function() {
                                if (input.dataset.pass) {
                                    $copy.show();
                                    clearInterval(watch);
                                }
                            }, 100);
                            setTimeout(function() {
                                clearInterval(watch);
                            }, 5000);
                        });

                        jQuery(input).on('keyup', function() {
                            $copy.hide();
                        });

                        $copy.on('click', function() {
                            var pass = input.dataset.pass || '';

                            if (pass === '') {
                                return;
                            }

                            var done = function() {
                                sysPassApp.msg.ok(sysPassApp.config.LANG[45]);
                            };

                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(pass).then(done);
                            } else {
                                var ta = document.createElement('textarea');
                                ta.value = pass;
                                document.body.appendChild(ta);
                                ta.select();
                                document.execCommand('copy');
                                document.body.removeChild(ta);
                                done();
                            }
                        });
                    });
                }
            }, 100);
        })();
    </script>
</div>
