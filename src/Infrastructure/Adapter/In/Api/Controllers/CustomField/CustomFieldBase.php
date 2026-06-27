<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\CustomField\Ports\CustomFieldDefinitionService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class CustomFieldBase extends ControllerBase
{
    protected CustomFieldDefinitionService $customFieldService;

    public function __construct(
        Application                    $application,
        Router                         $router,
        ApiService                     $apiService,
        AclInterface                   $acl,
        CustomFieldDefinitionService   $customFieldService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->customFieldService = $customFieldService;
    }
}
