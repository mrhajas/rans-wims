<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Record {{ $staff['staff_number'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .profile { width: 100%; border-collapse: collapse; margin-bottom: 14px; page-break-inside: avoid; break-inside: avoid; }
        .profile td { vertical-align: top; }
        .photo-cell { width: 128px; }
        .photo-frame { width: 112px; height: 132px; border: 3px solid #1c3f66; border-radius: 12px; overflow: hidden; background: #eef2f7; text-align: center; }
        .photo-frame img { width: 112px; height: 132px; }
        .photo-frame .initials { display: block; padding-top: 44px; font-size: 34px; font-weight: bold; color: #1c3f66; }
        .who .name { font-size: 21px; font-weight: bold; color: #0f2540; margin: 0 0 2px; }
        .who .role { font-size: 12px; color: #475569; margin: 0 0 8px; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 9.5px; font-weight: bold; margin: 0 4px 4px 0; }
        .p-green { background: #d1fae5; color: #047857; } .p-amber { background: #fef3c7; color: #b45309; } .p-violet { background: #ede9fe; color: #5b21b6; } .p-red { background: #ffe4e6; color: #be123c; } .p-slate { background: #f1f5f9; color: #475569; } .p-blue { background: #dbeafe; color: #1e40af; }
        table.facts { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin: 8px -5px 0; }
        table.facts td { width: 25%; padding: 6px 8px; border-radius: 8px; background: #f1f5f9; border: 1px solid #e2e8f0; }
        table.facts .k { display: block; font-size: 8px; font-weight: bold; color: #64748b; text-transform: uppercase; }
        table.facts .v { display: block; font-size: 11.5px; font-weight: bold; color: #0f2540; margin-top: 1px; }
        .signatures { margin-top: 12px !important; } .signatures td { padding-top: 12px !important; }
        .card { margin-bottom: 10px; padding: 10px 14px; }
        .qr-box { width: 92px; text-align: center; } .qr-box img { width: 78px; height: 78px; } .qr-box div { font-size: 7.5px; color: #64748b; }
        .retired-card { border: 1.5px solid #f59e0b; background: #fffbeb; }
        .two { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 0 -10px; } .two > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
        .st-ok, .st-open { background: #dcfce7; color: #166534; } .st-soon { background: #fef3c7; color: #92400e; } .st-urgent, .st-expired { background: #fee2e2; color: #991b1b; } .st-renewed, .st-ended { background: #e2e8f0; color: #475569; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '-';
        $initials = collect(explode(' ', trim($staff['name'])))->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
        $statusPill = ['Active' => 'p-green', 'Retired Active' => 'p-amber', 'Retired' => 'p-violet', 'Terminated' => 'p-red', 'Inactive' => 'p-slate'][$staff['status']] ?? 'p-slate';
        $cur = collect($contracts)->firstWhere('status', 'Active');
        $isRetired = in_array($staff['status'], ['Retired', 'Retired Active'], true);
    @endphp

    <div class="title-card">
        <div class="doc-title">STAFF RECORD</div>
        <div class="doc-ref">Staff No.<br><strong>{{ $staff['staff_number'] }}</strong></div>
    </div>

    <table class="profile">
        <tr>
            <td class="photo-cell">
                <div class="photo-frame">
                    @if(!empty($photoData))
                        <img src="{{ $photoData }}" alt="Photo" />
                    @else
                        <span class="initials">{{ $initials }}</span>
                    @endif
                </div>
            </td>
            <td class="who">
                <div class="name">{{ $staff['name'] }}</div>
                <div class="role">{{ $staff['position'] ?: 'No position set' }}@if(!empty($staff['qualification'])) &nbsp;·&nbsp; {{ $staff['qualification'] }}@endif</div>
                <span class="pill {{ $statusPill }}">{{ $staff['status'] }}</span>
                @if($staff['employment_type'])<span class="pill p-violet">{{ $staff['employment_type'] }}</span>@endif
                @if($cur)<span class="pill {{ in_array($cur['state'], ['soon']) ? 'p-amber' : (in_array($cur['state'], ['urgent', 'expired']) ? 'p-red' : 'p-blue') }}">{{ $cur['type'] }} contract</span>@endif
                <table class="facts">
                    <tr>
                        <td><span class="k">Join date</span><span class="v">{{ $fmt($staff['join_date']) }}</span></td>
                        <td><span class="k">{{ $isRetired ? 'Years of service' : 'Service so far' }}</span><span class="v">{{ $service ?: '-' }}</span></td>
                        <td><span class="k">Net salary (TZS)</span><span class="v">{{ number_format($staff['net_salary'], 2) }}</span></td>
                        <td><span class="k">Contract</span><span class="v">@if($cur){{ $cur['end_date'] ? 'Ends '.$fmt($cur['end_date']) : ($cur['type'] === 'Permanent' ? 'Permanent' : 'Open ended') }}@else None @endif</span></td>
                    </tr>
                </table>
            </td>
            <td class="qr-box"><img src="{{ $qr }}" alt="QR" /><div>Scan to open this record</div></td>
        </tr>
    </table>

    @if($isRetired)
    <div class="card retired-card">
        <div class="card-title" style="color:#b45309;">Retirement</div>
        <table class="grid">
            <tr>
                <td class="label">Status</td><td class="value">{{ $staff['status'] === 'Retired Active' ? 'Retired, still on payroll' : 'Retired, off payroll' }}</td>
                <td class="label">Retired on</td><td class="value">{{ $fmt($staff['retired_at'] ?? null) }}</td>
            </tr>
            @if(!empty($staff['retirement_note']))<tr><td class="label">Note</td><td class="value" colspan="3">{{ $staff['retirement_note'] }}</td></tr>@endif
        </table>
    </div>
    @endif

    <table class="two"><tr>
        <td>
            <div class="card">
                <div class="card-title">Personal Information</div>
                <table class="grid">
                    <tr><td class="label" style="width:92px;">Gender</td><td class="value">{{ $staff['gender'] ?? '-' }}</td></tr>
                    <tr><td class="label">Date of birth</td><td class="value">{{ $fmt($staff['date_of_birth']) }}</td></tr>
                    <tr><td class="label">Qualification</td><td class="value">{{ $staff['qualification'] ?? '-' }}</td></tr>
                    <tr><td class="label">Employment</td><td class="value">{{ $staff['employment_type'] ?? '-' }}</td></tr>
                </table>
            </div>
        </td>
        <td>
            <div class="card">
                <div class="card-title">Contact Details</div>
                <table class="grid">
                    <tr><td class="label" style="width:92px;">@include('print.icon', ['name' => 'phone', 'color' => '#475569', 'size' => 10, 'style' => 'margin-right:4px;'])Mobile</td><td class="value">{{ $staff['mobile_no'] ?? '-' }}</td></tr>
                    <tr><td class="label">@include('print.icon', ['name' => 'mail', 'color' => '#475569', 'size' => 10, 'style' => 'margin-right:4px;'])Email</td><td class="value">{{ $staff['personal_email'] ?? '-' }}</td></tr>
                    <tr><td class="label">@include('print.icon', ['name' => 'map-pin', 'color' => '#475569', 'size' => 10, 'style' => 'margin-right:4px;'])Address</td><td class="value">{{ $staff['address'] ?? '-' }}</td></tr>
                    <tr><td class="label">Next of kin</td><td class="value">{{ $staff['father_name'] ?? '-' }}@if(!empty($staff['father_contact_no'])) ({{ $staff['father_contact_no'] }})@endif</td></tr>
                </table>
            </div>
        </td>
    </tr></table>

    <div class="card">
        <div class="card-title">Bank and Statutory Details (TIN, ZSSF)</div>
        <table class="grid">
            <tr>
                <td class="label">Bank</td><td class="value">{{ $staff['bank_name'] ?? '-' }}</td>
                <td class="label">Account holder</td><td class="value">{{ $staff['account_holder_name'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Account No.</td><td class="value">{{ $staff['account_no'] ?? '-' }}</td>
                <td class="label">TIN</td><td class="value">{{ $staff['tin_number'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">ZSSF No.</td><td class="value" colspan="3">{{ $staff['nssf_number'] ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card-title" style="margin-top:2px;">Employment &amp; Salary</div>
    <table class="items">
        <thead><tr><th>Basic Salary</th><th>Allowances</th><th>Deductions</th><th class="num">Net Salary (TZS)</th></tr></thead>
        <tbody><tr>
            <td>{{ number_format($staff['basic_salary'], 2) }}</td><td>{{ number_format($staff['allowances'], 2) }}</td><td>{{ number_format($staff['deductions'], 2) }}</td>
            <td class="num" style="font-weight:bold; color:#15803d;">{{ number_format($staff['net_salary'], 2) }}</td>
        </tr></tbody>
    </table>

    @if(count($contracts))
    <div class="card-title" style="margin-top:14px;">Contracts (newest first, previous contracts are kept)</div>
    <table class="items">
        <thead><tr><th>Contract</th><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($contracts as $c)
                <tr @if($c['status'] === 'Active') style="font-weight:bold;" @endif>
                    <td>{{ $c['contract_number'] }}@if($c['renewed_from_number']) <span style="color:#64748b; font-weight:normal;">(renews {{ $c['renewed_from_number'] }})</span>@endif</td>
                    <td>{{ $c['type'] }}</td>
                    <td>{{ $fmt($c['start_date']) }}</td>
                    <td>{{ $c['end_date'] ? $fmt($c['end_date']) : ($c['type'] === 'Permanent' ? 'Permanent' : 'Open ended') }}</td>
                    <td><span class="pill st-{{ $c['state'] }}" style="margin:0;">{{ ['open' => 'Open ended', 'ok' => 'Active', 'soon' => 'Ending soon', 'urgent' => 'Ending very soon', 'expired' => 'Expired', 'ended' => 'Ended', 'renewed' => 'Renewed'][$c['state']] ?? $c['status'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(!empty($history) && count($history))
    <div class="card-title" style="margin-top:14px;">Employment History (kept for good)</div>
    <table class="items">
        <thead><tr><th style="width:70px;">Date</th><th>What happened</th><th>Reason</th></tr></thead>
        <tbody>
            @foreach($history as $h)
                <tr>
                    <td>{{ $fmt($h['occurred_on']) }}</td>
                    <td><strong>{{ $h['title'] }}</strong>@if($h['from_status'] && $h['to_status']) <span style="color:#64748b;">({{ $h['from_status'] }} to {{ $h['to_status'] }})</span>@endif</td>
                    <td>@if($h['reason_type'])<strong>{{ $h['reason_type'] }}</strong>@endif @if($h['reason']) {{ $h['reason'] }}@endif @if(!$h['reason_type'] && !$h['reason'])-@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(!empty($staff['attachments']) && count($staff['attachments']))
    <div class="card-title" style="margin-top:14px;">Attached Documents</div>
    <table class="items">
        <thead><tr><th style="width:26px;">#</th><th>Document</th></tr></thead>
        <tbody>@foreach($staff['attachments'] as $i => $a)<tr><td>{{ $i + 1 }}</td><td>{{ $a['name'] }}</td></tr>@endforeach</tbody>
    </table>
    @endif

    @if(!empty($signatures['enabled']) && !empty($signatures['slots']))
    {{-- Human Resources = the person who printed the profile (their registered signature); the Employee and Authorised lines stay blank for the wet signature --}}
    @include('partials.print.generated-signatures', ['signatures' => $signatures, 'caption' => 'Human Resources', 'cells' => [['line' => 'Employee', 'role' => 'Signature and date', 'who' => $staff['name']], ['line' => 'Authorised Signatory', 'role' => 'Signature, stamp and date', 'who' => '']]])
    @else
    <table class="signatures">
        <tr>
            <td width="33%"><div class="line">Employee</div><div class="role">Signature and date</div><div class="who">{{ $staff['name'] }}</div></td>
            <td width="33%"><div class="line">Human Resources</div><div class="role">Signature and date</div></td>
            <td width="33%"><div class="line">Authorised Signatory</div><div class="role">Signature, stamp and date</div></td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell" style="text-align:center;">{{ $companyName ?? 'RANS Company Limited' }} / Staff Record {{ $staff['staff_number'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
