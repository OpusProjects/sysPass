<?php

declare(strict_types=1);
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

namespace SP\Domain\Common\Adapters;

use __PHP_Incomplete_Class;
use JsonException;
use ReflectionClass;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__u;

/**
 * Class Serde
 */
final class Serde
{
    /**
     * @param mixed[]|object|string|int $data
     */
    public static function serialize(array|object|string|int $data): string
    {
        return serialize($data);
    }

    /**
     * @param mixed[]|object|string|int $data
     * @throws SPException
     */
    public static function serializeJson(array|object|string|int $data, int $flags = 0): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | $flags);
        } catch (JsonException $e) {
            throw SPException::from($e);
        }
    }

    /**
     * @template T of object
     *
     * @param string $data
     * @param class-string<T>|null $class
     * @param class-string ...$nestedClasses Additional classes contained within the serialized object graph
     * @return T&object|array
     *
     * @throws SPException
     */
    public static function deserialize(string $data, ?string $class = null, string ...$nestedClasses): object|array
    {
        $value = @unserialize(
            $data,
            ['allowed_classes' => $class !== null ? [$class, ...$nestedClasses] : true]
        );

        return match (true) {
            $value === false => throw SPException::error(__u('Couldn\'t deserialize the data')),
            $class !== null && !is_a($value, $class) => throw SPException::error(__u('Invalid target class')),
            $value instanceof __PHP_Incomplete_Class => self::fixSerialized($data),
            default => $value
        };
    }

    /**
     * Takes an __PHP_Incomplete_Class and casts it to a stdClass object.
     * All properties will be made public in this step.
     *
     * @link https://stackoverflow.com/a/28353091
     *
     * @param string $serialized
     * @return object
     */
    private static function fixSerialized(string $serialized): object
    {
        $dump = preg_replace_callback(
            '/s:\d+:"\x00+[^\x00]*\x00+([^"]+)"/',
            static function ($matches) {
                return 's:' . strlen($matches[1]) . ':"' . $matches[1] . '"';
            },
            preg_replace('/^O:\d+:"[^"]++"/', 'O:8:"stdClass"', $serialized)
        );

        return unserialize($dump ?? '', ['allowed_classes' => [\stdClass::class]]);
    }

    /**
     * @throws SPException
     */
    public static function deserializeJson(string $data): object
    {
        try {
            return json_decode($data, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SPException::from($e);
        }
    }

    /**
     * @throws SPException
     */
    public static function serializeObjectToJson(object $data): string
    {
        try {
            $reflect = new ReflectionClass($data);
            $props = [];

            foreach ($reflect->getProperties() as $prop) {
                $props[$prop->getName()] = $prop->getValue($data);
            }

            return json_encode($props, JSON_THROW_ON_ERROR);
        } catch (\ReflectionException|JsonException $e) {
            throw SPException::from($e);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @return T
     *
     * @throws SPException
     */
    public static function deserializeObjectFromJson(string $data, string $class): object
    {
        try {
            $values = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
            $reflect = new ReflectionClass($class);
            $instance = $reflect->newInstanceWithoutConstructor();

            foreach ($values as $name => $value) {
                if ($reflect->hasProperty($name)) {
                    $reflect->getProperty($name)->setValue($instance, $value);
                }
            }

            return $instance;
        } catch (\ReflectionException|JsonException $e) {
            throw SPException::from($e);
        }
    }
}
