<?php
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Common\Dtos;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Dtos\FileDto;
use SP\Domain\Account\Models\File;
use SP\Domain\Client\Models\Client;
use SP\Domain\Common\Adapters\DumpMode;
use SP\Domain\Common\Dtos\Dto;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserPreferences;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\UnitaryTestCase;
use Error;
use TypeError;
use ValueError;

/**
 * Every Dto in the application inherits these, and they are the only way one is built or copied,
 * since the properties are readonly. The building is done by reflection over the constructor, so a
 * property that is not matched is silently null rather than a failure — which is exactly the kind
 * of mistake that shows up much later as an empty field on a page.
 */
#[Group('unitary')]
class DtoTest extends UnitaryTestCase
{
    #[Test]
    public function mutateChangesOnlyWhatWasAskedFor()
    {
        $mutated = (new DtoTestSubject(1, 'first', 'kept'))->mutate(['name' => 'second']);

        self::assertSame('second', $mutated->name);
        self::assertSame(1, $mutated->id, 'an untouched property keeps its value');
        self::assertSame('kept', $mutated->note);
    }

    /**
     * The original is left alone: a Dto is meant to be immutable, and whoever handed it over still
     * holds the instance they built.
     */
    #[Test]
    public function mutateLeavesTheOriginalAlone()
    {
        $original = new DtoTestSubject(1, 'first', 'kept');

        $original->mutate(['name' => 'second']);

        self::assertSame('first', $original->name);
    }

    /**
     * A name the Dto does not have is ignored rather than raising, so an extra key does not break
     * the copy.
     */
    #[Test]
    public function mutateIgnoresAnUnknownProperty()
    {
        $mutated = (new DtoTestSubject(1, 'first', 'kept'))->mutate(['nonexistent' => 'x']);

        self::assertSame('first', $mutated->name);
    }

    /**
     * mutate() rebuilds through reflection's newInstanceArgs(), which — unlike a plain `new` —
     * requires the constructor to be public; it isn't a runtime possibility for any real Dto in
     * the app (every constructor is public), but the fallback exists as a defensive path. Pinning
     * it shows that a Dto that somehow can't be reflected into fails hard (an uncaught Error)
     * rather than the copy silently coming back null or half-built.
     *
     * @throws SPException
     */
    #[Test]
    public function mutatingFailsHardWhenTheConstructorIsNotPublic()
    {
        $this->expectException(Error::class);

        PrivateConstructorDtoTestSubject::create(1)->mutate(['id' => 2]);
    }

    #[Test]
    public function dumpingReturnsEveryProperty()
    {
        $dumped = (new DtoTestSubject(1, 'first', 'kept'))->toArray();

        self::assertSame(['id' => 1, 'name' => 'first', 'note' => 'kept'], $dumped);
    }

    /**
     * A dump can be narrowed to the properties named, which is how a Dto becomes the columns of a
     * row or the fields of a payload.
     */
    #[Test]
    public function dumpingCanBeLimitedToNamedProperties()
    {
        $dumped = (new DtoTestSubject(1, 'first', 'kept'))->toArray(['id', 'name']);

        self::assertSame(['id' => 1, 'name' => 'first'], $dumped);
    }

    /**
     * Or can exclude them, which is how a field is kept out of one.
     */
    #[Test]
    public function dumpingCanExcludeNamedProperties()
    {
        $dumped = (new DtoTestSubject(1, 'first', 'kept'))->toArray(['note'], DumpMode::EXCLUDE);

        self::assertSame(['id' => 1, 'name' => 'first'], $dumped);
    }

    /**
     * Naming a property that does not exist narrows to nothing rather than raising.
     */
    #[Test]
    public function dumpingAnUnknownPropertyYieldsNothing()
    {
        self::assertSame([], (new DtoTestSubject(1, 'first', 'kept'))->toArray(['nonexistent']));
    }

    #[Test]
    public function buildingFromAnArrayFillsTheProperties()
    {
        $dto = DtoTestSubject::fromArray(['id' => 7, 'name' => 'built', 'note' => 'from array']);

        self::assertSame(7, $dto->id);
        self::assertSame('built', $dto->name);
        self::assertSame('from array', $dto->note);
    }

    /**
     * A key the constructor does not take is dropped instead of being passed on as an unknown
     * named argument, so a row carrying extra columns still builds.
     */
    #[Test]
    public function buildingFromAnArrayIgnoresKeysTheConstructorDoesNotTake()
    {
        $dto = DtoTestSubject::fromArray(['id' => 7, 'name' => 'built', 'note' => 'n', 'extra' => 'x']);

        self::assertSame(7, $dto->id);
    }

    /**
     * Building is by name, and a name that is not in the array becomes null rather than the
     * constructor's default. Optional properties are therefore not defaulted when a Dto is built
     * from a row, which is worth pinning because it is not what the signature suggests.
     */
    #[Test]
    public function buildingFromAnArrayNullsWhatItWasNotGiven()
    {
        $dto = UserDto::fromArray(['login' => 'someone']);

        self::assertSame('someone', $dto->login);
        self::assertNull($dto->name);
    }

    /**
     * Reflection needs a constructor to read the property names off, so a Dto without one cannot
     * be built at all — it fails loudly rather than returning an empty instance.
     */
    #[Test]
    public function aDtoWithoutAConstructorCannotBeBuilt()
    {
        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Cannot mutate a class without a constructor');

        ConstructorlessDtoTestSubject::fromArray(['whatever' => 1]);
    }

