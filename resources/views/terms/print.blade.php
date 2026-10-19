<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Terms & Conditions</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .terms-section { margin-bottom: 12px; }
        .terms-section-title { font-size: 11.5px; font-weight: bold; color: #1c3f66; margin: 0 0 4px; }
        .terms-section-body { font-size: 10.3px; color: #374151; line-height: 1.55; }
        .terms-sign { margin-top: 22px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10.3px; color: #374151; }
        .terms-sign strong { color: #1c3f66; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">TERMS &amp; CONDITIONS</div>
        <div class="doc-ref">Version<br><strong>{{ $version }}</strong></div>
    </div>

    @foreach(terms_sections($content) as $section)
        <div class="card terms-section">
            <div class="terms-section-title">{{ $section['title'] }}</div>
            <div class="terms-section-body">{!! $section['body'] !!}</div>
        </div>
    @endforeach

    <div class="terms-sign">
        <strong>WIMS Administration</strong><br>
        RANS: Workflow Integrated Management System
    </div>

    <table class="footer-row">
        <tr>
            <td class="qr-cell"></td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Terms & Conditions v{{ $version }}, Generated {{ now()->format('Y-m-d H:i') }} @if($generatedBy) by {{ $generatedBy }} @endif</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
