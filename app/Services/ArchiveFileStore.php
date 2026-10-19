<?php

namespace App\Services;

use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores Archive attachments on the PRIVATE local disk (storage/app/archive/…) — never under public/ — under a random
 * name, and records the size/type metadata the dashboard aggregates later. The browser only ever sees the
 * authorised WIMS routes, never a storage path.
 */
class ArchiveFileStore
{
    public const DISK = 'local';

    public const MAX_KB = 51200; // 50 MB per file

    public static function allowedExtensions(): array
    {
        return array_keys(ArchiveFile::EXTENSIONS);
    }

    /** Real content types the "inline" formats must have — a .pdf/.png that is really something else is refused. */
    private const EXPECTED_MIME = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'],
        'webp' => ['image/webp'], 'bmp' => ['image/bmp', 'image/x-ms-bmp'], 'tif' => ['image/tiff'], 'tiff' => ['image/tiff'],
    ];

    /** Throws a validation error (before anything is saved) when a file's content does not match its extension. */
    public static function assertContent(array $uploads, string $field = 'files'): void
    {
        $errors = [];
        foreach ($uploads as $i => $upload) {
            if (! $upload instanceof UploadedFile) {
                continue;
            }
            $ext = strtolower($upload->getClientOriginalExtension());
            $expected = self::EXPECTED_MIME[$ext] ?? null;
            if ($expected && ! in_array((string) $upload->getMimeType(), $expected, true)) {
                $errors["{$field}.{$i}"] = __('":name" is not a real :ext file.', ['name' => $upload->getClientOriginalName(), 'ext' => strtoupper($ext)]);
            }
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    public static function store(UploadedFile $upload, ArchiveRecord $record, ?User $user): ArchiveFile
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $original = self::cleanName($upload->getClientOriginalName());
        $mime = (string) ($upload->getMimeType() ?: 'application/octet-stream');
        $size = (int) $upload->getSize();
        $checksum = hash_file('sha256', $upload->getRealPath());

        $path = Storage::disk(self::DISK)->putFileAs(
            'archive/' . now()->format('Y') . '/' . $record->id,
            $upload,
            Str::uuid()->toString() . '.' . $extension
        );

        return $record->files()->create([
            'original_name' => $original,
            'stored_path' => $path,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_type' => ArchiveFile::typeFor($extension),
            'size_bytes' => $size,
            'processing_status' => 'not_applicable',
            'checksum' => $checksum,
            'uploaded_by_user_id' => optional($user)->id,
        ]);
    }

    private static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', $name);

        return mb_substr(trim($name) !== '' ? trim($name) : 'document', 0, 240);
    }
}
