<?php
declare(strict_types=1);
/*
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
namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\CustomField\Models\CustomFieldDefinition;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\CustomFields;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The form offers exactly the modules the definition accepts
 */
#[Group('unitary')]
class CustomFieldsTest extends UnitaryTestCase
{
    /**
     * A module the select offered and the service refused would be a form that cannot be saved;
     * one the service accepted and the select left out could only be reached through the API.
     */
    public function testTheFormOffersExactlyTheModulesADefinitionAccepts(): void
    {
        self::assertSame(CustomFieldDefinition::MODULES, array_keys(CustomFields::getFieldModules()));
    }
}
