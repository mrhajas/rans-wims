<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use Carbon\CarbonInterface;

/**
 * Draws the front and the shared back of a RANS card as HTML: every box is placed in millimetres on a 55 x 85 mm card (the size in the artwork brief), so the very same
 * markup is shown in the browser and turned into the PDF by DomPDF (text stays text, shapes stay shapes, the logo and photo are the only pictures at print resolution).
 * Sizes of names are decided here with the real font widths, so what is previewed is what is printed.
 *
 * $mode: 'pdf' puts file paths in the pictures, 'web' puts the pictures into the markup itself (data addresses) for the preview.
 */
class CardRenderer
{
    public const W = 55.0;

    public const H = 85.0;

    public const NAVY = '#1e4467';

    /** What a card shows, normalised: from an issued card or (with $draft) from the form being filled in. */
    public static function data(IdPassCard $c): array
    {
        $cat = $c->category;

        return self::build([
            'kind' => $c->kind, 'number' => $c->number, 'name' => $c->full_name, 'title' => $c->title, 'photo' => CardAssets::photo($c), 'qr' => CardAssets::qr($c),
            'issued' => $c->issued_at, 'expires' => $c->expires_at, 'host' => $c->host_name, 'vehicle' => $c->vehicle_registration, 'vehicle_description' => $c->vehicle_description,
            'authorisation' => $c->authorisation_type, 'purpose' => $c->purpose, 'status' => $c->effective_status,
        ], $cat);
    }

    /** The same shape from typed values (the preview in the issue form: nothing is saved yet). */
    public static function draft(array $in, IdPassCategory $cat, string $number, ?string $photoPath, ?string $qrText = null): array
    {
        return self::build([
            'kind' => $cat->kind, 'number' => $number, 'name' => (string) ($in['full_name'] ?? ''), 'title' => $in['title'] ?? null, 'photo' => $photoPath,
            'qr' => CardAssets::qrFor($qrText ?: url('/id-verify/preview')), 'issued' => $in['issued'] ?? now(), 'expires' => $in['expires'] ?? null, 'host' => $in['host_name'] ?? null,
            'vehicle' => $in['vehicle_registration'] ?? null, 'vehicle_description' => $in['vehicle_description'] ?? null, 'authorisation' => $in['authorisation_type'] ?? null,
            'purpose' => $in['purpose'] ?? null, 'status' => IdPassCard::ACTIVE,
        ], $cat);
    }

    private static function build(array $d, IdPassCategory $cat): array
    {
        $d['cat'] = ['name' => $cat->name, 'label' => $cat->label ?: $cat->name, 'band' => CardAssets::hex($cat->band_color, '#1565c0'), 'arrow' => CardAssets::hex($cat->arrow_color, '#fff200'), 'icon' => $cat->icon];
        $d['pooled'] = (bool) $cat->pooled;
        $d['template'] = match ($cat->kind) {
            'staff' => 'staff', 'compound' => 'worker', default => 'pass',
        };

        return $d;
    }

    // ---------------------------------------------------------------------------------------------------------------------------- the pieces

