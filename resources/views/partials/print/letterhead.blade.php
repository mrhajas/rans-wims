{{--
    Shared letterhead: logo + company name + full contact details with icons.
    DomPDF (the `isPdf` callers) needs a filesystem path to embed the image;
    a plain browser `view()` response needs a real URL — the same path
    string means a 404 in one context or the other, so the caller must say
    which one it is (defaults to the URL, since that's the more common
    "view in browser" case if a caller forgets to pass it).
--}}
@php($logoPath = public_path('images/logo.png'))
@php($logoSrc = !empty($isPdf) ? $logoPath : asset('images/logo.png'))
<table class="letterhead">
    <tr>
        <td class="logo-cell">
            @if(file_exists($logoPath))
                <img src="{{ $logoSrc }}" alt="Logo" />
            @endif
        </td>
        <td class="info-cell">
            <div class="company-name">{{ $companyName ?? 'RANS Company Limited' }}</div>
            <div class="tagline">Building &amp; Civil Contractors</div>
            <div class="contact-row">
                <span class="contact-item">
                    @include('print.icon', ['name' => 'phone', 'color' => '#1c3f66', 'size' => 10, 'style' => 'margin-right:3px;'])+255 777 700615
                </span>
                <span class="contact-item">
                    @include('print.icon', ['name' => 'mail', 'color' => '#1c3f66', 'size' => 10, 'style' => 'margin-right:3px;'])info@rans.co.tz
                </span>
                <span class="contact-item">
                    @include('print.icon', ['name' => 'map-pin', 'color' => '#1c3f66', 'size' => 10, 'style' => 'margin-right:3px;'])Fumba Road, Zanzibar
                </span>
                <span class="contact-item">
                    @include('print.icon', ['name' => 'globe', 'color' => '#1c3f66', 'size' => 10, 'style' => 'margin-right:3px;'])www.rans.co.tz
                </span>
            </div>
        </td>
    </tr>
</table>
<div class="band"></div>
