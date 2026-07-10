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

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

/**
 * @var ThemeIconsInterface $icons
 * @var ConfigDataInterface $configData
 * @var TemplateInterface $this
 */
?>

<div id="title" class="titleNormal">
    <i class="material-icons">security</i>
    <?php echo __('Security'); ?>
</div>

<table class="data tblConfig">
    <tr>
        <td class="descField" style="vertical-align:top; padding-top:11px; padding-bottom:11px">
            <div style="height:72px; display:flex; align-items:center; justify-content:flex-end">
                <?php echo __('HTTPS'); ?>
                <div id="help-https_enabled"
                     class="icon material-icons <?php echo $icons->help()->getClass(); ?>"><?php
                    echo $icons->help()->getIcon(); ?></div>
                <div class="mdl-tooltip mdl-tooltip--large" for="help-https_enabled">
                    <p><?php echo __('Redirects all HTTP connections to HTTPS.'); ?></p>
                    <p><?php echo __('Requires a valid SSL certificate configured on the web server. Enabling this without a certificate will lock you out of the application.'); ?></p>
                </div>
            </div>
            <div style="height:72px; display:flex; align-items:center; justify-content:flex-end">
                <?php echo __('Session'); ?>
                <div id="help-encrypt_session"
                     class="icon material-icons <?php echo $icons->help()->getClass(); ?>"><?php
                    echo $icons->help()->getIcon(); ?></div>
                <div class="mdl-tooltip mdl-tooltip--large" for="help-encrypt_session">
                    <p><?php echo __('Encrypts the PHP session data stored on the server.'); ?></p>
                    <p><?php echo __('This makes session contents unreadable even if an attacker gains filesystem access. Incurs a small performance overhead on each request.'); ?></p>
                </div>
            </div>
        </td>
        <td class="valField">
            <ul class="config-site-list-action mdl-list">
                <li class="mdl-list__item mdl-list__item--two-line">
                    <div class="mdl-switch__box">
                        <label class="mdl-switch mdl-js-switch mdl-js-ripple-effect"
                               for="https_enabled">
                            <input type="checkbox" id="https_enabled"
                                   class="mdl-switch__input"
                                   name="https_enabled" <?php
                            echo $configData->isHttpsEnabled() ? 'checked' : ''; ?>/>
                        </label>
                    </div>
                    <span class="mdl-list__item-primary-content">
                        <span><?php echo __('Force HTTPS'); ?></span>
                        <span class="mdl-list__item-sub-title"><?php
                            echo __('It forces the use of HTTPS on all the connections'); ?></span>
                    </span>
                </li>

                <li class="mdl-list__item mdl-list__item--two-line">
                    <div class="mdl-switch__box">
                        <label class="mdl-switch mdl-js-switch mdl-js-ripple-effect"
                               for="encrypt_session_enabled">
                            <input type="checkbox" id="encrypt_session_enabled"
                                   class="mdl-switch__input"
                                   name="encrypt_session_enabled" <?php
                            echo $configData->isEncryptSession() ? 'checked' : ''; ?>/>
                        </label>
                    </div>
                    <span class="mdl-list__item-primary-content">
                        <span><?php echo __('Encrypt Session'); ?></span>
                        <span class="mdl-list__item-sub-title"><?php
                            echo __('Encrypts PHP session data stored on the server.'); ?></span>
                    </span>
                </li>
            </ul>
        </td>
    </tr>
</table>
