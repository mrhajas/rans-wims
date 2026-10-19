@extends('wimshow.layout', ['current' => null])

@php
    use App\Support\Wimshow\Knowledge;
    // a few "behind the scenes" teasers, picked deterministically per day so the page feels alive
    $teasers = $kb->guides()->flatMap(fn ($g) => collect($g['behind'])->map(fn ($b, $i) => [...$b, 'guide' => $g, 'anchor' => 'behind-'.($i + 1)]))->values();
    $picked = $teasers->isEmpty() ? collect() : $teasers->shuffle(crc32(now()->format('Ymd')))->take(3);
@endphp

@section('content')
<section class="kb-hero">
    <div class="kb-hero__blobs" aria-hidden="true"><i></i><i></i><i></i></div>
    <p class="kb-eyebrow rv"><x-wimshow.icon name="sparkles" /> The open guide to WIMS</p>
    <h1 class="rv" style="--d:.05s">Learn every part of WIMS,<br><span>step by step.</span></h1>
    <p class="kb-hero__lead rv" style="--d:.1s">Each guide explains what a module is for, how to use every button and form with screenshots, and the hidden functions that quietly run behind the scenes. No login needed.</p>
    <button class="kb-hero__search rv" style="--d:.15s" type="button" data-search-open><x-wimshow.icon name="search" /><span>Search guides, steps and hidden functions…</span><kbd>/</kbd></button>
    <ul class="kb-stats rv" style="--d:.2s">
        <li><b data-count="{{ $stats['guides'] }}">{{ $stats['guides'] }}</b><span>guides</span></li>
        <li><b data-count="{{ $stats['functions'] }}">{{ $stats['functions'] }}</b><span>functions explained</span></li>
        <li><b data-count="{{ $stats['shots'] }}">{{ $stats['shots'] }}</b><span>screenshots</span></li>
        <li><b data-count="{{ $stats['behind'] }}">{{ $stats['behind'] }}</b><span>hidden behaviours</span></li>
    </ul>
</section>

<section class="kb-quick" aria-label="How to use this guide">
    <article class="rv"><span class="kb-chip c-indigo"><x-wimshow.icon name="compass" /></span><h2>1. Pick a topic</h2><p>Browse the groups below or press <kbd>/</kbd> to search by what you want to do, for example “transfer stock”.</p></article>
    <article class="rv" style="--d:.07s"><span class="kb-chip c-emerald"><x-wimshow.icon name="list" /></span><h2>2. Follow the steps</h2><p>Every function has numbered steps, the meaning of each field, and screenshots you can click to enlarge.</p></article>
    <article class="rv" style="--d:.14s"><span class="kb-chip c-amber"><x-wimshow.icon name="zap" /></span><h2>3. Know what runs behind</h2><p>Look for the lightning panels: automatic updates, notifications, schedules and rules you cannot see on screen.</p></article>
    <article class="rv" style="--d:.21s"><span class="kb-chip c-rose"><x-wimshow.icon name="printer" /></span><h2>4. Print what you need</h2><p>Use the print button on any page, or on a single function, for a clean copy to keep on site.</p></article>
</section>

@foreach ($groups as $g)
    @php
        $items = $kb->inGroup($g['slug']);
    @endphp
    @continue($items->isEmpty())
    <section class="kb-group c-{{ $g['color'] }}" id="{{ $g['slug'] }}">
        <header class="rv">
            <span class="kb-chip kb-chip--lg"><x-wimshow.icon :name="$g['icon']" /></span>
            <div><h2>{{ $g['title'] }}</h2><p>{{ $g['blurb'] }}</p></div>
        </header>
        <div class="kb-cards">
            @foreach ($items as $guide)
                <a class="kb-card rv c-{{ $guide['color'] }}" style="--d:{{ min($loop->index, 8) * 0.04 }}s" href="{{ route('wimshow.show', $guide['slug']) }}">
                    <span class="kb-chip"><x-wimshow.icon :name="$guide['icon']" /></span>
                    <h3>{{ $guide['title'] }}</h3>
                    <p>{{ $guide['summary'] }}</p>
                    <footer><span>{{ count($guide['functions']) }} {{ \Illuminate\Support\Str::plural('function', count($guide['functions'])) }}</span><x-wimshow.icon name="arrow-right" /></footer>
                </a>
            @endforeach
        </div>
    </section>
@endforeach

@if ($picked->isNotEmpty())
    <section class="kb-teasers">
        <header class="rv"><span class="kb-chip kb-chip--lg c-indigo"><x-wimshow.icon name="zap" /></span><div><h2>Did you know? Behind the scenes</h2><p>A few things WIMS does automatically, without anyone clicking a button.</p></div></header>
        <div class="kb-cards kb-cards--3">
            @foreach ($picked as $t)
                <a class="kb-card kb-card--behind rv c-{{ $t['guide']['color'] }}" style="--d:{{ $loop->index * 0.06 }}s" href="{{ route('wimshow.show', $t['guide']['slug']) }}#{{ $t['anchor'] }}">
                    <small>{{ $t['guide']['title'] }}</small>
                    <h3>{{ $t['title'] }}</h3>
                    <p>{!! Knowledge::inline(\Illuminate\Support\Str::limit(strip_tags($t['text']), 150)) !!}</p>
                    <footer><span>Read more</span><x-wimshow.icon name="arrow-right" /></footer>
                </a>
            @endforeach
        </div>
    </section>
@endif
@endsection
