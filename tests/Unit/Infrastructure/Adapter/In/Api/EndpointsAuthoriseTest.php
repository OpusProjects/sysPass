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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SP\Domain\Core\Acl\AclActionsInterface;

/**
 * Every REST endpoint asks who is calling, and asks for the right thing.
 *
 * The API's dispatch does not authenticate. `Bootstrap::handleRestRequest()` resolves the
 * controller from the path, checks the method exists, and calls it — nothing in between looks at
 * the Authorization header. The only thing that does is the `setupApi()` each controller makes as
 * its first act, which looks the bearer token up **by action id** and refuses if there is no token
 * issued for exactly that action.
 *
 * Two ways to get that wrong, and both are quiet. A controller that forgets the call is an
 * endpoint anybody can reach without a token at all. A controller that names the wrong action is
 * an endpoint a token for something else opens — `AccountFile\DeleteController` asking for
 * `ACCOUNT_FILE_LIST` would let a read token delete an attachment. Neither shows up in a test of
 * the endpoint's behaviour, because a test that authenticates correctly passes either way.
 *
 * All 66 are right today. This is what keeps the sixty-seventh right.
 */
#[Group('unitary')]
class EndpointsAuthoriseTest extends TestCase
{
    private const CONTROLLERS = REAL_APP_ROOT . '/src/Infrastructure/Adapter/In/Api/Controllers';

    /**
     * The ACL's name for each group of endpoints.
     *
     * Spelled out rather than derived, because the ACL does not spell them consistently:
     * `AccountFile` is `ACCOUNT_FILE` but `CustomField` is `CUSTOMFIELD`, and `UserGroup` is
     * `GROUP`. A new group has to be added here, which is the point — the author has to say which
     * permissions it belongs to rather than have a rule guess.
     *
     * @var array<string, string>
     */
    private const ACL_NAME_FOR_GROUP = [
        'Account' => 'ACCOUNT',
        'AccountFile' => 'ACCOUNT_FILE',
        'AuthToken' => 'AUTHTOKEN',
        'Category' => 'CATEGORY',
        'Client' => 'CLIENT',
        'Config' => 'CONFIG',
        'CustomField' => 'CUSTOMFIELD',
        'Eventlog' => 'EVENTLOG',
        'Notification' => 'NOTIFICATION',
        'Profile' => 'PROFILE',
        'PublicLink' => 'PUBLICLINK',
        'Tag' => 'TAG',
        'User' => 'USER',
        'UserGroup' => 'GROUP',
    ];

    /**
     * Where the endpoint's own name and the permission's name genuinely differ. Each of these is a
     * decision somebody made, not a slip: listing an account's files is the `LIST` permission,
     * fetching one is `DOWNLOAD`, and running an export or a backup is the privileged `_RUN`
     * rather than the section id of the same name.
     *
     * @var array<string, string>
     */
    private const NAMED_DIFFERENTLY = [
        'AccountFile/Search' => 'ACCOUNT_FILE_LIST',
        'AccountFile/View' => 'ACCOUNT_FILE_DOWNLOAD',
        'Config/Backup' => 'CONFIG_BACKUP_RUN',
        'Config/Export' => 'CONFIG_EXPORT_RUN',
    ];

    /**
     * @param string $endpoint group/action, as the directory and file name give it
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function anEndpointAsksWhoIsCalling(string $endpoint, string $source): void
    {
        self::assertMatchesRegularExpression(
            '/setupApi\(\s*AclActionsInterface::\w+/',
            $source,
            sprintf('%s never calls setupApi(), so it is reachable without a token', $endpoint)
        );
    }

    /**
     * And asks for the permission that belongs to it.
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function anEndpointAsksForItsOwnPermission(string $endpoint, string $source): void
    {
        preg_match('/setupApi\(\s*AclActionsInterface::(\w+)/', $source, $matches);

        $asked = $matches[1] ?? '';
        $expected = self::NAMED_DIFFERENTLY[$endpoint] ?? self::expectedActionFor($endpoint);

        self::assertSame($expected, $asked, sprintf('%s asks for the wrong permission', $endpoint));
    }

    /**
     * The permission it names is one the application has. A constant that does not exist would be
     * a fatal error at the moment somebody called the endpoint, not before.
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function thePermissionItAsksForExists(string $endpoint, string $source): void
    {
        preg_match('/setupApi\(\s*AclActionsInterface::(\w+)/', $source, $matches);

        self::assertArrayHasKey(
            $matches[1] ?? '',
            (new ReflectionClass(AclActionsInterface::class))->getConstants(),
            $endpoint
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        $endpoints = [];

        foreach (glob(self::CONTROLLERS . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $group = basename($directory);

            if ($group === 'Help') {
                continue;
            }

            foreach (glob($directory . '/*Controller.php') ?: [] as $file) {
                $name = $group . '/' . basename($file, 'Controller.php');

                $endpoints[$name] = [$name, (string)file_get_contents($file)];
            }
        }

        return $endpoints;
    }

    /**
     * `AccountFile/Delete` is `ACCOUNT_FILE_DELETE`; `Account/EditPass` is `ACCOUNT_EDIT_PASS`.
     */
    private static function expectedActionFor(string $endpoint): string
    {
        [$group, $action] = explode('/', $endpoint);

        self::assertArrayHasKey(
            $group,
            self::ACL_NAME_FOR_GROUP,
            sprintf('%s is a new group of endpoints — say which permissions it belongs to', $group)
        );

        return self::ACL_NAME_FOR_GROUP[$group]
               . '_'
               . strtoupper((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $action));
    }
}
