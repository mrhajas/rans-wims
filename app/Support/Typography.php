<?php

namespace App\Support;

/**
 * The typefaces of the WIMS interface, chosen in Settings (Super Admin). One font for the whole application (Avenir by default, Montserrat where Avenir is not
 * installed) and, optionally, a different font, weight and italic for each part: headings, paragraphs, labels, buttons, tabs, tables, tooltips and form fields.
 * Nothing is fixed in a template: the choices are stored as one JSON setting and turned into a small <style> block (plus the Google Fonts links the chosen fonts need)
 * when the page is built. A role left on "Same as the application" / "Automatic" / not italic changes nothing, so the existing look stays as designed.
 */
class Typography
{
    public const KEY = 'ui_typography';

    public const WEIGHTS = ['auto' => 'Automatic', '300' => 'Light', '400' => 'Regular', '500' => 'Medium', '600' => 'Semi Bold', '700' => 'Bold', '800' => 'Extra Bold'];

    private const FALLBACK = "'Segoe UI', Arial, sans-serif";

    /** key => label, CSS stack, Google Fonts spec (null = installed on the device, nothing to load), kind. */
    public static function fonts(): array
    {
        $all = 'ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700';

        return [
            'avenir' => ['label' => 'Avenir', 'stack' => "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, " . self::FALLBACK, 'google' => 'Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700', 'kind' => 'Sans serif', 'note' => 'Uses Avenir on Mac, iPhone and iPad; Montserrat on other devices'],
            'montserrat' => ['label' => 'Montserrat', 'stack' => 'Montserrat, ' . self::FALLBACK, 'google' => 'Montserrat:' . $all, 'kind' => 'Sans serif'],
            'nunito' => ['label' => 'Nunito', 'stack' => 'Nunito, ' . self::FALLBACK, 'google' => 'Nunito:' . $all, 'kind' => 'Sans serif'],
            'nunito-sans' => ['label' => 'Nunito Sans', 'stack' => "'Nunito Sans', " . self::FALLBACK, 'google' => 'Nunito+Sans:' . $all, 'kind' => 'Sans serif'],
            'inter' => ['label' => 'Inter', 'stack' => 'Inter, ' . self::FALLBACK, 'google' => 'Inter:wght@300;400;500;600;700;800', 'kind' => 'Sans serif'],
            'roboto' => ['label' => 'Roboto', 'stack' => 'Roboto, ' . self::FALLBACK, 'google' => 'Roboto:ital,wght@0,300;0,400;0,500;0,700;0,900;1,400;1,700', 'kind' => 'Sans serif'],
            'open-sans' => ['label' => 'Open Sans', 'stack' => "'Open Sans', " . self::FALLBACK, 'google' => 'Open+Sans:' . $all, 'kind' => 'Sans serif'],
            'lato' => ['label' => 'Lato', 'stack' => 'Lato, ' . self::FALLBACK, 'google' => 'Lato:ital,wght@0,300;0,400;0,700;0,900;1,400;1,700', 'kind' => 'Sans serif'],
            'poppins' => ['label' => 'Poppins', 'stack' => 'Poppins, ' . self::FALLBACK, 'google' => 'Poppins:' . $all, 'kind' => 'Sans serif'],
            'source-sans-3' => ['label' => 'Source Sans 3', 'stack' => "'Source Sans 3', " . self::FALLBACK, 'google' => 'Source+Sans+3:ital,wght@0,300;0,400;0,600;0,700;0,800;1,400;1,600;1,700', 'kind' => 'Sans serif'],
            'work-sans' => ['label' => 'Work Sans', 'stack' => "'Work Sans', " . self::FALLBACK, 'google' => 'Work+Sans:' . $all, 'kind' => 'Sans serif'],
            'ibm-plex-sans' => ['label' => 'IBM Plex Sans', 'stack' => "'IBM Plex Sans', " . self::FALLBACK, 'google' => 'IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400;1,600;1,700', 'kind' => 'Sans serif'],
            'helvetica' => ['label' => 'Helvetica Neue', 'stack' => "'Helvetica Neue', Helvetica, Arial, sans-serif", 'google' => null, 'kind' => 'Sans serif', 'note' => 'Installed on the device'],
            'arial' => ['label' => 'Arial', 'stack' => 'Arial, Helvetica, sans-serif', 'google' => null, 'kind' => 'Sans serif', 'note' => 'Installed on the device'],
            'segoe-ui' => ['label' => 'Segoe UI', 'stack' => "'Segoe UI', Tahoma, Arial, sans-serif", 'google' => null, 'kind' => 'Sans serif', 'note' => 'Installed on Windows'],
            'georgia' => ['label' => 'Georgia', 'stack' => "Georgia, 'Times New Roman', serif", 'google' => null, 'kind' => 'Serif', 'note' => 'Installed on the device'],
            'merriweather' => ['label' => 'Merriweather', 'stack' => "Merriweather, Georgia, 'Times New Roman', serif", 'google' => 'Merriweather:ital,wght@0,300;0,400;0,700;0,900;1,400;1,700', 'kind' => 'Serif'],
        ];
    }

