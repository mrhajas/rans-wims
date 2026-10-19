<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads a user-supplied image URL and compresses it down to a small,
 * web-friendly size (matches the GD resize pattern already used for user
 * avatars in App\Models\User::resizedProfilePhotoContents()) so it can be
 * attached as an item's photo. Follows the same synchronous Http::timeout()
 * + try/catch + Log::warning convention as WeatherService/AiAssistantService
 * — no queue, since QUEUE_CONNECTION=sync app-wide.
 */
class ItemPhotoFetcher
{
    /**
     * Returns null on any failure (unreachable URL, not a real image, etc.)
     * rather than throwing, so the caller can show one consistent "fetch
     * failed" response regardless of why it failed.
     */
    public function downloadAndResize(string $url): ?array
    {
        try {
            // Several sites (Wikipedia/Wikimedia among them) reject
            // requests with no User-Agent or Laravel's default Guzzle one —
            // a plain browser-style UA avoids that without pretending to be
            // anything more specific than "a browser fetching this image".
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; WIMS-ItemPhotoFetcher/1.0)',
            ])->timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contents = $response->body();
            $info = @getimagesizefromstring($contents);

            if (! $info) {
                return null;
            }

            $source = @imagecreatefromstring($contents);

            if (! $source) {
                return null;
            }

            // WIMS shows item photos as fixed 64x64 squares with
            // object-fit:cover everywhere (Item/Index.vue, Item/Details.vue)
            // — a non-square source would get silently center-cropped there,
            // potentially cutting off real content. Padded onto a white
            // square canvas here instead (never cropped), so every fetched
            // photo already matches that square convention and the full
            // image stays visible everywhere it's shown, not just in the
            // uncropped full-size preview.
            $canvasSize = 600;
            $width = imagesx($source);
            $height = imagesy($source);
            $ratio = min($canvasSize / $width, $canvasSize / $height, 1);
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));

            $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled(
                $canvas,
                $source,
                (int) round(($canvasSize - $newWidth) / 2),
                (int) round(($canvasSize - $newHeight) / 2),
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height
            );

            ob_start();
            imagejpeg($canvas, null, 82);
            $output = ob_get_clean();

            return ['contents' => $output, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
        } catch (\Throwable $e) {
            Log::warning('ItemPhotoFetcher: download exception', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
