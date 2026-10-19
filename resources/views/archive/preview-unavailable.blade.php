<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ isset($file) && $file ? $file->original_name : 'Document' }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f1f5f9; --card: #ffffff; --ink: #0f172a; --mute: #64748b; --line: #e2e8f0; --brand: #1c3f66; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1120; --card: #131c31; --ink: #e2e8f0; --mute: #94a3b8; --line: #243049; --brand: #7aa7dc; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--ink); font-family: Helvetica, Arial, sans-serif; }
        .box { width: 100%; max-width: 440px; text-align: center; padding: 28px 24px; border-radius: 18px; background: var(--card); border: 1px solid var(--line); box-shadow: 0 18px 40px -18px rgba(15, 23, 42, .35); }
        .ico { width: 54px; height: 54px; margin: 0 auto 14px; border-radius: 16px; display: grid; place-items: center; background: var(--brand); color: #fff; font-size: 26px; }
        h1 { margin: 0 0 6px; font-size: 1.05rem; }
        p { margin: 0 0 16px; font-size: .9rem; color: var(--mute); overflow-wrap: anywhere; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 12px; background: var(--brand); color: #fff; font-weight: 700; font-size: .85rem; text-decoration: none; }
        @media (prefers-color-scheme: dark) { .btn { color: #0b1120; } }
    </style>
</head>
<body>
    <div class="box">
        <div class="ico">!</div>
        @if(! empty($message))
            <h1>Unable to open this document</h1>
            <p>{{ $message }}</p>
        @else
            <h1>Preview unavailable for this file type.</h1>
            <p>{{ $file->original_name }} ({{ strtoupper($file->extension) }}) can't be shown safely in the browser.</p>
            @if($canDownload)
                <a class="btn" href="{{ $downloadUrl }}">Download</a>
            @endif
        @endif
    </div>
</body>
</html>
