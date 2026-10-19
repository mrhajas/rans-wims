<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Item {{ $item->code }} - {{ $item->name }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #1c3f66; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print { .actions { display: none; } }
        table.hero { width: 100%; border-collapse: separate; border-spacing: 0; margin: 10px 0 12px; background: #eef3fb; border: 1px solid #d5e0f0; }
        table.hero td { padding: 12px 14px; vertical-align: middle; }
        .hero .kicker { font-size: 9px; letter-spacing: 1.5px; color: #6b7f9c; font-weight: bold; }
        .hero .name { font-size: 20px; font-weight: bold; color: #1c3f66; margin: 2px 0 6px; }
        .chip { display: inline-block; padding: 2px 9px; margin-right: 4px; font-size: 10px; font-weight: bold; border: 1px solid #c3d3ea; background: #fff; color: #1c3f66; }
        .codes { text-align: right; white-space: nowrap; }
        .codes img { vertical-align: middle; }
        .codes .cap { font-size: 9px; color: #6b7f9c; }
        table.tiles { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px; }
        table.tiles td { width: 25%; padding: 9px 10px; border: 1px solid #d5e0f0; background: #fff; vertical-align: top; }
        .tiles .k { font-size: 8.5px; letter-spacing: 1px; color: #6b7f9c; font-weight: bold; text-transform: uppercase; }
        .tiles .v { font-size: 14px; font-weight: bold; color: #1c3f66; white-space: nowrap; margin-top: 3px; }
        .tiles .v.money { color: #7f1d1d; }
        .yes { color: #047857; font-weight: bold; } .no { color: #64748b; }
        tr.total td { font-weight: bold; background: #eef3fb; border-top: 2px solid #1c3f66; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead', ['companyName' => $companyName ?? null, 'isPdf' => $isPdf])
    @php($yn = fn ($v) => $v ? '<span class="yes">Yes</span>' : '<span class="no">No</span>')
    @php($unit = optional($item->unit)->code ?: optional($item->unit)->name)
    @php($money = fn ($v) => number_format((float) $v, 2))

    <div class="title-card">
        <div class="doc-title">ITEM REPORT</div>
        <div class="doc-ref">Item Code<br><strong>{{ $item->code }}</strong></div>
    </div>

    <table class="hero">
        <tr>
            <td>
                <div class="kicker">ITEM</div>
                <div class="name">{{ $item->name }}</div>
                <span class="chip">Code: {{ $item->code }}</span>
                @if($item->sku)<span class="chip">SKU: {{ $item->sku }}</span>@endif
                @if($item->categories->count())<span class="chip">{{ $item->categories->first()->name }}</span>@endif
            </td>
            <td class="codes">
                <img src="{{ \App\Support\Code128::dataUri((string) $item->code, 3, 78) }}" style="width:{{ round(\App\Support\Code128::width((string) $item->code, 3) / 3 * 1.3) }}px;height:52px;" alt="Barcode" />
                &nbsp;
                <img src="{{ \App\Support\PrintQrCode::dataUri(route('items.show', $item->id), 5, 2) }}" style="width:62px;height:62px;" alt="QR" />
                <div class="cap">{{ $item->code }} &nbsp;&middot;&nbsp; Scan the QR code to open this item</div>
            </td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            <td><div class="k">Item Price</div><div class="v money">{{ $money($price) }} Tsh</div></td>
            <td><div class="k">Total Value</div><div class="v money">{{ $money($value) }} Tsh</div></td>
            <td><div class="k">Quantity</div><div class="v">{{ rtrim(rtrim(number_format($qty, 2), '0'), '.') }} {{ $unit }}</div></td>
            <td><div class="k">Project(s) / Warehouse(s)</div><div class="v">{{ $stock->count() }}</div></td>
        </tr>
    </table>

    <div class="card">
        <div class="card-title">Item Information</div>
        <table class="grid">
            <tr><td class="label">Category</td><td class="value">{{ optional($item->categories->get(0))->name ?: '-' }}</td><td class="label">Child Category</td><td class="value">{{ optional($item->categories->get(1))->name ?: '-' }}</td></tr>
            <tr><td class="label">Unit</td><td class="value">{{ optional($item->unit)->name ?: '-' }}</td><td class="label">Rack</td><td class="value">{{ $item->rack_location ?: '-' }}</td></tr>
            <tr><td class="label">Track Quantity</td><td class="value">{!! $yn($item->track_quantity) !!}</td><td class="label">Track Weight</td><td class="value">{!! $yn($item->track_weight) !!}</td></tr>
            <tr><td class="label">Track Serials</td><td class="value">{!! $yn($item->has_serials) !!}</td><td class="label">Symbology</td><td class="value">{{ $item->symbology ?: 'CODE128' }}</td></tr>
            @if($item->details)<tr><td class="label">Details</td><td class="value" colspan="3">{{ $item->details }}</td></tr>@endif
        </table>
    </div>

    @if($item->track_quantity)
    <div class="card">
        <div class="card-title">Stock by Project(s) / Warehouse(s)</div>
        <table class="items">
            <tr><th>No</th><th>Project(s) / Warehouse(s)</th><th>Code</th><th class="num">Quantity</th><th class="num">Value (Tsh)</th></tr>
            @forelse($stock as $i => $w)
                <tr><td>{{ $i + 1 }}</td><td>{{ $w['warehouse'] }}</td><td>{{ $w['code'] }}</td><td class="num">{{ rtrim(rtrim(number_format($w['quantity'], 2), '0'), '.') }} {{ $unit }}</td><td class="num">{{ $money($w['quantity'] * $price) }}</td></tr>
            @empty
                <tr><td colspan="5" style="text-align:center; color:#6b7280;">This item is not currently available in any warehouse.</td></tr>
            @endforelse
            @if($stock->count())
                <tr class="total"><td colspan="3">Total</td><td class="num">{{ rtrim(rtrim(number_format($qty, 2), '0'), '.') }} {{ $unit }}</td><td class="num">{{ $money($value) }}</td></tr>
            @endif
        </table>
    </div>
    @endif

    <table class="footer-row"><tr>
        <td class="qr-cell"><img src="{{ \App\Support\PrintQrCode::dataUri(route('items.show', $item->id)) }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to open the live item</div></td>
        <td style="text-align:right; font-size:10px; color:#6b7280; vertical-align:bottom;">Printed by {{ $printedBy }} &middot; {{ $printedAt }}<br>RANS WIMS / Item Report</td>
    </tr></table>

    @if(!empty($autoPrint))
    <div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
    @endif
</body>
</html>
