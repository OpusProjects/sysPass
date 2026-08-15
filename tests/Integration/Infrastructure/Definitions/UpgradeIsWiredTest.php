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
use ReflectionProperty;
use SP\Domain\File\FileSystem;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;
use SP\Domain\Upgrade\Ports\UpgradeService;
use SP\Domain\Upgrade\Services\UpgradeDatabase;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use Throwable;

/**
 * The upgrade has to have something to run.
 *
 * `Upgrade` keeps the handlers it will apply in a list that only `registerUpgradeHandler()` fills,
 * and nothing outside the tests ever called it. The service was reachable, `UpgradeDatabase` was
 * defined in the container, and the two were never introduced — so `upgrade()` looped over an empty
 * list, returned without error, and `UpgradeController` answered *"Application successfully
 * updated"* over a database it had not touched, then spent the one-time upgrade key.
 *
 * Nothing could see it. The unit tests register a handler themselves, which is what the class is
 * for; the container tests ask whether the definitions compile, which they did. The gap was between
 * the two, in whether the wiring had ever been done — so it is asked of the built container.
 *
 * The same shape as the optional-constructor-parameter trap this codebase has hit before: a
 * dependency that is defined, is legal, and is never actually passed.
 */
#[Group('integration')]
class UpgradeIsWiredTest extends TestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    public function theUpgradeServiceHasHandlersRegistered(): void
    {
        $upgrade = $this->buildContainer()->get(UpgradeService::class);

        $handlers = (new ReflectionProperty($upgrade, 'upgradeHandlers'))->getValue($upgrade);

        self::assertNotEmpty($handlers, 'the upgrade would run over an empty list of handlers');
        self::assertContains(
            UpgradeDatabase::class,
            $handlers,
            'nothing would bring the database schema up to date'
        );
    }

    /**
     * And each registered handler is one the service can actually build and call, rather than a
     * class name that merely passed registration.
     *
     * @throws Throwable
     */
    #[Test]
    public function eachRegisteredHandlerCanBeResolved(): void
    {
        $container = $this->buildContainer();
        $upgrade = $container->get(UpgradeService::class);

        $handlers = (new ReflectionProperty($upgrade, 'upgradeHandlers'))->getValue($upgrade);

        foreach ($handlers as $handler) {
            self::assertInstanceOf(UpgradeHandlerService::class, $container->get($handler), $handler);
        }
    }

    /**
     * @throws Throwable
     */
    private function buildContainer(): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'web'),
            FileSystem::require(
                FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Web', 'module.php')
            )
        );

        return $builder->build();
    }
}
