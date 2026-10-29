<?php

namespace App\Services\HolidayGreetings;

use App\Models\HolidayDay;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Turns a holiday's template (the WIMS one, or the Super Admin's own wording) into the email and the SMS of one person. */
class GreetingTemplates
{
    public const PLACEHOLDERS = ['{full}' => 'Title and name, for example Eng. Amina Juma', '{title}' => 'Title only', '{name}' => 'Name only', '{holiday}' => 'Name of the holiday', '{year}' => 'The year',
        '{date}' => 'Date of the holiday', '{company}' => 'The company name (Settings)', '{signoff}' => 'The sign-off (Settings)'];

    /** The WIMS wording of a holiday: what is used until the Super Admin changes it. */
    public static function defaults(HolidayDay $h): array
    {
        $t = GreetingThemes::get($h->theme);

        return ['subject' => $t['subject'], 'headline' => $t['headline'], 'body' => $t['body'], 'sms' => $t['sms']];
    }

    /** What will be sent: the Super Admin's wording where there is one, the WIMS wording otherwise. */
    public static function effective(HolidayDay $h): array
    {
        $d = self::defaults($h);

        return [
            'subject' => filled($h->email_subject) ? $h->email_subject : $d['subject'],
            'headline' => filled($h->email_headline) ? $h->email_headline : $d['headline'],
            'body' => filled($h->email_body) ? $h->email_body : $d['body'],
            'sms' => filled($h->sms_body) ? $h->sms_body : $d['sms'],
        ];
    }

    public static function edited(HolidayDay $h): bool
    {
        return filled($h->email_subject) || filled($h->email_headline) || filled($h->email_body) || filled($h->sms_body);
    }

    public static function vars(HolidayDay $h, ?string $title, ?string $name): array
    {
        $title = trim((string) $title);
        $name = trim((string) $name);
        $date = Carbon::parse($h->date)->locale('en')->translatedFormat('l, j F Y');

        return ['{full}' => trim(($title !== '' ? $title . ' ' : '') . $name) ?: 'Friend', '{title}' => $title, '{name}' => $name, '{holiday}' => $h->name, '{year}' => (string) $h->year,
            '{date}' => $date, '{company}' => HolidaySettings::company(), '{signoff}' => HolidaySettings::signoff()];
    }

    public static function fill(string $text, array $vars): string
    {
        return strtr($text, $vars);
    }

    /** The SMS of one person: placeholders filled, kept to plain characters (an accent or a curly quote would turn a one-part SMS into three) and without links (the gateway refuses them). */
    public static function sms(HolidayDay $h, ?string $title, ?string $name, ?array $tpl = null): string
    {
        $tpl ??= self::effective($h);
        $text = self::fill($tpl['sms'], self::vars($h, $title, $name));

        return self::plain($text);
    }

    public static function plain(string $text): string
    {
        $text = strtr($text, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => ',', "\u{2026}" => '...', "\u{00A0}" => ' ']);
        $text = preg_replace('/https?:\/\/\S+/i', '', $text);
        $text = preg_replace('/[^\x{20}-\x{7E}\x{0A}]/u', '', $text); // emoji and other non-GSM characters
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim(preg_replace('/\s*\n\s*/', ' ', $text));
    }

    public static function smsParts(string $text): int
    {
        $n = mb_strlen($text);

        return $n <= 160 ? 1 : (int) ceil($n / 153);
    }

    /** All that the email view needs for one person. */
    public static function emailData(HolidayDay $h, ?string $title, ?string $name, ?array $tpl = null): array
    {
        $tpl ??= self::effective($h);
        $vars = self::vars($h, $title, $name);
        $theme = GreetingThemes::get($h->theme);
        [$head, $sub] = array_pad(array_map('trim', explode('|', self::fill($tpl['headline'], $vars), 2)), 2, '');
        $paragraphs = [];
        foreach (preg_split('/\R{2,}/', trim(self::fill($tpl['body'], $vars))) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $quote = Str::startsWith($p, '>');
            $paragraphs[] = ['text' => trim(ltrim($p, "> \t")), 'quote' => $quote];
        }
        $hasName = trim((string) $name) !== '';

        return [
            'subject' => self::fill($tpl['subject'], $vars), 'headline' => $head, 'subline' => $sub, 'paragraphs' => $paragraphs,
            'greeting' => $hasName ? __('Dear :who,', ['who' => $vars['{full}']]) : __('Dear Friend,'),
            'palette' => $theme['palette'], 'emoji' => $theme['emoji'], 'ornament' => $theme['ornament'], 'wishes' => $theme['wishes'] ?? [], 'icon' => $theme['icon'] ?? 'sparkle',
            'bg' => HolidayPhotos::background((int) ($h->account_id ?: 1), array_key_exists((string) $h->theme, GreetingThemes::all()) ? (string) $h->theme : 'generic', $theme['palette']['primary']),
            'dayName' => Carbon::parse($h->date)->locale('en')->translatedFormat('l'), 'dayNum' => Carbon::parse($h->date)->format('j'), 'monthName' => Carbon::parse($h->date)->locale('en')->translatedFormat('F Y'), 'monthOnly' => Carbon::parse($h->date)->locale('en')->translatedFormat('F'), 'holidayName' => $h->name, 'dateText' => $vars['{date}'],
            'signoff' => HolidaySettings::signoff(), 'company' => HolidaySettings::company(), 'year' => $h->year,
        ];
    }

    /** The complete email as HTML (the preview, and the body that is mailed when no mail message is passed to carry the embedded logo). */
    public static function emailHtml(HolidayDay $h, ?string $title, ?string $name, ?array $tpl = null): string
    {
        return view('emails.holiday-greeting', self::emailData($h, $title, $name, $tpl))->render();
    }

    /** A plain-text version of the email (mail clients and spam filters like having one). */
    public static function emailText(HolidayDay $h, ?string $title, ?string $name, ?array $tpl = null): string
    {
        $d = self::emailData($h, $title, $name, $tpl);
        $lines = [$d['headline'] . ($d['subline'] ? ' - ' . $d['subline'] : ''), '', $d['greeting'], ''];
        foreach ($d['paragraphs'] as $p) {
            $lines[] = ($p['quote'] ? '"' . $p['text'] . '"' : $p['text']);
            $lines[] = '';
        }
        $lines[] = __('With warm regards,');
        $lines[] = $d['signoff'];
        $lines[] = $d['company'];

        return implode("\n", $lines);
    }
}
