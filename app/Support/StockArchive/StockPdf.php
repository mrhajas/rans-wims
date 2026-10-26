<?php

namespace App\Support\StockArchive;

use Dompdf\Cpdf;

/**
 * The Stock Archive report as a PDF, drawn straight with the PDF library DomPDF is built on (no HTML layout): about 10 times faster and a fraction of the size
 * of a DomPDF page for thousands of stock lines (the standard Helvetica font is not embedded, the logo is a small JPEG). Same look as the WIMS reports:
 * letterhead, blue title band, figures, then every warehouse / project with its items by category, and "Page x of y" on every page.
 */
class StockPdf
{
    private const W = 595.28;
    private const H = 841.89;
    private const M = 34.0;          // page margin
    private const TOP = 34.0;
    private const BOTTOM = 44.0;     // room for the footer

    private const INK = [0.06, 0.09, 0.16];
    private const MUTE = [0.39, 0.45, 0.55];
    private const BLUE = [0.114, 0.306, 0.847];   // #1d4ed8
    private const NAVY = [0.118, 0.227, 0.541];   // #1e3a8a
    private const SOFT = [0.937, 0.965, 1.0];     // #eff6ff
    private const LINE = [0.749, 0.859, 0.996];   // #bfdbfe
    private const ZEBRA = [0.973, 0.98, 0.992];

    private Cpdf $pdf;
    private float $y = self::TOP;     // distance from the top of the page
    private int $page = 1;
    private int $pages = 0;           // known after the first (dry) run
    private bool $dry = true;
    private array $meta;
    private array $data;
    private array $fonts = [];

    /** @param array{reference:string, title:string, generated_at:\Carbon\CarbonInterface, by:string, company:string} $meta */
    public static function render(array $data, array $meta): string
    {
        $one = new self($data, $meta);
        $one->run(true);                 // pass 1: how many pages
        $two = new self($data, $meta);
        $two->pages = $one->page;
        $two->run(false);                // pass 2: the real thing, with "Page x of y"

        return $two->pdf->output();
    }

    private function __construct(array $data, array $meta)
    {
        $this->data = $data;
        $this->meta = $meta;
        $dir = storage_path('framework/cache/cpdf');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->pdf = new Cpdf([0, 0, self::W, self::H], false, $dir, sys_get_temp_dir());
        $this->pdf->options['compression'] = 1;
        $fonts = base_path('vendor/dompdf/dompdf/lib/fonts/');
        $this->fonts = ['r' => $fonts . 'Helvetica', 'b' => $fonts . 'Helvetica-Bold'];
        $this->pdf->selectFont($this->fonts['r'], 'WinAnsiEncoding');
        $this->pdf->addInfo('Title', $meta['title']);
        $this->pdf->addInfo('Author', 'WIMS');
        $this->pdf->addInfo('Creator', 'WIMS Stock Archive');
    }

    private function run(bool $dry): void
    {
        $this->dry = $dry;
        $this->y = self::TOP;
        $this->page = 1;
        $this->cover();
        $this->summary();
        foreach ($this->data['warehouses'] as $i => $w) {
            // the first warehouse follows the header and summary; every other one starts at the top of its own page
            $this->warehouse($w, $i > 0);
        }
        if (! $this->data['warehouses']) {
            $this->need(60);
            $this->txt(self::M, $this->y + 14, 'There is no stock in any warehouse or project at the moment.', 9, 'r', self::MUTE);
        }
        $this->footer();
    }

    // ------------------------------------------------------------------ pages

