<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contract {{ $contract['contract_number'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .state { display: inline-block; padding: 2px 9px; border-radius: 9px; font-size: 9px; font-weight: bold; }
        .st-ok, .st-open { background: #dcfce7; color: #166534; } .st-soon { background: #fef3c7; color: #92400e; }
        .st-urgent, .st-expired { background: #fee2e2; color: #991b1b; } .st-renewed, .st-ended { background: #e2e8f0; color: #475569; }
        .note { white-space: pre-line; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '-';
        $label = ['open' => 'Open ended', 'ok' => 'Active', 'soon' => 'Ending soon', 'urgent' => 'Ending very soon', 'expired' => 'Expired', 'ended' => 'Ended', 'renewed' => 'Renewed'][$contract['state']] ?? $contract['status'];
        $left = $contract['days_left'];
    @endphp

    <div class="title-card">
        <div class="doc-title">EMPLOYMENT CONTRACT RECORD</div>
        <div class="doc-ref">Contract No.<br><strong>{{ $contract['contract_number'] }}</strong></div>
    </div>

    <div class="card">
        <div class="card-title">Staff Member</div>
        @if(!empty($photoData))<img src="{{ $photoData }}" alt="" style="float:right; width:62px; height:74px; border:2px solid #1c3f66; border-radius:8px;" />@endif
        <table class="grid">
            <tr>
                <td class="label">Name</td><td class="value">{{ $staff['name'] }}</td>
                <td class="label">Staff No.</td><td class="value">{{ $staff['staff_number'] }}</td>
            </tr>
            <tr>
                <td class="label">Position</td><td class="value">{{ $contract['position'] ?: ($staff['position'] ?? '-') }}</td>
                <td class="label">Employment</td><td class="value">{{ $staff['employment_type'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Mobile No.</td><td class="value">{{ $staff['mobile_no'] ?? '-' }}</td>
                <td class="label">Personal Email</td><td class="value">{{ $staff['personal_email'] ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Contract Terms</div>
        <table class="grid">
            <tr>
                <td class="label">Type</td><td class="value">{{ $contract['type'] }}</td>
                <td class="label">Status</td>
                <td class="value"><span class="state st-{{ $contract['state'] }}">{{ $label }}</span>@if($contract['status'] === 'Active' && $left !== null) &nbsp; {{ $left < 0 ? abs($left).' day(s) ago' : ($left === 0 ? 'ends today' : $left.' day(s) left') }}@endif</td>
            </tr>
            <tr>
                <td class="label">Start Date</td><td class="value">{{ $fmt($contract['start_date']) }}</td>
                <td class="label">End Date</td><td class="value">{{ $contract['end_date'] ? $fmt($contract['end_date']) : ($contract['type'] === 'Permanent' ? 'None (Permanent contract)' : 'Open ended') }}</td>
            </tr>
            <tr>
                <td class="label">Signed On</td><td class="value">{{ $fmt($contract['signed_date']) }}</td>
                <td class="label">Basic Salary (TZS)</td><td class="value">{{ $contract['basic_salary'] !== null ? number_format($contract['basic_salary'], 2) : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Probation</td><td class="value">{{ $contract['probation_months'] !== null ? $contract['probation_months'].' month(s)' : '-' }}</td>
                <td class="label">Notice Period</td><td class="value">{{ $contract['notice_period_days'] !== null ? $contract['notice_period_days'].' day(s)' : '-' }}</td>
            </tr>
            @if($contract['stopped_reason_type'])
            <tr><td class="label" style="color:#9a3412;">Stopped</td><td class="value" colspan="3" style="color:#9a3412;">{{ $contract['stopped_reason_type'] }}@if($contract['stopped_on']), effective {{ $fmt($contract['stopped_on']) }}@endif @if($contract['stopped_reason'])<br><span style="font-weight:normal;">{{ $contract['stopped_reason'] }}</span>@endif</td></tr>
            @endif
            @if($contract['renewed_from_number'])
            <tr><td class="label">Renews</td><td class="value" colspan="3">{{ $contract['renewed_from_number'] }}</td></tr>
            @endif
            @if($contract['notes'])
            <tr><td class="label">Notes</td><td class="value note" colspan="3">{{ $contract['notes'] }}</td></tr>
            @endif
        </table>
    </div>

    <div class="card-title" style="margin-top:2px;">Attached Documents</div>
    <table class="items">
        <thead><tr><th>#</th><th>Document</th></tr></thead>
        <tbody>
            @forelse($contract['attachments'] as $i => $a)
                <tr><td>{{ $i + 1 }}</td><td>{{ $a['name'] }}</td></tr>
            @empty
                <tr><td colspan="2" style="color:#6b7280;">No document is attached to this contract.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="card-title" style="margin-top:10px;">Contract History</div>
    <table class="items">
        <thead><tr><th>Contract</th><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($history as $h)
                <tr @if($h['id'] === $contract['id']) style="font-weight:bold;" @endif>
                    <td>{{ $h['contract_number'] }}</td><td>{{ $h['type'] }}</td><td>{{ $fmt($h['start_date']) }}</td><td>{{ $h['end_date'] ? $fmt($h['end_date']) : ($h['type'] === 'Permanent' ? 'Permanent' : 'Open ended') }}</td><td>{{ $h['status'] }}{{ $h['stopped_reason_type'] ? ' ('.$h['stopped_reason_type'].')' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td width="33%"><div class="line">Employee</div><div class="role">Signature and date</div><div class="who">{{ $staff['name'] }}</div></td>
            <td width="33%"><div class="line">Human Resources</div><div class="role">Signature and date</div></td>
            <td width="33%"><div class="line">Authorised Signatory</div><div class="role">Signature, stamp and date</div></td>
        </tr>
    </table>

    @if(!empty($signatures['enabled']))
        {{-- Only the "prepared" action is recorded for a contract. The three columns above are physical signatures and are never replaced. --}}
        @include('partials.print.signature-block', ['sig' => $signatures])
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to open the staff record</div></td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Contract {{ $contract['contract_number'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
