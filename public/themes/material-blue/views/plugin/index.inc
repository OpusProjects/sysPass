<h5 class="datagrid-header-title"><?php use SP\Infrastructure\Adapter\In\Web\View\Template;
use function SP\__;

    echo __('Plugins'); ?></h5>
<?php
/** @var \SP\Infrastructure\Adapter\In\Web\View\TemplateInterface $this */
include $this->includeTemplate('datagrid-grid', 'grid');