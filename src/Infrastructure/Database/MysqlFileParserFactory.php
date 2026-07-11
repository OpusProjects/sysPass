<?php

declare(strict_types=1);

namespace SP\Infrastructure\Database;

use SP\Domain\Database\Ports\DatabaseFileFactory;
use SP\Domain\Database\Ports\DatabaseFileInterface;
use SP\Infrastructure\File\FileHandler;

final class MysqlFileParserFactory implements DatabaseFileFactory
{
    public function fromPath(string $path): DatabaseFileInterface
    {
        return new MysqlFileParser(new FileHandler($path));
    }
}
