<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * The styled QR code of the Print Barcodes page (gradient soft dots, rounded finder eyes, a centre badge) drawn as an inline SVG
 * for the printed Item Labels document. Mirrors resources/js/Shared/Item/QrMark.vue, error correction H so the badge never costs a scan.
 */
class QrArt
{
    private static int $n = 0;

    public static function svg(string $text): string
    {
        try {
            $ec = ErrorCorrectionLevel::H();
            // short codes (up to 14 characters) use version 2 straight away, so the centre badge always fits (the page draws them the same way)
            $matrix = strlen($text) <= 14
                ? Encoder::encode($text, $ec, Encoder::DEFAULT_BYTE_MODE_ENCODING, \BaconQrCode\Common\Version::getVersionForNumber(2))->getMatrix()
                : Encoder::encode($text, $ec)->getMatrix();
        } catch (\Throwable $e) {
            return '';
        }
        $n = $matrix->getWidth();
        $q = 2;
        $size = $n + $q * 2;
        $id = 'qa' . ++self::$n;
        $inEye = fn ($r, $c) => ($r < 7 && $c < 7) || ($r < 7 && $c >= $n - 7) || ($r >= $n - 7 && $c < 7);
        $badge = $n >= 25;
        $s = max(5, (int) round($n * 0.2));
        if (($n - $s) % 2) {
            $s++;
        }
        $from = ($n - $s) / 2;
        $inBadge = fn ($r, $c) => $badge && $r >= $from - 0.5 && $r < $from + $s + 0.5 && $c >= $from - 0.5 && $c < $from + $s + 0.5;

        $dots = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix->get($c, $r) !== 1 || $inEye($r, $c) || $inBadge($r, $c)) {
                    continue;
                }
                $cx = $q + $c + 0.5;
                $cy = $q + $r + 0.5;
                $dots .= 'M' . $cx . ' ' . $cy . 'h0'; // a zero-length line with round caps is a dot: a fraction of the size of an arc
            }
        }

        $eyes = '';
        foreach ([[$q, $q], [$q + $n - 7, $q], [$q, $q + $n - 7]] as [$x, $y]) {
            $eyes .= '<rect x="' . ($x + 0.5) . '" y="' . ($y + 0.5) . '" width="6" height="6" rx="2.1" fill="none" stroke="url(#' . $id . 'e)" stroke-width="1"/>'
                . '<rect x="' . ($x + 2) . '" y="' . ($y + 2) . '" width="3" height="3" rx="1.05" fill="url(#' . $id . 'e)"/>';
        }

        $mark = '';
        if ($badge) {
            $bx = $q + $from;
            $by = $q + $from;
            $mark = '<g transform="translate(' . $bx . ' ' . $by . ')"><rect x="-0.3" y="-0.3" width="' . ($s + 0.6) . '" height="' . ($s + 0.6) . '" rx="1.7" fill="#fff"/>'
                . '<rect x="0.35" y="0.35" width="' . ($s - 0.7) . '" height="' . ($s - 0.7) . '" rx="1.3" fill="url(#' . $id . 'e)"/>'
                . '<text x="' . ($s / 2) . '" y="' . ($s / 2 + 0.95) . '" text-anchor="middle" font-family="Arial, sans-serif" font-weight="900" font-size="' . round($s * 0.58, 2) . '" fill="#fff">W</text></g>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" style="width:100%;height:auto;display:block">'
            . '<defs><linearGradient id="' . $id . '" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="' . $size . '" y2="' . $size . '"><stop offset="0" stop-color="#1e1b4b"/><stop offset=".55" stop-color="#312e81"/><stop offset="1" stop-color="#0f766e"/></linearGradient>'
            . '<linearGradient id="' . $id . 'e" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="' . $size . '" y2="' . $size . '"><stop offset="0" stop-color="#4338ca"/><stop offset="1" stop-color="#0f172a"/></linearGradient></defs>'
            . '<rect width="' . $size . '" height="' . $size . '" rx="3.2" fill="#fff"/>' . $eyes
            . '<path d="' . $dots . '" fill="none" stroke="url(#' . $id . ')" stroke-width=".88" stroke-linecap="round"/>' . $mark . '</svg>';
    }
}
