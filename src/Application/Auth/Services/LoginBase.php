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

namespace SP\Application\Auth\Services;

use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Auth\Services\AuthException;

use Exception;
use SP\Application\Application;
use SP\Domain\Common\Services\Service;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Infrastructure\Http\Ports\RequestService;
use SP\Infrastructure\Http\Providers\Uri;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Application\Security\Ports\TrackService;

use function SP\__u;

/**
 * Class LoginBase
 */
abstract class LoginBase extends Service
{
    private readonly TrackRequest $trackRequest;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        Application                         $application,
        private readonly TrackService       $trackService,
        protected readonly RequestService $request
    ) {
        parent::__construct($application);

        $this->trackRequest = $this->trackService->buildTrackRequest(static::class);
    }

    /**
     * @throws AuthException
     * @throws Exception
     */
    final protected function checkTracking(): void
    {
        if ($this->trackService->checkTracking($this->trackRequest)) {
            $this->addTracking();

            throw AuthException::error(__u('Attempts exceeded'), null, LoginStatus::MAX_ATTEMPTS_EXCEEDED->value);
        }
    }

    /**
     * Add a tracking entry
     *
     * @throws AuthException
     */
    final protected function addTracking(): void
    {
        try {
            $this->trackService->add($this->trackRequest);
        } catch (Exception $e) {
            throw AuthException::error(__u('Internal error'), null, Service::STATUS_INTERNAL_ERROR, $e);
        }
    }

    protected function getUriForRoute(string $route): string
    {
        return (new Uri('index.php'))->addParam('r', $route)->getUri();
    }
}
