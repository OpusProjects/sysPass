<?php
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\AccountFile;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use Symfony\Component\HttpFoundation\HeaderUtils;

use function SP\__u;

/**
 * Class DownloadController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class DownloadController extends AccountFileBase
{
    /**
     * Download action
     *
     * @param int $id
     *
     * @return ActionResponse
     * @throws ConstraintException
     * @throws QueryException
     */
    #[Action(ResponseType::PLAIN_TEXT)]
    public function downloadAction(int $id): ActionResponse
    {
        $fileDto = $this->accountFileService->getById($id);

        $this->accountFileAcl->requireView($fileDto->accountId ?? 0);

        $this->eventDispatcher->notify(new Event(
            'download.accountFile',
            $this,
            EventMessage::build(__u('File downloaded'))
                            ->addDetail(__u('File'), $fileDto->name)
        ));

        $response = $this->router->response();
        $response->header('Content-Length', (string)($fileDto->size ?? 0));
        $response->header('Content-Type', $fileDto->type ?? 'application/octet-stream');
        $response->header('Content-Description', ' sysPass file');
        $response->header('Content-Transfer-Encoding', 'binary');
        $response->header('Accept-Ranges', 'bytes');

        $type = strtolower($fileDto->type ?? '');

        $response->header(
            'Content-Disposition',
            self::disposition(
                $type === 'application/pdf' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileDto->name ?? ''
            )
        );

        return ActionResponse::ok($fileDto->content);
    }

    /**
     * The name is whoever uploaded the file's, and it reaches the browser as the name the download
     * is saved under. Interpolating it into `filename="…"` let that name end the quoted string and
     * add parameters of its own: `filename*=` takes precedence over `filename=` in every browser,
     * so an attachment listed as one thing downloaded as another. HeaderUtils encodes it instead,
     * which also gets a non-ASCII name across correctly rather than emitting it raw.
     *
     * It refuses a path separator outright, and a name is not a path — the download is a single
     * file whatever the name says — so both separators go, along with anything a fallback may not
     * carry.
     */
    private static function disposition(string $disposition, string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '_', $name));
        $fallback = trim((string)preg_replace('/[^A-Za-z0-9._-]/', '_', $name), '_');

        return HeaderUtils::makeDisposition(
            $disposition,
            $name === '' ? 'file' : $name,
            $fallback === '' ? 'file' : $fallback
        );
    }
}