    private static function src(?string $path, string $mode): string
    {
        if (! $path || ! is_file($path)) {
            return '';
        }
        if ($mode === 'pdf') {
            return $path;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private static function css(array $s): string
    {
        $o = '';
        foreach ($s as $k => $v) {
            $o .= $k . ':' . (is_float($v) || is_int($v) ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') . (in_array($k, ['font-size']) ? 'pt' : (in_array($k, ['opacity', 'z-index']) ? '' : 'mm')) : $v) . ';';
        }

        return $o;
    }

    private static function box(float $x, float $y, float $w, float $h, array $style = [], string $inner = ''): string
    {
        return '<div class="ip-b" style="' . self::css(['left' => $x, 'top' => $y, 'width' => $w, 'height' => $h] + $style) . '">' . $inner . '</div>';
    }

    private static function img(string $src, float $x, float $y, float $w, float $h, array $style = []): string
    {
        return $src === '' ? '' : '<img class="ip-i" src="' . $src . '" style="' . self::css(['left' => $x, 'top' => $y, 'width' => $w, 'height' => $h] + $style) . '">';
    }

    /** DomPDF puts the baseline of a line at a fixed share of its line height that depends on the font (measured: 0.913 for Gebuk, 0.862 for Pill Gothic), so a text is moved to sit centred in its box. */
    private const BASE = ['g' => 0.913, 'gb' => 0.913, 's' => 0.862, 'sb' => 0.862, 'sbb' => 0.862];

    /** A browser centres the font's own ascent and descent in the line; the share of the em above and below the baseline per font (from the font files). */
    private const ASC = ['g' => 0.812, 'gb' => 0.812, 's' => 0.74, 'sb' => 0.74, 'sbb' => 0.74];

    private const DESC = ['g' => 0.226, 'gb' => 0.226, 's' => 0.24, 'sb' => 0.24, 'sbb' => 0.24];

    private static string $mode = 'web';

    private const CAP = ['g' => 0.625, 'gb' => 0.625, 's' => 0.668, 'sb' => 0.668, 'sbb' => 0.668];

    /** A line of text centred (by its capital letters) in a box of the given height. */
    private static function text(string $text, float $x, float $y, float $w, float $h, string $font, float $pt, string $color, string $align = 'left'): string
    {
        $cls = 'ip-t ip-' . $font . ($align === 'center' ? ' ip-c' : ($align === 'right' ? ' ip-r' : ''));
        $s = $pt * 25.4 / 72;
        $cap = (self::CAP[$font] ?? 0.65);
        $shift = self::$mode === 'pdf' ? $h / 2 + $cap * $s / 2 - (self::BASE[$font] ?? 1.0) * $h : ($cap / 2 - ((self::ASC[$font] ?? 0.8) - (self::DESC[$font] ?? 0.25)) / 2) * $s;

        return '<div class="' . $cls . '" style="' . self::css(['left' => $x, 'top' => $y + $shift, 'width' => $w, 'height' => $h, 'line-height' => $h . 'mm', 'font-size' => $pt, 'color' => $color]) . '">' . e($text) . '</div>';
    }

    private static function fontKey(string $cls): string
    {
        return ['g' => 'gluten', 'gb' => 'gluten-b', 's' => 'saira', 'sb' => 'saira-b', 'sbb' => 'saira-bb'][$cls] ?? 'saira';
    }

    private static function ordinal(CarbonInterface $d): string
    {
        return $d->locale('en')->translatedFormat('jS M Y');
    }

    // ---------------------------------------------------------------------------------------------------------------------------- the front

    public static function front(array $d, string $mode = 'web'): string
    {
        self::$mode = $mode;
        $cat = $d['cat'];
        $band = $cat['band'];
        $h = '<div class="ip-card">';
        // the off-white panel with the soft wave, and the photograph frame
        $h .= self::box(2.5, 3.7, 50.3, 44.0, ['background' => '#f0efeb', 'border-top-right-radius' => 5.3, 'overflow' => 'hidden'],
            self::img(self::src(CardAssets::art('wave.jpg'), $mode), -2.5, -3.7, 55, 85));

        if ($d['template'] === 'pass') {
            $h .= self::img(self::src(CardAssets::logo(), $mode), 12.2, 5.6, 30.6, 11.4);
            $label = (string) $cat['label'];
            $one = NameFitter::fitLine($label, 'gluten-b', 44.0, 25, 14);
            if (! $one['cut'] && FontMeasure::mm($label, 'gluten-b', 25) <= 44.0) {
                $h .= self::text($label, 3.0, 19.0, 49.0, 15.5, 'gb', 25, '#111111', 'center');
            } else {
                $words = explode(' ', $label, 2);
                $a = NameFitter::fitLine($words[0], 'gluten-b', 46.0, 21, 12);
                $b = NameFitter::fitLine($words[1] ?? '', 'gluten-b', 46.0, 21, 12);
                $h .= self::text($a['text'], 3.0, 17.2, 49.0, 10.0, 'gb', $a['pt'], '#111111', 'center');
                $h .= self::text($b['text'], 3.0, 26.4, 49.0, 10.0, 'gb', $b['pt'], '#111111', 'center');
            }
        } else {
            // the photograph, cropped to the 31 x 38.75 mm frame by the photo tool; without one the frame shows a silhouette
            $photo = self::src($d['photo'], $mode);
            $frame = $photo !== '' ? self::img($photo, 0, 0, 31.0, 38.75) : self::img(self::src(CardAssets::tinted('icons/user.png', '#ffffff', 300), $mode), 7.0, 7.5, 17.0, 17.0, ['opacity' => 0.9]);
            $h .= self::box(19.4, 6.1, 31.0, 38.75, ['background' => '#afb3b6', 'border-radius' => 2.9, 'overflow' => 'hidden'], $frame);
            if ($d['template'] === 'staff') {
                $h .= self::img(self::src(CardAssets::logoUp(), $mode), 5.7, 7.0, 10.4, 27.9);
            } elseif (! empty($cat['icon']) && is_file(CardAssets::art('icons/' . $cat['icon'] . '.png'))) {
                $h .= self::img(self::src(CardAssets::tinted('icons/' . $cat['icon'] . '.png', $band, 360), $mode), 5.5, 6.5, 11.2, 11.2);
            }
        }

        // the colour band: the name and the title, or the number on a pass
        $h .= self::box(0, 38.0, 40.5, 12.6, ['background' => $band, 'border-top-right-radius' => 3.4]);
        if ($d['template'] === 'pass') {
            $n = NameFitter::fitLine((string) $d['number'], 'gluten', 36.0, 33, 18);
            $h .= self::text($n['text'], 3.2, 38.4, 36.5, 11.8, 'g', $n['pt'], '#ffffff');
        } else {
            $name = NameFitter::fitName((string) $d['name'], 'gluten', 37.0, 12.5, 8.0);
            $h .= self::text($name['text'], 2.6, 38.7, 37.5, 6.0, 'g', $name['pt'], '#fff200');
            if (trim((string) $d['title']) !== '') {
                $t = NameFitter::fitLine((string) $d['title'], 'gluten', 37.0, 10.5, 7.0);
                $h .= self::text($t['text'], 2.6, 44.6, 37.5, 4.8, 'g', $t['pt'], '#ffffff');
            }
        }

        // the QR code on its cream panel, the number of a staff or worker ID, the arrow
        $h .= self::box(2.5, 50.2, 29.1, 31.6, ['background' => '#fffbf3', 'border-top-right-radius' => 7.0, 'border-bottom-left-radius' => 5.4],
            self::img(self::src($d['qr'], $mode), 2.1, 3.6, 24.9, 24.9));
        if ($d['template'] !== 'pass') {
            $n = NameFitter::fitLine((string) $d['number'], 'gluten', 20.5, 22, 11);
            $h .= self::text($n['text'], 32.4, 51.6, 20.5, 8.0, 'g', $n['pt'], '#ffffff', 'right');
        } else {
            $h .= self::passDetails($d, $mode);
        }
        $h .= self::img(self::src(CardAssets::tinted('arrow.png', $cat['arrow'], 420), $mode), 39.5, 68.4, 13.3, 13.3);

        return $h . '</div>';
    }

    /** Beside the QR code of a pass: who holds it, and for a visitor who is visited, for a gate pass the vehicle, and until when it is valid. */
    private static function passDetails(array $d, string $mode): string
    {
        $x = 33.3;
        $w = 19.6;
        $y = 50.9;
        $h = '';
        $name = ! empty($d['pooled']) ? '' : NameFitter::display((string) $d['name']);
        $parts = array_values(array_filter(explode(' ', $name)));
        if (count($parts) >= 2) {
            $first = NameFitter::fitName(implode(' ', array_slice($parts, 0, -1)), 'saira-b', $w, 8.5, 6.0);
            $last = NameFitter::fitLine((string) end($parts), 'saira-b', $w, 8.5, 6.0);
            $h .= self::text($first['text'], $x, $y, $w, 3.5, 'sb', $first['pt'], '#ffffff');
            $h .= self::text($last['text'], $x, $y + 3.4, $w, 3.5, 'sb', $last['pt'], '#ffffff');
            $y += 7.4;
        } elseif ($name !== '') {
            $one = NameFitter::fitLine($name, 'saira-b', $w, 8.5, 6.0);
            $h .= self::text($one['text'], $x, $y, $w, 3.5, 'sb', $one['pt'], '#ffffff');
            $y += 3.9;
        }
        $line = function (string $text, string $font = 'saira', float $pt = 6.2, string $color = '#d6e0ea') use (&$h, &$y, $x, $w) {
            if (trim($text) === '') {
                return;
            }
            $f = NameFitter::fitLine($text, $font, $w, $pt, 4.6);
            $h .= self::text($f['text'], $x, $y, $w, 2.7, $font === 'saira' ? 's' : 'sb', $f['pt'], $color);
            $y += 2.8;
        };
        if ($d['kind'] === 'visitor') {
            $line(! empty($d['pooled']) || ! $d['host'] ? '' : __('Host') . ': ' . $d['host']);
        } else {
            $line((string) $d['vehicle'], 'saira-b', 7.0, '#ffffff');
            $line((string) $d['authorisation']);
        }
        $line($d['expires'] ? __('Valid until') . ' ' . $d['expires']->locale('en')->translatedFormat('j M Y') : IdPassSettings::get('idpass_no_expiry_text'), 'saira-b', 6.2, '#ffe9a8');

        return $h;
    }

    // ---------------------------------------------------------------------------------------------------------------------------- the shared back

    public static function back(array $d, string $mode = 'web'): string
    {
        self::$mode = $mode;
        $bg = CardAssets::hex(IdPassSettings::get('idpass_back_color'), '#cf7d1c');
        $h = '<div class="ip-card" style="background:' . $bg . '">';
        $h .= self::skyline($bg);
        $h .= self::img(self::src(CardAssets::art('back-arrow.png'), $mode), 40.2, 55.7, 14.8, 29.3);
        $h .= self::img(self::src(CardAssets::logo(), $mode), 13.6, 4.2, 28.0, 10.4);
        $intro = IdPassSettings::get('idpass_intro');
        foreach (self::wrap($intro, 'saira', 6.3, 40.5, 2) as $i => $line) {
            $h .= self::text($line, 4.0, 15.3 + $i * 2.5, 47.0, 2.5, 's', 6.3, '#ffffff', 'center');
        }
        $tt = NameFitter::fitLine(__('Terms & Conditions'), 'gluten-b', 42.6, 15, 9);
        $h .= self::text($tt['text'], 4.6, 20.7, 46.0, 5.6, 'gb', $tt['pt'], '#000000');

        $terms = IdPassSettings::terms();
        $maxLine = collect($terms)->max(fn ($t) => FontMeasure::mm($t, 'saira-b', 1)) ?: 1;       // width of the longest line per point
        $pt = round(min(7.0, 41.8 / $maxLine), 2);
        $step = min(3.9, 17.3 / max(1, count($terms)) + 0.0);
        $step = count($terms) > 0 ? min(3.85, 29.5 / count($terms)) : 3.85;
        $y = 27.0;
        $tick = self::src(CardAssets::tinted('icons/square-check.png', '#ffffff', 120), $mode);
        foreach ($terms as $t) {
            $h .= self::img($tick, 5.6, $y + 0.55, 2.35, 2.35);
            $h .= self::text($t, 9.9, $y, 44.0, 3.4, 'sb', $pt, '#ffffff');
            $y += $step;
        }

        $issued = $d['issued'] instanceof CarbonInterface ? self::ordinal($d['issued']) : '';
        $exp = $d['expires'] instanceof CarbonInterface ? self::ordinal($d['expires']) : IdPassSettings::get('idpass_no_expiry_text');
        $dateFont = 'gluten';
        $a = NameFitter::fitLine(__('Issued Date') . ' : ' . $issued, $dateFont, 34.0, 10.5, 6.5);
        $b = NameFitter::fitLine(__('Expiry Date') . ' : ' . $exp, $dateFont, 34.0, 10.5, 6.5);
        $h .= self::text($a['text'], 5.6, 56.6, 36.0, 3.6, 'g', $a['pt'], '#fff200');
        $h .= self::text($b['text'], 5.6, 60.2, 36.0, 3.6, 'g', $b['pt'], '#fff200');

        // the navy panel with the address, the box number, the telephones and the email
        $rows = [['location-dot', IdPassSettings::get('idpass_address')], ['inbox', IdPassSettings::get('idpass_pobox')], ['phone', IdPassSettings::get('idpass_phones')], ['envelope', IdPassSettings::get('idpass_email')]];
        $h .= self::box(3.8, 64.2, 48.2, 14.9, ['background' => self::NAVY, 'border-radius' => 4.0]);
        $y = 65.4;
        foreach ($rows as [$icon, $line]) {
            if (trim($line) === '') {
                continue;
            }
            $f = NameFitter::fitLine(preg_replace('/\s{2,}/', '   ', $line), 'saira-b', 40.8, 6.4, 4.6);
            $h .= self::img(self::src(CardAssets::tinted('icons/' . $icon . '.png', '#ffffff', 120), $mode), 6.2, $y + 0.15, 2.3, 2.3);
            $h .= self::text($f['text'], 9.8, $y, 41.0, 2.7, 'sb', $f['pt'], '#ffffff');
            $y += 3.2;
        }
        $web = IdPassSettings::get('idpass_website');
        if ($web !== '') {
            $f = NameFitter::fitLine($web, 'saira-b', 30.0, 8.6, 6.0);
            $tw = FontMeasure::mm($f['text'], 'saira-b', $f['pt']);
            $x0 = (55 - (3.2 + 1.2 + $tw)) / 2;
            $h .= self::img(self::src(CardAssets::tinted('icons/globe.png', '#ffffff', 120), $mode), $x0, 80.65, 3.2, 3.2);
            $h .= self::text($f['text'], $x0 + 4.4, 80.4, $tw + 2.0, 3.7, 'sb', $f['pt'], '#ffffff');
        }

        return $h . '</div>';
    }

    /** The faint outlines of tall buildings behind the back of the card: plain boxes in a slightly lighter tone of the back colour (no big picture to decode). */
    private static function skyline(string $bg): string
    {
        [$r, $g, $b] = sscanf($bg, '#%02x%02x%02x');
        $mix = fn ($c, $t) => (int) round($c + (255 - $c) * $t);
        $line = sprintf('#%02x%02x%02x', $mix($r, 0.11), $mix($g, 0.11), $mix($b, 0.11));
        $inner = sprintf('#%02x%02x%02x', $mix($r, 0.06), $mix($g, 0.06), $mix($b, 0.06));
        $h = '';
        // x, width, top (mm); every building stands on the bottom of the card
        foreach ([[-1.5, 5.2, 22.0], [4.2, 4.6, 9.0], [10.4, 5.4, 31.0], [17.4, 4.2, 14.0], [24.2, 5.8, 26.0], [31.6, 4.4, 7.5], [37.8, 5.6, 33.0], [44.4, 4.4, 19.0], [50.2, 6.0, 28.0]] as [$x, $w, $top]) {
            $h .= self::box($x, $top, $w, 85 - $top, ['border' => '0.25mm solid ' . $line]);
            foreach ([1, 2, 3] as $k) {
                $h .= self::box($x + $w * $k / 4, $top, 0.2, 85 - $top, ['background' => $inner]);
            }
        }

        return $h;
    }

    /** @return string[] the text broken into at most $lines lines no wider than $maxMm */
    private static function wrap(string $text, string $font, float $pt, float $maxMm, int $lines): array
    {
        $out = [];
        $cur = '';
        foreach (preg_split('/\s+/', trim($text)) as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur !== '' && FontMeasure::mm($try, $font, $pt) > $maxMm) {
                $out[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return array_slice($out, 0, $lines);
    }

    /** The style sheet of the cards; $mode 'pdf' points the fonts to the files, 'web' to the public copies. */
    public static function stylesheet(string $mode = 'web'): string
    {
        $dir = $mode === 'pdf' ? resource_path('idpass/fonts') : url('/fonts/idpass');

        return str_replace('%FONTS%', $dir, (string) file_get_contents(resource_path('idpass/card.css')));
    }
}
