@include('emails.partials.shell-open', ['emailTitle' => __('Mail') . ' ' . __('Adjustment') . ' ' . $adjustment->id])
@include('emails.partials.header', ['icon' => '⚖️', 'accent' => '#d97706', 'eyebrow' => __('Adjustment')])
<tr>
    <td style="padding:28px 28px 8px;">
        @if ($mail)
            <p style="margin:0 0 4px; font-size:15px; color:#111827;">{{ __('Hello') }},</p>
            <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">{{ __('Please review the adjustment details below') }}</p>
        @endif

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
            <tr>
                <td style="vertical-align:top;">
                    @if ($adjustment->warehouse->logo)
                        <img src="{{ $adjustment->warehouse->logo }}" style="max-height:64px; display:block;" />
                    @else
                        <span style="font-size:16px; font-weight:700; color:#0b1a3a;">{{ $adjustment->warehouse->name }}</span>
                    @endif
                </td>
                <td align="right" style="vertical-align:top; font-size:12.5px; color:#4b5563; line-height:1.6;">
                    <div style="font-weight:700; color:#111827;">{{ $adjustment->warehouse->name }} ({{ $adjustment->warehouse->code }})</div>
                    <div>{{ $adjustment->warehouse->address }}</div>
                    <div>{{ $adjustment->warehouse->phone }}</div>
                    <div>{{ $adjustment->warehouse->email }}</div>
                </td>
            </tr>
        </table>

        @if ($adjustment->draft)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; margin-bottom:16px;">
                <tr><td style="padding:10px 14px; font-size:12.5px; font-weight:600; color:#92400e;">{{ __('This record is still a draft.') }}</td></tr>
            </table>
        @endif

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid #d97706; border-radius:8px; margin-bottom:18px;">
            <tr>
                <td style="padding:14px 16px; font-size:12.5px; color:#374151; line-height:1.7;">
                    <div>{{ __('Type') }}: <strong>{{ $adjustment->type }}</strong></div>
                    <div>{{ __('Date') }}: <strong>{{ $adjustment->date }}</strong></div>
                    <div>{{ __('Reference') }}: <strong>{{ $adjustment->reference }}</strong></div>
                    <div>{{ __('Created at') }}: <strong>{{ $adjustment->created_at }}</strong></div>
                </td>
            </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:16px;">
            <tr style="background:#f1f5f9;">
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:50%;">{{ __('Item') }}</th>
                <th style="padding:9px 12px; text-align:right; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:25%;">{{ __('Weight') }}</th>
                <th style="padding:9px 12px; text-align:right; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:25%;">{{ __('Quantity') }}</th>
            </tr>
            @foreach ($adjustment->items as $item)
                @if ($item->variations->isNotEmpty())
                    <tr>
                        <td style="padding:9px 12px; border:1px solid #e5e7eb; font-weight:700; font-size:13px; color:#111827;">{{ $item->item->name }} ({{ $item->item->code }})</td>
                        @if ($settings->track_weight)
                            <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right;"></td>
                        @endif
                        <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right;"></td>
                    </tr>
                    @foreach ($item->variations as $variation)
                        <tr>
                            <td style="padding:6px 12px; border:1px solid #e5e7eb; font-size:12.5px; color:#4b5563;">
                                @foreach ($variation->meta as $variant => $value)
                                    {{ $variant }}: <strong>{{ $value }}</strong>{{ $loop->last ? '' : ', ' }}
                                @endforeach
                            </td>
                            @if ($settings->track_weight)
                                <td style="padding:6px 12px; border:1px solid #e5e7eb; text-align:right; font-size:12.5px;">
                                    @if ($item->item->track_weight)
                                        {{ formatNumber($variation->pivot->weight, $settings->fraction) }} {{ $settings->weight_unit }}
                                    @endif
                                </td>
                            @endif
                            <td style="padding:6px 12px; border:1px solid #e5e7eb; text-align:right; font-size:12.5px;">
                                {{ formatNumber($variation->pivot->quantity, $settings->fraction) }} {{ $item->unit ? $item->unit->code : '' }}
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#111827;">{{ $item->item->name }} ({{ $item->item->code }})</td>
                        @if ($settings->track_weight)
                            <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right; font-size:13px;">
                                @if ($item->item->track_weight)
                                    {{ formatNumber($item->weight, $settings->fraction) }} {{ $settings->weight_unit }}
                                @endif
                            </td>
                        @endif
                        <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right; font-size:13px;">
                            {{ formatNumber($item->quantity, $settings->fraction) }} {{ $item->unit ? $item->unit->code : '' }}
                        </td>
                    </tr>
                @endif
            @endforeach
        </table>

        @if($adjustment->details)
            <p style="margin:0 0 16px; font-size:13px; color:#4b5563;">{{ $adjustment->details }}</p>
        @endif
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
