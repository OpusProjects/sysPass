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

namespace SP\Tests\Unit\Infrastructure\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every action a controller asks the ACL about has an arm in the ACL.
 *
 * `Acl::checkUserAccess()` is one long switch ending in a deny. An action id that has no case
 * falls off the end, so it is refused for everyone except an application administrator, who
 * short-circuits at the top — and nothing anywhere reports that the id was simply not listed. It
 * looks exactly like a permission somebody has not been granted.
 *
 * Three had reached that state at once: PLUGIN_DELETE, ACCOUNTMGR_HISTORY_RESTORE and
 * ACCOUNTMGR_HISTORY_DELETE, each the only unlisted action in a family whose other members were
 * granted normally, and each with a button the grid rendered unconditionally. A holder of the
 * profile bit that grants the page could open it, see the action, and be refused by it.
 *
 * The other direction — an action id with no controller — is `RoutesAreDispatchableTest`'s
 * business. Plenty of ids exist only so that permissions can be named, and this deliberately says
 * nothing about them: it asks only that what the code *checks* is what the ACL *answers*.
 */
#[Group('unitary')]
class AclAnswersEveryActionCheckedTest extends TestCase
{
    private const CONTROLLERS = REAL_APP_ROOT . '/src/Infrastructure/Adapter/In';
    private const ACL = REAL_APP_ROOT . '/src/Infrastructure/Acl/Acl.php';

    /**
     * @return array<string, array{string}>
     */
    public static function actionProvider(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::CONTROLLERS, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());

            // The direct form: checkUserAccess(AclActionsInterface::FOO).
            preg_match_all('/checkUserAccess\(\s*AclActionsInterface::([A-Z0-9_]+)/', $source, $matches);

            // And the indirect one. `SearchGridControllerBase::searchAction()` calls
            // `checkUserAccess($this->getAclAction())`, so the id a search controller is
            // authorised by never appears beside the call — it is returned from a method in a
            // subclass. Six controllers work that way, and the one action of the six with no arm
            // in the ACL was exactly the one this test could not see: ACCOUNT_FILE_SEARCH, which
            // refused every search inside a Files tab that had been shown to the user.
            preg_match_all(
                '/function getAclAction\(\)[^{]*\{.*?AclActionsInterface::([A-Z0-9_]+)/s',
                $source,
                $indirect
            );

            foreach (array_merge($matches[1], $indirect[1]) as $action) {
                $found[$action] = [$action];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Actions a controller checks that the ACL deliberately does not list.
     *
     * All three are the notification management actions, and the effect is that only an
     * application administrator reaches them. That is what the feature is — a notification is
     * issued by an administrator — and the absence has been looked at before, when
     * NOTIFICATION_DELETE was mistakenly described as reachable by any signed-in user. It is not:
     * it falls through to the deny like these two beside it.
     *
     * They are listed rather than granted because adding an arm here hands the action to whoever
     * holds the profile bit, and which bit that should be is a decision about the product rather
     * than a defect to fix. Listing them keeps the check honest: the test asserts these three are
     * *still* unlisted, so a fourth cannot join them by being forgotten.
     */
    private const DELIBERATELY_UNLISTED = [
        'NOTIFICATION_CREATE',
        'NOTIFICATION_EDIT',
        'NOTIFICATION_DELETE',
    ];

    #[Test]
    #[DataProvider('actionProvider')]
    public function theAclHasAnArmForIt(string $action): void
    {
        $hasArm = preg_match(
            '/^\s*case self::' . preg_quote($action, '/') . ':\s*$/m',
            (string)file_get_contents(self::ACL)
        ) === 1;

        if (in_array($action, self::DELIBERATELY_UNLISTED, true)) {
            self::assertFalse(
                $hasArm,
                sprintf(
                    '%s is listed as deliberately unlisted but Acl now has a case for it. Remove '
                    . 'it from DELIBERATELY_UNLISTED.',
                    $action
                )
            );

            return;
        }

        self::assertTrue(
            $hasArm,
            sprintf(
                'A controller calls checkUserAccess(AclActionsInterface::%s), but Acl has no case '
                . 'for it — so it falls through to the deny at the end and only an application '
                . 'administrator can ever reach that action.',
                $action
            )
        );
    }
}
