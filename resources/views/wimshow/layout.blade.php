@php
    $kbTitle = ($title ?? null) ? $title.' · WIMS Show' : 'WIMS Show · The WIMS knowledge base';
    $kbDescription = $description ?? 'How every part of WIMS works, step by step, with screenshots and the hidden functions that run behind the scenes. Open to everyone, no login needed.';
    $v = $kb->version();
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $kbTitle }}</title>
    <meta name="description" content="{{ $kbDescription }}">
    <meta name="robots" content="noindex, follow">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f4f7fc" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap">
    <script>
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('wimshow-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
            document.documentElement.classList.add('js');
        })();
    </script>
    <link rel="stylesheet" href="{{ asset('wimshow-assets/wimshow.css') }}?v={{ filemtime(public_path('wimshow-assets/wimshow.css')) }}">
</head>
<body class="kb">
    <a class="kb-skip" href="#main">Skip to content</a>
    <div class="kb-progress" aria-hidden="true"><span></span></div>

    <header class="kb-top">
        <button class="kb-btn kb-btn--icon kb-burger" type="button" data-nav-toggle aria-label="Open the menu" aria-expanded="false"><x-wimshow.icon name="menu" /></button>
        <a class="kb-brand" href="{{ route('wimshow.index') }}" aria-label="WIMS Show home">
            <span class="kb-brand__mark"><x-wimshow.icon name="book-open" /></span>
            <span class="kb-brand__text"><b>WIMS Show</b><small>Knowledge base</small></span>
        </a>
        <button class="kb-search-trigger" type="button" data-search-open aria-label="Search the guides">
            <x-wimshow.icon name="search" /><span>Search every guide</span><kbd>/</kbd>
        </button>
        <div class="kb-top__actions">
            <button class="kb-btn kb-btn--icon" type="button" data-search-open aria-label="Search" data-mobile-search><x-wimshow.icon name="search" /></button>
            <button class="kb-btn kb-btn--icon kb-tip" type="button" data-print-page data-tip="Print this page" aria-label="Print this page"><x-wimshow.icon name="printer" /></button>
            <button class="kb-btn kb-btn--icon kb-tip" type="button" data-theme-toggle data-tip="Light / dark" aria-label="Switch between light and dark mode"><x-wimshow.icon name="moon" class="kb-i kb-i--moon" /><x-wimshow.icon name="sun" class="kb-i kb-i--sun" /></button>
            <a class="kb-btn kb-btn--primary kb-open-wims" href="{{ url('/') }}" target="_blank" rel="noopener"><x-wimshow.icon name="external" /><span>Open WIMS</span></a>
        </div>
    </header>

    <div class="kb-shell">
        @include('wimshow.nav', ['current' => $current ?? null])
        <div class="kb-backdrop" data-nav-close></div>
        <main id="main" class="kb-main" tabindex="-1">
            @yield('content')
            <footer class="kb-foot">
                <p><b>WIMS Show</b> is the open guide to the Workflow Integrated Management System. Anyone can read it, no login needed. <a href="{{ route('wimshow.index') }}">All guides</a></p>
                <p class="kb-foot__print">Printed from WIMS Show · {{ url('/wimshow') }} · {{ now()->format('j F Y') }}</p>
            </footer>
        </main>
        @yield('toc')
    </div>

    <dialog class="kb-search" id="kb-search" aria-label="Search the guides" data-index="{{ route('wimshow.search') }}?v={{ $v }}">
        <div class="kb-search__box">
            <label class="kb-search__field"><x-wimshow.icon name="search" /><input type="search" placeholder="Search guides, steps and hidden functions…" autocomplete="off" spellcheck="false" data-search-input></label>
            <button class="kb-btn kb-btn--icon" type="button" data-search-close aria-label="Close search"><x-wimshow.icon name="x" /></button>
        </div>
        <ul class="kb-search__results" data-search-results role="listbox"></ul>
        <p class="kb-search__hint"><kbd>↑</kbd><kbd>↓</kbd> to move · <kbd>Enter</kbd> to open · <kbd>Esc</kbd> to close</p>
    </dialog>

    <dialog class="kb-lightbox" id="kb-lightbox" aria-label="Screenshot">
        <button class="kb-btn kb-btn--icon kb-lightbox__close" type="button" data-lb-close aria-label="Close"><x-wimshow.icon name="x" /></button>
        <button class="kb-btn kb-btn--icon kb-lightbox__prev" type="button" data-lb-prev aria-label="Previous screenshot"><x-wimshow.icon name="arrow-left" /></button>
        <button class="kb-btn kb-btn--icon kb-lightbox__next" type="button" data-lb-next aria-label="Next screenshot"><x-wimshow.icon name="arrow-right" /></button>
        <figure><img alt="" data-lb-img><figcaption data-lb-caption></figcaption></figure>
    </dialog>
    <div class="kb-toast" role="status" aria-live="polite" data-toast></div>

    <script src="{{ asset('wimshow-assets/wimshow.js') }}?v={{ filemtime(public_path('wimshow-assets/wimshow.js')) }}" defer></script>
</body>
</html>
