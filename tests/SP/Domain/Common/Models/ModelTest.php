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

namespace SP\Tests\Domain\Common\Models;

use Error;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SP\Tests\Stubs\ModelStub;

/**
 * Class ModelTest
 */
#[Group('unitary')]
class ModelTest extends TestCase
{
    protected static Generator $faker;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$faker = Factory::create();
    }

    public function testInstance()
    {
        $data = [
            'id' => self::$faker->randomNumber(3),
            'name' => self::$faker->colorName(),
            'test' => self::$faker->text()
        ];

        $model = new ModelStub($data);
        self::assertEquals($data['id'], $model->getId());
        self::assertEquals($data['id'], $model->id);
        self::assertEquals($data['name'], $model->getName());
        self::assertEquals($data['name'], $model->name);
        self::assertEquals($data['test'], $model['test']);
        // Outer (non-class) properties are also readable through __get.
        self::assertEquals($data['test'], $model->test);
    }

    #[TestWith(['id', 100])]
    #[TestWith(['test_a', 'a_text'])]
    public function testModifyClassProperties(string $property, mixed $value)
    {
        $data = [
            'id' => self::$faker->randomNumber(3),
            'test' => self::$faker->text()
        ];

        $model = new ModelStub($data);

        self::expectException(Error::class);

        $model->{$property} = $value;
    }

    public function testModifyInternalProperties()
    {
        $data = [
            'test' => self::$faker->text()
        ];

        $model = new ModelStub($data);

        self::expectException(Error::class);

        $model['test'] = 'a_text';
    }

    public function testIsset()
    {
        $data = [
            'id' => self::$faker->randomNumber(3),
            'test' => self::$faker->text()
        ];

        $model = new ModelStub($data);

        // A declared (protected) class property with a non-null value.
        self::assertTrue(isset($model->id));
        // An outer (non-class) property with a non-null value.
        self::assertTrue(isset($model->test));
        // A property that was never assigned at all.
        self::assertFalse(isset($model->missing));
    }

    public function testIssetWithNullValue()
    {
        $model = new ModelStub(['id' => null]);

        // isset() follows PHP's usual semantics: a property explicitly set to null
        // is still reported as "not set".
        self::assertFalse(isset($model->id));
    }

    public function testGetColsWithPreffix()
    {
        self::assertSame(['acc.id', 'acc.name'], ModelStub::getColsWithPreffix('acc'));
        self::assertSame(['acc.name'], ModelStub::getColsWithPreffix('acc', ['id']));
    }

    public function testGetCols()
    {
        self::assertSame(['id', 'name'], ModelStub::getCols());
        self::assertSame(['name'], ModelStub::getCols(['id']));
    }

    /**
     * @throws \SP\Domain\Core\Exceptions\SPException
     */
    public function testToJson()
    {
        $model = new ModelStub(['id' => 1, 'name' => 'red']);

        self::assertSame(json_encode($model->toArray()), $model->toJson());
    }

    public function testBuildFromSimpleModel()
    {
        $source = new ModelStub(['id' => 7, 'name' => 'blue', 'extra' => 'outer']);

        $built = ModelStub::buildFromSimpleModel($source);

        self::assertInstanceOf(ModelStub::class, $built);
        self::assertSame(7, $built->getId());
        self::assertSame('blue', $built->getName());
        // buildFromSimpleModel includes outer (non-class) properties.
        self::assertSame('outer', $built['extra']);
    }

    public function testToArray()
    {
        $model = new ModelStub(['id' => 5, 'name' => 'green', 'extra' => 'outer']);

        self::assertSame(['id' => 5, 'name' => 'green'], $model->toArray());
        self::assertSame(['id' => 5, 'name' => 'green', 'extra' => 'outer'], $model->toArray(null, null, true));
        self::assertSame(['id' => 5], $model->toArray(['id']));
        self::assertSame(['name' => 'green'], $model->toArray(null, ['id']));
    }

    public function testMutate()
    {
        $model = new ModelStub(['id' => 1, 'name' => 'red']);

        $mutated = $model->mutate(['name' => 'blue']);

        self::assertNotSame($model, $mutated);
        self::assertSame(1, $mutated->getId());
        self::assertSame('blue', $mutated->getName());
        // The original model is unchanged.
        self::assertSame('red', $model->getName());
    }

    public function testJsonSerialize()
    {
        $model = new ModelStub(['id' => 1, 'name' => 'red', 'extra' => 'outer']);

        self::assertSame($model->toArray(), $model->jsonSerialize());
    }
}
