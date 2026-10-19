<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher {{ $payment['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">SUPPLIER PAYMENT VOUCHER</div>
        <div class="doc-ref">Voucher No.<br><strong>{{ $payment['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Paid To (Supplier)</td>
                <td class="value">{{ $supplier->name ?? 'N/A' }}</td>
                <td class="label">Date Paid</td>
                <td class="value">{{ \Illuminate\Support\Carbon::parse($payment['paid_on'])->format('d M Y') }}</td>
            </tr>
            <tr>
                <td class="label">Payment Method</td>
                <td class="value">{{ $payment['method'] }}{{ $payment['method'] === 'Cheque' && !empty($payment['cheque_number']) ? ': ' . $payment['cheque_number'] : '' }}</td>
                <td class="label">Reference / Txn No.</td>
                <td class="value">{{ $payment['reference_number'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Payment Made By</td>
                <td class="value">{{ $paidByName ?? '-' }}</td>
                <td class="label">Verification</td>
                <td class="value">
                    <span class="status-pill status-{{ ($payment['verification_status'] ?? '') === 'Verified' ? 'active' : 'warn' }}">{{ $payment['verification_status'] ?? 'Pending Verification' }}</span>
                    @if(!empty($payment['verified_by_name'])) by {{ $payment['verified_by_name'] }} @endif
                </td>
            </tr>
            @if($paymentRequest)
            <tr>
                <td class="label">Payment Request</td>
                <td class="value">{{ $paymentRequest['reference'] }}</td>
                <td class="label">Type</td>
                <td class="value">{{ ($paymentRequest['type'] ?? 'invoice') === 'balance' ? 'Account Balance Settlement' : 'Invoice Payment' }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="amount-card">
        <div class="label">Amount Paid</div>
        <div class="value">{{ number_format($payment['amount'], 2) }} {{ $payment['currency'] ?? 'TZS' }}</div>
    </div>

    <div class="card-title">Invoices Covered By This Payment</div>
    <table class="items">
        <thead>
            <tr>
                <th>Invoice No.</th>
                <th>Invoice Date</th>
                <th class="num">Invoice Total</th>
                <th class="num">Applied From This Payment</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payment['applied_invoices'] as $inv)
                <tr>
                    <td>{{ $inv['reference'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($inv['invoice_date'])->format('d M Y') }}</td>
                    <td class="num">{{ number_format($inv['total'], 2) }}</td>
                    <td class="num" style="font-weight:bold;">{{ number_format($inv['applied_amount'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; padding:10px;">No invoices linked to this payment.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($payment['remarks']))
    <div class="card" style="margin-top:14px;">
        <div class="card-title">Remarks</div>
        <div>{{ $payment['remarks'] }}</div>
    </div>
    @endif

    @if(!empty($signatures['enabled']))
        {{-- Prepared By / Paid By / Verified By (and Approved By once the payment request's approval was stamped) come from what WIMS recorded; the supplier signs by hand. --}}
        @include('partials.print.signature-block', ['sig' => $signatures])
        @php($__mapped = collect($signatures['slots'])->pluck('action')->all())
        <table class="signatures">
            <tr>
                <td style="width:33.33%;">
                    @if(!in_array('approved', $__mapped, true))<div class="line">Approved By</div>@endif
                </td>
                <td style="width:33.33%;"></td>
                <td style="width:33.33%;">
                    <div class="line">Received By (Supplier Signature)</div>
                </td>
            </tr>
        </table>
    @else
    <table class="signatures">
        <tr>
            <td style="width:33.33%;">
                <div class="line">Prepared By</div>
            </td>
            <td style="width:33.33%;">
                <div class="line">Approved By</div>
            </td>
            <td style="width:33.33%;">
                <div class="line">Received By (Supplier Signature)</div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Payment Voucher {{ $payment['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
