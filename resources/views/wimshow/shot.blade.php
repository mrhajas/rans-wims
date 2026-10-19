{{-- One screenshot in a window frame. $shot = ['file','caption','alt']. Light + dark variants; the dark one is optional. --}}
@php
    $dir = public_path('wimshow-assets/shots/');
    $light = $shot['file'].'.webp';
    $darkFile = $shot['file'].'-dark.webp';
    $hasLight = is_file($dir.$light);
    $hasDark = is_file($dir.$darkFile);
    $size = $hasLight ? (@getimagesize($dir.$light) ?: [1440, 860]) : [1440, 860];
@endphp
@if ($hasLight)
    <figure class="kb-shot rv" data-shot>
        <div class="kb-shot__frame">
            <div class="kb-shot__bar" aria-hidden="true"><i></i><i></i><i></i></div>
            <button type="button" class="kb-shot__zoom" data-zoom aria-label="Enlarge screenshot: {{ $shot['alt'] ?: $shot['caption'] }}">
                <img class="kb-shot__img kb-shot__img--light" src="{{ asset('wimshow-assets/shots/'.$light) }}" width="{{ $size[0] }}" height="{{ $size[1] }}" loading="lazy" decoding="async" alt="{{ $shot['alt'] ?: $shot['caption'] }}" data-full="{{ asset('wimshow-assets/shots/'.$light) }}">
                @if ($hasDark)
                    <img class="kb-shot__img kb-shot__img--dark" src="{{ asset('wimshow-assets/shots/'.$darkFile) }}" width="{{ $size[0] }}" height="{{ $size[1] }}" loading="lazy" decoding="async" alt="" aria-hidden="true" data-full="{{ asset('wimshow-assets/shots/'.$darkFile) }}">
                @endif
                <span class="kb-shot__hint"><x-wimshow.icon name="maximize" /> Click to enlarge</span>
            </button>
        </div>
        @if ($shot['caption'])<figcaption>{{ $shot['caption'] }}</figcaption>@endif
    </figure>
@endif
