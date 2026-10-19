<?php

namespace App\Services\Signatories;

use App\Models\SignatureVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Where signature pictures live: the PRIVATE local disk (storage/app/signatures), never a public folder. Files are only ever read through an authorised controller or the report resolver. */
class SignatureStore
{
    private function disk()
    {
        return Storage::disk('local');
    }

    // ---- confirmed versions
    public function saveVersion(int $account, int $signatoryId, int $versionNo, string $png, ?string $thumb, ?string $original): array
    {
        $base = "signatures/{$account}/{$signatoryId}/v{$versionNo}";
        $this->disk()->put($base . '.png', $png);
        if ($thumb) {
            $this->disk()->put($base . '_t.png', $thumb);
        }
        $originalPath = null;
        if ($original !== null) {
            $originalPath = "signatures/originals/{$account}/{$signatoryId}/v{$versionNo}.bin";
            $this->disk()->put($originalPath, $original);
        }

        return ['processed' => $base . '.png', 'thumb' => $thumb ? $base . '_t.png' : null, 'original' => $originalPath];
    }

    public function png(SignatureVersion $v): ?string
    {
        return $this->disk()->exists($v->processed_path) ? $this->disk()->get($v->processed_path) : null;
    }

    public function thumb(SignatureVersion $v): ?string
    {
        $path = $v->thumb_path ?: $v->processed_path;

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function original(SignatureVersion $v): ?string
    {
        return $v->original_path && ! $v->original_purged_at && $this->disk()->exists($v->original_path) ? $this->disk()->get($v->original_path) : null;
    }

    public function deleteOriginal(SignatureVersion $v): void
    {
        if ($v->original_path && $this->disk()->exists($v->original_path)) {
            $this->disk()->delete($v->original_path);
        }
        $v->forceFill(['original_purged_at' => now()])->save();
    }

    /** A processed PNG as a data URI for a printed report (DomPDF and the print view both embed it; nothing is linked). */
    public function dataUri(SignatureVersion $v): ?string
    {
        $png = $this->png($v);

        return $png ? 'data:image/png;base64,' . base64_encode($png) : null;
    }

    // ---- the picture waiting for a person to look at it and confirm (the original is kept here so a failed or unhappy attempt can be retried)
    public function stash(int $actorId, string $binary, array $meta): string
    {
        $token = Str::random(40);
        $this->disk()->put("signatures/tmp/{$actorId}/{$token}.bin", $binary);
        $this->disk()->put("signatures/tmp/{$actorId}/{$token}.json", json_encode($meta + ['created_at' => now()->toIso8601String()]));

        return $token;
    }

    /** @return array{0:string,1:array}|null */
    public function stashed(int $actorId, string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }
        $bin = "signatures/tmp/{$actorId}/{$token}.bin";
        $meta = "signatures/tmp/{$actorId}/{$token}.json";
        if (! $this->disk()->exists($bin) || ! $this->disk()->exists($meta)) {
            return null;
        }

        return [$this->disk()->get($bin), json_decode($this->disk()->get($meta), true) ?: []];
    }

    public function forget(int $actorId, string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $this->disk()->delete(["signatures/tmp/{$actorId}/{$token}.bin", "signatures/tmp/{$actorId}/{$token}.json"]);
        }
    }

    /** Pictures nobody confirmed are removed after $hours. Returns how many files were deleted. */
    public function purgeStash(int $hours = 2): int
    {
        $n = 0;
        foreach ($this->disk()->allFiles('signatures/tmp') as $file) {
            if ($this->disk()->lastModified($file) < now()->subHours($hours)->timestamp) {
                $this->disk()->delete($file);
                $n++;
            }
        }

        return $n;
    }
}
