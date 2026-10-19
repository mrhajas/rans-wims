@php
    $warehouses = $single ? collect([$data]) : collect($data);
    $severityOf = fn ($w) => ($w->alert / ($w->stock_count ?: 1)) * 100;
@endphp
@include('emails.partials.shell-open', ['emailTitle' => __('Low Stock Alert')])
@include('emails.partials.header', ['icon' => '📦', 'accent' => '#b45309', 'eyebrow' => __('Stock Alert')])
<tr>
    <td style="padding:28px 28px 8px;">
        <p style="margin:0 0 4px; font-size:15px; color:#111827;">{{ __('Hello') }}{{ $user ? ' ' . $user->name : '' }},</p>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">
            {{ $single ? __('A warehouse has items at or below their alert threshold.') : __('The following warehouses have items at or below their alert threshold.') }}
        </p>

        @foreach ($warehouses as $warehouse)
            @php
                $severity = $severityOf($warehouse);
                $isCritical = $severity > 20;
                $badgeBg = $isCritical ? '#fee2e2' : '#fef3c7';
                $badgeColor = $isCritical ? '#b91c1c' : '#b45309';
            @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid {{ $isCritical ? '#dc2626' : '#f59e0b' }}; border-radius:8px; margin-bottom:12px;">
                <tr>
                    <td style="padding:14px 16px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="vertical-align:top;">
                                    <a href="{{ route('alerts.list', $warehouse->id) }}" target="_blank" style="text-decoration:none;">
                                        <div style="font-size:14px; font-weight:700; color:#0b1a3a;">{{ __($warehouse->name) }} <span style="font-weight:400; color:#94a3b8;">({{ $warehouse->code }})</span></div>
                                        <div style="margin-top:4px; font-size:12.5px; color:#64748b;">{{ __choice('x items are low in stock.', ['x' => $warehouse->alert]) }}</div>
                                    </a>
                                </td>
                                <td align="right" style="vertical-align:top; white-space:nowrap;">
                                    <span style="display:inline-block; padding:4px 11px; border-radius:9999px; font-size:11px; font-weight:700; background:{{ $badgeBg }}; color:{{ $badgeColor }};">
                                        {{ round($severity) }}%
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        @endforeach

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px auto 24px;">
            <tr>
                <td align="center" style="border-radius:8px; background-color:#b45309; background-image:linear-gradient(135deg, #b45309 0%, #1e3a8a 100%);">
                    <a href="{{ $single ? route('alerts.list', $data->id) : route('warehouses.index') }}" target="_blank" style="display:inline-block; padding:12px 26px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('Review Stock Levels') }}
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
