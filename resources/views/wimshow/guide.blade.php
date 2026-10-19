@extends('wimshow.layout', ['current' => $guide['slug'], 'title' => $guide['title'], 'description' => $guide['summary']])

@php
    use App\Support\Wimshow\Knowledge;
    $shotCount = collect($guide['functions'])->sum(fn ($f) => count($f['shots']));
@endphp

@section('content')
<article class="kb-guide c-{{ $guide['color'] }}" data-guide="{{ $guide['slug'] }}">
    <nav class="kb-crumbs" aria-label="Breadcrumb">
        <a href="{{ route('wimshow.index') }}">All guides</a><x-wimshow.icon name="chevron-down" class="kb-i kb-crumbs__sep" />
        @if ($group)<a href="{{ route('wimshow.index') }}#{{ $group['slug'] }}">{{ $group['title'] }}</a><x-wimshow.icon name="chevron-down" class="kb-i kb-crumbs__sep" />@endif
        <span aria-current="page">{{ $guide['title'] }}</span>
    </nav>

    <header class="kb-head rv" id="top">
        <span class="kb-chip kb-chip--xl"><x-wimshow.icon :name="$guide['icon']" /></span>
        <div class="kb-head__text">
            <p class="kb-eyebrow">{{ $group['title'] ?? 'Guide' }}</p>
            <h1>{{ $guide['title'] }}</h1>
            <p class="kb-head__summary">{!! Knowledge::inline($guide['summary']) !!}</p>
            <ul class="kb-meta">
                @if ($guide['where'])<li><x-wimshow.icon name="map-pin" /><span><b>Where to find it</b>{!! Knowledge::inline($guide['where']) !!}</span></li>@endif
                <li><x-wimshow.icon name="list" /><span><b>Functions</b>{{ count($guide['functions']) }} explained</span></li>
                @if ($shotCount)<li><x-wimshow.icon name="image" /><span><b>Screenshots</b>{{ $shotCount }}</span></li>@endif
                @if (count($guide['behind']))<li><x-wimshow.icon name="zap" /><span><b>Behind the scenes</b>{{ count($guide['behind']) }} automatic behaviours</span></li>@endif
            </ul>
        </div>
        <div class="kb-head__actions">
            @if ($guide['path'])<a class="kb-btn kb-btn--primary" href="{{ url($guide['path']) }}" target="_blank" rel="noopener"><x-wimshow.icon name="external" /> Open in WIMS</a>@endif
            <button class="kb-btn" type="button" data-print-page><x-wimshow.icon name="printer" /> Print guide</button>
        </div>
    </header>

    @if (count($guide['overview']))
        <section class="kb-section kb-overview rv" id="overview" data-spy>
            <h2 class="kb-h2"><x-wimshow.icon name="info" /> Overview</h2>
            @foreach ($guide['overview'] as $p)<p>{!! Knowledge::inline($p) !!}</p>@endforeach
            @if (count($guide['concepts']))
                <dl class="kb-concepts">
                    @foreach ($guide['concepts'] as [$term, $meaning])
                        <div><dt>{{ $term }}</dt><dd>{!! Knowledge::inline($meaning) !!}</dd></div>
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    @if (count($guide['permissions']))
        <section class="kb-section rv" id="permissions" data-spy>
            <h2 class="kb-h2"><x-wimshow.icon name="lock" /> Who can do what</h2>
            <p class="kb-muted">{!! Knowledge::inline('Access is controlled by permissions that a Super Admin gives to each role (see [[roles-and-permissions-explained|Roles and permissions]]). Super Admins can always do everything.') !!}</p>
            <div class="kb-perms">
                @foreach ($guide['permissions'] as $permission => $meaning)
                    <div><code>{{ $permission }}</code><span>{!! Knowledge::inline($meaning) !!}</span></div>
                @endforeach
            </div>
        </section>
    @endif

    @foreach ($guide['functions'] as $fn)
        <section class="kb-section kb-fn rv" id="{{ $fn['id'] }}" data-spy data-fn>
            <header class="kb-fn__head">
                <span class="kb-fn__no">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span class="kb-chip"><x-wimshow.icon :name="$fn['icon']" /></span>
                <h2>{{ $fn['title'] }}</h2>
                <div class="kb-fn__tools">
                    <button class="kb-btn kb-btn--icon kb-btn--sm kb-tip" type="button" data-copy-link="{{ $fn['id'] }}" data-tip="Copy link" aria-label="Copy a link to this function"><x-wimshow.icon name="link" /></button>
                    <button class="kb-btn kb-btn--icon kb-btn--sm kb-tip" type="button" data-print-fn data-tip="Print this function" aria-label="Print this function only"><x-wimshow.icon name="printer" /></button>
                </div>
            </header>
            @if ($fn['summary'])<p class="kb-fn__summary">{!! Knowledge::inline($fn['summary']) !!}</p>@endif

            @if (count($fn['steps']))
                <ol class="kb-steps">
                    @foreach ($fn['steps'] as $step)<li><span>{!! Knowledge::inline($step) !!}</span></li>@endforeach
                </ol>
            @endif

            @if (count($fn['fields']))
                <div class="kb-table-wrap">
                    <table class="kb-table">
                        <thead><tr><th>Field / option</th><th>What it does</th></tr></thead>
                        <tbody>@foreach ($fn['fields'] as [$field, $meaning])<tr><th scope="row">{{ $field }}</th><td>{!! Knowledge::inline($meaning) !!}</td></tr>@endforeach</tbody>
                    </table>
                </div>
            @endif

            @if (count($fn['shots']))
                <div class="kb-shots {{ count($fn['shots']) > 1 ? 'kb-shots--multi' : '' }}">
                    @foreach ($fn['shots'] as $shot)@include('wimshow.shot', ['shot' => $shot])@endforeach
                </div>
            @endif

            @foreach ($fn['tips'] as $tip)
                <aside class="kb-note kb-note--tip"><x-wimshow.icon name="lightbulb" /><div><b>Tip</b><p>{!! Knowledge::inline($tip) !!}</p></div></aside>
            @endforeach
            @foreach ($fn['warnings'] as $warning)
                <aside class="kb-note kb-note--warn"><x-wimshow.icon name="alert" /><div><b>Be careful</b><p>{!! Knowledge::inline($warning) !!}</p></div></aside>
            @endforeach

            @if (! empty($fn['inner']['items']))
                <aside class="kb-inner">
                    <h3><x-wimshow.icon name="zap" /> {{ $fn['inner']['title'] ?? 'What happens behind the scenes' }}</h3>
                    <ul>@foreach ($fn['inner']['items'] as $item)<li>{!! Knowledge::inline($item) !!}</li>@endforeach</ul>
                </aside>
            @endif
        </section>
    @endforeach

    @if (count($guide['behind']))
        <section class="kb-section kb-behind rv" id="behind" data-spy>
            <h2 class="kb-h2"><x-wimshow.icon name="zap" /> Behind the scenes</h2>
            <p class="kb-muted">These things happen automatically. You will not see a button for them, but it helps to know they exist.</p>
            <div class="kb-behind__grid">
                @foreach ($guide['behind'] as $i => $b)
                    <article id="behind-{{ $i + 1 }}" class="kb-behind__item">
                        <span class="kb-chip"><x-wimshow.icon :name="$b['icon'] ?? 'zap'" /></span>
                        <h3>{{ $b['title'] }}</h3>
                        <p>{!! Knowledge::inline($b['text']) !!}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if (count($guide['faq']))
        <section class="kb-section rv" id="faq" data-spy>
            <h2 class="kb-h2"><x-wimshow.icon name="help" /> Common questions</h2>
            <div class="kb-faq">
                @foreach ($guide['faq'] as $item)
                    <details><summary>{{ $item['q'] }}<x-wimshow.icon name="plus" /></summary><p>{!! Knowledge::inline($item['a']) !!}</p></details>
                @endforeach
            </div>
        </section>
    @endif

    @if ($related->isNotEmpty())
        <section class="kb-section kb-related rv" id="related">
            <h2 class="kb-h2"><x-wimshow.icon name="link" /> Related guides</h2>
            <div class="kb-cards kb-cards--3">
                @foreach ($related as $r)
                    <a class="kb-card c-{{ $r['color'] }}" href="{{ route('wimshow.show', $r['slug']) }}"><span class="kb-chip"><x-wimshow.icon :name="$r['icon']" /></span><h3>{{ $r['title'] }}</h3><p>{{ $r['summary'] }}</p></a>
                @endforeach
            </div>
        </section>
    @endif

    <nav class="kb-pager" aria-label="Previous and next guide">
        @if ($previous)<a class="kb-pager__prev" href="{{ route('wimshow.show', $previous['slug']) }}"><x-wimshow.icon name="arrow-left" /><span><small>Previous</small>{{ $previous['title'] }}</span></a>@else<span></span>@endif
        @if ($next)<a class="kb-pager__next" href="{{ route('wimshow.show', $next['slug']) }}"><span><small>Next</small>{{ $next['title'] }}</span><x-wimshow.icon name="arrow-right" /></a>@endif
    </nav>
</article>
@endsection

@section('toc')
<aside class="kb-toc" aria-label="On this page">
    <p class="kb-toc__title">On this page</p>
    <ul data-toc>
        @if (count($guide['overview']))<li><a href="#overview">Overview</a></li>@endif
        @if (count($guide['permissions']))<li><a href="#permissions">Who can do what</a></li>@endif
        @foreach ($guide['functions'] as $fn)<li><a href="#{{ $fn['id'] }}">{{ $fn['title'] }}</a></li>@endforeach
        @if (count($guide['behind']))<li><a href="#behind" class="kb-toc__zap">Behind the scenes</a></li>@endif
        @if (count($guide['faq']))<li><a href="#faq">Common questions</a></li>@endif
    </ul>
    <div class="kb-toc__tools">
        <button class="kb-btn kb-btn--sm" type="button" data-print-page><x-wimshow.icon name="printer" /> Print guide</button>
        <a class="kb-btn kb-btn--sm" href="#top"><x-wimshow.icon name="arrow-up" /> Top</a>
    </div>
</aside>
@endsection
