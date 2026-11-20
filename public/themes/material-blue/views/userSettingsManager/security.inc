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
 * @var ThemeIconsInterface $icons
 * @var callable $_getvar
 * @var callable $_e
 * @var TemplateInterface $this
 */

use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

$totpEnabled = (bool)$_getvar('totpEnabled');
$recoveryCount = (int)$_getvar('recoveryCount');
?>

<div id="title" class="titleNormal">
    <i class="material-icons">phonelink_lock</i>
    <?php
    echo __('Two-factor authentication'); ?>
</div>

<table id="tblTotp" class="data tblConfig round">
    <?php
    if ($totpEnabled): ?>
        <tr id="totp-on">
            <td class="descField"><?php
                echo __('Status'); ?></td>
            <td class="valField">
                <p><?php
                    echo __('On: sign-in asks for a code from your authenticator app.'); ?></p>
                <p><?php
                    printf(__('Unused recovery codes: %d'), $recoveryCount); ?></p>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="totp-code" name="code" type="text" class="mdl-textfield__input"
                           maxlength="20" autocomplete="one-time-code">
                    <label class="mdl-textfield__label" for="totp-code"><?php
                        echo __('Current code or recovery code'); ?></label>
                </div>

                <div>
                    <button type="button" class="btn-action mdl-button mdl-js-button mdl-button--raised"
                            data-onclick="user/totp"
                            data-action-route="<?php
                            echo $_e($_getvar('routeRecoveryCodes')); ?>"><?php
                        echo __('New recovery codes'); ?></button>
                    <button type="button" class="btn-action mdl-button mdl-js-button mdl-button--raised"
                            data-onclick="user/totp"
                            data-action-route="<?php
                            echo $_e($_getvar('routeDisable')); ?>"><?php
                        echo __('Turn off'); ?></button>
                </div>
            </td>
        </tr>
    <?php
    else: ?>
        <tr id="totp-off">
            <td class="descField"><?php
                echo __('Status'); ?></td>
            <td class="valField">
                <p><?php
                    echo __('Off: sign-in asks only for your password.'); ?></p>
                <button type="button" class="btn-action mdl-button mdl-js-button mdl-button--raised mdl-button--colored"
                        data-onclick="user/totp"
                        data-action-route="<?php
                        echo $_e($_getvar('routeStart')); ?>"><?php
                    echo __('Turn on'); ?></button>
            </td>
        </tr>
        <tr id="totp-enrol" style="display: none">
            <td class="descField"><?php
                echo __('Authenticator app'); ?></td>
            <td class="valField">
                <p><?php
                    echo __('Scan the code with your authenticator app, or type in the key below it.'); ?></p>
                <div id="totp-qr"></div>
                <p><code id="totp-secret"></code></p>

                <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                    <input id="totp-code" name="code" type="text" class="mdl-textfield__input"
                           maxlength="12" inputmode="numeric" autocomplete="one-time-code">
                    <label class="mdl-textfield__label" for="totp-code"><?php
                        echo __('Code from the app'); ?></label>
                </div>

                <button type="button" class="btn-action mdl-button mdl-js-button mdl-button--raised mdl-button--colored"
                        data-onclick="user/totp"
                        data-action-route="<?php
                        echo $_e($_getvar('routeConfirm')); ?>"><?php
                    echo __('Confirm'); ?></button>
            </td>
        </tr>
    <?php
    endif; ?>
    <tr id="totp-codes" style="display: none">
        <td class="descField"><?php
            echo __('Recovery codes'); ?></td>
        <td class="valField">
            <p><?php
                echo __('Each one signs you in once without the app. Keep them safe: they are shown only now.'); ?></p>
            <ul id="totp-codes-list"></ul>
            <button type="button" class="btn-action mdl-button mdl-js-button mdl-button--raised"
                    data-onclick="user/totpDone"
                    data-action-route="<?php
                    echo $_e($_getvar('routeSettings')); ?>"><?php
                echo __('Done'); ?></button>
        </td>
    </tr>
</table>
