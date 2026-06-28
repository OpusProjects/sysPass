<?php

declare(strict_types=1);

namespace SP\Tests\Domain\User\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\User\Models\UserPreferences;

#[Group('unitary')]
class UserPreferencesTest extends TestCase
{
    public function testDefaults(): void
    {
        $prefs = new UserPreferences();

        self::assertNull($prefs->getLang());
        self::assertNull($prefs->getTheme());
        self::assertSame(0, $prefs->getResultsPerPage());
        self::assertFalse($prefs->isAccountLink());
        self::assertFalse($prefs->isSortViews());
        self::assertFalse($prefs->isTopNavbar());
        self::assertFalse($prefs->isOptionalActions());
        self::assertFalse($prefs->isResultsAsCards());
        self::assertTrue($prefs->isCheckNotifications());
        self::assertFalse($prefs->isShowAccountSearchFilters());
        self::assertNull($prefs->getUserId());
    }

    public function testConstructorSetsProperties(): void
    {
        $prefs = new UserPreferences([
            'lang' => 'en_US',
            'theme' => 'material-blue',
            'resultsPerPage' => 25,
            'accountLink' => true,
            'sortViews' => true,
            'topNavbar' => true,
            'optionalActions' => true,
            'resultsAsCards' => true,
            'checkNotifications' => false,
            'showAccountSearchFilters' => true,
            'user_id' => 42,
        ]);

        self::assertSame('en_US', $prefs->getLang());
        self::assertSame('material-blue', $prefs->getTheme());
        self::assertSame(25, $prefs->getResultsPerPage());
        self::assertTrue($prefs->isAccountLink());
        self::assertTrue($prefs->isSortViews());
        self::assertTrue($prefs->isTopNavbar());
        self::assertTrue($prefs->isOptionalActions());
        self::assertTrue($prefs->isResultsAsCards());
        self::assertFalse($prefs->isCheckNotifications());
        self::assertTrue($prefs->isShowAccountSearchFilters());
        self::assertSame(42, $prefs->getUserId());
    }

    public function testToArrayShape(): void
    {
        $prefs = new UserPreferences(['lang' => 'es_ES', 'resultsPerPage' => 10]);
        $array = $prefs->toArray();

        self::assertSame('es_ES', $array['lang']);
        self::assertSame(10, $array['resultsPerPage']);
        self::assertArrayHasKey('theme', $array);
        self::assertArrayHasKey('user_id', $array);
    }

    public function testMutateCreatesNewInstance(): void
    {
        $original = new UserPreferences(['lang' => 'en_US', 'resultsPerPage' => 10]);
        $mutated = $original->mutate(['lang' => 'es_ES']);

        self::assertNotSame($original, $mutated);
        self::assertSame('en_US', $original->getLang());
        self::assertSame('es_ES', $mutated->getLang());
        self::assertSame(10, $mutated->getResultsPerPage());
    }

    public function testSerializationRoundTrip(): void
    {
        $prefs = new UserPreferences([
            'lang' => 'en_US',
            'theme' => 'material-blue',
            'resultsPerPage' => 50,
            'accountLink' => true,
            'user_id' => 7,
        ]);

        $serialized = serialize($prefs);
        $restored = unserialize($serialized);

        self::assertInstanceOf(UserPreferences::class, $restored);
        self::assertSame('en_US', $restored->getLang());
        self::assertSame('material-blue', $restored->getTheme());
        self::assertSame(50, $restored->getResultsPerPage());
        self::assertTrue($restored->isAccountLink());
        self::assertSame(7, $restored->getUserId());
    }

    public function testWakeupRenamesUnderscorePrefixedProperties(): void
    {
        $prefs = new UserPreferences(['lang' => 'en_US', 'resultsPerPage' => 25]);

        $serialized = serialize($prefs);
        $tampered = str_replace('"lang"', '"_lang"', $serialized);
        $tampered = str_replace('"resultsPerPage"', '"_resultsPerPage"', $tampered);
        $restored = unserialize($tampered);

        self::assertSame('en_US', $restored->getLang());
        self::assertSame(25, $restored->getResultsPerPage());
    }

    public function testWakeupRemovesUnderscorePrefixedProperty(): void
    {
        $prefs = new UserPreferences(['lang' => 'de_DE']);

        $serialized = serialize($prefs);
        $tampered = str_replace('"lang"', '"_lang"', $serialized);
        $restored = unserialize($tampered);

        $array = $restored->toArray();
        foreach (array_keys($array) as $key) {
            self::assertStringStartsNotWith('_', $key);
        }
    }

    public function testJsonSerialize(): void
    {
        $prefs = new UserPreferences(['lang' => 'fr_FR', 'resultsPerPage' => 15]);

        $json = json_encode($prefs);
        $decoded = json_decode($json, true);

        self::assertSame('fr_FR', $decoded['lang']);
        self::assertSame(15, $decoded['resultsPerPage']);
    }
}
