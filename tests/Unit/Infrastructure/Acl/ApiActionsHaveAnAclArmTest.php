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

namespace SP\Tests\Unit\Infrastructure\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\ActionsInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Acl\Acl;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Every action the API offers has an arm in Acl
 *
 * `Api::setup()` asks `Acl::checkUserAccess()` for the token's action on every call, and an action
 * with no arm falls through to the deny at the end — so a new endpoint whose action nobody added
 * to Acl would refuse every token but an administrator's, and nothing else would say so. Every
 * action an API controller sets up with must be granted by some profile permission, unless it is
 * named below as administrator-only on purpose.
 */
#[Group('unitary')]
class ApiActionsHaveAnAclArmTest extends UnitaryTestCase
{
    /**
     * Administrator-only by design, as on the web: Acl has no arm for them.
     */
    private const ADMINISTRATOR_ONLY = [
        'NOTIFICATION_CREATE',
        'NOTIFICATION_EDIT',
        'NOTIFICATION_DELETE',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function apiActionProvider(): array
    {
        $root = dirname(__DIR__, 4) . '/src/Infrastructure/Adapter/In/Api/Controllers';
        $actions = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/setupApi\(AclActionsInterface::(\w+)\)/',
                (string)file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $name) {
                $actions[$name] = [$name];
            }
        }

        ksort($actions);

        return $actions;
    }

    /**
     * A profile holding every permission is granted every API action that is not administrator-only.
     */
    #[DataProvider('apiActionProvider')]
    public function testEveryApiActionIsGrantedBySomePermission(string $name): void
    {
        $actionId = constant(AclActionsInterface::class . '::' . $name);

        self::assertSame(
            !in_array($name, self::ADMINISTRATOR_ONLY, true),
            $this->aclForAProfileHoldingEverything()->checkUserAccess($actionId),
            in_array($name, self::ADMINISTRATOR_ONLY, true)
                ? $name . ' is listed as administrator-only but some permission now grants it'
                : $name . ' has no arm in Acl: every non-administrator token for it is refused'
        );
    }

    /**
     * The scan itself found the API: a pattern that matched nothing would pass vacuously.
     */
    public function testTheScanFindsTheApiActions(): void
    {
        self::assertGreaterThan(50, count(self::apiActionProvider()));
    }

    private function aclForAProfileHoldingEverything(): Acl
    {
        $permissions = [];

        foreach ((new \ReflectionClass(ProfileData::class))->getProperties() as $property) {
            if ($property->getType()?->getName() === 'bool') {
                $permissions[$property->getName()] = true;
            }
        }

        $this->context->setUserProfile(new ProfileData($permissions));
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => false, 'isAdminAcc' => false])
        );

        return new Acl($this->context, $this->application->getEventDispatcher(), $this->createStub(ActionsInterface::class));
    }
}
