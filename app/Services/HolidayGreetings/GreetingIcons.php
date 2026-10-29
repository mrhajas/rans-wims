<?php

namespace App\Services\HolidayGreetings;

/**
 * The flat icons of the greeting emails, drawn in code (no pictures). Each icon is a small SVG on a 24 x 24 grid in the colour of the text around it (currentColor) and
 * a plain text symbol for the mail apps that do not draw SVG (Gmail, Outlook), so every reader sees an icon and none is a downloaded image.
 */
class GreetingIcons
{
    /** name => [svg shapes, text symbol] */
    private const SET = [
        'sun' => ['<circle cx="12" cy="12" r="4.4" fill="currentColor" stroke="none"/><path d="M12 2.6v3M12 18.4v3M2.6 12h3M18.4 12h3M5.4 5.4l2.1 2.1M16.5 16.5l2.1 2.1M18.6 5.4l-2.1 2.1M7.5 16.5l-2.1 2.1"/>', "\u{2600}"],
        'health' => ['<rect x="9" y="3.5" width="6" height="17" rx="2" fill="currentColor" stroke="none"/><rect x="3.5" y="9" width="17" height="6" rx="2" fill="currentColor" stroke="none"/>', "\u{271A}"],
        'star' => ['<path d="M12 2.6l2.9 5.9 6.5.95-4.7 4.6 1.1 6.5L12 17.5l-5.8 3.05 1.1-6.5-4.7-4.6 6.5-.95z" fill="currentColor" stroke="none"/>', "\u{2605}"],
        'peace' => ['<circle cx="12" cy="12" r="9"/><path d="M12 3v18M12 12l-6.4 6.4M12 12l6.4 6.4"/>', "\u{262E}"],
        'unity' => ['<circle cx="8.6" cy="12" r="5.6"/><circle cx="15.4" cy="12" r="5.6"/>', "\u{2756}"],
        'flag' => ['<path d="M5 21.5V3"/><path d="M5 4h12.5L15 8.2l2.5 4.2H5" fill="currentColor"/>', "\u{2691}"],
        'flower' => ['<circle cx="12" cy="12" r="2.6" fill="currentColor" stroke="none"/><circle cx="12" cy="6.2" r="3"/><circle cx="12" cy="17.8" r="3"/><circle cx="6.2" cy="12" r="3"/><circle cx="17.8" cy="12" r="3"/>', "\u{273F}"],
        'moon' => ['<path d="M20 14.6A8.6 8.6 0 1 1 9.4 4a7 7 0 0 0 10.6 10.6z" fill="currentColor" stroke="none"/>', "\u{263E}"],
        'sparkle' => ['<path d="M12 2.4c.8 5.1 2.2 7.3 8.1 9.6-5.9 2.3-7.3 4.5-8.1 9.6-.8-5.1-2.2-7.3-8.1-9.6C9.8 9.7 11.2 7.5 12 2.4z" fill="currentColor" stroke="none"/><circle cx="19" cy="5" r="1.4" fill="currentColor" stroke="none"/><circle cx="5" cy="19" r="1.1" fill="currentColor" stroke="none"/>', "\u{2726}"],
        'smile' => ['<circle cx="12" cy="12" r="9"/><path d="M8 14.2c1 2 2.6 3 4 3s3-1 4-3"/><circle cx="9" cy="9.6" r="1.1" fill="currentColor" stroke="none"/><circle cx="15" cy="9.6" r="1.1" fill="currentColor" stroke="none"/>', "\u{263A}"],
        'heart' => ['<path d="M12 20.6C5 15.6 3 12.3 3 9.1a4.6 4.6 0 0 1 9-1.4 4.6 4.6 0 0 1 9 1.4c0 3.2-2 6.5-9 11.5z" fill="currentColor" stroke="none"/>', "\u{2665}"],
        'bolt' => ['<path d="M13.6 2.4 5 13.6h6l-1 8 9-11.6h-6z" fill="currentColor" stroke="none"/>', "\u{26A1}"],
        'hammer' => ['<path d="M13.6 3.6l6.8 6.8-2.6 2.6-6.8-6.8z" fill="currentColor"/><path d="M11.4 8.4 3.8 16a1.9 1.9 0 0 0 2.7 2.7l7.6-7.6"/>', "\u{2692}"],
        'gear' => ['<circle cx="12" cy="12" r="3.2"/><circle cx="12" cy="12" r="6.6"/><path d="M12 2.4v3.2M12 18.4v3.2M2.4 12h3.2M18.4 12h3.2M5.2 5.2l2.2 2.2M16.6 16.6l2.2 2.2M18.8 5.2l-2.2 2.2M7.4 16.6l-2.2 2.2"/>', "\u{2699}"],
        'trend' => ['<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>', "\u{279A}"],
        'wheat' => ['<path d="M12 21.5V8.5"/><path d="M12 8.5c-3 0-4.6-2.1-4.6-4.7 3 0 4.6 1.6 4.6 4.7zM12 8.5c3 0 4.6-2.1 4.6-4.7-3 0-4.6 1.6-4.6 4.7zM12 15c-3 0-4.6-2.1-4.6-4.7 3 0 4.6 1.6 4.6 4.7zM12 15c3 0 4.6-2.1 4.6-4.7-3 0-4.6 1.6-4.6 4.7z" fill="currentColor"/>', "\u{2698}"],
        'pen' => ['<path d="M4.2 19.8l1-4.2L16.6 4.2a2.1 2.1 0 0 1 3 3L8.2 18.8z"/><path d="M14.6 6.2l3 3"/>', "\u{270E}"],
        'house' => ['<path d="M3.4 11.6 12 4l8.6 7.6"/><path d="M6 10v10.4h12V10"/><path d="M10 20.4v-6h4v6"/>', "\u{2302}"],
        'cross' => ['<rect x="10" y="3" width="4" height="18" rx="1.2" fill="currentColor" stroke="none"/><rect x="6" y="7" width="12" height="4" rx="1.2" fill="currentColor" stroke="none"/>', "\u{271D}"],
        'crescent' => ['<path d="M19.4 15.4A8.2 8.2 0 1 1 9.2 5a6.6 6.6 0 0 0 10.2 10.4z" fill="currentColor" stroke="none"/><path d="M17.6 3.6l.8 1.8 2 .2-1.5 1.3.5 1.9-1.8-1-1.8 1 .5-1.9-1.5-1.3 2-.2z" fill="currentColor" stroke="none"/>', "\u{262A}"],
        'snowflake' => ['<path d="M12 2.4v19.2M3.7 7.2l16.6 9.6M3.7 16.8 20.3 7.2"/><path d="M9.4 4 12 6.6 14.6 4M9.4 20 12 17.4 14.6 20"/>', "\u{2744}"],
        'email' => ['<rect x="3" y="5" width="18" height="14" rx="2.6"/><path d="M3.8 7.4 12 13.2l8.2-5.8"/>', "\u{2709}"],
        'sms' => ['<path d="M4 5.5h16v11H9.6L4.6 20.6V16.5z" fill="currentColor" stroke="none"/><path d="M8 9.5h8M8 12.5h5" stroke="#ffffff00"/>', "\u{2706}"],
        'brick' => ['<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 12h18M9 6v6M15 12v6"/>', "\u{25A6}"],
        'hardhat' => ['<path d="M3.4 17.6h17.2"/><path d="M5.4 17.6v-3a6.6 6.6 0 0 1 13.2 0v3"/><path d="M12 8v4"/>', "\u{26D1}"],
        'ruler' => ['<path d="M4 20V4l16 16z"/><path d="M7.4 17v-3.4M10.6 17v-5.4"/>', "\u{25FF}"],
    ];

