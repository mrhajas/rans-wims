<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compliance Record {{ $record->compliance_number }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">COMPLIANCE RECORD</div>
        <div class="doc-ref">Compliance No.<br><strong>{{ $record->compliance_number }}</strong></div>
    </div>

    <div class="card">
        <div class="card-title">Identification</div>
        <table class="grid">
            <tr>
                <td class="label">Title</td>
                <td class="value">{{ $record->title }}</td>
                <td class="label">Type</td>
                <td class="value">{{ $record->compliance_type }}</td>
            </tr>
            <tr>
                <td class="label">Reference No.</td>
                <td class="value">{{ $record->reference_number ?? '-' }}</td>
                <td class="label">Issuing Authority</td>
                <td class="value">{{ $record->issuing_authority ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value">
                    <span class="status-pill status-{{ in_array($record->status, ['Active','Renewed']) ? 'active' : (in_array($record->status, ['Expired','Rejected']) ? 'danger' : (in_array($record->status, ['Due Soon']) ? 'warn' : 'neutral')) }}">{{ $record->status }}</span>
                </td>
                <td class="label">Priority</td>
                <td class="value">{{ $record->priority }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Dates</div>
        <table class="grid">
            <tr>
                <td class="label">Issue Date</td>
                <td class="value">{{ $record->issue_date ? $record->issue_date->format('d M Y') : '-' }}</td>
                <td class="label">Expiry Date</td>
                <td class="value">{{ $record->expiry_date ? $record->expiry_date->format('d M Y') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Days Remaining</td>
                <td class="value">{{ $record->days_remaining_label }}</td>
                <td class="label">Next Review</td>
                <td class="value">{{ $record->next_review_date ? $record->next_review_date->format('d M Y') : '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Responsibility & Relationships</div>
        <table class="grid">
            <tr>
                <td class="label">Responsible Person</td>
                <td class="value">{{ optional($record->responsibleUser)->name ?? '-' }}</td>
                <td class="label">Project</td>
                <td class="value">{{ optional($record->warehouse)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ optional($record->supplier)->name ?? '-' }}</td>
                <td class="label">Asset</td>
                <td class="value">{{ optional($record->asset)->name ?? '-' }}</td>
            </tr>
        </table>
    </div>

    @if($record->renewalHistory->count())
    <div class="card">
        <div class="card-title">Renewal History</div>
        <table class="items">
            <tr><th>Started</th><th>Status</th><th>New Issue Date</th><th>New Expiry Date</th><th>Renewed At</th></tr>
            @foreach($record->renewalHistory as $r)
            <tr>
                <td>{{ optional($r->renewal_started_at)->format('d M Y') }}</td>
                <td>{{ $r->status }}</td>
                <td>{{ optional($r->new_issue_date)->format('d M Y') ?? '-' }}</td>
                <td>{{ optional($r->new_expiry_date)->format('d M Y') ?? '-' }}</td>
                <td>{{ optional($r->renewed_at)->format('d M Y') ?? '-' }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if($record->documentVersions->count())
    <div class="card">
        <div class="card-title">Documents</div>
        <table class="items">
            <tr><th>Type</th><th>Version</th><th>Current</th><th>Uploaded</th></tr>
            @foreach($record->documentVersions as $d)
            <tr>
                <td>{{ $d->document_type }}</td>
                <td>{{ $d->version_number }}</td>
                <td>{{ $d->is_current ? 'Yes' : 'No' }}</td>
                <td>{{ optional($d->created_at)->format('d M Y') }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if($record->history->count())
    <div class="card">
        <div class="card-title">History</div>
        <table class="items">
            <tr><th>Date</th><th>Event</th><th>Description</th><th>By</th></tr>
            @foreach($record->history as $h)
            <tr>
                <td>{{ optional($h->created_at)->format('d M Y H:i') }}</td>
                <td>{{ $h->event_type }}</td>
                <td>{{ $h->description }}</td>
                <td>{{ optional($h->user)->name ?? 'System' }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName }} &mdash; Compliance Record {{ $record->compliance_number }} &mdash; Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