    /**
     * Same reflection limitation as mutate(): fromArray() also falls back to a bare `new static()`
     * when newInstanceArgs() can't reach a non-public constructor, and that fallback fails just as
     * hard, for the same visibility reason.
     *
     * @throws SPException
     */
    #[Test]
    public function buildingFromAnArrayFailsHardWhenTheConstructorIsNotPublic()
    {
        $this->expectException(Error::class);

        PrivateConstructorDtoTestSubject::fromArray(['id' => 1]);
    }

    /**
     * setBatch() is declared on the Dto interface and implemented here, but nothing in the
     * application calls it (confirmed by grep across src/ and tests/) — and this is why: it hands
     * the whole properties array to the constructor as a single positional argument
     * (`new static($array)`), not spread as named arguments. Every real Dto in the app takes
     * individual named/typed constructor parameters (scalars, or in one case a single strongly
     * typed object), so an array in that first slot is a guaranteed TypeError, not a working build
     * path. This documents that reality rather than pretending the method has a usable call shape.
     */
    #[Test]
    public function setBatchCannotBuildADtoWithNamedConstructorParameters()
    {
        $this->expectException(TypeError::class);

        (new DtoTestSubject(1, 'first', 'kept'))->setBatch(['id', 'name', 'note'], [2, 'second', 'changed']);
    }

    /**
     * Building from a model is how most Dtos are made. Both the columns and the outer properties a
     * join added are carried over.
     *
     * @throws SPException
     */
    #[Test]
    public function buildingFromAModelCarriesItsProperties()
    {
        $user = UserDataGenerator::factory()->buildUserData();

        $dto = UserDto::fromModel($user);

        self::assertSame($user->getId(), $dto->id);
        self::assertSame($user->getLogin(), $dto->login);
        self::assertSame($user->getEmail(), $dto->email);
        self::assertSame($user->isAdminApp(), $dto->isAdminApp);
    }

    /**
     * A property marked with a transformation is not copied across as-is: UserDto's preferences
     * arrive as a serialized blob on the model and have to reach the Dto as the object, since
     * everything downstream calls methods on it.
     *
     * @throws SPException
     */
    #[Test]
    public function buildingFromAModelAppliesATransformation()
    {
        $user = UserDataGenerator::factory()->buildUserData();

        $dto = UserDto::fromModel($user);

        self::assertInstanceOf(UserPreferences::class, $dto->preferences);
        self::assertSame(
            $user->hydrate(UserPreferences::class)->getLang(),
            $dto->preferences->getLang()
        );
    }

    /**
     * A Dto bound to a model refuses any other one. Without the check the mismatched properties
     * would simply come out null, and the Dto would look built.
     *
     * @throws SPException
     */
    #[Test]
    public function aBoundDtoRefusesAModelOfAnotherKind()
    {
        $this->expectException(ValueError::class);

        UserDto::fromModel(new Client(['id' => 1, 'name' => 'a client']));
    }

    /**
     * The result of a query can be turned straight into a Dto, which is how the services hand one
     * back to a controller.
     *
     * @throws SPException
     */
    #[Test]
    public function buildingFromAQueryResultUsesTheRow()
    {
        $file = new File(['id' => 3, 'accountId' => 100, 'name' => 'notes.txt', 'type' => 'text/plain']);

        $dto = FileDto::fromResult(new QueryResult([$file]));

        self::assertSame(3, $dto->id);
        self::assertSame('notes.txt', $dto->name);
    }

    /**
     * The type the callers pass is a guard, not a cast: the row has to already be that model. A
     * query whose mapping was changed underneath therefore fails here rather than producing a Dto
     * with every field null.
     *
     * @throws SPException
     */
    #[Test]
    public function buildingFromAQueryResultRefusesARowOfAnotherModel()
    {
        $row = new Simple(['id' => 3, 'accountId' => 100, 'name' => 'notes.txt']);

        $this->expectException(TypeError::class);

        FileDto::fromResult(new QueryResult([$row]), File::class);
    }
}

/**
 * A minimal Dto, so the inherited behaviour is exercised without dragging a real one's dependencies
 * into the assertions.
 */
final class DtoTestSubject extends Dto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $note
    ) {
    }
}

/**
 * @see DtoTest::aDtoWithoutAConstructorCannotBeBuilt()
 */
final class ConstructorlessDtoTestSubject extends Dto
{
}

/**
 * A non-public constructor is not something any real Dto in the app has (they are all built via
 * fromArray()/fromModel()/mutate(), which need reflection to reach the constructor), but it is
 * exactly the shape that makes ReflectionClass::newInstanceArgs() refuse to instantiate — so this
 * fixture exercises fromArray()'s and mutate()'s reflection-failure fallback. create() is test-only
 * scaffolding to obtain an instance without going through the very reflection path being tested.
 *
 * @see DtoTest::buildingFromAnArrayFailsHardWhenTheConstructorIsNotPublic()
 * @see DtoTest::mutatingFailsHardWhenTheConstructorIsNotPublic()
 */
final class PrivateConstructorDtoTestSubject extends Dto
{
    private function __construct(public readonly int $id)
    {
    }

    public static function create(int $id): self
    {
        return new self($id);
    }
}
