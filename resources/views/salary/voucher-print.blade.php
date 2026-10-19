<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Salary Voucher {{ $voucher['voucher_number'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">{{ $voucher['type'] === 'bulk' ? 'BULK SALARY VOUCHER' : 'SALARY VOUCHER' }}</div>
        <div class="doc-ref">Voucher No.<br><strong>{{ $voucher['voucher_number'] }}</strong></div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">Salary Period</td>
            <td class="value">{{ $voucher['period_label'] }}</td>
            <td class="label">Date</td>
            <td class="value">{{ $voucher['created_at'] }}</td>
        </tr>
        @if($voucher['type'] !== 'bulk')
        <tr>
            <td class="label">Employee</td>
            <td class="value">{{ $voucher['staff_name'] }}</td>
            <td class="label">Staff No.</td>
            <td class="value">{{ $voucher['staff_number'] }}</td>
        </tr>
        @endif
    </table>

    <br>

    @if($voucher['type'] === 'bulk')
        <table class="items">
            <thead>
                <tr>
                    <th style="width:34px;">SN</th>
                    <th>Employee</th>
                    <th>Voucher No.</th>
                    <th class="num">Net Salary (TZS)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($voucher['children'] as $i => $child)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $child['staff_name'] }}</td>
                        <td>{{ $child['voucher_number'] }}</td>
                        <td class="num">{{ number_format($child['net_salary'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <br>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Particulars</th>
                <th class="num">Amount (TZS)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Gross Earnings</td><td class="num">{{ number_format($voucher['gross_amount'], 2) }}</td></tr>
            <tr><td>Total Deductions</td><td class="num">({{ number_format($voucher['total_deductions'], 2) }})</td></tr>
            <tr><td><strong>Net Salary</strong></td><td class="num"><strong>{{ number_format($voucher['net_salary'], 2) }}</strong></td></tr>
            <tr><td>Previous Outstanding Balance</td><td class="num">{{ number_format($voucher['opening_outstanding_balance'], 2) }}</td></tr>
            <tr><td><strong>Current Payment</strong></td><td class="num"><strong>{{ number_format($voucher['current_payment'], 2) }}</strong></td></tr>
            <tr><td>Remaining Balance</td><td class="num">{{ number_format($voucher['remaining_balance'], 2) }}</td></tr>
        </tbody>
    </table>

    <div class="amount-card">
        <div class="label">Current Payment</div>
        <div class="value">TZS {{ number_format($voucher['current_payment'], 2) }}</div>
    </div>

    @if(!empty($voucher['payment_date']))
        <table class="grid">
            <tr>
                <td class="label">Payment Date</td>
                <td class="value">{{ $voucher['payment_date'] }}</td>
                <td class="label">Payment Method</td>
                <td class="value">{{ $voucher['payment_method'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Payment Reference</td>
                <td class="value" colspan="3">{{ $voucher['payment_reference'] ?? '-' }}</td>
            </tr>
        </table>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        {{-- the existing Paid By line stays unless the document setup maps the Paid action to a signing role --}}
        @if(!collect($signatures['slots'])->contains('action', 'paid'))
        <table class="signatures" style="margin-top:0; width:34%;">
            <tr>
                <td style="width:100%;">
                <div class="line">Paid By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['paid_by_name'] ?? '-' }}</div>
            </td>
            </tr>
        </table>
        @endif
    @else
    <table class="signatures">
        <tr>
            <td style="width:33%;">
                <div class="line">Prepared By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['prepared_by_name'] ?? '-' }}</div>
            </td>
            <td style="width:33%;">
                <div class="line">Approved By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['approved_by_name'] ?? '-' }}</div>
            </td>
            <td style="width:34%;">
                <div class="line">Paid By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['paid_by_name'] ?? '-' }}</div>
            </td>
        </tr>
    </table>
    @endif

    @if($voucher['printed_count'] > 1)
        <div style="text-align:center; margin-top:6px;">
            <span class="status-pill status-neutral">Reprint #{{ $voucher['printed_count'] }}</span>
        </div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Salary Voucher {{ $voucher['voucher_number'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

    @if(!$isPdf)
        <div class="print-hide" style="text-align:center; margin-top:18px;">
            <button onclick="window.print()" style="padding:8px 18px; background:#1c3f66; color:#fff; border:0; border-radius:6px; cursor:pointer; font-size:12px;">Print / Save as PDF</button>
        </div>
    @endif
</body>
</html>
