<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Addendum No. {{ $addendum->addendum_number }} &middot; {{ $warehouse['name'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.desc { max-width: 220px; }
        .reason-block { font-size: 10.3px; color: #374151; line-height: 1.5; }
    </style>
</head>
<body>
    @include('partials.print.letterhead', ['isPdf' => !empty($autoPrint) ? false : true, 'companyName' => $companyName])

    <div class="title-card">
        <div class="doc-title">CONTRACT ADDENDUM NO. {{ $addendum->addendum_number }}</div>
        <div class="doc-ref">
            Status<br>
            <span class="status-pill {{ $addendum->status === 'approved' ? 'status-active' : ($addendum->status === 'rejected' ? 'status-danger' : ($addendum->status === 'pending_approval' ? 'status-warn' : 'status-neutral')) }}">
                {{ strtoupper(str_replace('_', ' ', $addendum->status)) }}
            </span>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Project &amp; Contract</div>
        <table class="grid">
            <tr>
                <td class="label">Project</td><td class="value">{{ $warehouse['name'] }}</td>
                <td class="label">Project Code</td><td class="value">{{ $warehouse['code'] }}</td>
            </tr>
            <tr>
                <td class="label">Client</td><td class="value">{{ $contract->client?->name ?? '-' }}</td>
                <td class="label">Consultant</td><td class="value">{{ $contract->consultant?->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Contractor</td><td class="value">{{ $contract->contractorDisplayName() }}</td>
                <td class="label">Contract Ref.</td><td class="value">{{ $contract->contract_reference ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Original Contract Sum</td><td class="value">{{ $contract->currency }} {{ number_format($contract->original_contract_sum, 2) }}</td>
                <td class="label">Addendum Reference No.</td><td class="value">{{ $addendum->reference_number ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Addendum Details</div>
        <table class="grid">
            <tr>
                <td class="label">Title</td><td class="value" colspan="3">{{ $addendum->title }}</td>
            </tr>
            <tr>
                <td class="label">Addendum Date</td><td class="value">{{ optional($addendum->addendum_date)->format('d M Y') ?? '-' }}</td>
                <td class="label">Effective Date</td><td class="value">{{ optional($addendum->effective_date)->format('d M Y') ?? '-' }}</td>
            </tr>
            @if($addendum->reason)
            <tr>
                <td class="label">Reason / Purpose</td>
                <td class="value reason-block" colspan="3">{{ $addendum->reason }}</td>
            </tr>
            @endif
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 26px;">#</th>
                <th>Description</th>
                <th class="num" style="width: 70px;">Qty</th>
                <th style="width: 55px;">Unit</th>
                <th class="num" style="width: 90px;">Unit Rate</th>
                <th class="num" style="width: 100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="desc">{{ $item->description }}</td>
                    <td class="num">{{ number_format($item->quantity, 3) }}</td>
                    <td>{{ $item->unit ?? '-' }}</td>
                    <td class="num">{{ number_format($item->unit_rate, 2) }}</td>
                    <td class="num">{{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="amount-card">
        <div class="label">Total Addendum Value</div>
        <div class="value">{{ $contract->currency }} {{ number_format($addendum->total_amount, 2) }}</div>
    </div>

    @if($addendum->notes)
    <div class="card">
        <div class="card-title">Notes &amp; Remarks</div>
        <div class="reason-block">{{ $addendum->notes }}</div>
    </div>
    @endif

    @if(!empty($signatures['enabled']))
        {{-- prepared / approved signatures take the first two thirds, the typed client line keeps its third (a client is not a WIMS user) --}}
        <table style="width:100%; border-collapse:collapse; page-break-inside:avoid;">
            <tr>
                <td style="width:67%; vertical-align:top; padding:0;">
                    @include('partials.print.signature-block', ['sig' => $signatures])
                </td>
                <td style="width:33%; vertical-align:top; padding:0;">
                    <table class="signatures" style="margin-top:0;">
                        <tr>
                            <td style="padding-top:88px;">
                                <div class="line">Signed By</div>
                                <div class="role">(Client)</div>
                                <div class="who">{{ $addendum->signed_by ?? '-' }}</div>
                                <div class="when">{{ optional($addendum->signed_date)->format('d M Y') ?? '-' }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @else
    <table class="signatures">
        <tr>
            <td style="width:33%;">
                <div class="line">Prepared By</div>
                <div class="role">(Quantity Surveyor)</div>
                <div class="who">{{ $addendum->createdBy->name ?? '-' }}</div>
                <div class="when">{{ optional($addendum->created_at)->format('d M Y') }}</div>
            </td>
            <td style="width:33%;">
                <div class="line">Approved By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $addendum->approvedBy->name ?? '-' }}</div>
                <div class="when">{{ optional($addendum->approved_at)->format('d M Y') }}</div>
            </td>
            <td style="width:33%;">
                <div class="line">Signed By</div>
                <div class="role">(Client)</div>
                <div class="who">{{ $addendum->signed_by ?? '-' }}</div>
                <div class="when">{{ optional($addendum->signed_date)->format('d M Y') ?? '-' }}</div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="foot-cell" style="text-align:left;">
                {{ $companyName ?? 'RANS Company Limited' }} &middot; Addendum No. {{ $addendum->addendum_number }} ({{ $warehouse['name'] }})
                &middot; Generated {{ $generatedAt->format('d M Y H:i') }} by {{ $generatedBy ?? 'system' }}
            </td>
        </tr>
    </table>

    @if(!empty($autoPrint))
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 250);
            });
        </script>
    @endif
</body>
</html>
