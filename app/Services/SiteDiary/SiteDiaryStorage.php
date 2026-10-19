<?php

namespace App\Services\SiteDiary;

use App\Models\SiteDiary;
use App\Models\SiteDiaryMedia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accepts, validates, thumbnails and stores Site Diary media; pushes it to Google Drive; and serves it back only
 * through authorised WIMS routes. Files are staged on the private local disk, uploaded to Drive, and the local copy is
 * then removed (only a small thumbnail stays), so the cPanel disk does not fill up. If Drive is switched off or
 * unreachable the file simply stays on the private disk and is moved later by `site-diary:sync-drive`.
 */
class SiteDiaryStorage
{
    /** extension => accepted real MIME types (checked against the file's content, not the browser's claim). */
    private const MIMES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'mp4' => ['video/mp4', 'application/mp4', 'video/x-m4v'], 'mov' => ['video/quicktime', 'video/mp4'],
        'webm' => ['video/webm'],
    ];

    private const CLAIMED_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
    ];

    public function __construct(private SiteDiarySettings $settings, private GoogleDriveClient $drive)
    {
    }

    private function disk()
    {
        return Storage::disk('local');
    }

    // ---- adding ---------------------------------------------------------------------------------------------------

    public function add(SiteDiary $diary, UploadedFile $file, string $type, ?string $clientUuid, User $user): SiteDiaryMedia
    {
        $settings = $this->settings->forAccount($diary->account_id);
        $label = $type === 'photo' ? __('Photos') : __('Videos');

        if (! $settings->enabled($type)) {
            throw ValidationException::withMessages(['file' => __(':media are not enabled for Site Diary.', ['media' => $label])]);
        }
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('The file did not upload completely. Please try again.')]);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $settings->types($type), true)) {
            throw ValidationException::withMessages(['file' => __('This file type is not allowed. Allowed: :types.', ['types' => strtoupper(implode(', ', $settings->types($type)))])]);
        }
        $maxMb = $settings->maxMb($type);
        if ($file->getSize() > $maxMb * 1048576) {
            throw ValidationException::withMessages(['file' => __('The file is larger than :mb MB.', ['mb' => $maxMb])]);
        }
        $this->assertContent($file, $ext, $type);

        if ($clientUuid) {
            $existing = SiteDiaryMedia::where('site_diary_id', $diary->id)->where('client_uuid', $clientUuid)->first();
            if ($existing) { // a retried request: hand back the same row instead of creating a duplicate
                if (in_array($existing->upload_status, ['pending', 'failed'], true)) {
                    $this->push($existing);
                }

                return $existing->fresh();
            }
        }

        $max = $settings->maxCount($type);
        $media = DB::transaction(function () use ($diary, $type, $max, $label, $ext, $file, $clientUuid, $user) {
            SiteDiary::whereKey($diary->id)->lockForUpdate()->first(); // serialise concurrent uploads so the limit cannot be beaten
            $count = SiteDiaryMedia::where('site_diary_id', $diary->id)->where('media_type', $type)->count();
            if ($count >= $max) {
                throw ValidationException::withMessages(['file' => __('Maximum of :max :media is allowed for this Site Diary.', ['max' => $max, 'media' => Str::lower($label)])]);
            }

            return SiteDiaryMedia::create([
                'site_diary_id' => $diary->id,
                'account_id' => $diary->account_id,
                'media_type' => $type,
                'original_name' => $this->safeName($file->getClientOriginalName(), $ext),
                'mime_type' => self::CLAIMED_MIME[$ext],
                'extension' => $ext,
                'file_size' => $file->getSize(),
                'upload_status' => SiteDiaryMedia::STATUS_PENDING,
                'client_uuid' => $clientUuid,
                'uploaded_by' => $user->id,
                'storage_provider' => 'local',
            ]);
        });

        try {
            $relative = "site-diary/staging/{$diary->id}/".Str::uuid().'.'.$ext;
            $this->disk()->putFileAs(dirname($relative), $file, basename($relative));
            $absolute = $this->disk()->path($relative);
            $media->forceFill(['local_path' => $relative, 'checksum' => hash_file('sha256', $absolute)])->save();
            if ($type === 'photo') {
                $this->makeThumbnail($media, $absolute, $settings->flag('thumbnails'));
            }
        } catch (\Throwable $e) {
            $media->forceDelete();
            throw ValidationException::withMessages(['file' => __('The file could not be saved. Please try again.')]);
        }

        $this->refreshCounters($diary);

        if ($this->drive->ready()) {
            $this->push($media);
        } else {
            $media->forceFill(['upload_status' => SiteDiaryMedia::STATUS_UPLOADED, 'uploaded_at' => now()])->save();
        }

        return $media->fresh();
    }

    private function assertContent(UploadedFile $file, string $ext, string $type): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $real = (string) $finfo->file($file->getRealPath());
        $accepted = self::MIMES[$ext] ?? [];
        // some builds report webm / mov as a generic container; trust the extension only for those two video types
        $lenient = $type === 'video' && in_array($real, ['application/octet-stream', 'video/x-matroska'], true) && in_array($ext, ['webm', 'mov'], true);
        if (! in_array($real, $accepted, true) && ! $lenient) {
            throw ValidationException::withMessages(['file' => __('The file content does not match its type (:type).', ['type' => strtoupper($ext)])]);
        }
        if ($type === 'photo' && @getimagesize($file->getRealPath()) === false) {
            throw ValidationException::withMessages(['file' => __('This image appears to be damaged.')]);
        }
    }

    private function safeName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N}\-_. ()]+/u', '_', $base), " ._");
        $base = mb_substr($base === '' ? 'file' : $base, 0, 100);

        return $base.'.'.$ext;
    }

    /** Small preview kept locally so galleries open instantly without touching Drive. The original is never altered. */
    private function makeThumbnail(SiteDiaryMedia $media, string $absolute, bool $enabled): void
    {
        $info = @getimagesize($absolute);
        if (! $info) {
            return;
        }
        [$w, $h] = $info;
        $media->forceFill(['width' => $w, 'height' => $h])->save();
        if (! $enabled || ! function_exists('imagecreatetruecolor')) {
            $media->forceFill(['compression_status' => 'skipped'])->save();

            return;
        }
        // decoding needs roughly 5 bytes per pixel — skip rather than risk exhausting memory on a huge image
        $limit = $this->memoryLimitBytes();
        if ($limit > 0 && ($w * $h * 5 + memory_get_usage()) > $limit * 0.8) {
            $media->forceFill(['compression_status' => 'skipped'])->save();

            return;
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            default => false,
        };
        if (! $src) {
            $media->forceFill(['compression_status' => 'skipped'])->save();

            return;
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolute);
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 0] ?? 0;
            if ($rot) {
                $src = imagerotate($src, $rot, 0) ?: $src;
                if (abs($rot) === 90) {
                    [$w, $h] = [$h, $w];
                    $media->forceFill(['width' => $w, 'height' => $h])->save();
                }
            }
        }
        $scale = min(1, 480 / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
        $relative = "site-diary/thumbs/{$media->site_diary_id}/".Str::uuid().'.jpg';
        $this->disk()->makeDirectory(dirname($relative));
        imagejpeg($dst, $this->disk()->path($relative), 78);
        imagedestroy($src);
        imagedestroy($dst);
        $media->forceFill([
            'thumb_path' => $relative,
            'optimized_size' => $this->disk()->size($relative),
            'compression_status' => 'thumbnail',
        ])->save();
    }

    private function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (float) $v;

        return (int) match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    // ---- Google Drive ---------------------------------------------------------------------------------------------

    /** Moves one media file to Drive. Safe to call repeatedly: it claims the row first, so two workers never upload twice. */
    public function push(SiteDiaryMedia $media): bool
    {
        $previous = SiteDiaryMedia::whereKey($media->id)->value('upload_status');
        $claimed = SiteDiaryMedia::whereKey($media->id)
            ->where(fn ($q) => $q->whereIn('upload_status', ['pending', 'failed'])
                ->orWhere(fn ($q) => $q->where('upload_status', 'uploaded')->where('storage_provider', 'local')))
            ->update(['upload_status' => 'uploading', 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return false;
        }
        $media->refresh();

        try {
            if (! $this->drive->ready()) {
                throw new \RuntimeException('Google Drive is not connected.');
            }
            if (! $media->local_path || ! $this->disk()->exists($media->local_path)) {
                throw new \RuntimeException('The staged file is missing on the server.');
            }
            @set_time_limit(0);
            $diary = SiteDiary::with('warehouse')->withTrashed()->find($media->site_diary_id);
            $folder = $this->drive->ensureFolder($this->folderSegments($diary, $media->media_type));
            $name = $diary->reference.'_'.$media->id.'_'.$media->original_name;
            $fileId = $this->drive->upload($this->disk()->path($media->local_path), $name, $media->mime_type, $folder);

            $this->disk()->delete($media->local_path);
            $media->forceFill([
                'storage_provider' => 'google_drive', 'drive_folder_id' => $folder, 'drive_file_id' => $fileId,
                'local_path' => null, 'upload_status' => 'uploaded', 'upload_error' => null, 'uploaded_at' => now(),
            ])->save();

            return true;
        } catch (\Throwable $e) {
            report($e);
            $media->forceFill([
                // a file that was already usable from the private disk stays usable; a brand-new upload is marked failed (retryable)
                'upload_status' => $previous === 'uploaded' ? 'uploaded' : 'failed',
                'upload_error' => mb_substr($e->getMessage(), 0, 490),
            ])->save();

            return false;
        }
    }

    /**
     * Deletes the file itself after it was removed from a diary: Google Drive Trash, the staged copy on the server and the
     * preview. Returns false (and changes nothing) when Drive cannot be reached right now — the scheduled sync retries.
     */
    public function purge(SiteDiaryMedia $media): bool
    {
        if ($media->purged_at) {
            return true;
        }
        if ($media->drive_file_id) {
            if (! $this->drive->configured()) {
                return false;
            }
            try {
                $this->drive->trash($media->drive_file_id);
            } catch (\Throwable $e) {
                report($e);
                $media->forceFill(['upload_error' => mb_substr($e->getMessage(), 0, 490)])->saveQuietly();

                return false;
            }
        }
        foreach ([$media->local_path, $media->thumb_path] as $path) {
            if ($path) {
                $this->disk()->delete($path);
            }
        }
        $media->forceFill(['local_path' => null, 'thumb_path' => null, 'purged_at' => now()])->saveQuietly();

        return true;
    }

    /** Erases the file from Google Drive for good but keeps the record (and the small local preview). */
    public function expire(SiteDiaryMedia $media): bool
    {
        if ($media->expired_at) {
            return true;
        }
        if ($media->drive_file_id) {
            if (! $this->drive->configured()) {
                return false;
            }
            try {
                $this->drive->delete($media->drive_file_id);
            } catch (\Throwable $e) {
                report($e);

                return false;
            }
        }
        $media->forceFill(['drive_file_id' => null, 'drive_folder_id' => null, 'expired_at' => now()])->saveQuietly();

        return true;
    }

    public function folderSegments(SiteDiary $diary, string $type): array
    {
        $w = $diary->warehouse;

        return [
            'Site Diaries',
            trim(($w->name ?? 'Project').($w && $w->code ? ' ('.$w->code.')' : '')),
            $diary->diary_date->format('Y'), $diary->diary_date->format('m'), $diary->diary_date->format('d'),
            $type === 'photo' ? 'Photos' : 'Videos',
        ];
    }

    public function refreshCounters(SiteDiary $diary): void
    {
        $rows = SiteDiaryMedia::where('site_diary_id', $diary->id)->selectRaw('media_type, COUNT(*) c, COALESCE(SUM(file_size),0) b')->groupBy('media_type')->get()->keyBy('media_type');
        SiteDiary::whereKey($diary->id)->update([
            'photos_count' => (int) ($rows['photo']->c ?? 0),
            'videos_count' => (int) ($rows['video']->c ?? 0),
            'media_bytes' => (int) (($rows['photo']->b ?? 0) + ($rows['video']->b ?? 0)),
            'last_media_at' => now(),
        ]);
    }

    // ---- serving --------------------------------------------------------------------------------------------------

    public function thumbResponse(SiteDiaryMedia $media)
    {
        if ($media->thumb_path && $this->disk()->exists($media->thumb_path)) {
            return response()->file($this->disk()->path($media->thumb_path), [
                'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return null;
    }

    /** Streams the real file (from the private disk or Drive) — never redirects to a Drive URL. */
    public function stream(SiteDiaryMedia $media, Request $request, string $disposition)
    {
        $headers = ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=0'];

        if ($media->local_path && $this->disk()->exists($media->local_path)) {
            return response()->file($this->disk()->path($media->local_path), $headers)->setContentDisposition($disposition, $media->original_name, Str::ascii($media->original_name));
        }
        if ($media->expired_at) {
            abort(410, __('This file was erased from Google Drive when its retention period ended.'));
        }
        if (! $media->drive_file_id) {
            abort(404, __('This file is not available.'));
        }
        // files already in Google Drive stay readable even if WIMS Cloud is now the storage location for new uploads
        if (! $this->drive->configured()) {
            abort(503, __('Google Drive Connection Problem, the file cannot be opened right now.'));
        }

        try {
            $upstream = $this->drive->open($media->drive_file_id, $request->header('Range'));
        } catch (\Throwable $e) {
            report($e);
            abort(503, __('Google Drive Connection Problem, the file cannot be opened right now.'));
        }
        $code = $upstream->getStatusCode();
        if ($code >= 400 && $code !== 416) {
            abort($code === 404 ? 404 : 503, $code === 404 ? __('The file is no longer in Google Drive.') : __('Google Drive Connection Problem, the file cannot be opened right now.'));
        }

        foreach (['Content-Length', 'Content-Range', 'Accept-Ranges'] as $h) {
            if ($upstream->hasHeader($h)) {
                $headers[$h] = $upstream->getHeaderLine($h);
            }
        }
        $headers['Content-Disposition'] = $disposition.'; filename="'.Str::ascii($media->original_name).'"; filename*=UTF-8\'\''.rawurlencode($media->original_name);
        $body = $upstream->getBody();

        return new StreamedResponse(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(262144);
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            }
        }, $code, $headers);
    }
}
