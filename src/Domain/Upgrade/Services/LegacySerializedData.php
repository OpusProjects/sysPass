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

namespace SP\Domain\Upgrade\Services;

use ReflectionClass;
use ReflectionNamedType;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\ItemPreset\Models\AccountPermission;
use SP\Domain\ItemPreset\Models\AccountPrivate;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Models\SessionTimeout;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Domain\Upgrade\Ports\LegacySerializedDataRepository;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\UserPreferences;
use Throwable;

use function SP\__u;
use function SP\processException;

/**
 * Converts the objects sysPass 3.2 stored with PHP serialization into the JSON this version writes
 *
 * 3.2 serialized user profiles, user preferences and item presets under its own class names —
 * `SP\DataModel\ProfileData`, `SP\DataModel\UserPreferencesData`,
 * `SP\DataModel\ItemPreset\Password` and so on — which this rewrite does not have. Reading one
 * asks `Serde::deserialize()` for the rewrite's class, gets an incomplete object back, and throws
 * "Invalid target class": an installation upgraded from 3.2 could read none of its profiles.
 *
 * The conversion reads the old object without instantiating any class, takes its properties by
 * name — they are the same in both versions — casts each to the type the new class declares, and
 * writes the JSON `dehydrate()` would have written. A row already in JSON is never touched, so it
 * is safe to run any number of times: by the upgrade, and by `sp:convertLegacyData` after a 3.2
 * database is restored into an installation that has already upgraded.
 */
final readonly class LegacySerializedData
{
    /**
     * Each column 3.2 wrote serialized: table, column, and the column that picks the class.
     */
    private const COLUMNS = [
        ['UserProfile', 'profile', null],
        ['User', 'preferences', null],
        ['ItemPreset', 'data', 'type'],
    ];

    public function __construct(private LegacySerializedDataRepository $repository)
    {
    }

    /**
     * Converts every row still holding PHP serialization
     *
     * A row that cannot be converted is logged and left as it was; it is no worse than before.
     *
     * @return array{converted: int, failed: int}
     */
    public function convert(): array
    {
        $converted = 0;
        $failed = 0;

        foreach (self::COLUMNS as [$table, $column, $typeColumn]) {
            foreach ($this->repository->findNotJson($table, $column, $typeColumn) as $row) {
                try {
                    $class = self::classFor($table, $row['type']);

                    if ($class === null) {
                        throw SPException::error(sprintf(__u('Unknown preset type: %s'), $row['type'] ?? ''));
                    }

                    if ($this->repository->replace(
                        $table,
                        $column,
                        $row['id'],
                        $row['value'],
                        self::toJson($row['value'], $class)
                    )) {
                        $converted++;
                    }
                } catch (Throwable $e) {
                    processException($e);

                    $failed++;
                }
            }
        }

        return ['converted' => $converted, 'failed' => $failed];
    }

    /**
     * The JSON this version writes for a PHP-serialized object, as an instance of the given class
     *
     * @param class-string $class
     * @throws SPException
     */
    public static function toJson(string $serialized, string $class): string
    {
        // No class is instantiated: whatever the blob names arrives as an incomplete object, and
        // only its property values are taken from it.
        $value = @unserialize($serialized, ['allowed_classes' => false]);

        if (!is_object($value)) {
            throw SPException::error(__u('Couldn\'t deserialize the data'));
        }

        $legacy = [];

        foreach ((array)$value as $key => $item) {
            // Protected and private names carry "\0*\0" and "\0Class\0" prefixes.
            $legacy[substr((string)$key, (int)strrpos("\0" . $key, "\0"))] = $item;
        }

        $reflection = new ReflectionClass($class);
        $defaults = self::constructorDefaults($reflection);
        $values = [];

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            if (array_key_exists($name, $legacy)) {
                $values[$name] = self::cast($legacy[$name], $property->getType());
            } elseif (!$property->hasDefaultValue() && array_key_exists($name, $defaults)) {
                // Built without its constructor below, so a promoted parameter's default would
                // otherwise never be applied and the property would be left uninitialised.
                $values[$name] = $defaults[$name];
            } elseif (!$property->hasDefaultValue() && $property->getType()?->allowsNull()) {
                $values[$name] = null;
            }
        }

        try {
            // Through the application's own round trip, so the result is exactly what dehydrate()
            // writes — and a class this data cannot fill fails here rather than when it is read.
            return Serde::serializeObjectToJson(
                Serde::deserializeObjectFromJson(json_encode($values, JSON_THROW_ON_ERROR), $class)
            );
        } catch (Throwable $e) {
            throw SPException::from($e);
        }
    }

    /**
     * @return class-string|null
     */
    private static function classFor(string $table, ?string $type): ?string
    {
        return match ($table) {
            'UserProfile' => ProfileData::class,
            'User' => UserPreferences::class,
            'ItemPreset' => match ($type) {
                ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION => AccountPermission::class,
                ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD => Password::class,
                ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE => AccountPrivate::class,
                ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT => SessionTimeout::class,
                default => null,
            },
            default => null,
        };
    }

    /**
     * The defaults of the constructor's promoted parameters, by property name
     *
     * @param ReflectionClass<object> $reflection
     * @return array<string, mixed>
     */
    private static function constructorDefaults(ReflectionClass $reflection): array
    {
        $defaults = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        return $defaults;
    }

    /**
     * 3.2 was loose about scalar types; the new classes declare them.
     */
    private static function cast(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || ($value === null && $type->allowsNull())) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => (string)$value,
            'array' => (array)$value,
            default => $value,
        };
    }
}
