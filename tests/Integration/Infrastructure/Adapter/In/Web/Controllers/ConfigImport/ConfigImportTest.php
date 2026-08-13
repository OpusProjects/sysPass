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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigImport;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;

use function SP\Tests\getResource;

/**
 * Class ConfigImportTest
 */
#[Group('integration')]
#[InjectVault]
class ConfigImportTest extends IntegrationTestCase
{
    private bool $demoEnabled = false;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isDemoEnabled' => $this->demoEnabled]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function import()
    {
        $file = sprintf('%s.csv', self::$faker->filePath());

        file_put_contents($file, getResource('import', 'data.csv'));

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/plain'
            ]
        ];

        $data = [
            'import_defaultuser' => self::$faker->randomNumber(3),
            'import_defaultgroup' => self::$faker->randomNumber(3),
            'importPwd' => self::$faker->password(),
            'importMasterPwd' => self::$faker->password(),
            'csvDelimiter' => ';',
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configImport/import'], $data, $files)
        );

        $this->expectOutputString(
            '{"status":"OK","description":"Import finished","data":"Please check out the event log for more details"}'
        );

        IntegrationTestCase::runApp($container);
    }
    /**
     * ImportParamsDto's `string $delimiter = ';'` default can't apply when the analyzed value is
     * passed positionally, so an absent or empty csvDelimiter has to fall back here instead of
     * reaching the constructor as null.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    public function importWithoutCsvDelimiter(?string $delimiter)
    {
        $file = sprintf('%s.csv', self::$faker->filePath());

        file_put_contents($file, getResource('import', 'data.csv'));

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/plain'
            ]
        ];

        $data = [
            'import_defaultuser' => self::$faker->randomNumber(3),
            'import_defaultgroup' => self::$faker->randomNumber(3),
            'importPwd' => self::$faker->password(),
            'importMasterPwd' => self::$faker->password(),
        ];

        if ($delimiter !== null) {
            $data['csvDelimiter'] = $delimiter;
        }

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configImport/import'], $data, $files)
        );

        $this->expectOutputString(
            '{"status":"OK","description":"Import finished","data":"Please check out the event log for more details"}'
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Posting no file is the failure path, so it must not be reported with the success string.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function importWithoutFileIsNotReportedAsSuccess()
    {
        $data = [
            'import_defaultuser' => self::$faker->randomNumber(3),
            'import_defaultgroup' => self::$faker->randomNumber(3),
            'csvDelimiter' => ';',
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configImport/import'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"ERROR","description":"File does not exist"/');
    }

    /**
     * The demo instance refuses the import outright, before the upload is even inspected.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function importIsRefusedOnADemoInstance()
    {
        $this->demoEnabled = true;

        $file = sprintf('%s.csv', self::$faker->filePath());

        file_put_contents($file, getResource('import', 'data.csv'));

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/plain'
            ]
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'configImport/import'],
                ['csvDelimiter' => ';'],
                $files
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"WARNING","description":"Ey, this is a DEMO!!","data":null}'
        );
    }

    /**
     * A file whose real content is not one of the supported types (sniffed from its bytes, not
     * its extension or the client-supplied Content-Type) is refused rather than misread as CSV
     * or XML.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function aFileOfAnUnsupportedTypeIsRefused()
    {
        $file = sprintf('%s.csv', self::$faker->filePath());

        // A GIF signature: mime_content_type() sniffs actual bytes, so this is genuinely
        // detected as image/gif rather than merely mislabeled by name or extension.
        file_put_contents($file, 'GIF89a' . str_repeat("\0", 20));

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/plain'
            ]
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'configImport/import'],
                ['csvDelimiter' => ';'],
                $files
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"ERROR","description":"Mime type not supported/');
    }

    /**
     * An encrypted sysPass export refuses to import with the wrong password: the file carries a
     * hash of its real password, checked before anything is decrypted, so a caller cannot fish
     * for the right one by reading account data back out of a near-miss.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function aWrongImportPasswordIsRefused()
    {
        $file = sprintf('%s.xml', self::$faker->filePath());

        file_put_contents($file, getResource('import', 'data_syspass_encrypted.xml'));

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/xml'
            ]
        ];

        $data = [
            'importPwd' => 'definitely-the-wrong-password',
            'csvDelimiter' => ';',
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configImport/import'], $data, $files)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Wrong encryption password","data":null}'
        );
    }

    /**
     * The import can run to completion and still add nothing: every CSV row here is missing its
     * client/category, so each is skipped individually (as its own caught, logged error) rather
     * than aborting the whole file, and the run ends by reporting nothing was imported instead of
     * a false "Import finished".
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function noAccountsImportedIsReportedWhenEveryRowFails()
    {
        $file = sprintf('%s.csv', self::$faker->filePath());

        // 7 fields (matches CsvImport::NUM_FIELDS) but client and category are both blank,
        // so CsvImport::processAccounts() catches an exception for the row and moves on.
        file_put_contents($file, '"Broken account";"";"";"http://test.me";"login";"pass";"notes"' . "\n");

        $files = [
            'inFile' => [
                'name' => self::$faker->name(),
                'tmp_name' => $file,
                'size' => filesize($file),
                'type' => 'text/plain'
            ]
        ];

        $data = [
            'import_defaultuser' => self::$faker->randomNumber(3),
            'import_defaultgroup' => self::$faker->randomNumber(3),
            'csvDelimiter' => ';',
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configImport/import'], $data, $files)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"WARNING","description":"No accounts were imported",'
            . '"data":"Please check out the event log for more details"}'
        );
    }
}
