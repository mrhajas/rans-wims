{{-- Faint rotated logo watermark, repeated on every page (DomPDF replicates position:fixed elements automatically). --}}
@php($logoPath = public_path('images/logo.png'))
@php($logoSrc = !empty($isPdf) ? $logoPath : asset('images/logo.png'))
@if(file_exists($logoPath))
    <img src="{{ $logoSrc }}" class="watermark" alt="" />
@endif
