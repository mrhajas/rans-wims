<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Scannable QR codes for printed documents (Payment Vouchers, Staff Records,
 * Supplier Vouchers) — encodes the record's own show-page URL so scanning it
 * jumps straight to the live record.
 *
 * Rasterized to a PNG data URI via GD rather than the SVG renderer that
 * ships with bacon/bacon-qr-code (already vendored, no extra package): this
 * DomPDF installation doesn't render inline <svg> at all (confirmed with a
 * plain <rect> test — came out blank), while a base64 <img> is DomPDF's
 * best-supported embedding path.
 */
class PrintQrCode
{
    public static function dataUri(string $data, int $moduleSize = 6, int $quietZoneModules = 3): string
    {
        // a record's own address (…/transfers/12) is swapped for its scan-to-open code (…/q/<token>): no database id is printed
        $data = \App\Services\Qr\QrCodeService::forPrint($data);

        return 'data:image/png;base64,' . base64_encode(self::png($data, $moduleSize, $quietZoneModules));
    }

    /** Raw PNG bytes of a code for exactly this text (no address swap). */
    public static function png(string $data, int $moduleSize = 6, int $quietZoneModules = 3, string $color = '#111827', string $level = 'Q'): string
    {
        $ec = ['L' => ErrorCorrectionLevel::L(), 'M' => ErrorCorrectionLevel::M(), 'H' => ErrorCorrectionLevel::H()][$level] ?? ErrorCorrectionLevel::Q();
        $matrix = Encoder::encode($data, $ec)->getMatrix();
        $modules = $matrix->getWidth();
        $pixels = ($modules + $quietZoneModules * 2) * $moduleSize;

        $image = imagecreatetruecolor($pixels, $pixels);
        $white = imagecolorallocate($image, 255, 255, 255);
        [$r, $g, $b] = sscanf($color, '#%02x%02x%02x') ?: [17, 24, 39];
        $black = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 0, $pixels, $pixels, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = ($x + $quietZoneModules) * $moduleSize;
                    $py = ($y + $quietZoneModules) * $moduleSize;
                    imagefilledrectangle($image, $px, $py, $px + $moduleSize - 1, $py + $moduleSize - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
