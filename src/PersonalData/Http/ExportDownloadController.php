<?php

namespace GP247\Core\PersonalData\Http;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use Symfony\Component\HttpFoundation\Response;

/**
 * An administrator downloads a customer's data package from the "Personal data requests" screen.
 * The link was given to this administrator after they confirmed who they are; it works once,
 * within 24 hours. The file is removed after its last allowed download (or after 24 hours).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-admin-create, US-personal-data-requests-permission
 * @aidlc-adr personal-data-requests_access-and-settings
 */
class ExportDownloadController
{
    /**
     * @param string $export Package id.
     * @param string $token  Secret part of the link.
     * @return Response
     */
    public function __invoke(string $export, string $token): Response
    {
        $admin = function_exists('admin') ? admin()->user() : null;
        $user = app(AdminUserContract::class);
        // WHY: View.all may open the path (GET) but never gets a link; refuse it plainly too.
        if ($admin === null || (!$user->isAdministrator() && $user->isViewAll())) {
            return $this->refuse();
        }
        $service = app(ExportService::class);
        $path = $service->claim($export, $token, 'admin:' . $admin->id);
        if ($path === null) {
            return $this->refuse();
        }
        $package = $service->find($export);
        if ($package !== null) {
            app(PrivacyExports::class)->downloaded($package, ['type' => RequestLog::ACTOR_ADMIN, 'id' => (string) $admin->id]);
        }

        $response = response()->download($path, 'personal-data-' . now()->format('Ymd-His') . '.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);

        // WHY only the last time: a download that breaks halfway can be tried again with a new link.
        return $service->lastDownload($export) ? $response->deleteFileAfterSend() : $response;
    }

    /**
     * @return Response
     */
    private function refuse(): Response
    {
        return response(gp247_language_render('admin.personal_data.requests.link_invalid'), 403)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store, private');
    }
}