    /** key => label, what it covers, CSS selectors. "base" is the whole application; the others only change what they name. */
    public static function roles(): array
    {
        return [
            'base' => ['label' => 'Whole application', 'about' => 'The default for every text, button and field.', 'selectors' => 'html, body, button, input, select, textarea, optgroup'],
            'headings' => ['label' => 'Headings', 'about' => 'Page and card titles.', 'selectors' => 'h1, h2, h3, h4, h5, h6, .lg-card-title, .lg-hero-title, .ac-cur-total'],
            'paragraphs' => ['label' => 'Paragraphs', 'about' => 'Descriptions, notes, lists and sub-texts.', 'selectors' => 'p, li, dd, .lg-sub, .lg-card-sub, .lg-subtext'],
            'labels' => ['label' => 'Labels', 'about' => 'Field labels, table headings and legends.', 'selectors' => 'label, th, legend, dt'],
            'buttons' => ['label' => 'Buttons', 'about' => 'Buttons and action links.', 'selectors' => 'button, .lg-btn, .lg-icon-btn, input[type=button], input[type=submit]'],
            'tabs' => ['label' => 'Tabs', 'about' => 'Folder tabs and tab bars.', 'selectors' => '.sc-tab, [role=tab]'],
            'tables' => ['label' => 'Tables', 'about' => 'The text of table rows.', 'selectors' => 'td'],
            'tooltips' => ['label' => 'Tooltips', 'about' => 'The small hint bubbles.', 'selectors' => '.lg-tip::after, [data-tip]::after, [role=tooltip], .tooltip'],
            'inputs' => ['label' => 'Form fields', 'about' => 'What is typed into fields.', 'selectors' => 'input:not([type=checkbox]):not([type=radio]), select, textarea'],
        ];
    }

    public static function defaults(): array
    {
        $d = [];
        foreach (self::roles() as $k => $r) {
            $d[$k] = ['font' => $k === 'base' ? 'avenir' : 'inherit', 'weight' => 'auto', 'italic' => false];
        }

        return $d;
    }

    /** The saved choices merged over the defaults; anything unknown or invalid falls back to the default. */
    public static function current(): array
    {
        $out = self::defaults();
        $raw = get_settings(self::KEY);
        $saved = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return is_array($saved) ? self::merge($out, $saved) : $out;
    }

    public static function merge(array $base, array $saved): array
    {
        $fonts = self::fonts();
        foreach (array_keys($base) as $role) {
            $s = is_array($saved[$role] ?? null) ? $saved[$role] : [];
            $font = (string) ($s['font'] ?? $base[$role]['font']);
            if ($font === 'inherit' ? $role === 'base' : ! isset($fonts[$font])) {
                $font = $base[$role]['font'];
            }
            $weight = (string) ($s['weight'] ?? 'auto');
            $base[$role] = ['font' => $font, 'weight' => array_key_exists($weight, self::WEIGHTS) ? $weight : 'auto', 'italic' => ! empty($s['italic'])];
        }

        return $base;
    }

