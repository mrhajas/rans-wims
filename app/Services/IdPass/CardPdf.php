<?php

namespace App\Services\IdPass;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Makes the PDFs: one card (two pages of 55 x 85 mm, front then back), A4 sheets with the front and the back side by side, A4 double-sided sheets (the backs mirrored so
 * they land behind their fronts), and many cards as one PDF. A card is never stretched: it keeps its 55 x 85 mm on every layout.
 */
class CardPdf
{
    public const MM_PT = 72 / 25.4;

    private const MARGIN = 8.0;

    private static function wrap(string $body, string $size, string $extra = ''): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:' . $size . ';margin:0}html,body{margin:0;padding:0}.ip-pg{position:relative;overflow:hidden}.ip-pg+.ip-pg{page-break-before:always}'
            . $extra . CardRenderer::stylesheet('pdf') . '</style></head><body>' . $body . '</body></html>';
    }

    /** Where the PDF engine keeps the fonts it prepared (created when it is missing, as on a fresh server). */
    public static function fontDir(): string
    {
        $d = storage_path('fonts');
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    private static function make(string $html, array $paperPt): string
    {
        return Pdf::loadHTML($html)->setPaper([0, 0, $paperPt[0], $paperPt[1]])->setOptions([
            'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'isHtml5ParserEnabled' => true,
            'fontDir' => self::fontDir(), 'fontCache' => self::fontDir(), 'chroot' => [base_path()],
        ])->output();
    }

    /** One card: page 1 the front, page 2 the back, each exactly 55 x 85 mm, nothing else on the page. */
    public static function card(array $d): string
    {
        $pg = fn (string $inner) => '<div class="ip-pg" style="width:55mm;height:85mm">' . $inner . '</div>';
        $html = self::wrap($pg(CardRenderer::front($d, 'pdf')) . $pg(CardRenderer::back($d, 'pdf')), '55mm 85mm');

        return self::make($html, [55 * self::MM_PT, 85 * self::MM_PT]);
    }

    /** Many cards, two pages each (front, back), all 55 x 85 mm. */
    public static function cards(iterable $datas): string
    {
        $pg = fn (string $inner) => '<div class="ip-pg" style="width:55mm;height:85mm">' . $inner . '</div>';
        $body = '';
        foreach ($datas as $d) {
            $body .= $pg(CardRenderer::front($d, 'pdf')) . $pg(CardRenderer::back($d, 'pdf'));
        }

        return self::make(self::wrap($body, '55mm 85mm'), [55 * self::MM_PT, 85 * self::MM_PT]);
    }

    /** How a sheet is laid out: [columns, rows, sheet width, sheet height]. */
    public static function grid(string $layout, bool $landscape): array
    {
        [$sw, $sh] = $landscape ? [297.0, 210.0] : [210.0, 297.0];
        $gap = 6.0;
        if ($layout === 'a4_duplex') {
            [$sw, $sh] = [210.0, 297.0];
            $g = 5.0;

            return [max(1, (int) floor(($sw - 2 * self::MARGIN + $g) / (55 + $g))), max(1, (int) floor(($sh - 2 * self::MARGIN + $g) / (85 + $g))), $sw, $sh, $g];
        }
        $pair = 55 * 2 + 3;

        return [max(1, (int) floor(($sw - 2 * self::MARGIN + $gap) / ($pair + $gap))), max(1, (int) floor(($sh - 2 * self::MARGIN + $gap) / (85 + $gap))), $sw, $sh, $gap];
    }

    /** How many sheets $cards cards need on this layout. */
    public static function sheets(string $layout, bool $landscape, int $cards): int
    {
        [$c, $r] = self::grid($layout, $landscape);
        $per = $layout === 'a4_duplex' ? $c * $r : $c * $r;

        return max(1, (int) ceil($cards / max(1, $per))) * ($layout === 'a4_duplex' ? 2 : 1);
    }

    private static function marks(float $x, float $y, float $w, float $h): string
    {
        $m = '';
        $len = 2.6;
        $off = 0.8;
        $line = fn ($lx, $ly, $lw, $lh) => '<div style="position:absolute;left:' . $lx . 'mm;top:' . $ly . 'mm;width:' . $lw . 'mm;height:' . $lh . 'mm;background:#555"></div>';
        foreach ([[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]] as $i => [$cx, $cy]) {
            $dirX = ($i % 2 === 0) ? -1 : 1;
            $dirY = $i < 2 ? -1 : 1;
            $m .= $line($dirX < 0 ? $cx - $off - $len : $cx + $off, $cy - 0.05, $len, 0.1);
            $m .= $line($cx - 0.05, $dirY < 0 ? $cy - $off - $len : $cy + $off, 0.1, $len);
        }

        return $m;
    }

    private static function caption(string $text, float $x, float $y, float $w): string
    {
        return '<div style="position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $w . 'mm;height:3mm;font-family:IpSairaB;font-size:5pt;color:#777;text-align:center;line-height:3mm">' . e($text) . '</div>';
    }

    private static function slot(string $cardHtml, float $x, float $y): string
    {
        return '<div style="position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:55mm;height:85mm">' . $cardHtml . '</div>';
    }

    /**
     * A4 sheets. 'a4_side': each card's front and back next to each other (a pair), as many pairs as fit. 'a4_duplex': a sheet of fronts, then a sheet of backs with the
     * columns mirrored, so with "flip on the long edge" every back is printed behind its front.
     */
    public static function a4(array $datas, string $layout = 'a4_side', bool $landscape = false): string
    {
        $datas = array_values($datas);
        [$cols, $rows, $sw, $sh, $gap] = self::grid($layout, $landscape);
        $per = $cols * $rows;
        $pages = '';
        $page = fn (string $inner) => '<div class="ip-pg" style="width:' . $sw . 'mm;height:' . $sh . 'mm">' . $inner . '</div>';
        $chunks = array_chunk($datas, $per);
        if ($layout === 'a4_duplex') {
            $totalW = $cols * 55 + ($cols - 1) * $gap;
            $left = ($sw - $totalW) / 2;
            $top = ($sh - ($rows * 85 + ($rows - 1) * $gap)) / 2;
            foreach ($chunks as $chunk) {
                foreach (['front', 'back'] as $side) {
                    $inner = '';
                    foreach ($chunk as $i => $d) {
                        $col = $i % $cols;
                        $row = intdiv($i, $cols);
                        $col = $side === 'back' ? $cols - 1 - $col : $col;
                        $x = $left + $col * (55 + $gap);
                        $y = $top + $row * (85 + $gap);
                        $inner .= self::slot($side === 'front' ? CardRenderer::front($d, 'pdf') : CardRenderer::back($d, 'pdf'), $x, $y) . self::marks($x, $y, 55, 85);
                    }
                    $pages .= $page($inner . self::caption($side === 'front' ? __('FRONT SIDES: print on the first side of the paper') : __('BACK SIDES: print double sided, flip on the long edge'), 0, $sh - 5.5, $sw));
                }
            }
        } else {
            $pairW = 55 * 2 + 3;
            $totalW = $cols * $pairW + ($cols - 1) * $gap;
            $left = ($sw - $totalW) / 2;
            $top = ($sh - ($rows * 85 + ($rows - 1) * $gap)) / 2;
            foreach ($chunks as $chunk) {
                $inner = '';
                foreach ($chunk as $i => $d) {
                    $x = $left + ($i % $cols) * ($pairW + $gap);
                    $y = $top + intdiv($i, $cols) * (85 + $gap);
                    $inner .= self::slot(CardRenderer::front($d, 'pdf'), $x, $y) . self::marks($x, $y, 55, 85);
                    $inner .= self::slot(CardRenderer::back($d, 'pdf'), $x + 58, $y) . self::marks($x + 58, $y, 55, 85);
                    $inner .= self::caption(__('FRONT') . ' ' . ($d['number'] ?? ''), $x, $y + 85.3, 55) . self::caption(__('BACK'), $x + 58, $y + 85.3, 55);
                }
                $pages .= $page($inner);
            }
        }

        return self::make(self::wrap($pages, 'A4 ' . ($layout === 'a4_duplex' || ! $landscape ? 'portrait' : 'landscape')), [$sw * self::MM_PT, $sh * self::MM_PT]);
    }
}
