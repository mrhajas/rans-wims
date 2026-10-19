{{--
    The signatures row of a report that is GENERATED on request: the "Prepared By" cell becomes the Signatories block (the person who
    generated the report), and the lines that have no data source stay exactly as the blank wet-signature lines they always were.
    $signatures  the resolver result (enabled)
    $cells       the remaining cells, each: ['line' => text above/at the rule, 'role' => italic caption, 'who' => bold name line, 'when' => small grey line]
    $caption     optional italic caption under the prepared block (for example "(Site Engineer / Planner)")
--}}
@php
    $__s = \App\Services\Signatories\SignatorySettings::get();
    $__h = (int) round(((float) $__s['report_signature_height_mm']) * 3.7795);
    $__n = 1 + count($cells);
    $__w = floor(100 / $__n);
@endphp
<table class="signatures">
    <tr>
        <td style="width:{{ $__w }}%; padding-top:0;">
            @include('partials.print.signature-block', ['sig' => $signatures])
            @if(!empty($caption))<div class="role" style="margin:3px 0 0;">{{ $caption }}</div>@endif
        </td>
        @foreach($cells as $__c)
        <td style="width:{{ $__w }}%; padding-top:0;">
            <div style="height:{{ $__h + 37 }}px;"></div>
            <div class="line">{{ ($__c['line'] ?? '') !== '' ? $__c['line'] : ' ' }}</div>
            <div class="role">{!! ($__c['role'] ?? '') !== '' ? e($__c['role']) : '&nbsp;' !!}</div>
            @if(array_key_exists('who', $__c))<div class="who">{!! ($__c['who'] ?? '') !== '' ? e($__c['who']) : '&nbsp;' !!}</div>@endif
            @if(array_key_exists('when', $__c))<div class="when">{!! ($__c['when'] ?? '') !== '' ? e($__c['when']) : '&nbsp;' !!}</div>@endif
        </td>
        @endforeach
    </tr>
</table>
