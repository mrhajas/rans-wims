@php
    // text for the page-margin footer of a browser print (CSS string, safely quoted)
    $cssq = fn ($s) => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], (string) $s).'"';
    $stampText = 'Printed by '.($printedBy ?? '—').'  ·  '.($printedAt ?? now())->format('d M Y, H:i');
@endphp
<style>
    @page { margin: 22px 26px 44px 26px; }
    body { margin: 0; }
    .printed-line { font-size: 8.5px; color: #64748b; text-align: right; margin: -8px 2px 8px; }
    .printed-line strong { color: #1c3f66; }
    .chip { display: inline-block; margin: 0 6px 4px 0; padding: 3px 10px; border-radius: 999px; font-size: 9.5px; font-weight: bold; }

    @if(! empty($isPdf))
    /* PDF: a fixed footer that repeats on every page */
    .pfoot { position: fixed; left: 0; right: 0; bottom: -30px; padding-top: 4px; border-top: 1px solid #cbd5e1; font-size: 8px; color: #64748b; }
    .pfoot table { width: 100%; border-collapse: collapse; }
    .pfoot td { padding: 0; }
    .pfoot .l { text-align: left; width: 38%; }
    .pfoot .c { text-align: center; width: 36%; color: #94a3b8; }
    .pfoot .r { text-align: right; width: 26%; }
    .pfoot .pgn:after { content: "Page " counter(page); }
    .pfoot strong { color: #334155; }
    @else
    /* browser print: the footer lives in the page margin (never on top of the content) and repeats on every sheet */
    .pfoot { display: none; }
    @page {
        @bottom-left { content: {!! $cssq($stampText) !!}; font-family: Helvetica, Arial, sans-serif; font-size: 8px; color: #475569; border-top: 1px solid #cbd5e1; padding-top: 4px; vertical-align: top; }
        @bottom-center { content: {!! $cssq(($companyName ?? 'RANS Company Limited').' · '.($footerRef ?? '')) !!}; font-family: Helvetica, Arial, sans-serif; font-size: 8px; color: #94a3b8; border-top: 1px solid #cbd5e1; padding-top: 4px; vertical-align: top; }
        @bottom-right { content: "Page " counter(page) " of " counter(pages); font-family: Helvetica, Arial, sans-serif; font-size: 8px; color: #475569; border-top: 1px solid #cbd5e1; padding-top: 4px; vertical-align: top; }
    }
    @endif
</style>
