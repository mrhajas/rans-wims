<?php

namespace App\Services\WimsChat;

use App\Models\User;
use App\Models\WimsChatAttachment;
use App\Models\WimsChatMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WIMSChat attachments: policy checks (type, real MIME, size, daily role quota) that always run on the server, private
 * storage on the local disk, thumbnails, and streaming back through an authorised route. Nothing is ever a public URL.
 */
class WimsChatFiles
{
    public const DISK = 'local';

    public function disk()
    {
        return Storage::disk(self::DISK);
    }

    // ---- quota -----------------------------------------------------------------------------------------------

    public function usedToday(User $user): int
    {
        return (int) WimsChatAttachment::withTrashed()->withoutGlobalScope('account')
            ->where('user_id', $user->id)->where('is_copy', false)
            ->where('created_at', '>=', now()->startOfDay())->sum('size_bytes');
    }

    /** ['limit', 'used', 'remaining'] in bytes for today. */
    public function quota(User $user, WimsChatSettings $cfg): array
    {
        $limit = $cfg->dailyQuotaBytes($user);
        $used = $this->usedToday($user);

        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }

    // ---- checks ----------------------------------------------------------------------------------------------

    /**
     * Validates every file BEFORE anything is stored. Returns descriptors; throws a 422 with a human message on the
     * first problem so the person knows exactly what to fix.
     *
     * @param  UploadedFile[]  $files
     */
    public function inspect(array $files, User $user, WimsChatSettings $cfg, bool $voice = false, ?int $durationMs = null): array
    {
        $files = array_values(array_filter($files));
        if (! $files) {
            return [];
        }
        $max = (int) $cfg->get('max_files_per_message');
        if ($max > 0 && count($files) > $max) {
            $this->reject(__('You can attach up to :n files to one message.', ['n' => $max]));
        }

        $policies = $cfg->policies();
        $total = 0;
        $out = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $this->reject(__('One of the files could not be uploaded. Please try again.'), $file instanceof UploadedFile ? $file->getClientOriginalName() : null);
            }
            $name = $file->getClientOriginalName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = (int) $file->getSize();

