@php
    $groups = $kb->groups();
    $all = $kb->guides();
@endphp
<aside class="kb-nav" id="kb-nav" aria-label="Guides">
    <div class="kb-nav__scroll">
        <a class="kb-nav__home {{ ($current ?? null) === null ? 'is-current' : '' }}" href="{{ route('wimshow.index') }}"><x-wimshow.icon name="home" /> All guides</a>
        @foreach ($groups as $g)
            @php
                $items = $all->where('group', $g['slug']);
            @endphp
            @continue($items->isEmpty())
            <details class="kb-nav__group c-{{ $g['color'] }}" @if ($items->has($current ?? '')) open @endif>
                <summary><span class="kb-chip"><x-wimshow.icon :name="$g['icon']" /></span><span>{{ $g['title'] }}</span><em>{{ $items->count() }}</em><x-wimshow.icon name="chevron-down" class="kb-i kb-nav__caret" /></summary>
                <ul>
                    @foreach ($items as $slug => $item)
                        <li><a href="{{ route('wimshow.show', $slug) }}" class="{{ ($current ?? null) === $slug ? 'is-current' : '' }}" @if (($current ?? null) === $slug) aria-current="page" @endif><x-wimshow.icon :name="$item['icon']" /><span>{{ $item['title'] }}</span></a></li>
                    @endforeach
                </ul>
            </details>
        @endforeach
    </div>
</aside>