    private function cover(): void
    {
        $m = $this->meta;
        $logo = self::logo();
        $xText = self::W - self::M;
        if ($logo && ! $this->dry) {
            $this->pdf->addJpegFromFile($logo['path'], self::M, self::H - self::TOP - 40, 109, 40);
        }
        $this->right($xText, self::TOP + 12, (string) $m['company'], 13, 'b', self::NAVY);
        $this->right($xText, self::TOP + 24, 'Building & Civil Contractors', 8, 'r', self::MUTE);
        $this->right($xText, self::TOP + 35, '+255 777 700615   |   info@rans.co.tz   |   Fumba Road, Zanzibar   |   www.rans.co.tz', 7, 'r', self::NAVY);
        $this->y = self::TOP + 48;
        $this->box(self::M, $this->y, self::W - 2 * self::M, 2.6, self::BLUE);
        $this->y += 12;

        // title band
        $bh = 54;
        $this->box(self::M, $this->y, self::W - 2 * self::M, $bh, self::BLUE, 9);
        $this->txt(self::M + 16, $this->y + 24, 'STOCK REPORT', 19, 'b', [1, 1, 1]);
        $this->txt(self::M + 16, $this->y + 40, 'The whole company\'s stock, each warehouse and project by category', 8.5, 'r', [0.86, 0.92, 1]);
        $this->right(self::W - self::M - 16, $this->y + 18, 'REFERENCE', 7, 'r', [0.86, 0.92, 1]);
        $this->right(self::W - self::M - 16, $this->y + 32, (string) $m['reference'], 12, 'b', [1, 1, 1]);
        $this->right(self::W - self::M - 16, $this->y + 45, $m['generated_at']->format('d/m/Y H:i'), 8, 'r', [0.86, 0.92, 1]);
        $this->y += $bh + 12;

        // the figures
        $t = $this->data['totals'];
        $tiles = [['Warehouses and projects', number_format($t['warehouses'])], ['Different items', number_format($t['items'])], ['Stock lines', number_format($t['rows'])], ['Total value (TZS)', number_format($t['value'], 2)]];
        $gap = 8;
        $w = (self::W - 2 * self::M - 3 * $gap) / 4;
        foreach ($tiles as $i => [$label, $value]) {
            $x = self::M + $i * ($w + $gap);
            $this->box($x, $this->y, $w, 40, self::SOFT, 7, self::LINE);
            $this->txt($x + 10, $this->y + 14, strtoupper($label), 6.4, 'b', self::MUTE);
            $this->txt($x + 10, $this->y + 31, $value, strlen($value) > 14 ? 10 : 13, 'b', self::NAVY);
        }
        $this->y += 40 + 10;
        $this->txt(self::M, $this->y + 8, 'Generated ' . $m['generated_at']->format('d/m/Y \a\t H:i') . ' by ' . $m['by'] . '. Quantities are the stock of every variation of an item added together; value = quantity x the item price.', 7, 'r', self::MUTE);
        $this->y += 22;
    }

    private function summary(): void
    {
        $rows = $this->data['warehouses'];
        if (! $rows) {
            return;
        }
        $this->need(60);
        $this->heading('Summary: stock held by each warehouse and project');
        $cols = [['No', 24, 'l'], ['Warehouse / project', 236, 'l'], ['Categories', 62, 'r'], ['Items', 52, 'r'], ['Value (TZS)', 0, 'r']];
        $cols[4][1] = self::W - 2 * self::M - array_sum(array_column(array_slice($cols, 0, 4), 1));
        $this->tableHead($cols);
        $n = 0;
        $group = null;
        $sum = ['cats' => 0, 'items' => 0, 'value' => 0.0];
        foreach ($rows as $w) {
            if ($w['group'] !== $group) {
                $group = $w['group'];
                $this->need(40);
                $this->box(self::M, $this->y, self::W - 2 * self::M, 14, self::SOFT);
                $this->txt(self::M + 6, $this->y + 10, strtoupper($group), 7, 'b', self::NAVY);
                $this->y += 14;
            }
            $this->need(14);
            $n++;
            if ($n % 2 === 0) {
                $this->box(self::M, $this->y, self::W - 2 * self::M, 13, self::ZEBRA);
            }
            $x = self::M;
            $vals = [(string) $n, $w['name'], (string) count($w['categories']), number_format($w['items']), number_format($w['value'], 2)];
            foreach ($cols as $i => [$label, $cw, $al]) {
                $this->cell($x, $this->y + 9.2, $cw, $vals[$i], 7.4, $i === 1 ? 'b' : 'r', $al, self::INK);
                $x += $cw;
            }
            $this->y += 13;
            $sum['cats'] += count($w['categories']);
            $sum['items'] += $w['items'];
            $sum['value'] += $w['value'];
        }
        $this->need(18);
        $this->box(self::M, $this->y, self::W - 2 * self::M, 15, self::SOFT, 0, null, self::BLUE);
        $x = self::M;
        foreach ($cols as $i => [$label, $cw, $al]) {
            $v = [$i === 1 ? 'Total' : '', '', '', number_format($sum['items']), number_format($sum['value'], 2)][$i] ?? '';
            if ($i === 1) {
                $v = 'Total';
            }
            $this->cell($x, $this->y + 10.5, $cw, $v, 7.8, 'b', $al, self::NAVY);
            $x += $cw;
        }
        $this->y += 24;
    }

