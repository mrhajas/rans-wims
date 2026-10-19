<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArchiveFile extends Model
{
    use SoftDeletes;

    public const TYPE_PDF = 'PDF';
    public const TYPE_IMAGE = 'Image';
    public const TYPE_OFFICE = 'Office';
    public const TYPE_ARCHIVE = 'Archive';
    public const TYPE_TEXT = 'Text';
    public const TYPE_OTHER = 'Other';

    public const TYPES = [self::TYPE_PDF, self::TYPE_IMAGE, self::TYPE_OFFICE, self::TYPE_ARCHIVE, self::TYPE_TEXT, self::TYPE_OTHER];

    /** Extension => file group. Anything not listed is refused at upload. */
    public const EXTENSIONS = [
        'pdf' => self::TYPE_PDF,
        'jpg' => self::TYPE_IMAGE, 'jpeg' => self::TYPE_IMAGE, 'png' => self::TYPE_IMAGE, 'gif' => self::TYPE_IMAGE,
        'webp' => self::TYPE_IMAGE, 'bmp' => self::TYPE_IMAGE, 'tif' => self::TYPE_IMAGE, 'tiff' => self::TYPE_IMAGE,
        'doc' => self::TYPE_OFFICE, 'docx' => self::TYPE_OFFICE, 'xls' => self::TYPE_OFFICE, 'xlsx' => self::TYPE_OFFICE,
        'ppt' => self::TYPE_OFFICE, 'pptx' => self::TYPE_OFFICE, 'odt' => self::TYPE_OFFICE, 'ods' => self::TYPE_OFFICE, 'rtf' => self::TYPE_OFFICE,
        'zip' => self::TYPE_ARCHIVE, 'rar' => self::TYPE_ARCHIVE, '7z' => self::TYPE_ARCHIVE,
        'txt' => self::TYPE_TEXT, 'csv' => self::TYPE_TEXT,
        'dwg' => self::TYPE_OTHER, 'dxf' => self::TYPE_OTHER,
    ];

    /** Only these are ever rendered inline by the browser; everything else is forced to download. */
    public const INLINE_MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    ];

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'optimized_size' => 'integer',
        'thumbnail_size' => 'integer',
    ];

    public function record()
    {
        return $this->belongsTo(ArchiveRecord::class, 'archive_record_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public static function typeFor(string $extension): string
    {
        return self::EXTENSIONS[strtolower($extension)] ?? self::TYPE_OTHER;
    }

    public function getCanPreviewAttribute(): bool
    {
        return isset(self::INLINE_MIME[strtolower((string) $this->extension)]);
    }

    /** Bytes this file really occupies: the optimised copy when there is one (never both), plus its thumbnail. */
    public function getStoredBytesAttribute(): int
    {
        return (int) ($this->optimized_size ?? $this->size_bytes) + (int) $this->thumbnail_size;
    }
}
