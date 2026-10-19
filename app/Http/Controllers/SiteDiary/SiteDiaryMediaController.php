<?php

namespace App\Http\Controllers\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\SiteDiaryNotificationRule as Rule;
use App\Services\SiteDiary\SiteDiaryLogger;
use App\Services\SiteDiary\SiteDiaryNotifier;
use App\Services\SiteDiary\SiteDiaryStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Upload / remove / retry media of a diary, and stream it back. Every action re-checks: authenticated user, Site Diary
 * permission, access to the project, access to this diary, and that the file really belongs to this diary (so changing
 * a media id in the URL can never reach someone else's file).
 */
class SiteDiaryMediaController extends SiteDiaryBaseController
{
    private function mediaOf(SiteDiary $diary, int $mediaId): SiteDiaryMedia
    {
        $media = SiteDiaryMedia::withoutGlobalScope('account')->whereKey($mediaId)->where('site_diary_id', $diary->id)->first();
        abort_unless($media, 404);

        return $media;
    }

    public function store(Request $request, SiteDiary $diary, SiteDiaryStorage $storage)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        abort_unless($this->canAddMedia($diary, $user), 403);
        if ($this->rejectIfWarehouseCompleted($diary->warehouse_id)) {
            throw ValidationException::withMessages(['file' => $this->completedWarehouseMessage()]);
        }

        $data = $request->validate([
            'type' => 'required|in:photo,video',
            'file' => 'required|file',
            'client_uuid' => 'nullable|string|max:64|regex:/^[A-Za-z0-9\-_]+$/',
        ]);
        $can = $this->abilities($user, $diary);
        // permission AND the Global Feature / setting switch, enforced here whatever the browser sent
        abort_unless($data['type'] === 'photo' ? $can['addPhotos'] : $can['addVideos'], 403, $data['type'] === 'video' ? __('Videos are not enabled.') : __('Photos are not enabled.'));

        $media = $storage->add($diary->loadMissing('warehouse'), $request->file('file'), $data['type'], $data['client_uuid'] ?? null, $user);

        SiteDiaryLogger::log($user, $diary, $data['type'] === 'photo' ? 'PHOTO_ADD' : 'VIDEO_ADD', $media, ['size' => $media->file_size, 'storage' => $media->storage_provider]);
        if ($media->storage_provider === 'google_drive') {
            SiteDiaryLogger::log($user, $diary, 'DRIVE_UPLOAD', $media);
        } elseif ($media->upload_status === 'failed') {
            SiteDiaryLogger::log($user, $diary, 'DRIVE_UPLOAD_FAILED', $media, ['error' => $media->upload_error]);
            SiteDiaryNotifier::event(Rule::DRIVE_UPLOAD_FAILED, $user, $diary, ['filename' => $media->original_name, 'changes' => (string) $media->upload_error]);
        }

        return response()->json(['media' => $media->load('uploader:id,name')->toClient([
            'thumb_url' => $media->thumb_path ? route('site-diary.media.thumb', [$diary->id, $media->id]) : null,
            'view_url' => route('site-diary.media.view', [$diary->id, $media->id]),
            'open_url' => route('site-diary.media.open', [$diary->id, $media->id]),
            'download_url' => $can['download'] ? route('site-diary.media.download', [$diary->id, $media->id]) : null,
        ]), 'photos_count' => (int) $diary->fresh()->photos_count, 'videos_count' => (int) $diary->fresh()->videos_count]);
    }

    public function destroy(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        $m = $this->mediaOf($diary, $media);
        $can = $this->abilities($user, $diary);
        abort_unless($m->isPhoto() ? $can['deletePhotos'] : $can['deleteVideos'], 403);
        if ($this->rejectIfWarehouseCompleted($diary->warehouse_id)) {
            return response()->json(['message' => $this->completedWarehouseMessage()], 422);
        }

        if ($this->settings($user)->flag('require_media') && $diary->status !== 'Draft'
            && SiteDiaryMedia::where('site_diary_id', $diary->id)->where('id', '!=', $m->id)->doesntExist()) {
            return response()->json(['message' => __('A Site Diary must keep at least one photo or video. Add another before removing this one.')], 422);
        }

        // The file stays in Google Drive (recoverable); only WIMS stops showing it. The removal is logged.
        $m->forceFill(['deleted_by' => $user->id])->save();
        $m->delete();
        $storage->refreshCounters($diary);
        SiteDiaryLogger::log($user, $diary, $m->isPhoto() ? 'PHOTO_DELETE' : 'VIDEO_DELETE', $m);
        SiteDiaryNotifier::event(Rule::MEDIA_REMOVED, $user, $diary->loadMissing('warehouse'), ['filename' => $m->original_name]);

        // …and the file itself, when that is switched on (a failure never blocks the removal; the sync retries it)
        if ($this->settings($user)->flag('delete_files_on_remove')) {
            SiteDiaryLogger::log($user, $diary, $storage->purge($m) ? 'FILE_PURGED' : 'FILE_PURGE_FAILED', $m);
        }

        return response()->json(['success' => true, 'photos_count' => (int) $diary->fresh()->photos_count, 'videos_count' => (int) $diary->fresh()->videos_count]);
    }

    public function retry(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        abort_unless($this->canAddMedia($diary, $user), 403);
        $m = $this->mediaOf($diary, $media);
        abort_unless($m->upload_status === 'failed', 422);

        SiteDiaryLogger::log($user, $diary, 'DRIVE_RETRY', $m);
        $ok = $storage->push($m);
        $m->refresh();
        SiteDiaryLogger::log($user, $diary, $ok ? 'DRIVE_UPLOAD' : 'DRIVE_UPLOAD_FAILED', $m, $ok ? [] : ['error' => $m->upload_error]);
        if (! $ok) {
            SiteDiaryNotifier::event(Rule::DRIVE_UPLOAD_FAILED, $user, $diary->loadMissing('warehouse'), ['filename' => $m->original_name, 'changes' => (string) $m->upload_error]);
        }

        return response()->json(['media' => $m->load('uploader:id,name')->toClient(), 'ok' => $ok]);
    }

    // ---- reading ----------------------------------------------------------------------------------------------------

    private function viewable(Request $request, SiteDiary $diary, int $media): SiteDiaryMedia
    {
        $user = $request->user();
        $this->diaryFor($diary, $user);
        $m = $this->mediaOf($diary, $media);
        $can = $this->abilities($user, $diary);
        abort_unless($m->isPhoto() ? $can['viewPhotos'] : $can['viewVideos'], 403);

        return $m;
    }

    /** Small preview used by the gallery grid (not individually logged — the grid would write one row per picture). */
    public function thumb(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $m = $this->viewable($request, $diary, $media);
        abort_unless($m->isPhoto(), 404);

        return $storage->thumbResponse($m) ?? $storage->stream($m, $request, 'inline');
    }

    public function view(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $m = $this->viewable($request, $diary, $media);
        $this->logRead($request, $diary, $m, $m->isPhoto() ? 'PHOTO_VIEW' : 'VIDEO_VIEW');

        return $storage->stream($m, $request, 'inline');
    }

    public function open(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $m = $this->viewable($request, $diary, $media);
        $this->logRead($request, $diary, $m, $m->isPhoto() ? 'PHOTO_OPEN_NEW_TAB' : 'VIDEO_OPEN_NEW_TAB');

        return $storage->stream($m, $request, 'inline');
    }

    public function download(Request $request, SiteDiary $diary, int $media, SiteDiaryStorage $storage)
    {
        $user = $request->user();
        $m = $this->viewable($request, $diary, $media);
        abort_unless($this->abilities($user, $diary)['download'], 403, __('Downloading is not allowed.'));
        $this->logRead($request, $diary, $m, $m->isPhoto() ? 'PHOTO_DOWNLOAD' : 'VIDEO_DOWNLOAD', true);

        return $storage->stream($m, $request, 'attachment');
    }

    /** A video player asks for the file in many byte ranges — only the first request is one "view". */
    private function logRead(Request $request, SiteDiary $diary, SiteDiaryMedia $m, string $action, bool $always = false): void
    {
        $range = (string) $request->header('Range');
        if (! $always && $range !== '' && ! preg_match('/^bytes=0-/', $range)) {
            return;
        }
        SiteDiaryLogger::log($request->user(), $diary->loadMissing('warehouse'), $action, $m);
    }
}
