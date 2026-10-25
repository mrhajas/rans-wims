<?php

namespace App\Support;

/**
 * CODE128 barcode drawn as an inline SVG, for the printed Item Labels document (the Print Barcodes page draws the same codes
 * in the browser with JsBarcode; both read identically). Digits only with an even length use set C, everything else set B.
 */
class ItemLabelBarcode
{
    private const BARS = ['11011001100', '11001101100', '11001100110', '10010011000', '10010001100', '10001001100', '10011001000', '10011000100', '10001100100', '11001001000', '11001000100', '11000100100', '10110011100', '10011011100', '10011001110', '10111001100', '10011101100', '10011100110', '11001110010', '11001011100', '11001001110', '11011100100', '11001110100', '11101101110', '11101001100', '11100101100', '11100100110', '11101100100', '11100110100', '11100110010', '11011011000', '11011000110', '11000110110', '10100011000', '10001011000', '10001000110', '10110001000', '10001101000', '10001100010', '11010001000', '11000101000', '11000100010', '10110111000', '10110001110', '10001101110', '10111011000', '10111000110', '10001110110', '11101110110', '11010001110', '11000101110', '11011101000', '11011100010', '11011101110', '11101011000', '11101000110', '11100010110', '11101101000', '11101100010', '11100011010', '11101111010', '11001000010', '11110001010', '10100110000', '10100001100', '10010110000', '10010000110', '10000101100', '10000100110', '10110010000', '10110000100', '10011010000', '10011000010', '10000110100', '10000110010', '11000010010', '11001010000', '11110111010', '11000010100', '10001111010', '10100111100', '10010111100', '10010011110', '10111100100', '10011110100', '10011110010', '11110100100', '11110010100', '11110010010', '11011011110', '11011110110', '11110110110', '10101111000', '10100011110', '10001011110', '10111101000', '10111100010', '11110101000', '11110100010', '10111011110', '10111101110', '11101011110', '11110101110', '11010000100', '11010010000', '11010011100', '1100011101011'];

    /** The module pattern ('1' = bar, '0' = space) of a text, start and stop included. */
    public static function modules(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?: ' ';
        $values = [];
        if (ctype_digit($text) && strlen($text) % 2 === 0) {
            $values[] = 105;
            foreach (str_split($text, 2) as $pair) {
                $values[] = (int) $pair;
            }
        } else {
            $values[] = 104;
            foreach (str_split($text) as $ch) {
                $values[] = ord($ch) - 32;
            }
        }
        $sum = $values[0];
        foreach (array_slice($values, 1) as $i => $v) {
            $sum += $v * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = 106;

        $out = '';
        foreach ($values as $v) {
            $out .= self::BARS[$v];
        }

        return $out; // the stop pattern (13 modules) already ends with its final bar
    }

    /** SVG of the barcode with the text under it. $bar is the width of one module in px, $height the bar height. */
    public static function svg(string $text, float $bar = 1.8, int $height = 52, string $color = '#0b1530'): string
    {
        $m = self::modules($text);
        $quiet = 10;
        $w = (strlen($m) + $quiet * 2) * $bar;
        $fontSize = max(20, (int) round($height * 0.42));
        $h = $height + $fontSize + 8;
        $rects = '';
        $len = strlen($m);
        for ($i = 0; $i < $len;) {
            if ($m[$i] !== '1') {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $len && $m[$j] === '1') {
                $j++;
            }
            $rects .= '<rect x="' . round(($quiet + $i) * $bar, 2) . '" y="0" width="' . round(($j - $i) * $bar, 2) . '" height="' . $height . '"/>';
            $i = $j;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . round($w, 2) . ' ' . $h . '" style="width:100%;height:auto;display:block" shape-rendering="crispEdges">'
            . '<g fill="' . $color . '">' . $rects . '</g>'
            . '<text x="' . round($w / 2, 2) . '" y="' . ($height + $fontSize + 2) . '" text-anchor="middle" font-family="Menlo, Consolas, monospace" font-weight="700" font-size="' . $fontSize . '" fill="' . $color . '">' . htmlspecialchars($text, ENT_QUOTES) . '</text>'
            . '</svg>';
    }
}
