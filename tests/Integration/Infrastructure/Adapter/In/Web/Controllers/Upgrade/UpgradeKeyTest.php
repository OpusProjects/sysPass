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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Upgrade;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The upgrade endpoint is unauthenticated and privileged: it runs a database migration and saves
 * the configuration. All that stands in front of it is a key from config.xml and a confirmation
 * flag, so each way past that gate is covered.
 *
 * The existing test covers the case that was once a real bypass — no key configured and none
 * supplied. These cover the rest, including a correct key, without which every other test here
 * would pass against a gate that simply refused everything.
 */
#[Group('integration')]
class UpgradeKeyTest extends IntegrationTestCase
{
    private const CONFIGURED_KEY = 'a-configured-upgrade-key';

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['getUpgradeKey' => self::CONFIGURED_KEY]);
    }

    /**
     * The confirmation is checked before the key, so an unconfirmed request never reaches it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotConfirmed')]
    public function anUnconfirmedUpgradeIsRefused()
    {
        $this->whenUpgrading(['key' => self::CONFIGURED_KEY]);
    }

    /**
     * A key that is not the configured one is refused, so guessing is the only way through and
     * the comparison is done in constant time.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWrongKey')]
    public function aWrongKeyIsRefused()
    {
        $this->whenUpgrading(['chkConfirm' => '1', 'key' => 'not-the-key']);
    }

    /**
     * A key that is a prefix of the configured one must not be accepted either.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWrongKey')]
    public function aPrefixOfTheKeyIsRefused()
    {
        $this->whenUpgrading(['chkConfirm' => '1', 'key' => substr(self::CONFIGURED_KEY, 0, 10)]);
    }

    /**
     * The correct key gets past the gate. Without this the refusals above would be satisfied by
     * an endpoint that rejected everything, which is not the same thing as a working check.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPastTheGate')]
    public function theConfiguredKeyIsAccepted()
    {
        $this->whenUpgrading(['chkConfirm' => '1', 'key' => self::CONFIGURED_KEY]);
    }

    /**
     * @param array<string, string> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenUpgrading(array $fields): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'upgrade/upgrade'], $fields)
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerNotConfirmed(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('The updating need to be confirmed', $json->description);
    }

    private function outputCheckerWrongKey(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Wrong security code', $json->description);
    }

    /**
     * Whatever the upgrade itself does next, it is no longer the gate refusing.
     */
    private function outputCheckerPastTheGate(string $output): void
    {
        $json = json_decode($output);

        self::assertNotSame('Wrong security code', $json->description);
        self::assertNotSame('The updating need to be confirmed', $json->description);
    }
}