    private function warehouse(array $w, bool $newPage = false): void
    {
        if ($newPage) {
            $this->breakPage();
        }
        $this->need(86);
        $bh = 22;
        $this->box(self::M, $this->y, self::W - 2 * self::M, $bh, self::BLUE, 6);
        $this->txt(self::M + 10, $this->y + 14.5, $w['name'], 10, 'b', [1, 1, 1]);
        $info = $w['group'] . '  |  ' . number_format($w['items']) . ' items  |  TZS ' . number_format($w['value'], 2);
        $this->right(self::W - self::M - 10, $this->y + 14, $info, 7.2, 'r', [0.86, 0.92, 1]);
        $this->y += $bh + 3;

        $cols = [['Code', 62, 'l'], ['Item', 0, 'l'], ['Unit', 44, 'l'], ['Quantity', 62, 'r'], ['Price', 64, 'r'], ['Value', 78, 'r']];
        $cols[1][1] = self::W - 2 * self::M - array_sum(array_column(array_filter($cols, fn ($c) => $c[0] !== 'Item'), 1));
        foreach ($w['categories'] as $cat => $list) {
            $this->need(14 + 13 + 13);
            $this->box(self::M, $this->y, self::W - 2 * self::M, 14, self::SOFT, 0, null, self::LINE);
            $this->txt(self::M + 6, $this->y + 9.8, $cat, 7.8, 'b', self::NAVY);
            $this->right(self::W - self::M - 6, $this->y + 9.8, count($list) . (count($list) === 1 ? ' item' : ' items'), 7, 'r', self::MUTE);
            $this->y += 14;
            $this->tableHead($cols);
            foreach ($list as $i => $row) {
                if ($this->need(13)) {
                    $this->txt(self::M, $this->y + 8, $w['name'] . ' (continued)  |  ' . $cat, 7, 'b', self::NAVY);
                    $this->y += 12;
                    $this->tableHead($cols);
                }
                if ($i % 2 === 1) {
                    $this->box(self::M, $this->y, self::W - 2 * self::M, 12.5, self::ZEBRA);
                }
                $qty = self::num($row['qty']) . ($row['weight'] != 0 && $row['qty'] == 0 ? ' (' . self::num($row['weight']) . ' kg)' : '');
                $vals = [$row['code'], $row['name'], $row['unit'], $qty, $row['price'] ? number_format($row['price'], 2) : '-', $row['value'] ? number_format($row['value'], 2) : '-'];
                $x = self::M;
                foreach ($cols as $c => [$label, $cw, $al]) {
                    $this->cell($x, $this->y + 8.8, $cw, $vals[$c], 7, 'r', $al, self::INK);
                    $x += $cw;
                }
                $this->y += 12.5;
            }
            $this->y += 4;
        }
        $this->need(20);
        $this->box(self::M, $this->y, self::W - 2 * self::M, 15, self::SOFT, 0, null, self::BLUE);
        $this->txt(self::M + 6, $this->y + 10.4, 'Total of ' . $w['name'], 7.8, 'b', self::NAVY);
        $this->right(self::W - self::M - 6, $this->y + 10.4, 'TZS ' . number_format($w['value'], 2), 8, 'b', self::NAVY);
        $this->y += 26;
    }

    // ------------------------------------------------------------------ pieces

    private function heading(string $text): void
    {
        $this->txt(self::M, $this->y + 9, strtoupper($text), 8.4, 'b', self::NAVY);
        $this->y += 15;
    }

    private function tableHead(array $cols): void
    {
        $this->box(self::M, $this->y, self::W - 2 * self::M, 12.5, self::BLUE);
        $x = self::M;
        foreach ($cols as [$label, $cw, $al]) {
            $this->cell($x, $this->y + 8.6, $cw, strtoupper($label), 6.3, 'b', $al, [1, 1, 1]);
            $x += $cw;
        }
        $this->y += 12.5;
    }

    /** Makes sure $h points are left on the page, otherwise starts the next page. Returns true when a new page was started. */
    private function need(float $h): bool
    {
        if ($this->y + $h <= self::H - self::BOTTOM) {
            return false;
        }
        $this->breakPage();

        return true;
    }

    /** Ends the page and starts the next one with its running head. */
    private function breakPage(): void
    {
        $this->footer();
        if (! $this->dry) {
            $this->pdf->newPage();
        }
        $this->page++;
        $this->y = self::TOP;
        // the running head of every page after the first
        $this->txt(self::M, $this->y + 8, 'STOCK REPORT  ' . $this->meta['reference'], 7.5, 'b', self::NAVY);
        $this->right(self::W - self::M, $this->y + 8, $this->meta['generated_at']->format('d/m/Y H:i'), 7.5, 'r', self::MUTE);
        $this->box(self::M, $this->y + 12, self::W - 2 * self::M, 1.4, self::BLUE);
        $this->y += 22;
    }

