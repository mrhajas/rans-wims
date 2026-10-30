<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $kindLabel }} {{ $card->number }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        {!! $cardCss !!}
        .ip-show { position: relative; width: 120mm; height: 87mm; margin: 4mm auto 2mm; }
        .ip-slot { position: absolute; top: 0; width: 55mm; height: 85mm; }
        .ip-cap { position: absolute; top: 86mm; width: 55mm; text-align: center; font-size: 7px; color: #94a3b8; text-transform: uppercase; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">{{ strtoupper($kindLabel) }}</div>
        <div class="doc-ref">ID number<br><strong>{{ $card->number }}</strong></div>
    </div>

    <div class="ip-show">
        <div class="ip-slot" style="left:0">{!! $front !!}</div>
        <div class="ip-slot" style="left:65mm">{!! $back !!}</div>
        <div class="ip-cap" style="left:0">Front</div>
        <div class="ip-cap" style="left:65mm">Back</div>
    </div>

    <div class="card">
        <table class="grid">
            <tr><td class="label">Holder</td><td class="value">{{ $holder }}</td><td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $card->effective_status }}</span></td></tr>
            <tr><td class="label">Category</td><td class="value">{{ optional($card->category)->name }}</td><td class="label">Title / work</td><td class="value">{{ $card->title ?: '-' }}</td></tr>
            <tr><td class="label">Issued</td><td class="value">{{ optional($card->issued_at)->format('d M Y') }}</td><td class="label">Valid until</td><td class="value">{{ $card->expires_at ? $card->expires_at->format('d M Y') : $noExpiry }}</td></tr>
            @if($card->staff)<tr><td class="label">WIMS staff reference</td><td class="value">{{ $card->staff->staff_number }}</td><td class="label">Phone</td><td class="value">{{ $card->phone ?: '-' }}</td></tr>@endif
            @if($card->vehicle_registration || $card->authorisation_type)<tr><td class="label">Vehicle</td><td class="value">{{ $card->vehicle_registration ?: '-' }} {{ $card->vehicle_description }}</td><td class="label">Authorisation</td><td class="value">{{ $card->authorisation_type ?: '-' }}</td></tr>@endif
            @if($card->host_name || $card->purpose)<tr><td class="label">Person visited</td><td class="value">{{ $card->host_name ?: '-' }}</td><td class="label">Purpose</td><td class="value">{{ $card->purpose ?: '-' }}</td></tr>@endif
            <tr><td class="label">Printed times</td><td class="value">{{ $card->print_count }}</td><td class="label">Printed by / on</td><td class="value">{{ $printedBy }} / {{ $printedAt }}</td></tr>
        </table>
    </div>
    <div style="font-size:8.5px;color:#64748b;margin-top:6px">The cards above are shown at their real size (55 x 85 mm). To print the card itself, use the A4 sheet or the card-size PDF.</div>
</body>
</html>
