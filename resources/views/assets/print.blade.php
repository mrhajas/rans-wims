<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset {{ $asset->asset_number }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $tab = $tab ?? 'full';
        $has = fn (string $k) => $tab === 'full' || $tab === $k;
        $tabTitles = ['overview' => 'Overview', 'movements' => 'Movement History', 'maintenance' => 'Maintenance', 'depreciation' => 'Depreciation', 'appreciation' => 'Appreciation', 'documents' => 'Documents', 'trails' => 'Trails', 'disposal' => 'Disposal'];
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '-';
        $pillClass = in_array($asset->status, ['Available', 'Active']) ? 'active' : (in_array($asset->status, ['Lost', 'Stolen', 'Damaged']) ? 'danger' : 'neutral');
    @endphp
    <div class="title-card">
        <div class="doc-title">ASSET RECORD{{ isset($tabTitles[$tab]) ? ' - ' . strtoupper($tabTitles[$tab]) : '' }}</div>
        <div class="doc-ref">Asset No.<br><strong>{{ $asset->asset_number }}</strong></div>
    </div>

    @if(! in_array($tab, ['full', 'overview'], true))
    <div class="card">
        <div class="card-title">Asset</div>
        <table class="grid">
            <tr>
                <td class="label">Name</td>
                <td class="value">{{ $asset->name }}</td>
                <td class="label">Category</td>
                <td class="value">{{ optional($asset->category)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill status-{{ $pillClass }}">{{ $asset->status }}</span></td>
                <td class="label">Current Location</td>
                <td class="value">{{ optional($asset->currentWarehouse)->name ?? $asset->current_location_label ?? '-' }}</td>
            </tr>
        </table>
    </div>
    @endif

    @if($has('overview'))
    <div class="card">
        <div class="card-title">Identification</div>
        <table class="grid">
            <tr>
                <td class="label">Name</td>
                <td class="value">{{ $asset->name }}</td>
                <td class="label">Category</td>
                <td class="value">{{ optional($asset->category)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Manufacturer</td>
                <td class="value">{{ $asset->manufacturer ?? '-' }}</td>
                <td class="label">Model</td>
                <td class="value">{{ $asset->model ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Serial No.</td>
                <td class="value">{{ $asset->serial_number ?? '-' }}</td>
                <td class="label">Registration No.</td>
                <td class="value">{{ $asset->registration_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Asset Type</td>
                <td class="value">{{ $asset->asset_type ?? '-' }}</td>
                <td class="label">Tag / Plate No.</td>
                <td class="value">{{ $asset->tag_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value">
                    <span class="status-pill status-{{ in_array($asset->status, ['Available','Active']) ? 'active' : (in_array($asset->status, ['Lost','Stolen','Damaged']) ? 'danger' : 'neutral') }}">{{ $asset->status }}</span>
                </td>
                <td class="label">Condition</td>
                <td class="value">{{ $asset->condition }}</td>
            </tr>
        </table>
    </div>
    @endif

    @if($has('overview'))
    <div class="card">
        <div class="card-title">Location & Registration</div>
        <table class="grid">
            <tr>
                <td class="label">Current Location</td>
                <td class="value">{{ optional($asset->currentWarehouse)->name ?? $asset->current_location_label ?? '-' }}</td>
                <td class="label">Responsible</td>
                <td class="value">{{ optional($asset->assignedUser)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Operating Personnel</td>
                <td class="value">{{ optional($asset->operatingPersonnel)->name ?? '-' }}</td>
                <td class="label">Supplier</td>
                <td class="value">{{ optional($asset->supplier)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Registered By</td>
                <td class="value">{{ optional($asset->creator)->name ?? '-' }}</td>
                <td class="label">Registered On</td>
                <td class="value">{{ optional($asset->created_at)->format('d M Y H:i') ?? '-' }}</td>
            </tr>
        </table>
    </div>

    @endif

    @if($has('overview'))
    <div class="card">
        <div class="card-title">Acquisition & Value</div>
        <table class="grid">
            <tr>
                <td class="label">Acquisition Date</td>
                <td class="value">{{ $asset->acquisition_date ? \Illuminate\Support\Carbon::parse($asset->acquisition_date)->format('d M Y') : '-' }}</td>
                <td class="label">Total Capitalised Cost</td>
                <td class="value">{{ number_format($asset->total_capitalised_cost, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Current Book Value</td>
                <td class="value" style="font-weight:bold; color:#15803d;">{{ number_format($asset->current_book_value, 2) }}</td>
                <td class="label">Residual Value</td>
                <td class="value">{{ number_format($asset->residual_value, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Warranty &amp; Insurance</div>
        <table class="grid">
            <tr>
                <td class="label">Warranty End</td>
                <td class="value">{{ $d($asset->warranty_end) }}</td>
                <td class="label">Insurance Expiry</td>
                <td class="value">{{ $d($asset->insurance_expiry) }}</td>
            </tr>
            <tr>
                <td class="label">Insurance Provider</td>
                <td class="value">{{ $asset->insurance_provider ?? '-' }}</td>
                <td class="label"></td>
                <td class="value"></td>
            </tr>
        </table>
    </div>
    @endif

    @if($has('movements'))
    <div class="card">
        <div class="card-title">Movement History</div>
        <table class="items">
            <tr><th>Date</th><th>Type</th><th>Qty</th><th>From</th><th>To</th><th>Condition</th><th>Performed By</th></tr>
            @forelse($asset->movements as $m)
            <tr>
                <td>{{ optional($m->movement_date)->format('d M Y') }}</td>
                <td>{{ $m->movement_type }}</td>
                <td>{{ rtrim(rtrim(number_format((float) $m->quantity, 3, '.', ''), '0'), '.') }}{{ optional($asset->unit)->name ? ' '.$asset->unit->name : '' }}</td>
                <td>{{ optional($m->fromWarehouse)->name ?? $m->from_label ?? '-' }}</td>
                <td>{{ optional($m->toWarehouse)->name ?? $m->to_label ?? '-' }}</td>
                <td>{{ $m->condition_after ?: ($m->condition_before ?: '-') }}</td>
                <td>{{ optional($m->performedBy)->name ?? '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center; color:#6b7280;">No movements recorded.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($has('maintenance'))
    <div class="card">
        <div class="card-title">Maintenance Records</div>
        <table class="items">
            <tr><th>Date</th><th>Type</th><th>Service Provider</th><th class="num">Cost</th><th>Next Service</th></tr>
            @forelse($asset->maintenanceRecords as $mr)
            <tr>
                <td>{{ optional($mr->maintenance_date)->format('d M Y') }}</td>
                <td>{{ $mr->maintenance_type }}</td>
                <td>{{ $mr->service_provider ?? '-' }}</td>
                <td class="num">{{ number_format($mr->cost, 2) }}</td>
                <td>{{ $d($mr->next_service_date) }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No maintenance recorded.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($has('depreciation'))
    <div class="card">
        <div class="card-title">Depreciation Entries</div>
        <table class="items">
            <tr><th>Period</th><th class="num">Opening</th><th class="num">Depreciation</th><th class="num">Accumulated</th><th class="num">Closing Value</th></tr>
            @forelse($asset->depreciationEntries as $dp)
            <tr>
                <td>{{ optional($dp->period_start)->format('d M Y') }} &ndash; {{ optional($dp->period_end)->format('d M Y') }}</td>
                <td class="num">{{ number_format($dp->opening_book_value, 2) }}</td>
                <td class="num">{{ number_format($dp->period_depreciation, 2) }}</td>
                <td class="num">{{ number_format($dp->accumulated_depreciation, 2) }}</td>
                <td class="num">{{ number_format($dp->closing_book_value, 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No depreciation posted.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($has('appreciation'))
    <div class="card">
        <div class="card-title">Appreciation Entries</div>
        <table class="items">
            <tr><th>Period</th><th class="num">Opening</th><th class="num">Appreciation</th><th class="num">Accumulated</th><th class="num">Closing Value</th></tr>
            @forelse($asset->appreciationEntries as $ap)
            <tr>
                <td>{{ optional($ap->period_start)->format('d M Y') }} &ndash; {{ optional($ap->period_end)->format('d M Y') }}</td>
                <td class="num">{{ number_format($ap->opening_value, 2) }}</td>
                <td class="num">{{ number_format($ap->period_appreciation, 2) }}</td>
                <td class="num">{{ number_format($ap->accumulated_appreciation, 2) }}</td>
                <td class="num">{{ number_format($ap->closing_value, 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No appreciation posted.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($tab === 'documents')
    <div class="card">
        <div class="card-title">Documents</div>
        <table class="items">
            <tr><th>Title</th><th>Group</th><th>Type</th><th class="num">Size</th><th>Uploaded</th></tr>
            @forelse($asset->attachments as $doc)
            <tr>
                <td>{{ $doc->title }}</td>
                <td>{{ $doc->group ?? '-' }}</td>
                <td>{{ strtoupper($doc->filetype ?? '-') }}</td>
                <td class="num">{{ $doc->filesize ? number_format($doc->filesize / 1024, 1) . ' KB' : '-' }}</td>
                <td>{{ optional($doc->created_at)->format('d M Y') ?? '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center; color:#6b7280;">No documents uploaded.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($tab === 'trails')
    <div class="card">
        <div class="card-title">Trails</div>
        <table class="items">
            <tr><th style="width:120px;">When</th><th>What happened</th><th style="width:130px;">By</th></tr>
            @forelse($trails as $t)
            <tr>
                <td>{{ $t['created_at'] ? \Illuminate\Support\Carbon::parse($t['created_at'])->format('d M Y H:i') : '-' }}</td>
                <td>{{ $t['description'] }}</td>
                <td>{{ $t['causer'] }}</td>
            </tr>
            @empty
            <tr><td colspan="3" style="text-align:center; color:#6b7280;">No history recorded yet.</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    @if($asset->disposal && $has('disposal'))
    <div class="card">
        <div class="card-title">Disposal</div>
        <table class="grid">
            <tr>
                <td class="label">Method</td>
                <td class="value">{{ $asset->disposal->disposal_method }}</td>
                <td class="label">Date</td>
                <td class="value">{{ optional($asset->disposal->disposal_date)->format('d M Y') }}</td>
            </tr>
            <tr>
                <td class="label">Disposal Value</td>
                <td class="value">{{ number_format($asset->disposal->disposal_value, 2) }}</td>
                <td class="label">Gain / Loss</td>
                <td class="value">{{ number_format($asset->disposal->gain_loss, 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName }} &mdash; Asset Record {{ $asset->asset_number }} &mdash; Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
