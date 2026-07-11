<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AccountFile;

use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Account\Ports\AccountFileService;
use SP\Application\Account\Services\AccountFileAcl;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class AccountFileBase extends ControllerBase
{
    protected AccountFileService $accountFileService;
    protected AccountFileAcl     $accountFileAcl;

    public function __construct(
        Application        $application,
        Router             $router,
        ApiService         $apiService,
        AclInterface       $acl,
        AccountFileService $accountFileService,
        AccountFileAcl     $accountFileAcl
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->accountFileService = $accountFileService;
        $this->accountFileAcl = $accountFileAcl;
    }
}
