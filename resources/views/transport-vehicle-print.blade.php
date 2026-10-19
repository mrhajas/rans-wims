<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle {{ $vehicle->asset_number }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '-';
        $stats = $vehicle->trip_stats ?? [];
    @endphp

    <div class="title-card">
        <div class="doc-title">VEHICLE RECORD</div>
        <div class="doc-ref">Vehicle No.<br><strong>{{ $vehicle->asset_number }}</strong></div>
    </div>

    <div class="card">
        <div class="card-title">Identification</div>
        <table class="grid">
            <tr>
                <td class="label">Name</td>
                <td class="value">{{ $vehicle->name }}</td>
                <td class="label">Registration No.</td>
                <td class="value">{{ $vehicle->registration_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Category</td>
                <td class="value">{{ optional($vehicle->category)->name ?? '-' }}</td>
                <td class="label">Status</td>
                <td class="value">{{ $vehicle->status }}</td>
            </tr>
            <tr>
                <td class="label">Make</td>
                <td class="value">{{ $vehicle->manufacturer ?? '-' }}</td>
                <td class="label">Model</td>
                <td class="value">{{ $vehicle->model ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Year</td>
                <td class="value">{{ $vehicle->year ?? '-' }}</td>
                <td class="label">Colour</td>
                <td class="value">{{ $vehicle->color ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Chassis / VIN</td>
                <td class="value">{{ $vehicle->vin_chassis_number ?? '-' }}</td>
                <td class="label">Engine No.</td>
                <td class="value">{{ $vehicle->engine_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Fuel Type</td>
                <td class="value">{{ $vehicle->fuel_type ?? '-' }}</td>
                <td class="label">Capacity</td>
                <td class="value">{{ $vehicle->capacity ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Driver</td>
                <td class="value">{{ optional($vehicle->operatingPersonnelContact)->name ?? '-' }}{{ optional($vehicle->operatingPersonnelContact)->phone ? ' (' . $vehicle->operatingPersonnelContact->phone . ')' : '' }}</td>
                <td class="label">Odometer</td>
                <td class="value">{{ $vehicle->current_odometer ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Registration &amp; Insurance</div>
        <table class="grid">
            <tr>
                <td class="label">Registration Expiry</td>
                <td class="value">{{ $d($vehicle->registration_expiry) }}</td>
                <td class="label">Inspection Expiry</td>
                <td class="value">{{ $d($vehicle->inspection_expiry) }}</td>
            </tr>
            <tr>
                <td class="label">Licence Expiry</td>
                <td class="value">{{ $d($vehicle->license_expiry) }}</td>
                <td class="label">Insurance Expiry</td>
                <td class="value">{{ $d($vehicle->insurance_expiry) }}</td>
            </tr>
            <tr>
                <td class="label">Insurer</td>
                <td class="value">{{ $vehicle->insurance_provider ?? '-' }}</td>
                <td class="label">Policy No.</td>
                <td class="value">{{ $vehicle->insurance_policy_number ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Trips &amp; Money</div>
        <table class="grid">
            <tr>
                <td class="label">Total Trips</td>
                <td class="value">{{ $stats['total_trips'] ?? 0 }}</td>
                <td class="label">Total Amount</td>
                <td class="value">{{ $money($stats['total_amount'] ?? 0) }}</td>
            </tr>
            <tr>
                <td class="label">Total Paid</td>
                <td class="value">{{ $money($stats['total_paid'] ?? 0) }}</td>
                <td class="label">Outstanding</td>
                <td class="value">{{ $money($stats['total_outstanding'] ?? 0) }}</td>
            </tr>
            @if((float) $vehicle->opening_balance)
            <tr>
                <td class="label">Opening Balance</td>
                <td class="value">{{ $money($vehicle->opening_balance) }}</td>
                <td class="label">As At</td>
                <td class="value">{{ $d($vehicle->opening_balance_date) }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if($vehicle->transportRecords && $vehicle->transportRecords->count())
    <div class="card">
        <div class="card-title">Recent Trips</div>
        <table class="items">
            <tr><th style="width:5%;">No.</th><th>Trip #</th><th>Date</th><th>Driver</th><th>Destination</th><th>Status</th><th class="num">Amount</th><th class="num">Paid</th></tr>
            @foreach($vehicle->transportRecords as $t)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $t->trip_number }}</td>
                <td>{{ optional($t->trip_date)->format('d M Y') }}</td>
                <td>{{ optional($t->driver)->name ?? '-' }}</td>
                <td>{{ $t->destination_label ?? optional($t->warehouse)->name ?? '-' }}</td>
                <td>{{ $t->payment_status }}</td>
                <td class="num">{{ $money($t->amount) }}</td>
                <td class="num">{{ $money($t->paid_amount) }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName }} &mdash; Vehicle Record {{ $vehicle->asset_number }} &mdash; Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
