<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Labour List {{ $labourList['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">LABOUR LIST</div>
        <div class="doc-ref">Reference<br><strong>{{ $labourList['reference'] }}</strong></div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">Project / Site</td>
            <td class="value">{{ $labourList['warehouse_name'] }}</td>
            <td class="label">Payment Period</td>
            <td class="value">{{ $labourList['period_start'] }} – {{ $labourList['period_end'] }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td class="value">{{ $labourList['status'] }}</td>
            <td class="label">Submitted By</td>
            <td class="value">{{ $labourList['submitted_by_name'] ?? '-' }}</td>
        </tr>
    </table>

    <br>

    <table class="items">
        <thead>
            <tr>
                <th style="width:34px;">SN</th>
                <th>Labour Personnel</th>
                <th>Priority</th>
                <th>Work Performed</th>
                <th class="num">Days/Hrs</th>
                <th class="num">Rate</th>
                <th class="num">Amount (TZS)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($labourList['items'] as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['payee_name'] }}</td>
                    <td>{{ $item['priority'] ?? 'Normal' }}</td>
                    <td>{{ $item['work_description'] ?? '-' }}</td>
                    <td class="num">{{ $item['rate_type'] === 'day' ? $item['days'] : ($item['rate_type'] === 'hour' ? $item['hours'] : '-') }}</td>
                    <td class="num">{{ number_format($item['rate'], 2) }}</td>
                    <td class="num">
                        {{ number_format($item['final_amount'], 2) }}
                        @if($item['approved_amount'] !== null && (float) $item['approved_amount'] !== (float) $item['amount'])
                            <br><span style="font-size:8.5px; color:#9ca3af; text-decoration:line-through;">{{ number_format($item['amount'], 2) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php($labourListFinalTotal = collect($labourList['items'])->sum('final_amount'))
    <div class="amount-card">
        <div class="label">Total Labour Amount</div>
        <div class="value">TZS {{ number_format($labourListFinalTotal, 2) }}</div>
    </div>

    @if(!empty($labourList['description']))
        <div class="card">
            <div class="card-title">Description</div>
            <div>{{ $labourList['description'] }}</div>
        </div>
    @endif

    @if(!empty($labourList['review_remarks']))
        <div class="card">
            <div class="card-title">Latest Review Remarks</div>
            <div>{{ $labourList['review_remarks'] }}</div>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        <table class="signatures" style="margin-top:0; width:25%;">
            <tr>
                <td style="width:100%;">
                <div class="line">Payment Voucher</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $labourList['batch_number'] ?? '-' }}</div>
                <div class="when">&nbsp;</div>
            </td>
            </tr>
        </table>
    @else
    <table class="signatures">
        <tr>
            <td style="width:25%;">
                <div class="line">Submitted By</div>
                <div class="role">(Site Engineer)</div>
                <div class="who">{{ $labourList['submitted_by_name'] ?? '-' }}</div>
                <div class="when">{{ $labourList['submitted_at'] ?? '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Cleared By</div>
                <div class="role">(Project Manager)</div>
                <div class="who">{{ $labourList['cleared_by_name'] ?? '-' }}</div>
                <div class="when">{{ $labourList['cleared_at'] ?? '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Approved By</div>
                <div class="role">(Super Admin)</div>
                <div class="who">{{ $labourList['approved_by_name'] ?? '-' }}</div>
                <div class="when">{{ $labourList['approved_at'] ?? '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Payment Voucher</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $labourList['batch_number'] ?? '-' }}</div>
                <div class="when">&nbsp;</div>
            </td>
        </tr>
    </table>
    @endif

    <div style="text-align:center; margin-top:6px;">
        <span class="status-pill status-{{ $labourList['status'] === 'Payment Voucher Created' ? 'active' : ($labourList['status'] === 'Returned' ? 'danger' : 'warn') }}">
            {{ $labourList['status'] }}
        </span>
    </div>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">Labour List {{ $labourList['reference'] }}, Generated {{ $printedAt }} by {{ $printedBy }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
