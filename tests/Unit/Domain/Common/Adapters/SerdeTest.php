<?php
declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Domain\Common\Adapters;

use __PHP_Incomplete_Class;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;
use stdClass;

/**
 * Class SerdeTest
 */
#[Group('unitary')]
class SerdeTest extends UnitaryTestCase
{

    public static function serializeDataProvider(): array
    {
        return [
            [['a' => 'testA', 'b' => 1, 'c' => true], 'a:3:{s:1:"a";s:5:"testA";s:1:"b";i:1;s:1:"c";b:1;}'],
            [
                (object)['a' => 'testA', 'b' => 1, 'c' => true],
                'O:8:"stdClass":3:{s:1:"a";s:5:"testA";s:1:"b";i:1;s:1:"c";b:1;}'
            ],
            ['a_string', 's:8:"a_string";'],
            [1, 'i:1;']
        ];
    }

    #[DataProvider('serializeDataProvider')]
    public function testSerialize(mixed $data, string $expected)
    {
        $out = \SP\Domain\Common\Adapters\Serde::serialize($data);

        $this->assertEquals($expected, $out);
    }

    /**
     * @throws SPException
     */
    public function testDeserialize()
    {
        $data = 'O:20:"SP\Config\ConfigData":1:{s:13:"'
                . "\0" . '*' . "\0" .
                'attributes";a:4:{s:12:"passwordSalt";s:60:"901a4d025ab807564c3c46afc69ab9fd1ae25c6dbba7d62ce3b279f7523c";s:10:"configDate";i:1633156732;s:11:"configSaver";s:7:"sysPass";s:10:"configHash";s:40:"0f099212786ab8090432f2889ac37c2a977f164a";}}';

        $out = Serde::deserialize($data);

        $this->assertInstanceOf(stdClass::class, $out);
        $this->assertIsArray($out->attributes);
        $this->assertEquals(
            '901a4d025ab807564c3c46afc69ab9fd1ae25c6dbba7d62ce3b279f7523c',
            $out->attributes['passwordSalt']
        );
        $this->assertEquals('1633156732', $out->attributes['configDate']);
        $this->assertEquals('sysPass', $out->attributes['configSaver']);
        $this->assertEquals('0f099212786ab8090432f2889ac37c2a977f164a', $out->attributes['configHash']);
    }

    /**
     * @throws SPException
     */
    public function testDeserializeWithClass()
    {
        $data = 'O:20:"SP\Config\ConfigData":1:{s:13:"'
                . "\0" . '*' . "\0" .
                'attributes";a:4:{s:12:"passwordSalt";s:60:"901a4d025ab807564c3c46afc69ab9fd1ae25c6dbba7d62ce3b279f7523c";s:10:"configDate";i:1633156732;s:11:"configSaver";s:7:"sysPass";s:10:"configHash";s:40:"0f099212786ab8090432f2889ac37c2a977f164a";}}';

        $out = \SP\Domain\Common\Adapters\Serde::deserialize($data, __PHP_Incomplete_Class::class);

        $this->assertInstanceOf(stdClass::class, $out);
        $this->assertIsArray($out->attributes);
        $this->assertEquals(
            '901a4d025ab807564c3c46afc69ab9fd1ae25c6dbba7d62ce3b279f7523c',
            $out->attributes['passwordSalt']
        );
        $this->assertEquals('1633156732', $out->attributes['configDate']);
        $this->assertEquals('sysPass', $out->attributes['configSaver']);
        $this->assertEquals('0f099212786ab8090432f2889ac37c2a977f164a', $out->attributes['configHash']);
    }

