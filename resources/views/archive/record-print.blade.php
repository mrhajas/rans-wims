<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archive {{ $record->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #1c3f66; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead', ['companyName' => $companyName])
    @php($d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '-')

    <div class="title-card">
        <div class="doc-title">ARCHIVE RECORD</div>
        <div class="doc-ref">Reference<br><strong>{{ $record->reference }}</strong></div>
    </div>

    <div class="card">
        <div class="card-title">Document</div>
        <table class="grid">
            <tr><td class="label">Title</td><td class="value" colspan="3">{{ $record->title }}</td></tr>
            <tr><td class="label">Category</td><td class="value">{{ $record->category }}{{ $record->subcategory ? ' / ' . $record->subcategory : '' }}</td><td class="label">Document Type</td><td class="value">{{ $record->document_type ?: '-' }}</td></tr>
            <tr><td class="label">Source</td><td class="value">{{ $record->source_type ?: '-' }}</td><td class="label">Organization</td><td class="value">{{ $record->organization ?: '-' }}</td></tr>
            <tr><td class="label">Sender</td><td class="value">{{ $record->sender ?: '-' }}</td><td class="label">Recipient</td><td class="value">{{ $record->recipient ?: '-' }}</td></tr>
            <tr><td class="label">Document RefNo</td><td class="value">{{ $record->reference_number ?: '-' }}</td><td class="label">Project</td><td class="value">{{ optional($record->project)->name ?: '-' }}</td></tr>
            <tr><td class="label">Contact</td><td class="value">{{ optional($record->contact)->name ?: '-' }}</td><td class="label">Department</td><td class="value">{{ $record->department ?: '-' }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Dates &amp; Status</div>
        <table class="grid">
            <tr><td class="label">Document Date</td><td class="value">{{ $d($record->document_date) }}</td><td class="label">Received Date</td><td class="value">{{ $d($record->received_date) }}</td></tr>
            <tr><td class="label">Expiry Date</td><td class="value">{{ $d($record->expiry_date) }}</td><td class="label">Status</td><td class="value">{{ $record->status }}</td></tr>
            <tr><td class="label">Confidentiality</td><td class="value">{{ $record->confidentiality }}</td><td class="label">Attachments</td><td class="value">{{ $record->files_count }}</td></tr>
            <tr><td class="label">Created By</td><td class="value">{{ optional($record->creator)->name ?: '-' }}</td><td class="label">Created</td><td class="value">{{ optional($record->created_at)->format('d M Y H:i') }}</td></tr>
        </table>
    </div>

    @if($record->description || $record->keywords || count($tags))
    <div class="card">
        <div class="card-title">Description &amp; Keywords</div>
        @if($record->description)<p style="margin:0 0 6px;">{{ $record->description }}</p>@endif
        @if($record->keywords)<p style="margin:0 0 6px;"><b>Keywords:</b> {{ $record->keywords }}</p>@endif
        @if(count($tags))<p style="margin:0;"><b>Tags:</b> {{ implode(', ', $tags) }}</p>@endif
    </div>
    @endif

    <div class="card">
        <div class="card-title">Attachments ({{ $files->count() }})</div>
        <table class="items">
            <tr><th>File</th><th>Type</th><th class="num">Size</th><th>Uploaded</th></tr>
            @forelse($files as $f)
                <tr><td>{{ $f->original_name }}</td><td>{{ strtoupper($f->extension) }}</td><td class="num">{{ number_format($f->size_bytes / 1024, 1) }} KB</td><td>{{ optional($f->created_at)->format('d M Y') }}</td></tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#6b7280;">No attachments.</td></tr>
            @endforelse
        </table>
    </div>

    <table class="footer-row"><tr><td class="qr-cell"><img src="{{ \App\Support\PrintQrCode::dataUri(route('archive.records.show', $record->id)) }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td><td class="foot-cell">{{ $companyName }} &mdash; Archive Record {{ $record->reference }} &mdash; Printed {{ now()->format('Y-m-d H:i') }} by {{ $generatedBy }}</td><td class="qr-cell"></td></tr></table>

    <div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body>
</html>
