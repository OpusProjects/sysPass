<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Account\Ports\AccountFileService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class AccountFileBase extends ControllerBase
{
    protected AccountFileService $accountFileService;

    public function __construct(
        Application        $application,
        Router             $router,
        ApiService         $apiService,
        AclInterface       $acl,
        AccountFileService $accountFileService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->accountFileService = $accountFileService;
    }
}
