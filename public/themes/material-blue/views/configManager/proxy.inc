<?php

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

use function SP\__;

/**
 * @var ThemeIconsInterface $icons
 * @var ConfigDataInterface $configData
 * @var callable $_getvar
 * @var TemplateInterface $this
 */
?>
<form method="post" name="frmProxy" id="frmProxy" class="form-action"
      data-onsubmit="config/save"
      data-action-route="configProxy/save"
      data-hash="">

    <?php
    include $this->includeTemplate('general-proxy'); ?>
</form>

<div class="tab-actions">
    <ul>
        <li>
            <button id="btnBack" type="button"
                    class="btn-back mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php
                    echo $icons->back()->getClassButton(); ?>"
                    title="<?php
                    echo __('Back'); ?>">
                <i class="material-icons"><?php
                    echo $icons->back()->getIcon(); ?></i>
            </button>
        </li>
        <li>
            <button form="frmProxy"
                    class="mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php
                    echo $icons->save()->getClassButton(); ?>"
                    title="<?php
                    echo $icons->save()->getTitle(); ?>">
                <i class="material-icons"><?php
                    echo $icons->save()->getIcon(); ?></i>
            </button>
        </li>
    </ul>
</div>
