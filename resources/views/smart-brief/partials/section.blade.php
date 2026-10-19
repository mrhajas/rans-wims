@if(($s['type'] ?? '') === 'paragraphs')
    <div class="section-title">{{ $s['title'] }}</div>
    @forelse($s['items'] as $line)
        <p class="para">{{ $line }}</p>
    @empty
        <p class="para muted">Nothing to summarise for this period.</p>
    @endforelse
@elseif(($s['type'] ?? '') === 'table')
    <div class="section-title">{{ $s['title'] }}</div>
    <table class="items">
        <thead><tr>@foreach($s['columns'] as $c)<th class="{{ $c['align'] === 'right' ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($s['rows'] as $row)
            <tr class="{{ $loop->even ? 'zebra' : '' }}">@foreach($s['columns'] as $c)<td class="{{ $c['align'] === 'right' ? 'num' : '' }}">{{ $cell($row[$c['key']] ?? '') }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($s['columns']) }}" class="empty">{{ $s['empty'] ?? 'No data.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
