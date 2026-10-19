<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Request - {{ $materialRequest->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        /* A4 portrait for both the browser's Print and the PDF download */
        @page { size: A4 portrait; margin: 12mm 11mm; }
        table.items { table-layout: fixed; width: 100%; }
        table.items td, table.items th { word-wrap: break-word; overflow-wrap: anywhere; vertical-align: top; }
        .mr-proc-name { font-weight: bold; color: #1c3f66; }
        .mr-proc-detail { display: block; margin-top: 2px; font-size: 9px; color: #4b5563; }
        .mr-section-title { font-size: 11px; font-weight: bold; color: #1c3f66; text-transform: uppercase; letter-spacing: 0.3px; margin: 0 0 8px; }
        .mr-section-title .chip {
            display: inline-block; width: 18px; height: 18px; line-height: 18px; text-align: center;
            border-radius: 6px; background: #1c3f66; color: #fff; font-size: 9.5px; font-weight: bold; margin-right: 7px; vertical-align: -4px;
        }
        /* Section-heading glyphs used to be raw Unicode symbols (&#9633;/&#9998;/&#8635;)
           rendered by DomPDF's core Helvetica font, which only reliably covers
           ASCII/Latin-1 — the higher codepoints came out as missing-glyph boxes.
           Inline <svg> was tried next and confirmed (via a standalone render
           test against this exact DomPDF install) to not paint at all in the
           actual PDF output — DomPDF's SVG engine silently drops it even
           though the same markup renders fine in a real browser. Every icon
           below is built from plain nested <span> boxes (background-color +
           border-radius + border), the same primitives already proven to
           render correctly elsewhere on this page (.chip itself, .status-pill,
           .mr-prio) — verified against this DomPDF install before landing here. */
        /* Every icon's content is sized to roughly half the 18px chip and its
           margins are symmetric top/bottom (verified with a standalone
           render-and-inspect pass against this DomPDF install) so it sits
           centered with visible breathing room instead of touching the
           chip's edges. */
        .mr-icon-dot { display: block; width: 2px; height: 2px; background: #fff; border-radius: 50%; margin: 4px auto 1px; }
        .mr-icon-stem { display: block; width: 2px; height: 7px; background: #fff; margin: 0 auto 4px; }
        .mr-icon-bar1 { display: block; width: 10px; height: 1.8px; background: #fff; border-radius: 1px; margin: 5.1px auto 1px; }
        .mr-icon-bar2 { display: block; width: 10px; height: 1.8px; background: #fff; border-radius: 1px; margin: 0 auto 1px; }
        .mr-icon-bar3 { display: block; width: 6px; height: 1.8px; background: #fff; border-radius: 1px; margin: 0 auto 5.1px; }
        .mr-icon-note-box { display: block; box-sizing: border-box; width: 10px; height: 8px; border: 1.1px solid #fff; border-radius: 1.4px; margin: 5px auto 0; }
        .mr-icon-note-line1 { display: block; width: 6px; height: 1.1px; background: #fff; margin: -5.6px auto 0; }
        .mr-icon-note-line2 { display: block; width: 6px; height: 1.1px; background: #fff; margin: 2.2px auto 5px; }
        .mr-icon-ring { display: block; box-sizing: border-box; width: 9px; height: 9px; border: 1.2px solid #fff; border-radius: 50%; margin: 4.5px auto; }
        .mr-prio { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 9px; font-weight: bold; }
        .mr-prio-low { background: #f1f5f9; color: #475569; }
        .mr-prio-normal { background: #dbeafe; color: #1d4ed8; }
        .mr-prio-high { background: #fef3c7; color: #b45309; }
        .mr-prio-emergency { background: #ffe4e6; color: #be123c; }
        .mr-signblock { font-size: 8.6px; color: #6b7280; }
        .mr-signblock .fill-line { display: inline-block; border-bottom: 1px solid #cbd5e1; min-width: 90px; height: 11px; }
        @media print { .print-hide { display: none; } }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php
        $showProcedure = !empty($materialRequest->cleared_by_user_id) || $materialRequest->items->contains(fn ($i) => !empty($i->procedure));
        $statusClass = match ($materialRequest->status) {
            'Approved', 'Issued' => 'status-active',
            'Pending Approval', 'Partially Issued' => 'status-warn',
            'Rejected' => 'status-danger',
            default => 'status-neutral',
        };
        $prioClass = fn ($p) => match ($p) {
            'High' => 'mr-prio-high',
            'Emergency' => 'mr-prio-emergency',
            'Normal' => 'mr-prio-normal',
            default => 'mr-prio-low',
        };
    @endphp

    <div class="title-card">
        <div class="doc-title">MATERIAL REQUEST</div>
        <div class="doc-ref">Reference<br><strong>{{ $materialRequest->reference }}</strong></div>
    </div>

    <div class="card">
        <div class="mr-section-title"><span class="chip"><span class="mr-icon-dot"></span><span class="mr-icon-stem"></span></span>Request Details</div>
        <table class="grid">
            <tr>
                <td class="label">Date Requested</td>
                <td class="value">{{ optional($materialRequest->date_requested)->format('Y-m-d') }}</td>
                <td class="label">Requested By</td>
                <td class="value">{{ optional($materialRequest->user)->name }}</td>
            </tr>
            <tr>
                <td class="label">Project(s) / Warehouse(s)</td>
                <td class="value">{{ optional($materialRequest->warehouse)->name }}</td>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill {{ $statusClass }}">{{ $materialRequest->status }}</span></td>
            </tr>
            @if(!empty($materialRequest->cleared_by_user_id))
                <tr>
                    <td class="label">Cleared By</td>
                    <td class="value" colspan="3">
                        {{ optional($materialRequest->clearedBy)->name }}
                        @if($materialRequest->cleared_at) ({{ optional($materialRequest->cleared_at)->format('Y-m-d H:i') }}) @endif
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <div class="mr-section-title"><span class="chip"><span class="mr-icon-bar1"></span><span class="mr-icon-bar2"></span><span class="mr-icon-bar3"></span></span>Items</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width: 25%;">Item</th>
                <th style="width: 11%;" class="num">Quantity</th>
                <th style="width: 7%;">Unit</th>
                <th style="width: 10%;">Priority</th>
                @if($showProcedure)
                    <th style="width: 27%;">Procedure</th>
                @endif
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($materialRequest->items as $item)
                <tr>
                    <td>{{ optional($item->item)->code }} - {{ optional($item->item)->name }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td>{{ optional($item->unit)->name ?? optional($item->unit)->code }}</td>
                    <td><span class="mr-prio {{ $prioClass($item->priority) }}">{{ $item->priority }}</span></td>
                    @if($showProcedure)
                        <td>
                            @if($item->procedure)
                                <span class="mr-proc-name">{{ $item->procedure === 'MainWarehouse' ? 'Main Warehouse' : $item->procedure }}</span>
                                @php $detail = optional($item->procedureContact)->name ?: (optional($item->procedureWarehouse)->name ?: $item->procedure_note); @endphp
                                @if($detail)<span class="mr-proc-detail">{{ $detail }}</span>@endif
                            @else
                                -
                            @endif
                        </td>
                    @endif
                    <td>{{ $item->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $showProcedure ? 6 : 5 }}" style="text-align:center; padding:16px;">No items found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <br>

    <div class="card">
        <div class="mr-section-title"><span class="chip">?</span>Reason for Request</div>
        <div>{{ $materialRequest->reason }}</div>
    </div>

    @if($materialRequest->comments)
        <div class="card">
            <div class="mr-section-title"><span class="chip"><span class="mr-icon-note-box"></span><span class="mr-icon-note-line1"></span><span class="mr-icon-note-line2"></span></span>Comments &amp; Notes</div>
            <div>{{ $materialRequest->comments }}</div>
        </div>
    @endif

    <div class="mr-section-title"><span class="chip"><span class="mr-icon-ring"></span></span>Audit Trail</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width: 130px;">Status</th>
                <th style="width: 140px;">Updated By</th>
                <th style="width: 150px;">Date &amp; Time</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($materialRequest->logs as $log)
                <tr>
                    <td>{{ $log->status }}</td>
                    <td>{{ optional($log->user)->name }}</td>
                    <td>{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $log->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; padding:16px;">No logs found.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
    <table class="signatures">
        <tr>
            <td style="width:50%;">
                <div class="line">Reviewed By</div>
                <div class="role">(Procurement / Store Manager)</div>
                <div class="who">{{ $materialRequest->clearedBy->name ?? '-' }}</div>
                <div class="when">{{ $materialRequest->cleared_at ? $materialRequest->cleared_at->format('Y-m-d H:i') : '' }}</div>
                <div class="mr-signblock" style="margin: 10px 10px 0;">
                    Date: <span class="fill-line"></span> &nbsp; Place: <span class="fill-line"></span>
                </div>
            </td>
            <td style="width:50%;">
                <div class="line">Final Approval By</div>
                <div class="role">(Super Admin / Director)</div>
                <div class="who">{{ $approvedByName ?? '-' }}</div>
                <div class="when">{{ $approvedAt ?? '' }}</div>
                <div class="mr-signblock" style="margin: 10px 10px 0;">
                    Date: <span class="fill-line"></span> &nbsp; Place: <span class="fill-line"></span>
                </div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ \App\Support\PrintQrCode::dataUri(route('material-requests.show', $materialRequest->id)) }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">Material Request {{ $materialRequest->reference }}, Printed {{ $printedAt }} by {{ $printedBy }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

    @unless($isPdf)
        <div class="print-hide" style="text-align:center; margin-top:18px;">
            <button onclick="window.print()" style="padding:8px 18px; background:#1c3f66; color:#fff; border:0; border-radius:6px; cursor:pointer; font-size:12px;">Print / Save as PDF</button>
        </div>
    @endunless
</body>
</html>
