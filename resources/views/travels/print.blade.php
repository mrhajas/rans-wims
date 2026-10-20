<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Travel {{ $t->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; break-inside: avoid; }
        .note-box { border: 1px solid #d6dde6; border-radius: 4px; padding: 7px 10px; font-size: 10px; color: #334155; margin-top: 10px; }
        .note-box b { color: #1c3f66; }
        .sec-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #1c3f66; margin: 12px 0 4px; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php($statusClass = $t->status === 'Approved' ? 'active' : ($t->status === 'Pending Approval' ? 'warn' : 'inactive'))
    @php($money = fn ($v) => number_format((float) $v, 2, '.', ','))
    @php($flight = trim(($t->airline ?: '') . ' ' . ($t->flight_number ?: '')))
    @php($retFlight = trim(($t->return_airline ?: $t->airline ?: '') . ' ' . ($t->return_flight_number ?: '')))
    <div class="title-card">
        <div class="doc-title">TRAVEL RECORD</div>
        <div class="doc-ref">Reference<br><strong>{{ $t->reference }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Traveller(s)</td><td class="value">{{ $t->travellers->pluck('name')->implode(', ') }}</td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $t->status }}</span></td>
            </tr>
            <tr>
                <td class="label">From</td><td class="value">{{ $t->from_place ?: '-' }}</td>
                <td class="label">To</td><td class="value"><strong>{{ $t->to_place }}</strong></td>
            </tr>
            <tr>
                <td class="label">Departs</td><td class="value">{{ $t->depart_date->format('d M Y') }}</td>
                <td class="label">Returns</td><td class="value">{{ $t->trip_type === 'return' && $t->return_date ? $t->return_date->format('d M Y') : ($t->trip_type === 'one_way' ? 'One way' : '-') }}</td>
            </tr>
            <tr>
                <td class="label">Kind of travel</td><td class="value">{{ $t->travel_type ?: '-' }}</td>
                <td class="label">Ticket booked on</td><td class="value">{{ $t->booking_date ? $t->booking_date->format('d M Y') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Flight</td><td class="value">{{ $flight ?: '-' }}</td>
                <td class="label">Return flight</td><td class="value">{{ $t->trip_type === 'return' ? ($retFlight ?: '-') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Booking reference</td><td class="value">{{ $t->booking_reference ?: '-' }}</td>
                <td class="label">Recorded by</td><td class="value">{{ optional($t->user)->name ?? '-' }}</td>
            </tr>
            @if($t->purpose)
            <tr><td class="label">Purpose</td><td class="value" colspan="3">{{ $t->purpose }}</td></tr>
            @endif
        </table>
    </div>

    <div class="sec-title">Ticket and visa ({{ $t->currency }})</div>
    <table class="grid" style="margin-bottom:6px;">
        <tr>
            <td class="label">Ticket issued by</td><td class="value">{{ optional($t->ticketAgent)->name ?: '-' }}@if(optional($t->ticketAgent)->phone) ({{ $t->ticketAgent->phone }})@endif</td>
            <td class="label">Visa</td><td class="value">@if($t->has_visa){{ trim(($t->visa_type ?: 'Yes') . ($t->visa_country ? ', ' . $t->visa_country : '')) }}@else No visa @endif</td>
        </tr>
        @if($t->has_visa)
        <tr>
            <td class="label">Visa issued by</td><td class="value">{{ optional($t->visaAgent)->name ?: '-' }}@if(optional($t->visaAgent)->phone) ({{ $t->visaAgent->phone }})@endif</td>
            <td class="label">Visa reference</td><td class="value">{{ $t->visa_reference ?: '-' }}</td>
        </tr>
        @endif
    </table>

    <table class="items">
        <thead><tr><th style="width:26px;">#</th><th>Traveller</th><th>Ticket number</th><th class="num">Ticket</th>@if($t->has_visa)<th class="num">Visa</th>@endif<th class="num">Total</th></tr></thead>
        <tbody>
            @foreach($t->travellers as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $p->name }}@if($p->phone) <span style="color:#94a3b8">({{ $p->phone }})</span>@endif</td>
                    <td>{{ $p->ticket_number ?: '-' }}</td>
                    <td class="num">{{ $money($p->ticket_amount) }}</td>
                    @if($t->has_visa)<td class="num">{{ $money($p->visa_amount) }}</td>@endif
                    <td class="num">{{ $money($p->ticket_amount + $p->visa_amount) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="text-align:right;font-weight:bold;">Total {{ $t->currency }}</td>
                <td class="num" style="font-weight:bold;">{{ $money($t->ticket_amount) }}</td>
                @if($t->has_visa)<td class="num" style="font-weight:bold;">{{ $money($t->visa_amount) }}</td>@endif
                <td class="num" style="font-weight:bold;">{{ $money($t->total_amount) }}</td>
            </tr>
        </tbody>
    </table>

    @if($t->currency !== 'TZS')
        <div class="note-box"><b>Exchange rate:</b> 1 {{ $t->currency }} = TZS {{ rtrim(rtrim(number_format((float) $t->exchange_rate, 6, '.', ','), '0'), '.') }} &nbsp; (ticket and visa in TZS: {{ $money($t->travelTotalTzs()) }})</div>
    @endif
    @if($t->items->count())
        <div class="sec-title">Other costs</div>
        <table class="items">
            <thead><tr><th style="width:26px;">#</th><th>Type of cost</th><th>Description</th><th>Owed to</th><th>Cur.</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">In TZS</th></tr></thead>
            <tbody>
                @foreach($t->items as $i => $it)
                    <tr>
                        <td>{{ $i + 1 }}</td><td>{{ $it->category }}</td><td>{{ $it->description ?: '-' }}</td><td>{{ optional($it->contact)->name ?: 'Company expense' }}</td>
                        <td>{{ $it->currency }}</td><td class="num">{{ $it->currency === 'TZS' ? '-' : rtrim(rtrim(number_format((float) $it->exchange_rate, 6, '.', ','), '0'), '.') }}</td>
                        <td class="num">{{ $money($it->amount) }}</td><td class="num">{{ $money($it->amount_tzs) }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="7" style="text-align:right;">Other costs (TZS)</td><td class="num">{{ $money($t->itemsTotalTzs()) }}</td></tr>
                <tr><td colspan="7" style="text-align:right;">Ticket and visa (TZS)</td><td class="num">{{ $money($t->travelTotalTzs()) }}</td></tr>
                <tr><td colspan="7" style="text-align:right;font-weight:bold;">All costs of the travel (TZS)</td><td class="num" style="font-weight:bold;">{{ $money($t->travelTotalTzs() + $t->itemsTotalTzs()) }}</td></tr>
            </tbody>
        </table>
    @endif

    @if($showAgentBalance && $t->status === 'Approved')
        @php($owed = $t->ticketAgent ? \App\Services\Travels\TravelLedger::owed((int) $t->ticketAgent->id) : [])
        @if(isset($owed[$t->currency]))
            <div class="note-box"><b>Owed to {{ $t->ticketAgent->name }} (all travels, {{ $t->currency }}):</b> {{ $money($owed[$t->currency]) }} at {{ now()->format('d M Y') }}</div>
        @endif
    @endif
    @if($t->status === 'Rejected' && $t->decision_remarks)
        <div class="note-box"><b>Rejected:</b> {{ $t->decision_remarks }}</div>
    @elseif($t->status === 'Cancelled' && $t->cancel_reason)
        <div class="note-box"><b>Cancelled:</b> {{ $t->cancel_reason }}</div>
    @elseif($t->status === 'Approved' && $t->decision_remarks)
        <div class="note-box"><b>Approval remarks:</b> {{ $t->decision_remarks }}</div>
    @endif
    @if($t->notes)
        <div class="note-box"><b>Notes:</b> {{ $t->notes }}</div>
    @endif
    @if(count($files))
        <div class="note-box"><b>Attached documents:</b> {{ implode(', ', $files) }}</div>
    @endif

    <table class="signatures">
        <tr>
            <td style="width:33.33%;"><div class="line">Prepared By</div><div style="font-size:9px;color:#64748b">{{ optional($t->user)->name }}</div></td>
            <td style="width:33.33%;"><div class="line">Approved By</div><div style="font-size:9px;color:#64748b">{{ optional($t->decidedBy)->name }}@if($t->decided_at && $t->status === 'Approved') / {{ $t->decided_at->format('d M Y') }}@endif</div></td>
            <td style="width:33.33%;"><div class="line">Traveller</div></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / Travel {{ $t->reference }}, Printed by {{ $printedBy }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
