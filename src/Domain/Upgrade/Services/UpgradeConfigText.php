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

namespace SP\Domain\Upgrade\Services;

use SP\Application\Application;
use SP\Domain\Common\Attributes\UpgradeVersion;
use SP\Domain\Common\Services\Service;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;

use function SP\__u;

/**
 * The configuration's half of the same repair the SQL file does for the database.
 *
 * Every value typed into a configuration form went through `Filter::getString()`, which escaped
 * HTML, and was written to `config.xml` in that form. For most settings that is the cosmetic
 * problem it is everywhere else. For a few it is not: an LDAP filter is written
 * `(&(objectClass=user))`, was stored `(&amp;(objectClass=user))`, and was handed to the directory
 * like that — where `&amp;` is not an operator and the search matches nothing.
 *
 * The configuration is a file rather than a table, so the SQL that repairs the rows cannot reach
 * it; this runs beside that file, against the same version.
 */
#[UpgradeVersion('400.24240101')]
final class UpgradeConfigText extends Service implements UpgradeHandlerService
{
    /**
     * In this order, and the ampersand last.
     *
     * A value that literally contained `&lt;` when it was typed was stored as `&amp;lt;`, so
     * decoding the ampersand first would leave `&lt;` for the next pass to turn into a `<` —
     * quietly changing what somebody wrote into something else.
     */
    private const ENTITIES = ['&lt;' => '<', '&gt;' => '>', '&amp;' => '&'];

    public function __construct(Application $application)
    {
        parent::__construct($application);
    }

    /**
     * @inheritDoc
     */
    public function apply(string $version, ConfigDataInterface $configData): bool
    {
        $changed = 0;

        foreach ($configData as $key => $value) {
            $decoded = $this->decode($value);

            if ($decoded !== $value) {
                $configData->set($key, $decoded);
                $changed++;
            }
        }

        $this->eventDispatcher->notify(new Event(
            'upgrade.config.process',
            $this,
            EventMessage::build()
                        ->addDescription(__u('Configuration updating was completed successfully.'))
                        ->addDetail(__u('Version'), $version)
                        ->addDetail(__u('Fields'), (string)$changed)
        ));

        return true;
    }

    /**
     * Strings and lists of strings; anything else is left exactly as it was.
     *
     * There is nothing to exclude by name. The settings that hold opaque material — the master
     * password hash, the configuration's own hash, the backup and export hashes — are hexadecimal,
     * and a value that never contained an entity cannot be changed by decoding one.
     */
    private function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace(array_keys(self::ENTITIES), array_values(self::ENTITIES), $value);
        }

        if (is_array($value)) {
            return array_map($this->decode(...), $value);
        }

        return $value;
    }
}
