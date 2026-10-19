<?php

namespace App\Http\Middleware;

use App\Models\DownloadPrintLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LogDownloadPrintActions
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $user = $request->user();
        $route = $request->route();
        if (! $user || ! $route) {
            return $response;
        }

        $routeName = (string) ($route->getName() ?? '');
        if ($routeName && Str::startsWith($routeName, 'download-print-logs.')) {
            return $response;
        }

        // Site Diary views / new-tab opens / thumbnails stream media inline and are audited as PHOTO_VIEW / VIDEO_OPEN_NEW_TAB … in
        // the Site Diary log; only the real download route belongs here.
        // Archive previews / new-tab views stream a PDF inline; they are audited as PREVIEW / OPEN_NEW_TAB in the Archive
        // log and must not be mislabelled as downloads here (real Archive downloads still match on their route name).
        if ($routeName && in_array($routeName, ['archive.records.files.preview', 'archive.records.files.open', 'site-diary.media.view', 'site-diary.media.open', 'site-diary.media.thumb', 'wimschat.attachments.view', 'wimschat.attachments.thumb', 'wimschat.attachments.avatar'], true)) {
            return $response;
        }

        $statusCode = (int) $response->getStatusCode();
        if ($statusCode >= 400) {
            return $response;
        }

        $actionName = (string) $route->getActionName();
        $path = $request->path();
        $signature = strtolower("{$routeName} {$actionName} {$path}");

        $contentDisposition = strtolower((string) $response->headers->get('content-disposition', ''));
        $contentType = strtolower((string) $response->headers->get('content-type', ''));
        $output = strtolower((string) $request->query('output', ''));

        $queryPrint = $output === 'print';
        $queryDownload = in_array($output, ['pdf', 'download', 'export'], true);

        $looksLikePrint = $queryPrint || Str::contains($signature, ['print']);
        $looksLikeDownload = $queryDownload || Str::contains($signature, ['download', 'export', 'pdf', 'backup']);
        $hasAttachmentHeader = Str::contains($contentDisposition, ['attachment', 'filename=']);
        $fileContentType = Str::contains($contentType, [
            'application/pdf',
            'application/vnd',
            'text/csv',
            'application/octet-stream',
            'application/zip',
            'application/x-zip-compressed',
            'text/plain',
        ]);

        if (! $looksLikePrint && ! $looksLikeDownload && ! $hasAttachmentHeader && ! $fileContentType) {
            return $response;
        }

        if ($queryPrint) {
            $actionType = 'print';
        } elseif ($queryDownload) {
            $actionType = 'download';
        } else {
            $actionType = $looksLikePrint && ! $looksLikeDownload && ! $hasAttachmentHeader ? 'print' : 'download';
        }
        $agent = user_agent_info((string) $request->userAgent());

        DownloadPrintLog::query()->create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'role' => optional($user->roles->first())->name,
            'action_type' => $actionType,
            'module' => $this->moduleFromRoute($routeName),
            'route_name' => $routeName ?: null,
            'method' => strtoupper($request->method()),
            'url' => $request->fullUrl(),
            'file_name' => $this->extractFileName($contentDisposition),
            'ip_address' => $request->ip(),
            'device' => $agent['device'] ?? null,
            'os' => $agent['os'] ?? null,
            'browser' => $agent['browser'] ?? null,
            'location' => geo_from_ip($request->ip()),
            'user_agent' => (string) $request->userAgent(),
            'status_code' => $statusCode,
            'performed_at' => now(),
            'extra_attributes' => [
                'query' => $request->query(),
                'route_parameters' => $route->parameters(),
            ],
        ]);

        return $response;
    }

    protected function moduleFromRoute(string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        return Str::before($routeName, '.');
    }

    protected function extractFileName(string $contentDisposition): ?string
    {
        if (! $contentDisposition) {
            return null;
        }

        if (preg_match('/filename\*=UTF-8\\\'\\\'([^;]+)/i', $contentDisposition, $m)) {
            return urldecode(trim($m[1], "\"' "));
        }

        if (preg_match('/filename=\"?([^\";]+)\"?/i', $contentDisposition, $m)) {
            return trim($m[1], "\"' ");
        }

        return null;
    }
}
