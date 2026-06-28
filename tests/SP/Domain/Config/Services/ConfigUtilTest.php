<?php

declare(strict_types=1);

namespace SP\Tests\Domain\Config\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Config\Services\ConfigUtil;

#[Group('unitary')]
class ConfigUtilTest extends TestCase
{
    public static function mailAddressesProvider(): array
    {
        return [
            'single valid' => [
                'alice@example.com',
                ['alice@example.com'],
            ],
            'multiple valid' => [
                'alice@example.com,bob@example.org',
                ['alice@example.com', 'bob@example.org'],
            ],
            'filters invalid' => [
                'alice@example.com,not-an-email,bob@example.org',
                [0 => 'alice@example.com', 2 => 'bob@example.org'],
            ],
            'all invalid' => [
                'not-email,also-not',
                [],
            ],
            'empty string' => [
                '',
                [],
            ],
            'whitespace in addresses' => [
                ' alice@example.com , bob@example.org ',
                [],
            ],
            'single trailing comma' => [
                'alice@example.com,',
                [0 => 'alice@example.com'],
            ],
        ];
    }

    #[DataProvider('mailAddressesProvider')]
    public function testMailAddressesAdapter(string $input, array $expected): void
    {
        $result = ConfigUtil::mailAddressesAdapter($input);

        self::assertSame($expected, $result);
    }

    public static function eventsProvider(): array
    {
        return [
            'valid events' => [
                ['account.create', 'user.delete', 'login'],
                ['account.create', 'user.delete', 'login'],
            ],
            'filters invalid' => [
                ['account.create', '123invalid', 'user.delete'],
                [0 => 'account.create', 2 => 'user.delete'],
            ],
            'all invalid' => [
                ['123', '.leading.dot', ''],
                [],
            ],
            'empty array' => [
                [],
                [],
            ],
            'case insensitive' => [
                ['Account.Create', 'USER.DELETE'],
                ['Account.Create', 'USER.DELETE'],
            ],
        ];
    }

    #[DataProvider('eventsProvider')]
    public function testEventsAdapter(array $input, array $expected): void
    {
        $result = ConfigUtil::eventsAdapter($input);

        self::assertSame($expected, $result);
    }
}
