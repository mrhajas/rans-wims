<?php

namespace App\Models;

/**
 * A photo or video of a Site Diary. Holds only metadata and the Google Drive ids; the bytes are fetched from Drive
 * (or from the private local disk while still waiting to be synced) only when someone views or downloads them.
 */
class SiteDiaryMedia extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    // Everything that happens to a diary is written explicitly by SiteDiaryLogger (with IP / device), so the generic
    // attribute auditing is switched off to keep the System Logs readable.
    protected static function eventsToBeRecorded(): \Illuminate\Support\Collection
    {
        return collect();
    }

    protected $table = 'site_diary_media';

    protected $hidden = ['drive_folder_id', 'drive_file_id', 'local_path', 'thumb_path', 'checksum'];

    public $casts = ['uploaded_at' => 'datetime', 'expired_at' => 'datetime'];

    public function diary()
    {
        return $this->belongsTo(SiteDiary::class, 'site_diary_id')->withTrashed();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function isPhoto(): bool
    {
        return $this->media_type === 'photo';
    }

    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }

    /** What the front-end is allowed to know about a file: never a Drive id, a path or a Drive URL. */
    public function toClient(array $can = []): array
    {
        return [
            'id' => $this->id,
            'type' => $this->media_type,
            'name' => $this->original_name,
            'mime' => $this->mime_type,
            'size' => (int) $this->file_size,
            'status' => $this->upload_status,
            'error' => $this->upload_status === self::STATUS_FAILED ? $this->upload_error : null,
            'provider' => $this->storage_provider,
            'has_thumb' => (bool) $this->thumb_path,
            'expired' => (bool) $this->expired_at,
            'uploaded_at' => optional($this->uploaded_at ?? $this->created_at)->toIso8601String(),
            'uploaded_by' => optional($this->uploader)->name,
            'width' => $this->width,
            'height' => $this->height,
        ] + $can;
    }
}
