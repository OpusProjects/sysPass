<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use SP\Domain\Core\AppInfoInterface;

header('Content-Type: application/json; charset=utf-8');

$spec = file_get_contents(__DIR__ . '/openapi.json');
echo str_replace('@@VERSION@@', implode('.', array_slice(AppInfoInterface::APP_VERSION, 0, 2)), $spec);
