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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Account;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Dtos\PublicLinkKey;
use SP\Domain\Account\Models\PublicLink;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Crypt\Vault;
use SP\Infrastructure\Crypt\Crypt;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\Generators\PublicLinkDataGenerator;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;

/**
 * A public link is the one way an account is shown to somebody who is not signed in, and the only
 * two things standing between the link and the account are its expiry date and its view count.
 *
 * The existing test covers a link that is still valid. These cover the two ways a link stops being
 * one, because a link that outlives either limit hands the account's password to whoever kept the
 * URL.
 */
#[Group('integration')]
#[InjectVault]
class ViewLinkRefusalsTest extends IntegrationTestCase
{
    private const ACCOUNT_NAME = 'a-shared-account';

    /**
     * A link past its expiry date shows the refusal page instead of the account.
     *
     * @throws ContainerExceptionInterface
     * @throws CryptException
     * @throws EnvironmentIsBrokenException
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRefused')]
    public function anExpiredLinkDoesNotShowTheAccount()
    {
        $this->givenALink(['dateExpire' => time() - 1, 'maxCountViews' => 3, 'countViews' => 0]);

        $this->whenFollowingTheLink();
    }

    /**
     * Nor does one that has been viewed as many times as it was allowed. The count is checked
     * before the view is recorded, so the last permitted view is the one that reaches the limit.
     *
     * @throws ContainerExceptionInterface
     * @throws CryptException
     * @throws EnvironmentIsBrokenException
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRefused')]
    public function anExhaustedLinkDoesNotShowTheAccount()
    {
        $this->givenALink(['dateExpire' => time() + 100, 'maxCountViews' => 3, 'countViews' => 3]);

        $this->whenFollowingTheLink();
    }

    /**
     * One view short of the limit still works. Without this the refusals above would be satisfied
     * by a page that refused every link, which is not the same thing as a limit.
     *
     * @throws ContainerExceptionInterface
     * @throws CryptException
     * @throws EnvironmentIsBrokenException
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerShown')]
    public function aLinkWithinBothLimitsShowsTheAccount()
    {
        $this->givenALink(['dateExpire' => time() + 100, 'maxCountViews' => 3, 'countViews' => 2]);

        $this->whenFollowingTheLink();
    }

    /**
     * The account the link was created for, sealed into the link's vault the way the service does
     * it.
     *
     * @param array<string, mixed> $properties
     *
     * @throws CryptException
     * @throws EnvironmentIsBrokenException
     */
    private function givenALink(array $properties): void
    {
        $account = serialize(
            Simple::buildFromSimpleModel(
                AccountDataGenerator::factory()->buildAccount()->mutate(['name' => self::ACCOUNT_NAME])
            )
        );

        $publicLinkKey = new PublicLinkKey($this->passwordSalt);
        $vault = Vault::factory(new Crypt())->saveData($account, $publicLinkKey->getKey());

        $publicLink = PublicLinkDataGenerator::factory()
                                             ->buildPublicLink()
                                             ->mutate(
                                                 array_merge(
                                                     [
                                                         'hash' => $publicLinkKey->getHash(),
                                                         'data' => $vault->getSerialized()
                                                     ],
                                                     $properties
                                                 )
                                             );

        $this->addDatabaseMapperResolver(PublicLink::class, new QueryResult([$publicLink]));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenFollowingTheLink(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'account/viewLink/' . self::$faker->sha1()])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The refusal is asserted both ways round: the page says so, and the account's name is nowhere
     * in what was sent back.
     */
    private function outputCheckerRefused(string $output): void
    {
        self::assertStringContainsString('You don\'t have permission to access this page', $output);
        self::assertStringNotContainsString(self::ACCOUNT_NAME, $output);
    }

    private function outputCheckerShown(string $output): void
    {
        self::assertStringContainsString(self::ACCOUNT_NAME, $output);
        self::assertStringNotContainsString('You don\'t have permission to access this page', $output);
    }
}
