<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rans Wims - Task Reminders</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #27447a; }
        .sub { color: #6b7280; font-size: 12px; margin-bottom: 12px; }
        .meta { margin-bottom: 16px; padding: 8px 12px; background: #f3f4f6; border-radius: 4px; }
        .meta strong { color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #27447a; color: #fff; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #27447a; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 11px; text-align: center; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
        .badge-done { background: #d1fae5; color: #065f46; }
        .badge-overdue { background: #fee2e2; color: #991b1b; }
        .badge-upcoming { background: #dbeafe; color: #1e40af; }
        .priority { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; text-transform: capitalize; }
        .priority-low { background: #f1f5f9; color: #475569; }
        .priority-normal { background: #dbeafe; color: #1d4ed8; }
        .priority-high { background: #fef3c7; color: #b45309; }
        .priority-urgent { background: #fee2e2; color: #b91c1c; }
        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    @php
        $companyNameSafe = (string) ($companyName ?? '');
        $displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', $companyNameSafe);
    @endphp
    <div style="text-align:center; margin-bottom: 12px;">
        <img src="{{ asset('images/logo.png') }}" alt="RANS Logo" style="max-height: 90px; width: auto;" />
    </div>
    <h1 style="text-align:center;">Rans Wims - Task Reminders</h1>
    @if($displayCompanyName)
        <div class="sub" style="text-align:center;">{{ $displayCompanyName }}</div>
    @endif

    <div class="meta">
        <strong>Generated:</strong> {{ now()->format('Y-m-d H:i') }} &nbsp;
        <strong>By:</strong> {{ $generatedBy ?? 'system' }} &nbsp;
        @if($from || $to)
            <strong>Range:</strong> {{ $from ?? 'Any' }} &rarr; {{ $to ?? 'Any' }}
        @else
            <strong>Range:</strong> All reminders
        @endif
    </div>

    @if($autoPrint)
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Category</th>
                <th>Due</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Owner</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reminders as $reminder)
                @php
                    $status = $reminder->is_completed
                        ? ['label' => 'Completed', 'class' => 'badge-done']
                        : ($reminder->due_at->isPast() ? ['label' => 'Overdue', 'class' => 'badge-overdue'] : ['label' => 'Upcoming', 'class' => 'badge-upcoming']);
                @endphp
                <tr>
                    <td>{{ $reminder->name }}</td>
                    <td>{{ $reminder->category ?? '—' }}</td>
                    <td>{{ $reminder->due_at->format('Y-m-d H:i') }}</td>
                    <td><span class="priority priority-{{ $reminder->priority }}">{{ $reminder->priority }}</span></td>
                    <td><span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span></td>
                    <td>{{ optional($reminder->user)->name ?? '—' }}</td>
                    <td>{{ $reminder->notes ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; padding:16px;">No reminders in this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">RANS WIMS - Task Reminders Report</div>

    @if($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
