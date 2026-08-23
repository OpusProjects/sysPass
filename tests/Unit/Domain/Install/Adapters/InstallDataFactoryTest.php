<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Install\Adapters;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Install\Adapters\InstallDataFactory;
use SP\Tests\Support\UnitaryTestCase;

/**
 * What the installer reads out of a request, and what it refuses to invent.
 *
 * The factory carries an instruction in a comment — "No silent defaults ('admin', 'root', ...): a
 * missing field must surface as a validation error, not install with values the user never
 * entered" — and nothing held it to that. An installer that fills in its own administrator login or
 * database user is how an installation ends up with credentials nobody chose and nobody knows.
 *
 * The other half is the field names. Reading `adminlogin` where the form posts something else is
 * silent: the value simply arrives empty, which the installer then reports as a missing field, so
 * the mistake looks like the user's. Each name is therefore pinned individually.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class InstallDataFactoryTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * Every field the factory reads, as (request parameter, how it is read, the getter it lands in).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function fieldProvider(): array
    {
        return [
            'admin login' => ['adminlogin', 'analyzeString', 'getAdminLogin'],
            'admin password' => ['adminpass', 'analyzeEncrypted', 'getAdminPass'],
            'admin password repeat' => ['adminpassr', 'analyzeEncrypted', 'getAdminPassRepeat'],
            'master password' => ['masterpassword', 'analyzeEncrypted', 'getMasterPassword'],
            'master password repeat' => ['masterpasswordr', 'analyzeEncrypted', 'getMasterPasswordRepeat'],
            'database user' => ['dbuser', 'analyzeString', 'getDbAdminUser'],
            'database password' => ['dbpass', 'analyzeEncrypted', 'getDbAdminPass'],
            'database name' => ['dbname', 'analyzeString', 'getDbName'],
            'database host' => ['dbhost', 'analyzeString', 'getDbHost'],
        ];
    }

    /**
     * The value posted under that name is the value the installer uses — so a renamed field in the
     * form, or a typo here, does not quietly become an empty one.
     */
    #[Test]
    #[DataProvider('fieldProvider')]
    public function eachFieldComesFromItsOwnRequestParameter(string $param, string $reader, string $getter): void
    {
        $this->givenTheRequestAnswers([$param => 'the-value'], $reader);

        self::assertSame('the-value', InstallDataFactory::buildFromRequest($this->request)->$getter());
    }

    /**
     * And a field that was not sent stays empty rather than becoming something plausible.
     */
    #[Test]
    #[DataProvider('fieldProvider')]
    public function anAbsentFieldIsNotInvented(string $param, string $reader, string $getter): void
    {
        $this->givenTheRequestAnswers([], $reader);

        self::assertSame(
            '',
            InstallDataFactory::buildFromRequest($this->request)->$getter(),
            $param . ' was filled in with a value the user never entered'
        );
    }

    /**
     * The one deliberate default, and the only one: a language the interface can be shown in has
     * to be something, and it is not a credential.
     */
    #[Test]
    public function theLanguageIsTheOnlyThingWithADefault(): void
    {
        $this->givenTheRequestAnswers([], 'analyzeString');

        self::assertSame('en_US', InstallDataFactory::buildFromRequest($this->request)->getSiteLang());
    }

    #[Test]
    public function theLanguageIsTakenFromTheRequestWhenGiven(): void
    {
        $this->givenTheRequestAnswers(['sitelang' => 'es_ES'], 'analyzeString');

        self::assertSame('es_ES', InstallDataFactory::buildFromRequest($this->request)->getSiteLang());
    }

    /**
     * Hosting mode decides whether the installer creates the database and its user or expects them
     * to exist, so defaulting it the other way would have the installer try to create a database on
     * an installation that had one.
     */
    #[Test]
    public function hostingModeIsOffUnlessAskedFor(): void
    {
        $this->givenTheRequestAnswers([], 'analyzeString');
        $this->request->method('analyzeBool')
                      ->willReturnCallback(static fn(string $n, bool $default) => $default);

        self::assertFalse(InstallDataFactory::buildFromRequest($this->request)->isHostingMode());
    }

    /**
     * @param array<string, string> $answers
     */
    private function givenTheRequestAnswers(array $answers, string $reader): void
    {
        // The reader under test answers from $answers; the other one answers nothing, so a field
        // read with the wrong method shows up as absent rather than being quietly satisfied.
        $forReader = static fn(string $name, mixed $default = null) => $answers[$name] ?? $default;
        $nothing = static fn(string $name, mixed $default = null) => $default;

        $this->request->method('analyzeString')
                      ->willReturnCallback($reader === 'analyzeString' ? $forReader : $nothing);
        $this->request->method('analyzeEncrypted')
                      ->willReturnCallback($reader === 'analyzeEncrypted' ? $forReader : $nothing);
        $this->request->method('analyzeBool')->willReturn(false);
    }
}
