<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 18px; }
        .header { display: table; width: 100%; margin-bottom: 14px; }
        .header .left, .header .right { display: table-cell; vertical-align: top; }
        .header .right { text-align: right; }
        .header img { max-height: 70px; }
        h1 { font-size: 18px; margin: 8px 0 4px; color: #27447a; text-transform: uppercase; text-align: center; }
        .divider { border-bottom: 1px solid #e5e7eb; margin: 8px 0; }
        .meta { display: table; width: 100%; margin: 8px 0 14px; }
        .meta .col { display: table-cell; vertical-align: top; width: 50%; padding-right: 12px; }
        .meta strong { color: #111827; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th, table.items td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.items th { background: #27447a; color: #fff; font-weight: 600; font-size: 10.5px; }
        table.items td.right, table.items th.right { text-align: right; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 10px; text-align: center; }
        .details-block { margin-top: 12px; padding: 8px 10px; background: #f9fafb; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="left">
            @if(file_exists(public_path('images/logo.png')))
                <img src="{{ public_path('images/logo.png') }}" alt="Logo" />
            @endif
        </div>
        <div class="right">
            @if($type === 'transfer')
                <strong>{{ $record->fromWarehouse->name ?? '' }}</strong>
                @if(!empty($record->fromWarehouse->code)) ({{ $record->fromWarehouse->code }}) @endif
                <div>{{ $record->fromWarehouse->address ?? '' }}</div>
                <div>{{ $record->fromWarehouse->phone ?? '' }}</div>
            @else
                <strong>{{ $record->warehouse->name ?? '' }}</strong>
                @if(!empty($record->warehouse->code)) ({{ $record->warehouse->code }}) @endif
                <div>{{ $record->warehouse->address ?? '' }}</div>
                <div>{{ $record->warehouse->phone ?? '' }}</div>
                <div>{{ $record->warehouse->email ?? '' }}</div>
            @endif
        </div>
    </div>

    <div class="divider"></div>
    <h1>{{ ucfirst($type) }}</h1>

    <div class="meta">
        <div class="col">
            <div><strong>Reference:</strong> {{ $record->reference }}</div>
            <div><strong>Date:</strong> {{ optional($record->date)->format('Y-m-d') }}</div>
            <div><strong>Created at:</strong> {{ optional($record->created_at)->format('Y-m-d H:i') }}</div>
            @if($record->user)
                <div><strong>Person Responsible:</strong> {{ $record->user->name }}</div>
            @endif
        </div>
        <div class="col">
            @if($type === 'transfer')
                <div><strong>To:</strong> {{ $record->toWarehouse->name ?? '' }}</div>
                <div>{{ $record->toWarehouse->address ?? '' }}</div>
                <div>{{ $record->toWarehouse->phone ?? '' }}</div>
            @elseif($type === 'adjustment')
                <div><strong>Type:</strong> {{ $record->type ?? '' }}</div>
            @else
                @if($record->contact)
                    <div><strong>For:</strong> {{ $record->contact->name }}</div>
                    <div>{{ $record->contact->phone ?? '' }}</div>
                    <div>{{ $record->contact->email ?? '' }}</div>
                @endif
            @endif
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th class="right">Unit</th>
                <th class="right">Quantity</th>
                @if(in_array($type, ['checkout', 'checkin']))
                    <th class="right">Total</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @php($displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) ($companyName ?? '')))
            @forelse($record->items as $item)
                @if($item->variations && count($item->variations))
                    <tr>
                        <td colspan="{{ in_array($type, ['checkout', 'checkin']) ? 4 : 3 }}" style="font-weight:bold; background:#f3f4f6;">
                            {{ $item->item->name ?? '' }} ({{ $item->item->code ?? '' }})
                        </td>
                    </tr>
                    @foreach($item->variations as $variation)
                        <tr>
                            <td style="padding-left: 18px;">{{ $variation->name ?? ($variation->meta ?? '-') }}</td>
                            <td class="right">{{ $item->unit->code ?? '' }}</td>
                            <td class="right">{{ $variation->pivot->quantity ?? '' }}</td>
                            @if(in_array($type, ['checkout', 'checkin']))
                                <td class="right">{{ number_format(($variation->pivot->quantity ?? 0) * ($item->price ?? 0), 2) }}</td>
                            @endif
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td>{{ $item->item->name ?? '' }} ({{ $item->item->code ?? '' }})</td>
                        <td class="right">{{ $item->unit->code ?? '' }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        @if(in_array($type, ['checkout', 'checkin']))
                            <td class="right">{{ number_format(($item->quantity ?? 0) * ($item->price ?? 0), 2) }} Tsh</td>
                        @endif
                    </tr>
                @endif
            @empty
                <tr><td colspan="4" style="text-align:center; padding:14px;">No items.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($record->details))
        <div class="details-block">{{ $record->details }}</div>
    @endif

    <div class="footer">
        @if(!empty($displayCompanyName))
            {{ $displayCompanyName }} /
        @endif
        RANS WIMS, Generated {{ now()->format('Y-m-d H:i') }}. This is a computer generated document.
    </div>
</body>
</html>
