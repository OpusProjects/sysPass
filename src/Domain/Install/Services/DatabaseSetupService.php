<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Install\Services;

/**
 * Interface DatabaseSetupService
 */
interface DatabaseSetupService
{
    /**
     * Connect to the database
     *
     * Check whether the connection to the sysPass database is possible with
     * the provided details.
     */
    public function connectDatabase();

    public function setupDbUser(): array;

    /**
     * Create the user to connect to the database.
     * This function creates the user used to connect to the database.
     *
     * @param  string  $user
     * @param  string  $pass
     */
    public function createDBUser(string $user, string $pass);

    /**
     * Create the database
     */
    public function createDatabase(?string $dbUser = null);

    /**
     * @return mixed
     */
    public function checkDatabaseExists(): mixed;

    /**
     * Roll back the installation in case of failure.
     * This function removes the sysPass database and user.
     */
    public function rollback(?string $dbUser = null);

    /**
     * Create the database structure.
     * This function creates the database structure from the dbsctructure.sql file.
     */
    public function createDBStructure();

    /**
     * Check the connection to the database
     */
    public function checkConnection();
}
