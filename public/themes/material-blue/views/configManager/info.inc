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
 * @var ConfigDataInterface $configData
 * @var callable $_getvar
 * @var TemplateInterface $this
 */

use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\Plugin\Ports\Plugin;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

?>

<!-- Application -->
<div id="title" class="titleNormal">
    <i class="material-icons">info_outline</i>
    <?php echo __('Application'); ?>
</div>

<table class="data tblConfig">
    <tr>
        <td class="descField"><?php echo __('Version'); ?></td>
        <td class="valField">
            <?php printf(
                '%s (<b>%s</b>)',
                implode('.', array_slice(AppInfoInterface::APP_VERSION, 0, 2)),
                Version::getVersionStringNormalized()
            ); ?>
        </td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('Config'); ?></td>
        <td class="valField"><?php echo $configData->getConfigVersion(); ?></td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('App'); ?></td>
        <td class="valField"><?php echo $configData->getAppVersion(); ?></td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('DB'); ?></td>
        <td class="valField"><?php echo $configData->getDatabaseVersion(); ?></td>
    </tr>
</table>

<!-- Database -->
<div id="title" class="titleNormal">
    <i class="material-icons">storage</i>
    <?php echo __('Database'); ?>
</div>

<table class="data tblConfig">
    <?php foreach ($_getvar('dbInfo') ?? [] as $infoattr => $infoval): ?>
        <tr>
            <td class="descField"><?php echo $infoattr; ?></td>
            <td class="valField"><?php echo $infoval; ?></td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td class="descField"><?php echo __('Name'); ?></td>
        <td class="valField"><?php echo (!$_getvar('isDemo')) ? $_getvar('dbName') : '***'; ?></td>
    </tr>
</table>

<!-- Server -->
<div id="title" class="titleNormal">
    <i class="material-icons">dns</i>
    <?php echo __('Server'); ?>
</div>

<table class="data tblConfig">
    <tr>
        <td class="descField"><?php echo __('Web Server'); ?></td>
        <td class="valField">
            <?php echo !$_getvar('isDemo') ? htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? '', ENT_QUOTES) : '***'; ?>
        </td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('User'); ?></td>
        <td class="valField"><?php echo !$_getvar('isDemo')
            ? (function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : (get_current_user() ?: trim(shell_exec('whoami') ?? '')))
            : '***'; ?></td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('Download rate'); ?></td>
        <td class="valField"><?php printf('%d MB/s', $_getvar('downloadRate')); ?></td>
    </tr>
</table>

<!-- PHP -->
<div id="title" class="titleNormal">
    <i class="material-icons">code</i>
    PHP
</div>

<table class="data tblConfig">
    <tr>
        <td class="descField"><?php echo __('Version'); ?></td>
        <td class="valField"><?php echo PHP_VERSION; ?></td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('Used Memory'); ?></td>
        <td class="valField"><?php printf('%d KB', memory_get_usage(true) / 1024); ?></td>
    </tr>
    <tr>
        <td class="descField"><?php echo __('Extensions'); ?></td>
        <td class="valField"><?php echo implode(', ', get_loaded_extensions()); ?></td>
    </tr>
    <?php if (!empty($_getvar('missingExtensions'))): ?>
        <tr>
            <td class="descField"><?php echo __('Unavailable extensions'); ?></td>
            <td class="valField">
                <span class="mdl-color-text--red-900"><?php echo implode(', ', $_getvar('missingExtensions')); ?></span>
            </td>
        </tr>
    <?php endif; ?>
    <?php if (function_exists('opcache_get_status')): ?>
        <tr>
            <td class="descField"><?php echo __('OP Cache'); ?></td>
            <td class="valField">
                <?php foreach (opcache_get_status(false)['opcache_statistics'] ?? [] as $key => $value): ?>
                    <?php printf('%s: <b>%s</b>', $key, $value); ?><br>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endif; ?>
</table>

<!-- Status -->
<div id="title" class="titleNormal">
    <i class="material-icons">check_circle</i>
    <?php echo __('Status'); ?>
