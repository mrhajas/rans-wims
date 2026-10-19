<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issue {{ $issue->issue_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 18px; }
        .header { text-align: center; margin-bottom: 14px; }
        .header img { max-height: 72px; }
        h1 { font-size: 18px; margin: 6px 0; color: #27447a; }
        .muted { color: #6b7280; }
        .grid { display: table; width: 100%; margin-bottom: 12px; }
        .grid .col { display: table-cell; width: 50%; vertical-align: top; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #27447a; color: #fff; font-weight: 600; font-size: 10.5px; }
        .mt { margin-top: 12px; }
    </style>
</head>
<body>
    @php($logoPath = public_path('images/logo.png'))
    @php($logoSrc = !empty($autoPrint) ? asset('images/logo.png') : $logoPath)

    <div class="header">
        @if(file_exists($logoPath))
            <img src="{{ $logoSrc }}" alt="Logo" />
        @endif
        <h1>Issue & Return - Issue Slip</h1>
        <div class="muted">Issue Number: {{ $issue->issue_number }}</div>
    </div>

    <div class="grid">
        <div class="col">
            <div><strong>Date:</strong> {{ optional($issue->issue_date)->format('Y-m-d') }}</div>
            <div><strong>Project(s) / Warehouse(s):</strong> {{ optional($issue->warehouse)->name }}</div>
            <div><strong>Work Activity:</strong> {{ $issue->project }}</div>
            <div><strong>Issued To:</strong> {{ $issue->issued_to }}</div>
            <div><strong>Contact:</strong> {{ $issue->contact_number }}</div>
        </div>
        <div class="col">
            <div><strong>Department:</strong> {{ $issue->department }}</div>
            <div><strong>Expected Return:</strong> {{ optional($issue->expected_return_date)->format('Y-m-d') }}</div>
            <div><strong>Status:</strong> {{ strtoupper(str_replace('_', ' ', $issue->status)) }}</div>
            <div><strong>Total Qty:</strong> {{ number_format((float) $issue->total_quantity, 2) }}</div>
            <div><strong>Balance Qty:</strong> {{ number_format((float) $issue->balance_quantity, 2) }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Unit</th>
                <th>Issued Qty</th>
                <th>Returned Qty</th>
                <th>Balance Qty</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($issue->items as $line)
                <tr>
                    <td>{{ optional($line->item)->name }} ({{ optional($line->item)->code }})</td>
                    <td>{{ optional($line->unit)->code }}</td>
                    <td>{{ number_format((float) $line->issued_quantity, 2) }}</td>
                    <td>{{ number_format((float) $line->returned_quantity, 2) }}</td>
                    <td>{{ number_format((float) $line->balance_quantity, 2) }}</td>
                    <td>{{ $line->remarks }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($issue->returns && $issue->returns->count())
        <h3 class="mt">Returns Timeline</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Returned By</th>
                    <th>Received By</th>
                    <th>Condition Summary</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($issue->returns as $ret)
                    <tr>
                        <td>{{ optional($ret->return_date)->format('Y-m-d') }}</td>
                        <td>{{ $ret->returned_by }}</td>
                        <td>{{ $ret->received_by }}</td>
                        <td>
                            @php($summary = $ret->items->groupBy('condition')->map(fn($g) => $g->sum('returning_qty')))
                            Good: {{ number_format((float) ($summary['good'] ?? 0), 2) }},
                            Damaged: {{ number_format((float) ($summary['damaged'] ?? 0), 2) }},
                            Lost: {{ number_format((float) ($summary['lost'] ?? 0), 2) }}
                        </td>
                        <td>{{ $ret->remarks }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Signatories module: the issuer's signature, only when the issue slip has been set up there. Nothing is printed otherwise. --}}
    @if(!empty($signatures['enabled']))
        <style>table.sg-block td { border-bottom: 0; }</style>
        @include('partials.print.signature-block', ['sig' => $signatures])
    @endif

    @if(!empty($autoPrint))
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 250);
            });
        </script>
    @endif
</body>
</html>
