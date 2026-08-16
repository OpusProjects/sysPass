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

namespace SP\Tests\Integration\Infrastructure\Definitions;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;

/**
 * A dependency with a default is not injected, and nothing says so.
 *
 * php-di skips any constructor parameter that has a default value, **even when the container has a
 * binding for its type**. There is no error and no log: the object is built, the parameter is null,
 * and everything that depends on it quietly does nothing. `CompilableContainerTest` cannot see it
 * either — the container compiles perfectly well with the parameter unfilled.
 *
 * `Init::$sessionKeyService` was exactly this. The entry point decides, per request, whether a
 * logged-in user's session identifier is due to be rotated:
 *
 * ```php
 * } elseif (!$inMaintenance
 *           && SessionLifecycleHandler::needsRegenerate($sidStartTime)
 *           && $this->context->isLoggedIn()
 * ) {
 *     $this->sessionKeyService?->reKey($sessionContext);
 * ```
 *
 * and `reKey()` is where `session_regenerate_id(true)` lives. With the parameter null the null-safe
 * call swallowed the rest, so the rotation the condition had just decided on never happened and an
 * identifier stayed valid for the whole session. Silent twice over: once in the wiring, once at the
 * call.
 *
 * This asserts against a container built the way `Base.php` builds one, because the only way to
 * know a dependency arrived is to look at the object the container hands back.
 */
#[Group('integration')]
class OptionalDependenciesAreWiredTest extends TestCase
{
    /**
     * The session key service reaches the web entry point, so session identifiers are rotated.
     */
    #[Test]
    public function theWebEntryPointHasTheServiceThatRotatesSessionIdentifiers(): void
    {
        $init = self::containerFor('web')->get(ModuleInterface::class);

        self::assertNotNull(
            self::property($init, 'sessionKeyService'),
            'Init::$sessionKeyService is null, so session_regenerate_id() is never reached and a '
            . "logged-in user's session identifier is never rotated. php-di skips a constructor "
            . 'parameter that has a default: name it with ->constructorParameter().'
        );
    }

    /**
     * Nothing that a module registers may be left holding a null where a service was meant to go.
     *
     * Kept general on purpose: the failure above was found by reading one class, and the mechanism
     * applies to every entry point equally. Each module's own `ModuleInterface` is the object the
     * bootstrap actually uses, so it is the one worth checking.
     *
     * @return array<string, array{string}>
     */
    public static function moduleProvider(): array
    {
        return ['web' => ['web'], 'api' => ['api'], 'cli' => ['cli']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('moduleProvider')]
    public function noModuleIsBuiltWithAServiceParameterLeftNull(string $module): void
    {
        $module_ = self::containerFor($module)->get(ModuleInterface::class);

        $reflection = new ReflectionClass($module_);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                continue;
            }

            $type = $parameter->getType();

            // Only the ones the container could have filled: a scalar default is a real default.
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            self::assertNotNull(
                self::property($module_, $parameter->getName()),
                sprintf(
                    '%s::$%s is typed %s and defaulted, so php-di left it null. Name it with '
                    . '->constructorParameter() or make it required.',
                    $reflection->getShortName(),
                    $parameter->getName(),
                    $type->getName()
                )
            );
        }
    }

    /**
     * Every entry point can build the two things it asks the container for.
     *
     * `public/index.php` and `public/api.php` both do
     * `Bootstrap::run($dic->get(BootstrapInterface::class), $dic->get(ModuleInterface::class))`,
     * and `bin/cli.php` asks only for the module — the CLI binds no BootstrapInterface, which is
     * deliberate and recorded in CLAUDE.md.
     *
     * `CompilableContainerTest` asserts the container *has* an entry, which is not the same thing:
     * a definition compiles perfectly well and still throws when something asks for it. This
     * builds them, because building is what the entry points do.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('moduleProvider')]
    public function eachEntryPointCanBuildWhatItAsksFor(string $module): void
    {
        $container = self::containerFor($module);

        self::assertInstanceOf(ModuleInterface::class, $container->get(ModuleInterface::class));

        if ($module === 'cli') {
            // Asserted rather than skipped: the absence is the documented design, and an
            // unused-but-broken binding added "for consistency" is what once broke the compiled
            // container in production.
            self::assertFalse(
                $container->has(BootstrapInterface::class),
                'The CLI module binds no BootstrapInterface, and bin/cli.php never asks for one'
            );

            return;
        }

        self::assertInstanceOf(BootstrapInterface::class, $container->get(BootstrapInterface::class));
    }

    private static function containerFor(string $module): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            CoreDefinitions::getDefinitions(REAL_APP_ROOT, $module),
            FileSystem::require(
                FileSystem::buildPath(
                    REAL_APP_ROOT,
                    'src',
                    'Infrastructure',
                    'Adapter',
                    'In',
                    ucfirst($module),
                    'module.php'
                )
            )
        );

        return $builder->build();
    }

    private static function property(object $object, string $name): mixed
    {
        $reflection = new ReflectionClass($object);

        while (!$reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass();

            if ($reflection === false) {
                self::fail(sprintf('No property $%s on %s', $name, $object::class));
            }
        }

        return $reflection->getProperty($name)->getValue($object);
    }
}