            // a hidden second extension ("report.php.pdf") is refused as well
            foreach (explode('.', strtolower($name)) as $part) {
                if (in_array($part, $cfg->blockedExtensions(), true)) {
                    $this->reject(__('“:name” is not an allowed file type.', ['name' => $name]), $name);
                }
            }
            $category = $voice ? 'voice' : $cfg->categoryFor($ext);
            if ($category === 'voice' && ! $voice) {
                $this->reject(__('Voice notes are recorded in WIMSChat with the microphone button.'), $name);
            }
            if ($voice) {
                $vp = $policies['voice'] ?? null;
                $maxSec = (int) $cfg->get('voice_max_seconds');
                if (! $cfg->voiceOn() || ! $vp || empty($vp['enabled']) || count($files) > 1) {
                    $this->reject(__('Voice notes are switched off.'));
                }
                if (! in_array($ext, $vp['extensions'], true)) {
                    $this->reject(__('That voice recording format is not supported.'), $name);
                }
                if ($durationMs === null || $durationMs < 300 || $durationMs > ($maxSec + 1) * 1000 || $size > $cfg->voiceMaxBytes()) {
                    $this->reject(__('Voice notes can be up to :n seconds long.', ['n' => $maxSec]), $name);
                }
            }
            if (! $category) {
                $this->reject(__('“:name” is not an allowed file type.', ['name' => $name]), $name);
            }
            $policy = $policies[$category];
            if ($size > $policy['max_mb_effective'] * 1048576) {
                $this->reject(__('This file exceeds the configured :n MB maximum.', ['n' => $policy['max_mb_effective']]), $name);
            }
            // trust the content, not the extension
            $mime = strtolower((string) $file->getMimeType());
            if (! in_array($mime, $policy['mimes'], true)) {
                $this->reject(__('“:name” does not look like a real :type file.', ['name' => $name, 'type' => strtoupper($ext)]), $name);
            }
            if ($category === 'images' && ! @getimagesize($file->getRealPath())) {
                $this->reject(__('“:name” is not a valid image.', ['name' => $name]), $name);
            }
            $total += $size;
            $out[] = ['file' => $file, 'name' => $name, 'ext' => $ext, 'category' => $category, 'size' => $size, 'mime' => $mime, 'duration_ms' => $voice ? (int) $durationMs : null];
        }

        $q = $this->quota($user, $cfg);
        if ($total > $q['remaining']) {
            $this->reject($q['remaining'] <= 0
                ? __('Your daily attachment quota has been reached.')
                : __('Daily WIMSChat file quota exceeded. You have :left remaining today.', ['left' => self::human($q['remaining'])]));
        }

        return $out;
    }

    private function reject(string $message, ?string $filename = null): void
    {
        if ($filename !== null) {
            WimsChatLogger::log(auth()->user(), 'ATTACHMENT_REJECTED', null, ['filename' => $filename, 'reason' => $message]);
        } else {
            WimsChatLogger::log(auth()->user(), 'ATTACHMENT_REJECTED', null, ['filename' => '', 'reason' => $message]);
        }
        throw ValidationException::withMessages(['files' => $message]);
    }

    // ---- storing ---------------------------------------------------------------------------------------------

    public function store(array $d, WimsChatMessage $message, User $user, WimsChatSettings $cfg): WimsChatAttachment
    {
        /** @var UploadedFile $file */
        $file = $d['file'];
        $stored = 'wimschat/'.now()->format('Y/m').'/'.Str::uuid().($d['ext'] ? '.'.$d['ext'] : '');
        $this->disk()->putFileAs(dirname($stored), $file, basename($stored));
        $abs = $this->disk()->path($stored);

        $w = $h = $thumb = null;
        if ($d['category'] === 'images') {
            [$w, $h] = $this->imageInfo($abs);
            if ($cfg->flag('image_compress')) {
                $this->downscale($abs, $d['mime'], (int) $cfg->get('image_max_px'), $w, $h);
                [$w, $h] = $this->imageInfo($abs);
            }
            $thumb = $this->thumbnail($abs, $stored, $w, $h);
        }

        return WimsChatAttachment::create([
            'account_id' => $user->account_id,
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'user_id' => $user->id,
            'original_name' => $this->cleanName($d['name']),
            'stored_path' => $stored,
            'extension' => $d['ext'],
            'mime_type' => $d['mime'],
            'category' => $d['category'],
            'size_bytes' => (int) @filesize($abs) ?: $d['size'],
            'checksum' => hash_file('sha256', $abs),
            'width' => $w,
            'height' => $h,
            'thumb_path' => $thumb,
            'duration_ms' => $d['duration_ms'] ?? null,
        ]);
    }

    /** A forwarded attachment points at the same file: no copy on disk, no quota used. */
    public function copyRecord(WimsChatAttachment $a, WimsChatMessage $message, User $user): WimsChatAttachment
    {
        return WimsChatAttachment::create([
            'account_id' => $user->account_id, 'message_id' => $message->id, 'conversation_id' => $message->conversation_id,
            'user_id' => $user->id, 'original_name' => $a->original_name, 'stored_path' => $a->stored_path, 'extension' => $a->extension,
            'mime_type' => $a->mime_type, 'category' => $a->category, 'size_bytes' => $a->size_bytes, 'checksum' => $a->checksum,
            'width' => $a->width, 'height' => $a->height, 'thumb_path' => $a->thumb_path, 'duration_ms' => $a->duration_ms, 'is_copy' => true,
        ]);
    }

    public function cleanName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\s._()\-]+/u', '_', basename(str_replace('\\', '/', $name)));
        $name = ltrim(trim((string) $name), '.');

        return mb_substr($name === '' ? 'file' : $name, 0, 150);
    }

    private function imageInfo(string $abs): array
    {
        $i = @getimagesize($abs);

        return $i ? [(int) $i[0], (int) $i[1]] : [null, null];
    }

    private function load(string $abs)
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $data = @file_get_contents($abs);

        return $data ? @imagecreatefromstring($data) : null;
    }

    private function downscale(string $abs, string $mime, int $maxPx, ?int $w, ?int $h): void
    {
        if (! $w || ! $h || $maxPx < 320 || max($w, $h) <= $maxPx || $w * $h > 50_000_000 || $mime === 'image/gif') {
            return;
        }
        $img = $this->load($abs);
        if (! $img) {
            return;
        }
        $r = $maxPx / max($w, $h);
        $dst = imagecreatetruecolor((int) round($w * $r), (int) round($h * $r));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        match ($mime) {
            'image/png' => imagepng($dst, $abs, 7),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $abs, 82) : null,
            default => imagejpeg($dst, $abs, 82),
        };
    }

    private function thumbnail(string $abs, string $stored, ?int $w, ?int $h): ?string
    {
        if (! $w || ! $h || $w * $h > 50_000_000) {
            return null;
        }
        $img = $this->load($abs);
        if (! $img) {
            return null;
        }
        $r = min(1, 480 / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $r)), max(1, (int) round($h * $r)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        $rel = 'wimschat/thumbs/'.pathinfo($stored, PATHINFO_FILENAME).'.jpg';
        $this->disk()->makeDirectory('wimschat/thumbs');
        imagejpeg($dst, $this->disk()->path($rel), 78);

        return $rel;
    }

    // ---- serving ---------------------------------------------------------------------------------------------

    public function response(WimsChatAttachment $a, string $disposition, bool $thumb = false)
    {
        $path = $thumb && $a->thumb_path ? $a->thumb_path : $a->stored_path;
        abort_unless($path && $this->disk()->exists($path), 404, __('This file is no longer available.'));

        // only images and PDFs are ever rendered inline; everything else is forced to a download
        $inlineSafe = $thumb || in_array($a->category, ['images', 'pdf', 'voice'], true);
        // a voice note is played in an <audio> element: the type comes from the extension, never from what the uploader claimed
        $voiceTypes = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav'];
        $mime = $thumb ? 'image/jpeg' : ($a->category === 'voice' ? ($voiceTypes[$a->extension] ?? 'application/octet-stream') : ($inlineSafe ? $a->mime_type : 'application/octet-stream'));
        $name = $thumb ? pathinfo($a->original_name, PATHINFO_FILENAME).'.jpg' : $a->original_name;

        return response()->file($this->disk()->path($path), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ])->setContentDisposition($inlineSafe ? $disposition : 'attachment', $name, Str::ascii($name));
    }

    /**
     * Retention rule: erases the files of attachments older than $days (0 = never). A file shared with a newer forwarded
     * copy is kept until that copy is old enough too. Records stay so the conversation still shows what was there.
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        $cutoff = now()->subDays($days);
        $count = 0;
        $paths = WimsChatAttachment::withTrashed()->withoutGlobalScope('account')->whereNull('purged_at')->where('created_at', '<', $cutoff)
            ->orderBy('id')->limit(500)->pluck('stored_path')->unique();
        foreach ($paths as $path) {
            $newer = WimsChatAttachment::withTrashed()->withoutGlobalScope('account')->where('stored_path', $path)->where('created_at', '>=', $cutoff)->exists();
            if ($newer) {
                continue;
            }
            $thumbs = WimsChatAttachment::withTrashed()->withoutGlobalScope('account')->where('stored_path', $path)->pluck('thumb_path')->filter()->unique();
            $this->disk()->delete($path);
            foreach ($thumbs as $t) {
                $this->disk()->delete($t);
            }
            $count += WimsChatAttachment::withTrashed()->withoutGlobalScope('account')->where('stored_path', $path)
                ->update(['purged_at' => now(), 'thumb_path' => null]);
        }

        return $count;
    }
}
