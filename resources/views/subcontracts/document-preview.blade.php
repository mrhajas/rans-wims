<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} - Preview</title>
<style>
  :root { --ink:#0f2540; --mute:#64748b; --line:#e3e9f2; --soft:#f4f7fb; --blue:#2f8fe6; --maroon:#7a1c3c; }
  * { box-sizing: border-box; }
  body { margin:0; font: 14px/1.45 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--ink); background:#eef2f8; }
  header.bar { position: sticky; top: 0; z-index: 5; background:#fff; border-bottom:1px solid var(--line); box-shadow:0 8px 24px -18px rgba(31,70,130,.5); padding:12px 20px; display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
  .ico { width:40px; height:40px; border-radius:12px; background:#dcf7e8; color:#0d7a43; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:12px; }
  .ttl { min-width:0; flex:1 1 260px; } .ttl b { display:block; font-size:16px; overflow-wrap:anywhere; } .ttl span { color:var(--mute); font-size:12.5px; }
  .btn { display:inline-flex; align-items:center; gap:7px; height:36px; padding:0 15px; border-radius:999px; font-weight:700; font-size:13px; text-decoration:none; border:0; cursor:pointer; background:#e8f2fd; color:#1d6fd1; box-shadow:0 0 0 1px rgba(47,143,230,.25), 0 8px 16px -8px rgba(31,70,130,.45); }
  .btn.teal { background:#e6f6f4; color:#0b6f66; box-shadow:0 0 0 1px rgba(15,148,136,.25), 0 8px 16px -8px rgba(15,148,136,.45); }
  main { padding:18px 20px 40px; max-width: 1500px; margin: 0 auto; }
  .tabs { display:flex; gap:6px; flex-wrap:wrap; margin: 0 0 12px; }
  .tab { border:0; cursor:pointer; padding:7px 14px; border-radius:999px; background:#fff; color:var(--ink); font-weight:700; font-size:12.5px; box-shadow:0 0 0 1px var(--line); }
  .tab.on { background: linear-gradient(135deg,#3d97ee,#1d6fd1); color:#fff; box-shadow:0 10px 18px -10px rgba(29,111,209,.8); }
  .tab small { opacity:.7; font-weight:600; }
  .sheet { display:none; } .sheet.on { display:block; }
  .wrap { overflow:auto; background:#fff; border-radius:16px; box-shadow:0 0 0 1px var(--line), 0 18px 30px -22px rgba(31,70,130,.5); max-height: calc(100vh - 190px); }
  table { border-collapse: separate; border-spacing:0; min-width:100%; font-size:12.5px; }
  th, td { padding:6px 10px; border-bottom:1px solid #eef2f8; border-right:1px solid #f1f4f9; white-space:nowrap; max-width:380px; overflow:hidden; text-overflow:ellipsis; }
  thead th { position:sticky; top:0; background:var(--soft); color:var(--mute); font-size:11px; letter-spacing:.05em; z-index:2; text-align:center; }
  td.n, th.n { position:sticky; left:0; background:var(--soft); color:var(--mute); text-align:right; font-size:11px; z-index:1; }
  td.num { text-align:right; font-variant-numeric: tabular-nums; font-weight:700; color:var(--maroon); }
  tr:hover td { background:#f7fafd; } tr:hover td.n { background:var(--soft); }
  .note { margin:12px 0; padding:12px 14px; border-radius:14px; background:#fff3dc; color:#8a5200; font-weight:600; }
  .cut { margin:10px 2px; color:var(--mute); font-size:12.5px; }
  .empty { padding:40px; text-align:center; color:var(--mute); background:#fff; border-radius:16px; }
  @media print { header.bar .btn, .tabs { display:none; } .wrap { max-height:none; box-shadow:none; } .sheet { display:block !important; page-break-after: always; } }
</style>
</head>
<body>
<header class="bar">
  <span class="ico">{{ strtoupper(pathinfo($title, PATHINFO_EXTENSION)) ?: 'DOC' }}</span>
  <div class="ttl"><b>{{ $title }}</b><span>{{ $agreement }} · {{ number_format($size / 1024, 0) }} KB · Preview (read only)</span></div>
  <a class="btn teal" href="{{ $download }}">&#8681; Download</a>
  <button class="btn" onclick="window.print()">&#128424; Print</button>
  <button class="btn" onclick="window.close()">&#10005; Close</button>
</header>
<main>
@if($note)
  <div class="note">{{ $note }} <a href="{{ $download }}">Download</a></div>
@endif
@if(count($sheets) > 1)
  <div class="tabs" role="tablist">
    @foreach($sheets as $i => $s)
      <button class="tab {{ $i === 0 ? 'on' : '' }}" role="tab" data-i="{{ $i }}">{{ $s['name'] }}{!! $s['hidden'] ? ' <small>(hidden)</small>' : '' !!}</button>
    @endforeach
  </div>
@endif
@forelse($sheets as $i => $s)
  <section class="sheet {{ $i === 0 ? 'on' : '' }}" data-i="{{ $i }}">
    <div class="wrap">
      <table>
        <thead><tr><th class="n">#</th>@for($c = 1; $c <= $s['cols']; $c++)<th>{{ \App\Http\Controllers\Subcontracts\OpeningController::colName($c) }}</th>@endfor</tr></thead>
        <tbody>
        @foreach($s['rows'] as $r)
          <tr><td class="n">{{ $r['n'] }}</td>@foreach($r['cells'] as $k => $v)<td class="{{ $r['num'][$k] ? 'num' : '' }}" title="{{ $v }}">{{ $v }}</td>@endforeach</tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @if($s['cut'])<p class="cut">Showing the first {{ count($s['rows']) }} of {{ number_format($s['total_rows']) }} rows. Download the file to see everything.</p>@endif
  </section>
@empty
  @if(! $note)<div class="empty">This file has no data to show.</div>@endif
@endforelse
</main>
<script>
  document.querySelectorAll('.tab').forEach(function (b) { b.addEventListener('click', function () {
    document.querySelectorAll('.tab, .sheet').forEach(function (e) { e.classList.remove('on'); });
    b.classList.add('on'); document.querySelector('.sheet[data-i="' + b.dataset.i + '"]').classList.add('on');
  }); });
</script>
</body>
</html>