    private function footer(): void
    {
        $y = 28.0; // from the bottom of the page
        if ($this->dry) {
            return;
        }
        $this->pdf->setStrokeColor(self::LINE);
        $this->pdf->setLineStyle(0.6);
        $this->pdf->line(self::M, $y + 10, self::W - self::M, $y + 10);
        $this->pdf->setColor(self::MUTE);
        $this->pdf->selectFont($this->fonts['r'], 'WinAnsiEncoding');
        $this->pdf->addText(self::M, $y - 2, 7, 'WIMS Stock Archive  |  ' . $this->meta['reference'] . '  |  Generated ' . $this->meta['generated_at']->format('d/m/Y H:i') . ' by ' . $this->meta['by']);
        $label = 'Page ' . $this->page . ' of ' . max($this->page, $this->pages);
        $this->pdf->addText(self::W - self::M - $this->pdf->getTextWidth(7, $label), $y - 2, 7, $label);
    }

    // ------------------------------------------------------------------ drawing (y is the distance from the top of the page)

    private function box(float $x, float $top, float $w, float $h, array $fill, float $radius = 0, ?array $stroke = null, ?array $edge = null): void
    {
        if ($this->dry || $w <= 0 || $h <= 0) {
            return;
        }
        $y = self::H - $top - $h;
        $this->pdf->setColor($fill);
        if ($radius > 0) {
            $this->pdf->save();
            $this->pdf->clippingRectangleRounded($x, $y, $w, $h, $radius, $radius, $radius, $radius);
            $this->pdf->filledRectangle($x, $y, $w, $h);
            $this->pdf->restore();
        } else {
            $this->pdf->filledRectangle($x, $y, $w, $h);
        }
        if ($stroke) {
            $this->pdf->setStrokeColor($stroke);
            $this->pdf->setLineStyle(0.6);
            $this->pdf->rectangle($x, $y, $w, $h);
        }
        if ($edge) { // a coloured line along the bottom
            $this->pdf->setStrokeColor($edge);
            $this->pdf->setLineStyle(0.7);
            $this->pdf->line($x, $y, $x + $w, $y);
        }
    }

    /** Text with its baseline at $top from the top of the page. */
    private function txt(float $x, float $top, string $text, float $size, string $font, array $color): void
    {
        if ($this->dry || $text === '') {
            return;
        }
        $this->pdf->selectFont($this->fonts[$font], 'WinAnsiEncoding');
        $this->pdf->setColor($color);
        $this->pdf->addText($x, self::H - $top, $size, $text);
    }

    private function right(float $xRight, float $top, string $text, float $size, string $font, array $color): void
    {
        if ($this->dry || $text === '') {
            return;
        }
        $this->pdf->selectFont($this->fonts[$font], 'WinAnsiEncoding');
        $this->txt($xRight - $this->pdf->getTextWidth($size, $text), $top, $text, $size, $font, $color);
    }

    /** One table cell: the text cut to the column width ("...") and aligned left or right with a little padding. */
    private function cell(float $x, float $top, float $w, string $text, float $size, string $font, string $align, array $color): void
    {
        if ($this->dry || $w <= 0 || $text === '') {
            return;
        }
        $this->pdf->selectFont($this->fonts[$font], 'WinAnsiEncoding');
        $avail = $w - 8;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if ($this->pdf->getTextWidth($size, $text) > $avail) {
            while ($text !== '' && $this->pdf->getTextWidth($size, $text . '...') > $avail) {
                $text = mb_substr($text, 0, -1);
            }
            $text = rtrim($text) . '...';
        }
        $tx = $align === 'r' ? $x + $w - 4 - $this->pdf->getTextWidth($size, $text) : $x + 4;
        $this->txt($tx, $top, $text, $size, $font, $color);
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.') ?: '0';
    }

    /** A small JPEG copy of the company logo (white background), made once: the PNG is 40 KB, this is about 6 KB. */
    private static function logo(): ?array
    {
        $src = public_path('images/logo.png');
        $dst = storage_path('app/stock-archive-logo.jpg');
        if (! is_file($src) || ! function_exists('imagecreatefrompng')) {
            return null;
        }
        if (! is_file($dst) || filemtime($dst) < filemtime($src)) {
            $in = @imagecreatefrompng($src);
            if (! $in) {
                return null;
            }
            $w = 320;
            $h = (int) round(imagesy($in) * $w / imagesx($in));
            $out = imagecreatetruecolor($w, $h);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $in, 0, 0, 0, 0, $w, $h, imagesx($in), imagesy($in));
            imagejpeg($out, $dst, 82);
        }

        return ['path' => $dst];
    }
}