    /** The CSS for the chosen typography. Only what was changed is written, and with !important so it wins over the page styles it is meant to replace. */
    public static function css(?array $c = null): string
    {
        $c ??= self::current();
        $fonts = self::fonts();
        $css = '';
        foreach (self::roles() as $role => $def) {
            $r = $c[$role];
            // each selector is prefixed on its own: pseudo-elements (the tooltip bubble) are not allowed inside :is()
            $sel = implode(', ', array_map(fn ($x) => 'html body ' . trim($x), explode(',', $def['selectors'])));
            if ($role === 'base') {
                $css .= 'html, body, button, input, select, textarea, optgroup { font-family: ' . $fonts[$r['font']]['stack'] . "; }\n";
                $rules = [];
            } else {
                $rules = [];
                if ($r['font'] !== 'inherit') {
                    $rules[] = 'font-family: ' . $fonts[$r['font']]['stack'] . ' !important';
                }
            }
            if ($r['weight'] !== 'auto') {
                $rules[] = 'font-weight: ' . (int) $r['weight'] . ' !important';
            }
            if ($r['italic']) {
                $rules[] = 'font-style: italic !important';
            }
            if ($rules) {
                $css .= ($role === 'base' ? 'html body' : $sel) . ' { ' . implode('; ', $rules) . "; }\n";
            }
        }

        return $css;
    }

    /** The Google Fonts stylesheet URLs the chosen fonts need (each font has its own so one bad request never blocks the others). */
    public static function links(?array $c = null): array
    {
        $c ??= self::current();
        $fonts = self::fonts();
        $keys = [];
        foreach ($c as $r) {
            if ($r['font'] !== 'inherit' && isset($fonts[$r['font']])) {
                $keys[$r['font']] = true;
            }
        }
        $urls = [];
        foreach (array_keys($keys) as $k) {
            if ($fonts[$k]['google']) {
                $urls[$k] = 'https://fonts.googleapis.com/css2?family=' . $fonts[$k]['google'] . '&display=swap';
            }
        }

        return array_values(array_unique($urls));
    }

    /** What goes into the page <head>: the font links first (call before the app stylesheet), the style block last (call after it). */
    public static function linksHtml(): string
    {
        $h = "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
        foreach (self::links() as $u) {
            $h .= '<link rel="stylesheet" href="' . e($u) . "\">\n";
        }

        return $h;
    }

    public static function styleHtml(): string
    {
        return '<style id="wims-typography">' . "\n" . self::css() . '</style>';
    }

    /** What the Settings page needs to draw the chooser and its live preview. */
    public static function forSettings(): array
    {
        $fonts = [];
        foreach (self::fonts() as $k => $f) {
            $fonts[] = ['value' => $k, 'label' => $f['label'] . ($f['kind'] === 'Serif' ? ' (serif)' : ''), 'note' => $f['note'] ?? null, 'name' => $f['label'], 'stack' => $f['stack'], 'link' => $f['google'] ? 'https://fonts.googleapis.com/css2?family=' . $f['google'] . '&display=swap' : null];
        }
        $roles = [];
        foreach (self::roles() as $k => $r) {
            $roles[] = ['key' => $k, 'label' => $r['label'], 'about' => $r['about']];
        }
        $weights = [];
        foreach (self::WEIGHTS as $v => $l) {
            $weights[] = ['value' => (string) $v, 'label' => $l . ($v !== 'auto' ? ' (' . $v . ')' : '')];
        }

        return ['current' => self::current(), 'defaults' => self::defaults(), 'fonts' => $fonts, 'roles' => $roles, 'weights' => $weights];
    }
}
