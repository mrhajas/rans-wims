{{--
    Shared outer shell for every WIMS email — the centered, rounded white
    card on a soft grey ground. Paired with shell-close.blade.php around
    whatever header/content/footer partials a template includes in between.
    Table-based (not flex/grid) throughout, since Outlook's rendering
    engine ignores both.

    Params:
      $emailTitle - the <title> tag text (default: RANS WIMS)
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="ie=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $emailTitle ?? 'RANS WIMS' }}</title>
</head>
<body style="margin:0; padding:0; background:#eef2f7; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7; padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 1px 2px rgba(15,23,42,0.05), 0 12px 28px rgba(15,23,42,0.1);">
