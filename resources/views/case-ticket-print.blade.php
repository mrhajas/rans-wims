<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Case {{ $case['case_number'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">CASE REPORT</div>
        <div class="doc-ref">Reference<br><strong>{{ $case['case_number'] }}</strong></div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">Subject</td>
            <td class="value" colspan="3">{{ $case['subject'] }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td class="value">{{ $case['status'] }}</td>
            <td class="label">Priority</td>
            <td class="value">{{ $case['priority']['name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Category</td>
            <td class="value">{{ $case['category']['name'] ?? '-' }}</td>
            <td class="label">Project / Site</td>
            <td class="value">{{ $case['warehouse']['name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Created By</td>
            <td class="value">{{ $case['created_by']['name'] ?? '-' }}</td>
            <td class="label">Assigned To</td>
            <td class="value">{{ $case['assigned_to']['name'] ?? '-' }}</td>
        </tr>
    </table>

    <div class="card">
        <div class="card-title">Description</div>
        <div>{{ $case['description'] }}</div>
    </div>

    @if(!empty($case['resolution']))
        <div class="card">
            <div class="card-title">Resolution</div>
            <div>{{ $case['resolution'] }}</div>
            <div style="font-size:9px;color:#6b7280;margin-top:4px;">By {{ $case['resolved_by_name'] ?? '-' }} on {{ $case['resolved_at'] ?? '-' }}</div>
        </div>
    @endif

    @if(!empty($case['comments']))
        <div class="card">
            <div class="card-title">Comments</div>
            @foreach($case['comments'] as $comment)
                <div style="margin-bottom:6px;">
                    <strong>{{ $comment['user_name'] }}</strong> on <span style="font-size:9px;color:#6b7280;">{{ $comment['created_at'] }}</span>
                    <div>{{ $comment['body'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-title">Activity History</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Action</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_reverse($timeline) as $entry)
                    <tr>
                        <td class="num">{{ $entry['created_at'] }}</td>
                        <td>{{ $entry['description'] }}</td>
                        <td>{{ $entry['causer_name'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">Case {{ $case['case_number'] }}, Generated {{ $printedAt }} by {{ $printedBy }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
