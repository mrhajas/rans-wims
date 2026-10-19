<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\User;
use App\Services\ArchiveAccessLogger;
use App\Models\ArchiveNotificationRule;
use App\Services\ArchiveFileStore;
use App\Services\ArchiveNotifier;
use App\Services\ArchiveStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Attachment access. Preview, Open in New Tab and Download all run the same gate:
 *   permission → the record is visible to this user (confidentiality) → the file really belongs to THAT record
 *   (never trust the id in the URL) → the file is still there.
 * Only then is a byte streamed, and the action is written to the audit log first.
 */
class ArchiveFileController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** One page of attachments as JSON — the record page loads page 1 up front and "Load more" fetches the rest. */
    public function index(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = ArchiveRecordController::accessible($archive_record, $user, 'read-archive');
        if (ArchiveRecordController::restrictedBlocked($record)) {
            return ArchiveRecordController::lockedResponse($request, $record);
        }

        return response()->json(self::page($record, $user, max((int) $request->query('page', 1), 1)));
    }

    public static function page(ArchiveRecord $record, User $user, int $page): array
    {
        $paginator = $record->files()
            ->with('uploader:id,name')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()->map(fn (ArchiveFile $f) => [
                'id' => $f->id,
                'name' => $f->original_name,
                'extension' => strtoupper((string) $f->extension),
                'file_type' => $f->file_type,
                'size_bytes' => $f->size_bytes,
                'can_preview' => $f->can_preview,
                'processing_status' => $f->processing_status,
                'uploaded_by' => optional($f->uploader)->name,
                'uploaded_at' => optional($f->created_at)->toIso8601String(),
            ])->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    public function store(Request $request, ArchiveRecord $archive_record)
    {
        $user = $request->user();
        $record = ArchiveRecordController::accessible($archive_record, $user, 'update-archive');
        // the person who has just created a restricted record may attach its files without the password window (30 minutes)
        $fresh = (array) $request->session()->get('archive_fresh_upload', []);
        $justCreated = ($fresh['id'] ?? null) === $record->id && ($fresh['until'] ?? 0) >= now()->timestamp;
        if (! $justCreated && ArchiveRecordController::restrictedBlocked($record)) {
            return ArchiveRecordController::lockedResponse($request, $record);
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:' . ArchiveFileStore::MAX_KB, 'extensions:' . implode(',', ArchiveFileStore::allowedExtensions())],
        ]);

        ArchiveFileStore::assertContent((array) $request->file('files'));

        $names = [];
        foreach ($request->file('files') as $upload) {
            $file = ArchiveFileStore::store($upload, $record, $user);
            ArchiveAccessLogger::log($user, $record, 'UPLOAD', $file, ['size_bytes' => $file->size_bytes]);
            $names[] = $file->original_name;
        }
        // files that arrive while the record is still being created (the form sends them one by one right after saving it) are part of "record created"
        $creating = $record->created_by_user_id === $user->id && $record->created_at && $record->created_at->gt(now()->subMinutes(3));
        if (! $creating) {
            ArchiveNotifier::event(ArchiveNotificationRule::FILE_ADDED, $user, $record, ['filename' => implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) : '')]);
        }

        $record->forceFill(['updated_by_user_id' => $user->id])->touch();
        $record->refreshTotals();
        ArchiveStats::flush();

        if (ArchiveRecordController::jsonMode($request)) {
            return response()->json(['ok' => true]);
        }

        return back()->with('message', __('Attachment(s) added.'));
    }

    public function destroy(Request $request, ArchiveRecord $archive_record, ArchiveFile $archive_file)
    {
        $user = $request->user();
        $record = ArchiveRecordController::accessible($archive_record, $user, 'delete-archive');
        abort_unless($archive_file->archive_record_id === $record->id, 404);
        if (ArchiveRecordController::restrictedBlocked($record)) {
            return ArchiveRecordController::lockedResponse($request, $record);
        }

        // Soft delete only: the stored file stays on disk so a mistaken removal is never a data loss.
        $archive_file->delete();
        ArchiveAccessLogger::log($user, $record, 'FILE_DELETE', $archive_file);
        ArchiveNotifier::event(ArchiveNotificationRule::FILE_REMOVED, $user, $record, ['filename' => $archive_file->original_name]);
        $record->refreshTotals();
        ArchiveStats::flush();

        return back()->with('message', __('Attachment removed.'));
    }

    public function preview(Request $request, ArchiveRecord $archive_record, ArchiveFile $archive_file)
    {
        return $this->serve($request, $archive_record, $archive_file, 'preview-archive-files', 'PREVIEW', true);
    }

    public function open(Request $request, ArchiveRecord $archive_record, ArchiveFile $archive_file)
    {
        return $this->serve($request, $archive_record, $archive_file, 'preview-archive-files', 'OPEN_NEW_TAB', true);
    }

    public function download(Request $request, ArchiveRecord $archive_record, ArchiveFile $archive_file)
    {
        return $this->serve($request, $archive_record, $archive_file, 'download-archive-files', 'DOWNLOAD', false);
    }

    private function serve(Request $request, ArchiveRecord $archive_record, ArchiveFile $archive_file, string $permission, string $action, bool $inline)
    {
        $user = $request->user();
        $record = ArchiveRecordController::accessible($archive_record, $user, $permission);
        // The file id came from the browser: it must belong to the record that was just authorised.
        abort_unless($archive_file->archive_record_id === $record->id, 404);
        if (ArchiveRecordController::restrictedBlocked($record)) {
            return ArchiveRecordController::lockedResponse($request, $record);
        }

        $disk = Storage::disk(ArchiveFileStore::DISK);
        if (! $disk->exists($archive_file->stored_path)) {
            Log::warning('Archive file missing on disk', ['file_id' => $archive_file->id, 'record' => $record->reference]);

            return $this->failure($request, 404, __('This file could not be found. Please contact the administrator.'));
        }

        ArchiveAccessLogger::log($user, $record, $action, $archive_file);
        if ($record->is_restricted) {
            ArchiveNotifier::event($action === 'DOWNLOAD' ? ArchiveNotificationRule::RESTRICTED_DOWNLOADED : ArchiveNotificationRule::RESTRICTED_OPENED, $user, $record, ['filename' => $archive_file->original_name]);
        }

        if ($inline && ! $archive_file->can_preview) {
            // Never push an unknown format through the browser: say so and offer the download instead.
            return response()->view('archive.preview-unavailable', [
                'file' => $archive_file,
                'record' => $record,
                'canDownload' => $user->can('download-archive-files'),
                'downloadUrl' => route('archive.records.files.download', [$record->id, $archive_file->id]),
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        $mime = $inline ? ArchiveFile::INLINE_MIME[strtolower($archive_file->extension)] : 'application/octet-stream';
        $response = response()->file($disk->path($archive_file->stored_path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
        $response->setContentDisposition(
            $inline ? 'inline' : 'attachment',
            $archive_file->original_name,
            Str::ascii($archive_file->original_name) ?: 'document.' . $archive_file->extension
        );

        return $response;
    }

    private function failure(Request $request, int $status, string $message)
    {
        if ($request->expectsJson() || $request->header('X-Archive-Viewer')) {
            return response()->json(['message' => $message], $status);
        }

        return response()->view('archive.preview-unavailable', ['file' => null, 'record' => null, 'canDownload' => false, 'downloadUrl' => null, 'message' => $message], $status);
    }
}