    /**
     * @throws SPException
     */
    public function testDeserializeWithClassException()
    {
        $data = 'O:20:"SP\Config\ConfigData":1:{s:13:"'
                . "\0" . '*' . "\0" .
                'attributes";a:4:{s:12:"passwordSalt";s:60:"901a4d025ab807564c3c46afc69ab9fd1ae25c6dbba7d62ce3b279f7523c";s:10:"configDate";i:1633156732;s:11:"configSaver";s:7:"sysPass";s:10:"configHash";s:40:"0f099212786ab8090432f2889ac37c2a977f164a";}}';

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Invalid target class');

        \SP\Domain\Common\Adapters\Serde::deserialize($data, ConfigData::class);
    }

    /**
     * @throws SPException
     */
    public function testSerializeJson()
    {
        $data = ['a' => 'testA', 'b' => 1, 'c' => true, 'd' => new stdClass()];

        $out = Serde::serializeJson($data);

        $expected = '{"a":"testA","b":1,"c":true,"d":{}}';

        $this->assertEquals($expected, $out);
    }

    /**
     * @throws SPException
     */
    public function testSerializeJsonWithException()
    {
        $data = [
            'a' => 'testA',
            'b' => 1,
            'c' => true,
            'd' => &$data
        ];

        $this->expectException(SPException::class);

        Serde::serializeJson($data);
    }

    /**
     * @throws SPException
     */
    public function testDeserializeJson()
    {
        $data = '{"a":"testA","b":1,"c":true,"d":{}}';

        $out = Serde::deserializeJson($data);

        $expected = (object)['a' => 'testA', 'b' => 1, 'c' => true, 'd' => new stdClass()];

        $this->assertEquals($expected, $out);
    }

    /**
     * @throws SPException
     */
    public function testDeserializeJsonWithException()
    {
        $data = '{"a":"testA","b":1,"c":true,"d":}';

        $this->expectException(SPException::class);

        \SP\Domain\Common\Adapters\Serde::deserializeJson($data);
    }

    /**
     * A string that was never produced by serialize() (corrupted storage, a truncated file, a
     * caller passing the wrong value) makes PHP's unserialize() return false. That must surface as
     * a clear exception rather than a false value being handed to code that expects an object or
     * array.
     */
    public function testDeserializeWithInvalidDataThrowsException()
    {
        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Couldn\'t deserialize the data');

        Serde::deserialize('this-is-not-a-serialized-value');
    }

    /**
     * This is how a preset or plugin's JSON blob is turned back into its object without calling the
     * constructor (SerializedModel::hydrate()). Every property the JSON carries and the class
     * declares must land on the instance, or a stored setting would silently come back empty.
     *
     * @throws SPException
     */
    public function testDeserializeObjectFromJsonBuildsAnInstanceViaReflection()
    {
        $data = '{"id":42,"label":"a label"}';

        $out = Serde::deserializeObjectFromJson($data, SerdeDeserializeObjectFromJsonTestSubject::class);

        $this->assertInstanceOf(SerdeDeserializeObjectFromJsonTestSubject::class, $out);
        $this->assertSame(42, $out->id);
        $this->assertSame('a label', $out->label);
    }

    /**
     * A class can evolve and drop a property while old data on disk still carries it (a plugin or
     * preset's schema changing between sysPass versions). Hydration must skip that stale key instead
     * of failing, or every record saved under the old schema would become unreadable.
     *
     * @throws SPException
     */
    public function testDeserializeObjectFromJsonIgnoresPropertiesTheClassNoLongerHas()
    {
        $data = '{"id":42,"label":"a label","removedInAnOlderSchema":"stale"}';

        $out = Serde::deserializeObjectFromJson($data, SerdeDeserializeObjectFromJsonTestSubject::class);

        $this->assertSame(42, $out->id);
        $this->assertSame('a label', $out->label);
    }
}

/**
 * A minimal target class for Serde::deserializeObjectFromJson(), so the reflective property
 * population is exercised without depending on a specific domain type.
 *
 * @see SerdeTest::testDeserializeObjectFromJsonBuildsAnInstanceViaReflection()
 */
final class SerdeDeserializeObjectFromJsonTestSubject
{
    public int $id;
    public string $label;
}
