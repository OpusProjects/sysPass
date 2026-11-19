<?php

declare(strict_types=1);
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

namespace SP\Domain\Upgrade\Services;

use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\UpgradeVersion;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Log\Ports\FileHandlerProvider;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;
use SP\Domain\Upgrade\Ports\UpgradeService;
use SP\Domain\Core\Exceptions\FileException;
use Throwable;

use function SP\__u;
use function SP\logger;

/**
 * Class Upgrade
 */
final class Upgrade extends Service implements UpgradeService
{
    /**
     * The version this rewrite's installer stamped before APP_BUILD followed its migrations
     *
     * Only that installer ever recorded it as the database version — an upgrade from 3.2 records
     * the newest migration it applied there, and 3.2 itself recorded 320.x — and it installed
     * `dbstructure.sql`, which already is the schema both migrations up to 400.24240101 produce.
     * Reading it literally would re-run them against that schema: one drops a column that is not
     * there and fails for good, the other decodes text that was never encoded.
     */
    private const INSTALLED_BEFORE_THE_BUILD_FOLLOWED = ['400.21031301' => '400.24240101'];

    /**
     * @var array<string> $upgradeHandlers
     */
    private array $upgradeHandlers = [];

    public function __construct(
        Application                         $application,
        FileHandlerProvider                 $fileHandlerProvider,
        private readonly ContainerInterface $container
    ) {
        parent::__construct($application);

        $this->eventDispatcher->attach($fileHandlerProvider);
    }

    /**
     * @inheritDoc
     * @throws FileException
     * @throws ServiceException
     * @throws UpgradeException
     */
    public function upgrade(string $version, ConfigDataInterface $configData): void
    {
        $class = $this::class;

        // Where to resume from: the later of what the caller passed (the stored application
        // version) and the stored database version, which UpgradeDatabase stamps as each migration
        // applies. The application version alone was not enough — it used to be reset below the
        // last migration at the end of every run (see the end of this method), so raising
        // APP_BUILD would have started the next upgrade from a version older than migrations
        // already applied, and re-run them.
        $version = self::later($version, $configData->getDatabaseVersion());
        $version = self::INSTALLED_BEFORE_THE_BUILD_FOLLOWED[$version] ?? $version;
        $lastApplied = $version;

        $this->eventDispatcher->notify(
            new Event(
                sprintf('upgrade.%s.start', $class),
                $this,
                EventMessage::build()->addDescription(__u('Update'))->addDetail('type', $class)
            )
        );

        foreach ($this->getTargetUpgradeHandlersByVersion($version) as $targetVersion => $upgradeHandlers) {
            foreach ($upgradeHandlers as $upgradeHandlerClass) {
                try {
                    // Resolved here rather than while grouping, so a handler we never reach —
                    // because an earlier one failed — is never constructed. The conversion is
                    // what the grouping's own catch used to provide for this call.
                    $upgradeHandler = $this->container->get($upgradeHandlerClass);
                } catch (Throwable $e) {
                    throw ServiceException::from($e);
                }

                if (!$upgradeHandler->apply($targetVersion, $configData)) {
                    throw UpgradeException::critical(
                        __u('Error while applying the update'),
                        __u('Please, check the event log for more details')
                    );
                }

                logger('Upgrade: ' . $upgradeHandler::class);
            }

            // The resume point, advanced as each version finishes rather than only once at the end.
            //
            // What still needs running is derived from `appVersion`, and that used to be written
            // after *every* handler had succeeded, while progress was really being stamped per file
            // in `databaseVersion`. So an interruption between two versions — an OOM kill, a
            // stopped container, or simply `max_execution_time`, which nothing here raises although
            // every other long write path calls `set_time_limit(0)` — left a database already
            // migrated and a resume point that had not moved. The retry then re-ran a migration
            // that had already been applied: `40024210101.sql` drops a column that is no longer
            // there and fails for good, and `UpgradeConfigText` would decode text that is already
            // decoded, which its own header says must happen exactly once.
            //
            // Writing it inside the loop is safe because the generator was built from the original
            // version and is not re-evaluated; only a later run sees the advanced value.
            $configData->setAppVersion($targetVersion);
            $lastApplied = $targetVersion;

            $this->config->save($configData);
        }

        // The handlers stamp what they own — UpgradeDatabase records the schema version it brought
        // the database to. Nothing recorded that the *application* had been upgraded, and
        // ModuleBase::checkUpgradeNeeded() reads both: an install that upgraded successfully was
        // still sent to the upgrade page on the next request, with its one-time key already spent.
        //
        // Never older than the last version applied: a resume point that goes backwards is worse
        // than one that never moves. It was the application version unconditionally, and that was
        // older than the migrations it shipped with.
        $configData->setAppVersion(self::later(Version::getVersionStringNormalized(), $lastApplied));

        $this->config->save($configData);

        $this->eventDispatcher->notify(
            new Event(
                sprintf('upgrade.%s.end', $class),
                $this,
                EventMessage::build()->addDescription(__u('Update'))->addDetail('type', $class)
            )
        );
    }

    /**
     * The later of two stored versions, an empty one counting as the earliest
     */
    private static function later(string $version, ?string $other): string
    {
        if ($other === null || $other === '') {
            return $version;
        }

        return Version::checkVersion($version, $other) ? $other : $version;
    }

    /**
     * Every handler still to run, grouped by the version it belongs to, oldest version first.
     *
     * Grouped because two handlers can declare the same version — `UpgradeDatabase` and
     * `UpgradeConfigText` both carry `400.24240101` — and the resume point may only advance once
     * both have run. Sorted because it is a resume point: applying a lower version after a higher
     * one would move it backwards, and a migration must in any case not run before one that
     * precedes it. The order used to be whatever order the handlers were registered and their
     * attributes declared in, which happens to ascend today and is nothing the code required.
     *
     * @param string $version
     *
     * @return array<string, class-string<UpgradeHandlerService>[]>
     * @throws ServiceException
     */
    private function getTargetUpgradeHandlersByVersion(string $version): array
    {
        try {
            $byVersion = [];

            foreach ($this->upgradeHandlers as $class) {
                $reflection = new ReflectionClass($class);
                /** @var ReflectionAttribute<UpgradeVersion> $attribute */
                foreach ($reflection->getAttributes(UpgradeVersion::class) as $attribute) {
                    $instance = $attribute->newInstance();

                    if (Version::checkVersion($version, $instance->version)) {
                        // The class, not the instance: a handler that a failure upstream means we
                        // never reach should not be constructed either.
                        $byVersion[$instance->version][] = $class;
                    }
                }
            }

            uksort(
                $byVersion,
                static fn(string $left, string $right): int => version_compare(
                    (string)Version::normalizeVersionForCompare($left),
                    (string)Version::normalizeVersionForCompare($right)
                )
            );

            return $byVersion;
        } catch (Throwable $e) {
            throw ServiceException::from($e);
        }
    }

    /**
     * @throws ServiceException
     * @throws InvalidClassException
     */
    public function registerUpgradeHandler(string $class): void
    {
        if (!class_exists($class) || !is_subclass_of($class, UpgradeHandlerService::class)) {
            throw InvalidClassException::error('Class does not either exist or implement UpgradeService class');
        }

        $hash = sha1($class);

        if (array_key_exists($hash, $this->upgradeHandlers)) {
            throw ServiceException::error('Class already registered');
        }

        $this->upgradeHandlers[$hash] = $class;
    }
}
