<?php

namespace App\Support;

/**
 * Code 128 (set B, plain text such as "RSI-0001") rasterised to a PNG data URI with GD, for printed reports (DomPDF cannot run the browser's JsBarcode).
 * The same symbology the item pages show on screen.
 */
class Code128
{
    private const P = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** the bar/space widths of a text, as a string of digits (start, data, checksum, stop) */
    public static function pattern(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        $codes = [104];                                  // start B
        $sum = 104;
        foreach (str_split($text) as $i => $ch) {
            $v = ord($ch) - 32;
            $codes[] = $v;
            $sum += $v * ($i + 1);
        }
        $codes[] = $sum % 103;
        $codes[] = 106;                                  // stop

        return implode('', array_map(fn ($c) => self::P[$c], $codes));
    }

    /** pixel width of the image made by dataUri() */
    public static function width(string $text, int $module = 2): int
    {
        return 20 * $module + array_sum(str_split(self::pattern($text))) * $module;
    }

    public static function dataUri(string $text, int $module = 2, int $height = 56): string
    {
        $pattern = self::pattern($text);
        $quiet = 10 * $module;
        $width = $quiet * 2 + array_sum(str_split($pattern)) * $module;
        $im = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 17, 24, 39);
        imagefilledrectangle($im, 0, 0, $width, $height, $white);
        $x = $quiet;
        $bar = true;
        foreach (str_split($pattern) as $w) {
            $px = (int) $w * $module;
            if ($bar) {
                imagefilledrectangle($im, $x, 0, $x + $px - 1, $height - 1, $black);
            }
            $x += $px;
            $bar = ! $bar;
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
