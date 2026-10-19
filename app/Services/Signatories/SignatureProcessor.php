<?php

namespace App\Services\Signatories;

/**
 * Turns a photo, scan or drawing of a handwritten signature into a clean transparent PNG in one standard ink colour.
 *
 * It only SEPARATES the handwriting from the paper: it never redraws, smooths into new shapes, repairs or adds strokes. The output alpha
 * comes from how much darker each pixel is than the paper around it, so the stroke structure is the writer's own. When the image cannot be
 * separated reliably (too faint, too noisy, no handwriting found, ink touching the picture edge) it refuses with the reasons and asks for a
 * new image instead of producing a misleading result.
 *
 * Pure PHP GD (no Imagick), so it runs on ordinary shared hosting.
 *
 * Steps: decode (size and type checked) > fix orientation > work on a reduced copy > estimate the paper brightness around every pixel
 * (removes shadows and uneven light) > darkness relative to the paper > automatic threshold > drop edge shadows and stray specks >
 * crop to the writing + padding > soft alpha, ink recoloured > scale to the output width > PNG.
 */
class SignatureProcessor
{
    public const DEFAULTS = [
        'ink_color' => '#1b3fa0',      // the standard ink (blue)
        'work_max_px' => 1000,         // longest side used for analysis
        'output_width_px' => 900,      // widest the final PNG is made (never enlarged)
        'padding_pct' => 6,            // transparent border around the writing, % of the writing size
        'min_ink_px' => 120,           // the writing must be at least this wide/tall in the working copy
        'min_contrast' => 0.16,        // average darkness of the ink relative to the paper (0..1)
        'min_ink_coverage_pct' => 0.15,
        'max_ink_coverage_pct' => 30,  // more than this inside the crop = a dark photo, not a signature
        'max_input_px' => 6000,        // longest side accepted
        'min_input_px' => 160,
        'speck_pct' => 0.02,           // a piece smaller than this % of the writing area is noise unless it sits next to the writing
        'sensitivity' => 1.0,          // 0.6 (keeps faint ink) .. 1.6 (stricter)
    ];

    /** @return array{ok:bool, png:?string, width:int, height:int, reasons:list<string>, metrics:array} */
    public function process(string $binary, array $opts = []): array
    {
        $o = array_replace(self::DEFAULTS, array_intersect_key($opts, self::DEFAULTS));
        $fail = fn (array $why, array $m = []) => ['ok' => false, 'png' => null, 'width' => 0, 'height' => 0, 'reasons' => $why, 'metrics' => $m];

        $info = @getimagesizefromstring($binary);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return $fail(['The file is not a JPEG, PNG or WebP picture.']);
        }
        [$w0, $h0] = $info;
        if (max($w0, $h0) > $o['max_input_px'] || min($w0, $h0) < $o['min_input_px']) {
            return $fail(['The picture is ' . $w0 . ' x ' . $h0 . ' pixels. It must be between ' . $o['min_input_px'] . ' and ' . $o['max_input_px'] . ' pixels on its longest side.']);
        }
        if ($w0 * $h0 > 36_000_000) {
            return $fail(['The picture has too many pixels to process safely.']);
        }
        // decoding needs about 5 bytes a pixel; refuse politely instead of running the server out of memory
        $limit = $this->memoryLimit();
        if ($limit > 0 && ($w0 * $h0 * 5 + 40 * 1024 * 1024) > ($limit - memory_get_usage(true))) {
            return $fail(['The picture (' . $w0 . ' x ' . $h0 . ' pixels) is too large for this server to process. Use a smaller picture, about ' . max(1, (int) floor((($limit - memory_get_usage(true) - 40 * 1024 * 1024) / 5) / 1000000)) . ' megapixels or less, or take it again at a lower camera resolution.']);
        }
        $src = @imagecreatefromstring($binary);
        if (! $src) {
            return $fail(['The picture could not be read.']);
        }
        $src = $this->orient($src, $binary, $info[2]);
        $hasAlpha = $info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_WEBP;

