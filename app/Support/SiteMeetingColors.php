<?php

namespace App\Support;

/** The soft-coded status colour names of Site Meetings (Settings) as print colours: [text, background]. */
class SiteMeetingColors
{
    private const PALETTE = [
        'sky' => ['#0369a1', '#e0f2fe'], 'green' => ['#047857', '#d1fae5'], 'amber' => ['#b45309', '#fef3c7'], 'red' => ['#b91c1c', '#fee2e2'],
        'indigo' => ['#4338ca', '#e0e7ff'], 'violet' => ['#6d28d9', '#ede9fe'], 'slate' => ['#475569', '#e2e8f0'], 'teal' => ['#0f766e', '#ccfbf1'], 'rose' => ['#be123c', '#ffe4e6'],
    ];

    public static function of(?string $name): array
    {
        return self::PALETTE[$name] ?? self::PALETTE['slate'];
    }

    /** Inline CSS for a status pill from a style row ['label' => …, 'color' => …]. */
    public static function pill(array $style): string
    {
        [$fg, $bg] = self::of($style['color'] ?? 'slate');

        return 'background:'.$bg.';color:'.$fg.';';
    }
}
