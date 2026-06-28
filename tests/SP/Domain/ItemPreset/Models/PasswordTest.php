<?php

declare(strict_types=1);

namespace SP\Tests\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Ports\PresetInterface;

#[Group('unitary')]
class PasswordTest extends TestCase
{
    private function makePassword(array $overrides = []): Password
    {
        $defaults = [
            'length' => 12,
            'useNumbers' => true,
            'useLetters' => true,
            'useSymbols' => false,
            'useUpper' => true,
            'useLower' => true,
            'useImage' => false,
            'expireTime' => 30,
            'score' => 3,
            'regex' => null,
        ];

        return new Password(...array_merge($defaults, $overrides));
    }

    public function testConstructorSetsAllProperties(): void
    {
        $pw = $this->makePassword();

        self::assertSame(12, $pw->getLength());
        self::assertTrue($pw->isUseNumbers());
        self::assertTrue($pw->isUseLetters());
        self::assertFalse($pw->isUseSymbols());
        self::assertTrue($pw->isUseUpper());
        self::assertTrue($pw->isUseLower());
        self::assertFalse($pw->isUseImage());
        self::assertSame(3, $pw->getScore());
        self::assertNull($pw->getRegex());
    }

    public function testExpireTimeIsMultiplied(): void
    {
        $pw = $this->makePassword(['expireTime' => 7]);

        self::assertSame(7 * Password::EXPIRE_TIME_MULTIPLIER, $pw->getExpireTime());
    }

    public function testExpireTimeZero(): void
    {
        $pw = $this->makePassword(['expireTime' => 0]);

        self::assertSame(0, $pw->getExpireTime());
    }

    public function testRegexCanBeSet(): void
    {
        $pw = $this->makePassword(['regex' => '^[A-Za-z0-9]+$']);

        self::assertSame('^[A-Za-z0-9]+$', $pw->getRegex());
    }

    public function testGetPresetType(): void
    {
        $pw = $this->makePassword();

        self::assertSame('password', $pw->getPresetType());
    }

    public function testImplementsPresetInterface(): void
    {
        $pw = $this->makePassword();

        self::assertInstanceOf(PresetInterface::class, $pw);
    }

    public function testIsReadonly(): void
    {
        $pw = $this->makePassword();

        self::expectException(\Error::class);
        $pw->length = 99;
    }
}