</div>

<table class="data tblConfig">
    <tr>
        <td class="descField">
            <?php echo __('Language'); ?>
            <div id="help-infolang"
                 class="icon material-icons <?php echo $icons->help()->getClass(); ?>"><?php
                echo $icons->help()->getIcon(); ?></div>
            <div class="mdl-tooltip mdl-tooltip--large" for="help-infolang">
                <p><?php echo __('Tells whether the language is available or not.'); ?></p>
                <p><?php echo __('If it is not installed, you would need install the right operating system locales. More info at Wiki.'); ?></p>
            </div>
        </td>
        <td class="valField"><?php echo $_getvar('locale'); ?></td>
    </tr>
    <tr>
        <td class="descField">
            <?php echo __('Encrypted Session'); ?>
            <div id="help-infosession"
                 class="icon material-icons <?php echo $icons->help()->getClass(); ?>"><?php
                echo $icons->help()->getIcon(); ?></div>
            <div class="mdl-tooltip mdl-tooltip--large" for="help-infosession">
                <p><?php echo __('Tells whether the session data are encrypted in the server side or not'); ?></p>
            </div>
        </td>
        <td class="valField"><?php echo $_getvar('securedSession') ? __('Yes') : __('No'); ?></td>
    </tr>
</table>

<!-- Plugins -->
<div id="title" class="titleNormal">
    <i class="material-icons">extension</i>
    <?php echo __('Loaded Plugins'); ?>
</div>

<table class="data tblConfig">
    <?php
    $plugins = $_getvar('plugins') ?? [];
    if (empty($plugins)): ?>
        <tr>
            <td class="valField"><?php echo __('No plugins loaded'); ?></td>
        </tr>
    <?php else: ?>
        <?php /** @var Plugin $plugin */
        foreach ($plugins as $name => $plugin): ?>
            <tr>
                <td class="descField"><?php echo $name; ?></td>
                <td class="valField">
                    <?php printf('%s: %s', __('Version'), implode('.', $plugin->getVersion() ?? [])); ?>
                    &nbsp;&middot;&nbsp;
                    <?php printf('%s: %s', __('Author'), $plugin->getAuthor() ?? ''); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>

<!-- Downloads -->
<?php if ($_getvar('downloadConfigBackup') || $_getvar('downloadLog')): ?>
    <div id="title" class="titleNormal">
        <i class="material-icons">file_download</i>
        <?php echo __('Downloads'); ?>
    </div>

    <table class="data tblConfig">
        <tr>
            <td class="descField"><?php echo __('Configuration Backup'); ?></td>
            <td class="valField">
                <?php echo $_getvar('configBackupDate'); ?>
                <?php if ($_getvar('downloadConfigBackup')): ?>
                    <div>
                        <a href="<?php echo $_getRoute('configGeneral/downloadConfigBackup/json'); ?>"
                           class="download" target="_blank">
                            <button type="button" class="mdl-button mdl-js-button">
                                <?php printf(__('Download %s'), 'JSON'); ?>
                            </button>
                        </a>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($_getvar('downloadLog')): ?>
            <tr>
                <td class="descField"><?php echo __('Log file'); ?></td>
                <td class="valField">
                    <a href="<?php echo $_getRoute('configGeneral/downloadLog'); ?>"
                       class="download" target="_blank">
                        <button type="button" class="mdl-button mdl-js-button">
                            <?php printf(__('Download %s'), basename(LOG_FILE)); ?>
                        </button>
                    </a>
                </td>
            </tr>
        <?php endif; ?>
    </table>
<?php endif; ?>

<div class="tab-actions">
    <ul>
        <li>
            <button id="btnBack" type="button"
                    class="btn-back mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php
                    echo $icons->back()->getClassButton(); ?>"
                    title="<?php echo __('Back'); ?>">
                <i class="material-icons"><?php echo $icons->back()->getIcon(); ?></i>
            </button>
        </li>
    </ul>
</div>
