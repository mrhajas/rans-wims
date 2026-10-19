<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR label {{ $reference }}</title>
<style>
  @page { size: A4; margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Helvetica Neue', Arial, sans-serif; color: #111827; background: #f1f5f9; }
  .bar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; padding: 10px 14px; background: #fff; border-bottom: 1px solid #e2e8f0; }
  .bar b { flex: 1; font-size: 14px; } .bar button { padding: 8px 16px; border: 0; border-radius: 10px; font-weight: 700; color: #fff; background: #1c3f66; cursor: pointer; }
  .sheet { display: grid; grid-template-columns: repeat({{ $columns }}, max-content); gap: 6mm; justify-content: center; padding: 10mm; }
  .label { width: {{ $mm + 10 }}mm; padding: 4mm; text-align: center; background: #fff; {{ $frame ? 'border: 0.4mm solid #111827; border-radius: 3mm;' : '' }} break-inside: avoid; page-break-inside: avoid; }
  .label img { width: {{ $mm }}mm; height: {{ $mm }}mm; display: block; margin: 0 auto; image-rendering: pixelated; }
  .kind { margin-top: 2mm; font-size: 2.6mm; letter-spacing: .08em; text-transform: uppercase; color: #475569; font-weight: 700; }
  .ref { margin-top: .6mm; font-size: 3.6mm; font-weight: 800; word-break: break-word; }
  .ttl { margin-top: .6mm; font-size: 2.7mm; color: #475569; word-break: break-word; }
  .co { margin-top: 1.4mm; font-size: 2.3mm; color: #64748b; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { padding: 0; } }
</style>
</head>
<body>
<div class="bar"><b>{{ $kind }} · {{ $reference }} · {{ $copies }} {{ $copies === 1 ? 'label' : 'labels' }} · {{ $mm }} mm</b><button onclick="window.print()">Print</button></div>
<div class="sheet">
@for ($i = 0; $i < $copies; $i++)
  <div class="label">
    <img src="{{ $image }}" alt="QR code">
    @if ($caption)
      <div class="kind">{{ $kind }}</div>
      <div class="ref">{{ $reference }}</div>
      @if ($title)<div class="ttl">{{ $title }}</div>@endif
      @if ($company)<div class="co">{{ $company }}</div>@endif
    @endif
  </div>
@endfor
</div>
<script>window.addEventListener('load', function () { if (location.search.indexOf('auto=1') !== -1) setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
