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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigSections;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the config sections that only expose a save endpoint: events, security, auth, wiki and
 * DokuWiki. Each one had no tests at all.
 */
#[Group('integration')]
class ConfigSectionsTest extends IntegrationTestCase
{
    /**
     * Logging options are plain switches, so saving them just persists the configuration.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEvents()
    {
        $this->whenSaving('configEvents/save', ['log_enabled' => 'on', 'syslog_enabled' => 'on']);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * Turning on remote syslog without somewhere to send it is refused, rather than saving a
     * configuration that cannot work.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerMissingSyslogServer')]
    public function saveEventsWithRemoteSyslogButNoServer()
    {
        $this->whenSaving('configEvents/save', ['log_enabled' => 'on', 'remotesyslog_enabled' => 'on']);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveSecurity()
    {
        $this->whenSaving(
            'configSecurity/save',
            ['https_enabled' => 'on', 'encrypt_session_enabled' => 'on']
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveAuth()
    {
        $this->whenSaving(
            'configAuth/save',
            [
                'authbasic_enabled' => 'on',
                'authbasic_domain' => self::$faker->domainName(),
                'sso_defaultgroup' => self::$faker->randomNumber(2),
                'sso_defaultprofile' => self::$faker->randomNumber(2),
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveWiki()
    {
        $this->whenSaving(
            'configWiki/save',
            [
                'wiki_enabled' => 'on',
                'wiki_searchurl' => self::$faker->url(),
                'wiki_pageurl' => self::$faker->url(),
                'wiki_filter' => self::$faker->word(),
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * Enabling the wiki without its URLs is refused.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveWikiWithoutParameters()
    {
        $this->whenSaving('configWiki/save', ['wiki_enabled' => 'on']);

        $this->expectOutputString('{"status":"ERROR","description":"Missing Wiki parameters","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveDokuWiki()
    {
        $this->whenSaving(
            'configDokuWiki/save',
            [
                'dokuwiki_enabled' => 'on',
                'dokuwiki_url' => self::$faker->url(),
                'dokuwiki_urlbase' => self::$faker->url(),
                'dokuwiki_user' => self::$faker->userName(),
                'dokuwiki_pass' => self::$faker->password(),
                'dokuwiki_namespace' => self::$faker->word(),
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * Enabling DokuWiki without its connection details is refused.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveDokuWikiWithoutParameters()
    {
        $this->whenSaving('configDokuWiki/save', ['dokuwiki_enabled' => 'on']);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Missing DokuWiki parameters","data":null}'
        );
    }

    /**
     * Leaving a section switched off is accepted without its parameters — and because nothing
     * moved, the endpoint reports that rather than claiming it saved.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveWikiDisabledNeedsNoParameters()
    {
        $this->whenSaving('configWiki/save', []);

        $this->expectOutputString('{"status":"OK","description":"No changes","data":null}');
    }

    /**
     * The check is raised as a validation exception, so the response carries its trace as data;
     * only the status and message are the contract here.
     */
    private function outputCheckerMissingSyslogServer(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertEquals('Missing remote syslog parameters', $json->description);
    }

    /**
     * @param array<string, string|int> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenSaving(string $route, array $fields): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => $route], $fields)
        );

        IntegrationTestCase::runApp($container);
    }
}
