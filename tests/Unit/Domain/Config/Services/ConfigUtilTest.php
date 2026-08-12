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

namespace SP\Tests\Unit\Domain\Config\Services;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Config\Services\ConfigUtil;
use SP\Domain\Core\Exceptions\ConfigException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class ConfigUtilTest
 *
 * Both adapters turn what an administrator typed into a configured list, dropping what cannot be
 * used. What they drop is the point: a malformed address left in the list would make every
 * notification attempt fail, and an arbitrary string among the event names would be matched
 * against every event.
 *
 * checkConfigDir() is the boot-time gate on the '/config' directory that holds DB credentials
 * and crypto keys: it decides whether the app is allowed to start reading/writing that directory
 * at all, and it self-heals overly-permissive modes instead of always failing. These tests use
 * vfsStream (already the test suite's virtual filesystem, wired up in tests/bootstrap.php) so the
 * "not writable" and "chmod fails" branches can be exercised deterministically instead of relying
 * on real OS permissions, which the test container's root user would bypass entirely.
 */
#[Group('unitary')]
class ConfigUtilTest extends UnitaryTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['CONFIG_PATH']);

        parent::tearDown();
    }

    public function testAListOfAddressesKeepsOnlyTheValidOnes(): void
    {
        $addresses = ConfigUtil::mailAddressesAdapter(
            'someone@example.invalid,not-an-address,other@example.invalid'
        );

        self::assertCount(2, $addresses);
        self::assertContains('someone@example.invalid', $addresses);
        self::assertContains('other@example.invalid', $addresses);
        self::assertNotContains('not-an-address', $addresses);
    }

    public function testAnEmptyAddressListIsEmpty(): void
    {
        self::assertSame([], ConfigUtil::mailAddressesAdapter(''));
    }

    /**
     * A single address needs no separator.
     */
    public function testASingleAddressIsKept(): void
    {
        self::assertSame(['someone@example.invalid'], ConfigUtil::mailAddressesAdapter('someone@example.invalid'));
    }

    /**
     * Event names are dotted identifiers; anything else is dropped rather than being registered
     * as a name that could match unexpectedly.
     */
    public function testEventNamesAreKeptOnlyWhenTheyLookLikeEventNames(): void
    {
        $events = ConfigUtil::eventsAdapter(
            ['create.account', 'edit.user.password', 'not a name', '123', '', 'login']
        );

        self::assertContains('create.account', $events);
        self::assertContains('edit.user.password', $events);
        self::assertContains('login', $events);
        self::assertNotContains('not a name', $events);
        self::assertNotContains('123', $events);
        self::assertNotContains('', $events);
    }

    public function testAnEmptyEventListIsEmpty(): void
    {
        self::assertSame([], ConfigUtil::eventsAdapter([]));
    }

    /**
     * The installer and every later boot read DB credentials and crypto keys out of '/config'.
     * If that directory was never created (or got deleted), letting startup continue would fail
     * later with a confusing error from whatever tries to open a config file that isn't there;
     * this check fails fast with a CRITICAL severity naming the real cause instead.
     */
    public function testCheckConfigDirThrowsWhenTheDirectoryDoesNotExist(): void
    {
        $_ENV['CONFIG_PATH'] = APP_PATH . '/no-such-config-directory';

        try {
            ConfigUtil::checkConfigDir();
            self::fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('\'/config\' directory does not exist.', $e->getMessage());
            self::assertSame(SPException::CRITICAL, $e->getType());
        }
    }

    /**
     * A '/config' directory that exists but cannot be written to would still pass a bare
     * existence check, then fail later (regenerating config.xml, running an upgrade, changing
     * the master password) with a low-level write error. This asserts the gate catches it up
     * front, with a message that names '/config' rather than whatever file write happened next.
     */
    public function testCheckConfigDirThrowsWhenTheDirectoryIsNotWritable(): void
    {
        $dir = vfsStream::newDirectory('config-not-writable', 0555)->at(vfsStreamWrapper::getRoot());
        $_ENV['CONFIG_PATH'] = $dir->url();

        try {
            ConfigUtil::checkConfigDir();
            self::fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('Unable to write into \'/config\' directory', $e->getMessage());
            self::assertSame(SPException::CRITICAL, $e->getType());
        }
    }

    /**
     * checkConfigDir() tries to self-heal an overly-permissive '/config' mode with chmod(); when
     * even that fails (here: the process does not own the directory), the operator needs the
     * exact current vs. needed mode reported rather than a silent continuation that leaves DB
     * credentials and crypto keys readable by other accounts on the box.
     */
    public function testCheckConfigDirThrowsWhenPermissionsAreWrongAndChmodFails(): void
    {
        $dir = vfsStream::newDirectory('config-wrong-perms', 0757)->at(vfsStreamWrapper::getRoot());
        // Owned by neither the current user nor the current group: is_writable() still passes
        // via the "other" bits (7), but chmod() is only permitted for the owner, so it fails.
        $dir->chown(12345);
        $dir->chgrp(54321);
        $_ENV['CONFIG_PATH'] = $dir->url();

        try {
            ConfigUtil::checkConfigDir();
            self::fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('\'/config\' directory permissions are wrong', $e->getMessage());
            self::assertSame(SPException::ERROR, $e->getType());
            self::assertSame('Current: 757 - Needed: 750', $e->getHint());
        }
    }

    /**
     * When '/config' already has the required 750 mode, checkConfigDir() must be a silent no-op
     * on every request rather than an unconditional chmod attempt — a needless write on a
     * correctly-configured install is exactly the kind of side effect that turns a read-only
     * request into one that can fail on a read-only filesystem for no reason.
     */
    public function testCheckConfigDirDoesNothingWhenPermissionsAreAlreadyCorrect(): void
    {
        $dir = vfsStream::newDirectory('config-correct-perms', 0750)->at(vfsStreamWrapper::getRoot());
        $_ENV['CONFIG_PATH'] = $dir->url();

        $result = ConfigUtil::checkConfigDir();

        self::assertNull($result);
        self::assertSame('750', decoct($dir->getPermissions()));
    }

    /**
     * A '/config' directory left with an overly permissive mode (e.g. by an earlier install step)
     * is tightened to 750 automatically rather than blocking every subsequent request — this is
     * what lets a slightly-misconfigured install keep running instead of forcing a manual chmod
     * before the admin can even log in to fix it.
     */
    public function testCheckConfigDirFixesPermissionsWhenTheyAreWrongButChmodSucceeds(): void
    {
        $dir = vfsStream::newDirectory('config-fixable-perms', 0755)->at(vfsStreamWrapper::getRoot());
        $_ENV['CONFIG_PATH'] = $dir->url();

        $result = ConfigUtil::checkConfigDir();

        self::assertNull($result);
        self::assertSame('750', decoct(fileperms($dir->url()) & 0777));
    }
}