        // reduced working copy
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min(1.0, $o['work_max_px'] / max($sw, $sh));
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        $work = imagecreatetruecolor($w, $h);
        imagealphablending($work, true);
        imagefill($work, 0, 0, imagecolorallocate($work, 255, 255, 255)); // transparent areas become paper
        imagecopyresampled($work, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        $alphaMask = $hasAlpha ? $this->alphaOnly($src, $w, $h, $sw, $sh) : null;
        imagedestroy($src);

        // 1. luminance, 2. paper brightness around each pixel, 3. darkness relative to it
        $lum = [];
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($work, $x, $y);
                $row[] = (0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255)) / 255;
            }
            $lum[] = $row;
        }
        imagedestroy($work);
        $dark = $alphaMask ? $alphaMask : $this->darkness($lum, $w, $h);
        unset($lum);

        // 4. automatic threshold (Otsu on the darkness histogram, kept inside sane limits)
        $t = $alphaMask ? 0.35 : $this->threshold($dark, $w, $h) * $o['sensitivity'];
        $t = max(0.07, min(0.45, $t));

        // 5. ink mask, then drop what is not handwriting
        $edge = $alphaMask ? 0 : (int) round(max($w, $h) * 0.012); // a drawn pad has no scanner border
        $mask = [];
        $inkCount = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $on = $dark[$y][$x] >= $t && $x >= $edge && $y >= $edge && $x < $w - $edge && $y < $h - $edge;
                $mask[$y][$x] = $on;
                $inkCount += $on ? 1 : 0;
            }
        }
        if ($inkCount < 30) {
            return $fail(['No handwriting could be found. Use dark ink on plain light paper, fill more of the frame and retake the picture.'], ['threshold' => round($t, 3)]);
        }
        [$keep, $bbox, $comps] = $this->components($mask, $w, $h, $o + ['keep_lines' => $alphaMask !== null]);
        if (! $bbox) {
            return $fail(['No handwriting could be separated from the background. Retake the picture on plain light paper with even light.'], ['threshold' => round($t, 3)]);
        }
        [$x0, $y0, $x1, $y1] = $bbox;
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;
        if ($bw < $o['min_ink_px'] && $bh < $o['min_ink_px']) {
            return $fail(['The writing is too small in the picture (' . $bw . ' x ' . $bh . ' pixels). Move closer or write larger and retake it.'], ['bbox' => [$bw, $bh]]);
        }
        // touching the picture edge means the signature was cut off
        $margin = max(2, (int) round(max($w, $h) * 0.02));
        if (! $alphaMask && ($x0 <= $margin || $y0 <= $margin || $x1 >= $w - 1 - $margin || $y1 >= $h - 1 - $margin)) {
            return $fail(['The writing reaches the edge of the picture, so part of it may be cut off. Leave a clear border around the signature and retake it.'], ['bbox' => [$x0, $y0, $x1, $y1]]);
        }

        // 6. quality checks on the kept writing
        $sum = 0.0;
        $n = 0;
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                if ($keep[$y][$x] ?? false) {
                    $sum += $dark[$y][$x];
                    $n++;
                }
            }
        }
        $contrast = $n ? $sum / $n : 0;
        $coverage = $n / max(1, $bw * $bh) * 100;
        $metrics = ['threshold' => round($t, 3), 'contrast' => round($contrast, 3), 'coverage_pct' => round($coverage, 2), 'ink_px' => $n, 'pieces' => $comps, 'crop' => [$bw, $bh]];
        $why = [];
        if ($contrast < $o['min_contrast']) {
            $why[] = 'The ink is too faint against the paper (contrast ' . round($contrast, 2) . '). Use a darker pen or better light.';
        }
        if ($coverage < $o['min_ink_coverage_pct']) {
            $why[] = 'Very little writing was found. The strokes may be too thin or faint to reproduce faithfully.';
        }
        if ($coverage > $o['max_ink_coverage_pct']) {
            $why[] = 'Too much of the picture is dark, so the signature cannot be told apart from the background. Use plain light paper without shadows.';
        }
        if ($why) {
            return $fail($why, $metrics);
        }

        // 7. crop with padding, soft alpha, ink recolour
        $pad = (int) round(max($bw, $bh) * $o['padding_pct'] / 100);
        $cw = $bw + 2 * $pad;
        $ch = $bh + 2 * $pad;
        [$r, $g, $b] = $this->hex($o['ink_color']);
        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        $lo = $t * 0.55;
        $hi = $t * 1.6;
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                if (! ($keep[$y][$x] ?? false)) {
                    continue;
                }
                $a = ($dark[$y][$x] - $lo) / max(0.0001, $hi - $lo);
                $a = $a <= 0 ? 0 : ($a >= 1 ? 1 : $a * $a * (3 - 2 * $a)); // smoothstep keeps the anti-aliased edge of the stroke
                imagesetpixel($out, $x - $x0 + $pad, $y - $y0 + $pad, imagecolorallocatealpha($out, $r, $g, $b, (int) round(127 - 127 * $a)));
            }
        }

        // 8. scale to the output width (never enlarged)
        if ($cw > $o['output_width_px']) {
            $nh = (int) round($ch * $o['output_width_px'] / $cw);
            $scaled = imagecreatetruecolor($o['output_width_px'], $nh);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $out, 0, 0, 0, 0, $o['output_width_px'], $nh, $cw, $ch);
            imagedestroy($out);
            $out = $scaled;
            $cw = $o['output_width_px'];
            $ch = $nh;
        }
        ob_start();
        imagepng($out, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return ['ok' => true, 'png' => $png, 'width' => $cw, 'height' => $ch, 'reasons' => [], 'metrics' => $metrics];
    }

    /** A small preview of an existing PNG on white or grey, for showing the transparency: returns PNG bytes. */
    public function thumbnail(string $png, int $maxWidth = 240): ?string
    {
        $im = @imagecreatefromstring($png);
        if (! $im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $nw = min($maxWidth, $w);
        $nh = (int) round($h * $nw / $w);
        $t = imagecreatetruecolor($nw, $nh);
        imagealphablending($t, false);
        imagesavealpha($t, true);
        imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127));
        imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($t, null, 9);

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------------------------------------------------------ steps

    private function memoryLimit(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        $u = strtolower(substr($v, -1));

        return match ($u) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }

    private function orient($im, string $binary, int $type)
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $im;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($binary));
        $o = (int) ($exif['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) {
            $r = imagerotate($im, $rot, 0);
            if ($r) {
                imagedestroy($im);

                return $r;
            }
        }

        return $im;
    }

    /** For PNG/WebP input that already has transparency: the alpha channel IS the ink amount (a drawn signature). */
    private function alphaOnly($src, int $w, int $h, int $sw, int $sh): ?array
    {
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        $mask = [];
        $transparent = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = (127 - ((imagecolorat($tmp, $x, $y) >> 24) & 127)) / 127;
                $mask[$y][$x] = $a;
                $transparent += $a < 0.05 ? 1 : 0;
            }
        }
        imagedestroy($tmp);

        return $transparent / ($w * $h) > 0.2 ? $mask : null; // mostly transparent = a cut-out signature; otherwise treat as a photo
    }

    /** Darkness (0..1) of every pixel relative to the paper brightness estimated around it. */
    private function darkness(array $lum, int $w, int $h): array
    {
        // a small copy: take the brightest value in a window (the paper, not the ink), then blur, then read it back for each pixel
        $cell = max(4, (int) round(max($w, $h) / 90));
        $gw = (int) ceil($w / $cell);
        $gh = (int) ceil($h / $cell);
        $grid = [];
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                $vals = [];
                for ($y = $gy * $cell; $y < min($h, ($gy + 1) * $cell); $y += 2) {
                    for ($x = $gx * $cell; $x < min($w, ($gx + 1) * $cell); $x += 2) {
                        $vals[] = $lum[$y][$x];
                    }
                }
                sort($vals);
                $grid[$gy][$gx] = $vals[(int) floor(count($vals) * 0.85)] ?? 1; // upper quartile ignores ink dots
            }
        }
        $grid = $this->maxFilter($grid, $gw, $gh, 3);
        $grid = $this->boxBlur($grid, $gw, $gh, 3);
        $dark = [];
        for ($y = 0; $y < $h; $y++) {
            $fy = max(0, min($gh - 1.001, $y / $cell - 0.5));
            $gy = (int) floor($fy);
            $ty = $fy - $gy;
            for ($x = 0; $x < $w; $x++) {
                $fx = max(0, min($gw - 1.001, $x / $cell - 0.5));
                $gx = (int) floor($fx);
                $tx = $fx - $gx;
                $paper = ($grid[$gy][$gx] * (1 - $tx) + $grid[$gy][$gx + 1] * $tx) * (1 - $ty) + ($grid[$gy + 1][$gx] * (1 - $tx) + $grid[$gy + 1][$gx + 1] * $tx) * $ty;
                $paper = max(0.25, $paper);
                $d = ($paper - $lum[$y][$x]) / $paper;
                $dark[$y][$x] = $d < 0 ? 0 : ($d > 1 ? 1 : $d);
            }
        }

        return $dark;
    }

    private function maxFilter(array $g, int $w, int $h, int $r): array
    {
        $o = $g;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = 0;
                for ($yy = max(0, $y - $r); $yy <= min($h - 1, $y + $r); $yy++) {
                    for ($xx = max(0, $x - $r); $xx <= min($w - 1, $x + $r); $xx++) {
                        $m = max($m, $g[$yy][$xx]);
                    }
                }
                $o[$y][$x] = $m;
            }
        }

        return $o;
    }

    private function boxBlur(array $g, int $w, int $h, int $r): array
    {
        $o = $g;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $s = 0;
                $n = 0;
                for ($yy = max(0, $y - $r); $yy <= min($h - 1, $y + $r); $yy++) {
                    for ($xx = max(0, $x - $r); $xx <= min($w - 1, $x + $r); $xx++) {
                        $s += $g[$yy][$xx];
                        $n++;
                    }
                }
                $o[$y][$x] = $s / $n;
            }
        }

        return $o;
    }

    private function threshold(array $dark, int $w, int $h): float
    {
        $bins = array_fill(0, 64, 0);
        $total = 0;
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $d = $dark[$y][$x];
                if ($d > 0.03) { // paper noise is left out so it does not drag the threshold down
                    $bins[min(63, (int) ($d * 64))]++;
                    $total++;
                }
            }
        }
        if ($total < 20) {
            return 0.2;
        }
        $sumAll = 0;
        foreach ($bins as $i => $c) {
            $sumAll += $i * $c;
        }
        $wB = 0;
        $sumB = 0;
        $best = 0;
        $bestVar = -1;
        for ($i = 0; $i < 64; $i++) {
            $wB += $bins[$i];
            if ($wB === 0) {
                continue;
            }
            $wF = $total - $wB;
            if ($wF === 0) {
                break;
            }
            $sumB += $i * $bins[$i];
            $mB = $sumB / $wB;
            $mF = ($sumAll - $sumB) / $wF;
            $var = $wB * $wF * ($mB - $mF) ** 2;
            if ($var > $bestVar) {
                $bestVar = $var;
                $best = $i;
            }
        }

        return (($best + 1) / 64) * 0.9; // a little below the split so thin strokes keep their edges
    }

    /** Connected pieces of ink. Keeps the writing and the small marks next to it (i-dots, crossings) and drops stray specks and edge shadows. */
    private function components(array $mask, int $w, int $h, array $o): array
    {
        $label = [];
        $comps = [];
        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (! $mask[$y][$x] || isset($label[$y][$x])) {
                    continue;
                }
                $id = count($comps);
                $stack = [[$x, $y]];
                $label[$y][$x] = $id;
                $area = 0;
                $bx0 = $bx1 = $x;
                $by0 = $by1 = $y;
                while ($stack) {
                    [$cx, $cy] = array_pop($stack);
                    $area++;
                    $bx0 = min($bx0, $cx);
                    $bx1 = max($bx1, $cx);
                    $by0 = min($by0, $cy);
                    $by1 = max($by1, $cy);
                    foreach ($dirs as [$dx, $dy]) {
                        $nx = $cx + $dx;
                        $ny = $cy + $dy;
                        if ($nx >= 0 && $ny >= 0 && $nx < $w && $ny < $h && $mask[$ny][$nx] && ! isset($label[$ny][$nx])) {
                            $label[$ny][$nx] = $id;
                            $stack[] = [$nx, $ny];
                        }
                    }
                }
                $comps[$id] = ['area' => $area, 'box' => [$bx0, $by0, $bx1, $by1]];
            }
        }
        if (! $comps) {
            return [[], null, 0];
        }
        // very long thin straight pieces (a table edge, a ruled line) are not handwriting
        $bigArea = max(array_column($comps, 'area'));
        $totalArea = array_sum(array_column($comps, 'area'));
        $body = null;
        foreach ($comps as $id => $c) {
            $bw = $c['box'][2] - $c['box'][0] + 1;
            $bh = $c['box'][3] - $c['box'][1] + 1;
            // a table edge, ruled line or page frame: long and thin, or running from one side of the picture to the opposite side
            $m = (int) round(max($w, $h) * 0.012) + 4;
            $across = ($c['box'][0] <= $m && $c['box'][2] >= $w - 1 - $m) || ($c['box'][1] <= $m && $c['box'][3] >= $h - 1 - $m);
            // a drawn signature (alpha channel) has no table or page edge in it: a long stroke or an underline IS the signature
            $line = empty($o['keep_lines']) && ((($bw > $w * 0.5 || $bh > $h * 0.5) && ($bw / max(1, $bh) > 10 || $bh / max(1, $bw) > 10)) || $across);
            $comps[$id]['line'] = $line;
            if (! $line && $c['area'] >= max(0.04 * $bigArea, 12)) {
                $body = $body ? [min($body[0], $c['box'][0]), min($body[1], $c['box'][1]), max($body[2], $c['box'][2]), max($body[3], $c['box'][3])] : $c['box'];
            }
        }
        if (! $body) {
            return [[], null, 0];
        }
        $near = max(8, (int) round(($body[2] - $body[0]) * 0.08));
        $keepIds = [];
        $minSpeck = max(6, (int) round($totalArea * $o['speck_pct'] / 100));
        foreach ($comps as $id => $c) {
            if ($c['line']) {
                continue;
            }
            // a drawn signature is kept whole, wherever on the pad each stroke sits (a flourish, a dot, a second word)
            $inside = ! empty($o['keep_lines']) || ($c['box'][0] >= $body[0] - $near && $c['box'][1] >= $body[1] - $near && $c['box'][2] <= $body[2] + $near && $c['box'][3] <= $body[3] + $near);
            if (($c['area'] >= $minSpeck && $inside) || ($inside && $c['area'] >= 4)) {
                $keepIds[$id] = true;
            }
        }
        if (! $keepIds) {
            return [[], null, 0];
        }
        $keep = [];
        $x0 = $w;
        $y0 = $h;
        $x1 = $y1 = 0;
        foreach ($label as $y => $row) {
            foreach ($row as $x => $id) {
                if (isset($keepIds[$id])) {
                    // include a one pixel halo so the anti-aliased edge of each stroke survives
                    for ($yy = $y - 1; $yy <= $y + 1; $yy++) {
                        for ($xx = $x - 1; $xx <= $x + 1; $xx++) {
                            if ($xx >= 0 && $yy >= 0 && $xx < $w && $yy < $h) {
                                $keep[$yy][$xx] = true;
                            }
                        }
                    }
                    $x0 = min($x0, $x);
                    $y0 = min($y0, $y);
                    $x1 = max($x1, $x);
                    $y1 = max($y1, $y);
                }
            }
        }

        return [$keep, [$x0, $y0, $x1, $y1], count($keepIds)];
    }

    private function hex(string $c): array
    {
        $c = ltrim($c, '#');
        if (strlen($c) === 3) {
            $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $c)) {
            $c = '1b3fa0';
        }

        return [hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2))];
    }
}
