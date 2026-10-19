{{--
    Signature block for a printed record, from App\Services\Signatories\SignatureResolver::resolve().
    $sig   the resolver result (enabled, state, draft, slots[])
    Use:   @if(!empty($signatures['enabled'])) @include('partials.print.signature-block', ['sig' => $signatures]) @else (the old block) @endif
    Table based and image data URIs only, so the browser print and DomPDF show it the same way.
--}}
@php
    $__s = \App\Services\Signatories\SignatorySettings::get();
    $__w = (int) round(((float) $__s['report_signature_width_mm']) * 3.7795);
    $__h = (int) round(((float) $__s['report_signature_height_mm']) * 3.7795);
    $__slots = collect($sig['slots'])->sortBy('slot')->values();
    $__cols = max(1, min(4, $__slots->count()));
@endphp
@if($__slots->isNotEmpty())
<table class="sg-block" style="width:100%; border-collapse:collapse; margin-top:14px; page-break-inside:avoid;">
    @if(!empty($sig['draft']))
        <tr><td colspan="{{ $__cols }}" style="padding:4px 0; font-size:9px; font-weight:bold; letter-spacing:.08em; color:#b45309;">DRAFT: not yet signed off</td></tr>
    @endif
    @foreach($__slots->chunk($__cols) as $__row)
    <tr>
        @foreach($__row as $__sl)
        <td style="width:{{ floor(100 / $__cols) }}%; vertical-align:top; padding:6px 10px 8px 0;">
            <div style="font-size:8.5px; font-weight:bold; letter-spacing:.06em; text-transform:uppercase; color:#475569;">{{ $__sl['role_label'] }}</div>
            <div style="height:{{ $__h + 6 }}px; border-bottom:1px solid #94a3b8; text-align:left;">
                @if(!empty($__sl['image']))
                    <img src="{{ $__sl['image'] }}" alt="signature" style="max-width:{{ $__w }}px; max-height:{{ $__h }}px; width:auto; height:auto;" />
                @endif
            </div>
            @if(in_array($__sl['state'], ['signed', 'no_signature', 'unmatched']))
                @if(!empty($__sl['name']))<div style="font-size:10px; font-weight:bold; margin-top:3px;">{{ $__sl['name'] }}</div>@endif
                @if(!empty($__sl['designation']))<div style="font-size:8.8px; color:#475569;">{{ $__sl['designation'] }}</div>@endif
                @if(!empty($__sl['date']))<div style="font-size:8.8px; color:#475569;">Date: {{ $__sl['date'] }}@if(!empty($__sl['time'])) &nbsp; Time: {{ $__sl['time'] }}@endif</div>@endif
                @if(!empty($__sl['note']))<div style="font-size:8px; color:{{ !empty($__sl['applied_after']) ? '#64748b' : '#b45309' }}; {{ !empty($__sl['applied_after']) ? 'font-style:italic;' : '' }} margin-top:2px;">{{ $__sl['note'] }}</div>@endif
            @endif
        </td>
        @endforeach
    </tr>
    @endforeach
</table>
@endif
