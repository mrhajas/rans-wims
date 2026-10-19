<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trip {{ $record->trip_number }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '-';
        $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y, H:i') : '-';
    @endphp

    <div class="title-card">
        <div class="doc-title">TRANSPORT TRIP</div>
        <div class="doc-ref">Trip No.<br><strong>{{ $record->trip_number }}</strong></div>
    </div>

    <div class="card">
        <div class="card-title">Trip</div>
        <table class="grid">
            <tr>
                <td class="label">Date</td>
                <td class="value">{{ $d($record->trip_date) }}{{ $record->trip_time ? ', ' . substr($record->trip_time, 0, 5) : '' }}</td>
                <td class="label">Payment Status</td>
                <td class="value">{{ $record->payment_status }}</td>
            </tr>
            <tr>
                <td class="label">Vehicle</td>
                <td class="value">{{ optional($record->asset)->registration_number ?? '-' }}{{ optional($record->asset)->name ? ' - ' . $record->asset->name : '' }}</td>
                <td class="label">Driver</td>
                <td class="value">{{ optional($record->driver)->name ?? '-' }}{{ optional($record->driver)->phone ? ' (' . $record->driver->phone . ')' : '' }}</td>
            </tr>
            <tr>
                <td class="label">From</td>
                <td class="value">{{ $record->is_campus ? ($record->destination_label.($record->departure_location ? ', '.$record->departure_location : '')) : ($record->departure_label ?? $record->departure_location ?? '-') }}</td>
                <td class="label">To</td>
                <td class="value">{{ $record->is_campus ? ($record->destination_location ?: 'Same site campus') : ($record->destination_label ?? $record->destination_location ?? '-') }}</td>
            </tr>
            <tr>
                <td class="label">Destination Type</td>
                <td class="value">{{ $record->is_campus ? 'Within site campus' : ($record->destination_type ?? '-') }}</td>
                <td class="label">Delivery Note Ref.</td>
                <td class="value">{{ $record->delivery_note_ref ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Purpose</td>
                <td class="value" colspan="3">{{ $record->purpose ?: '-' }}</td>
            </tr>
            @if($record->transfer)
            <tr>
                <td class="label">Linked Transfer</td>
                <td class="value" colspan="3">{{ $record->transfer->reference }}@if($record->transfer->trashed()) (deleted)@endif</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="card">
        <div class="card-title">Money</div>
        <table class="grid">
            <tr>
                <td class="label">{{ $record->trips_count > 1 ? 'Total Amount' : 'Amount' }}</td>
                <td class="value">{{ $money($record->amount) }}@if($record->trips_count > 1) ({{ $record->trips_count }} trips x {{ $money($record->unit_price) }})@endif</td>
                <td class="label">Paid</td>
                <td class="value">{{ $money($record->paid_amount) }}</td>
            </tr>
            <tr>
                <td class="label">Outstanding</td>
                <td class="value">{{ $money($record->outstanding_amount) }}</td>
                <td class="label">Standard Amount</td>
                <td class="value">{{ $money($record->default_amount) }}{{ $record->amount_overridden ? ' (changed)' : '' }}</td>
            </tr>
            @if($record->amount_overridden && $record->amount_change_reason)
            <tr>
                <td class="label">Reason for Change</td>
                <td class="value" colspan="3">{{ $record->amount_change_reason }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">Payment Date</td>
                <td class="value">{{ $d($record->payment_date) }}</td>
                <td class="label">Method / Reference</td>
                <td class="value">{{ trim(($record->payment_method ?? '') . ' ' . ($record->payment_reference ?? '')) ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Payment Voucher</td>
                <td class="value">{{ optional($record->paymentVoucher)->pv_number ?? '-' }}</td>
                <td class="label">Paid By</td>
                <td class="value">{{ optional($record->paidBy)->name ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Control</div>
        <table class="grid">
            <tr>
                <td class="label">Recorded By</td>
                <td class="value">{{ optional($record->creator)->name ?? '-' }}</td>
                <td class="label">Recorded On</td>
                <td class="value">{{ $dt($record->created_at) }}</td>
            </tr>
            <tr>
                <td class="label">Verified By</td>
                <td class="value">{{ $record->verified_at ? (optional($record->verifiedBy)->name ?? '-') : 'Not verified' }}</td>
                <td class="label">Verified On</td>
                <td class="value">{{ $dt($record->verified_at) }}</td>
            </tr>
            @if($record->notes)
            <tr>
                <td class="label">Notes</td>
                <td class="value" colspan="3">{{ $record->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if(!empty($signatures['enabled']) && !empty($signatures['slots']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName }} &mdash; Trip {{ $record->trip_number }} &mdash; Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
