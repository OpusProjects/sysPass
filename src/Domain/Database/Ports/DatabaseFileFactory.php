<?php

declare(strict_types=1);

namespace SP\Domain\Database\Ports;

interface DatabaseFileFactory
{
    public function fromPath(string $path): DatabaseFileInterface;
}
