<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project(s) / Warehouse(s) Report</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; }
        .header { margin-bottom: 24px; }
        .logo-wrap { margin-bottom: 18px; }
        .logo { max-height: 64px; display: block; }
        .title { font-size: 22px; font-weight: 700; }
        .meta { font-size: 12px; color: #4b5563; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; font-size: 12px; }
        th { background: #f3f4f6; }
        .summary { margin-top: 14px; font-size: 13px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoPath)
            <div class="logo-wrap">
                <img src="{{ $logoPath }}" alt="RANS logo" class="logo">
            </div>
        @endif

        <div>
            <div class="title">PROJECT(S) / WAREHOUSE(S) REPORT - {{ $warehouse['name'] }}</div>
            <div class="meta">Assigned to - {{ $warehouse['assigned_users'] ?: $userName }}</div>
            <div class="meta">Printed on - {{ $printedAt }}</div>
            <div class="meta">Printed by - {{ $printedBy }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Quantity</th>
                <th>Unit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($warehouse['items'] as $item)
                <tr>
                    <td>{{ $item['code'] }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>{{ $item['unit'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">Total Items: {{ count($warehouse['items']) }}</div>
</body>
</html>
