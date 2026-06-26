<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use SP\Application\Install\Services\Installer;

header('Content-Type: application/json; charset=utf-8');

$spec = file_get_contents(__DIR__ . '/openapi.json');
echo str_replace('@@VERSION@@', Installer::VERSION_TEXT, $spec);
