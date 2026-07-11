<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Install\Adapters;

use SP\Domain\Http\Ports\RequestService;

/**
 * Class InstallDataAdapter
 */
final class InstallDataFactory
{
    public static function buildFromRequest(RequestService $request): InstallData
    {
        // No silent defaults ('admin', 'root', ...): a missing field must surface
        // as a validation error, not install with values the user never entered
        $installData = new InstallData();
        $installData->setSiteLang($request->analyzeString('sitelang', 'en_US'));
        $installData->setAdminLogin($request->analyzeString('adminlogin') ?? '');
        $installData->setAdminPass($request->analyzeEncrypted('adminpass') ?? '');
        $installData->setAdminPassRepeat($request->analyzeEncrypted('adminpassr') ?? '');
        $installData->setMasterPassword($request->analyzeEncrypted('masterpassword') ?? '');
        $installData->setMasterPasswordRepeat($request->analyzeEncrypted('masterpasswordr') ?? '');
        $installData->setDbAdminUser($request->analyzeString('dbuser') ?? '');
        $installData->setDbAdminPass($request->analyzeEncrypted('dbpass') ?? '');
        $installData->setDbName($request->analyzeString('dbname') ?? '');
        $installData->setDbHost($request->analyzeString('dbhost') ?? '');
        $installData->setHostingMode($request->analyzeBool('hostingmode', false));

        return $installData;
    }
}
