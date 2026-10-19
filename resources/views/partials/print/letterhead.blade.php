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
                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="#1c3f66" stroke-width="2.5"><path d="M4 5c0 8 7 15 15 15l2-4-6-2-2 2c-2-1-4-3-5-5l2-2-2-6-4 2z"/></svg>+255 777 700615
                </span>
                <span class="contact-item">
                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="#1c3f66" stroke-width="2.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>finance@rans.co.tz
                </span>
                <span class="contact-item">
                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="#1c3f66" stroke-width="2.5"><path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>Fumba Road, Zanzibar
                </span>
                <span class="contact-item">
                    <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="#1c3f66" stroke-width="2.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 010 18 15 15 0 010-18z"/></svg>www.rans.co.tz
                </span>
            </div>
        </td>
    </tr>
</table>
<div class="band"></div>