    public static function has(string $name): bool
    {
        return isset(self::SET[$name]);
    }

    /** The SVG of an icon at a given pixel size (the colour is the colour of the text it sits in). */
    public static function svg(string $name, int $size = 24, float $stroke = 2): string
    {
        [$shapes] = self::SET[$name] ?? self::SET['sparkle'];

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="display:block;">' . $shapes . '</svg>';
    }

    /** An icon for an email: the SVG for the apps that draw it and its text symbol for the ones that do not (the email's stylesheet shows one and hides the other). */
    public static function html(string $name, int $size): string
    {
        $sym = "'Segoe UI Symbol', 'Apple Symbols', 'Noto Sans Symbols', Arial, sans-serif";

        return '<span class="ic-svg" style="display:inline-block; line-height:0; vertical-align:middle;"><!--[if !mso]><!-->' . self::svg($name, $size) . '<!--<![endif]--></span>'
            . '<span class="fb" style="font-family:' . $sym . '; font-size:' . (int) round($size * 0.92) . 'px; line-height:' . $size . 'px;">' . self::glyph($name) . '</span>';
    }

    /** The plain-text symbol of an icon, in its text (not emoji) form. */
    public static function glyph(string $name): string
    {
        return (self::SET[$name][1] ?? "\u{2726}") . "\u{FE0E}";
    }
}
