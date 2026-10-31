<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  @php($faviconVersion = @filemtime(public_path('icons/favicon-32x32.png')) ?: time())
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png?v={{ $faviconVersion }}" />
  <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png?v={{ $faviconVersion }}" />
  <link rel="icon" type="image/png" sizes="48x48" href="/icons/favicon-48x48.png?v={{ $faviconVersion }}" />
  <link rel="shortcut icon" href="/icons/favicon-32x32.png?v={{ $faviconVersion }}" />
  <link rel="manifest" href="{{ asset('/manifest.webmanifest') }}">
  <meta name="theme-color" content="#27447a" />
  <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-180x180.png?v={{ $faviconVersion }}" />
  <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152x152.png?v={{ $faviconVersion }}" />
  <link rel="apple-touch-icon" sizes="144x144" href="/icons/icon-144x144.png?v={{ $faviconVersion }}" />
  <link rel="mask-icon" href="/icons/icon-512x512.png?v={{ $faviconVersion }}" color="#27447a" />
  <link rel="preload" as="image" href="/icons/icon-192x192.png?v={{ $faviconVersion }}" fetchpriority="high" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black" />
  <meta name="apple-mobile-web-app-title" content="WIMS" />
  <meta name="application-name" content="WIMS" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title inertia>{{ config('app.name', 'WIMS') }}</title>

  <!-- Fonts -->
  {!! \App\Support\Typography::linksHtml() !!}
  <!-- the Pro menu is set in Montserrat Light and Regular only -->
  <!-- these two stylesheets do not hold up the first paint: they apply as soon as they arrive (the loading screen covers the page until then) -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400&display=swap" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400&display=swap"></noscript>

  <!-- Font Awesome Icons -->
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>

  <!-- Scripts -->
  <script>
    window.Locale = '{{ app()->getLocale() }}'
  </script>
  <script>
    window.__wimsInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      window.__wimsInstallPrompt = event;
      window.dispatchEvent(new CustomEvent('wims-install-prompt-ready'));
    });
  </script>
  <script>
    (function () {
      var host = window.location.hostname;
      var isLocal = host === '127.0.0.1' || host === 'localhost'
        || /^192\.168\.\d{1,3}\.\d{1,3}$/.test(host)
        || /^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)
        || /^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/.test(host);
      if (!isLocal) return;

      var resetKey = 'wims-local-sw-reset-v1';
      if (window.sessionStorage.getItem(resetKey)) return;

      var jobs = [];
      if ('serviceWorker' in navigator) {
        jobs.push(
          navigator.serviceWorker.getRegistrations().then(function (regs) {
            return Promise.all(regs.map(function (reg) {
              return reg.unregister();
            }));
          })
        );
      }

      if ('caches' in window) {
        jobs.push(
          caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
              return caches.delete(key);
            }));
          })
        );
      }

      Promise.all(jobs).finally(function () {
        window.sessionStorage.setItem(resetKey, '1');
        window.location.reload();
      });
    })();
  </script>
  <style>
    .app-loading {
      top: 0px;
      left: 0px;
      right: 0px;
      bottom: 0px;
      width: 100%;
      z-index: 40;
      display: flex;
      position: fixed;
      min-height: 100vh;
      align-items: center;
      flex-direction: column;
      justify-content: center;
    }

    .app-loading.bg-gray-50 {
      --tw-bg-opacity: 1;
      background-color: rgb(249 250 251 / var(--tw-bg-opacity));
    }

    .dark .app-loading.dark\:bg-gray-900 {
      --tw-bg-opacity: 1;
      background-color: rgb(17 24 39 / var(--tw-bg-opacity));
    }

    /* ===== Loading screen: five blue squares hop in a wave and light up with the progress; the four corner brackets stay ===== */
    .app-loading { overflow: hidden; background: radial-gradient(120% 90% at 50% 38%, #e6f0ff 0%, #f5f9ff 48%, #ffffff 100%); font-family: Nunito, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; }
    .wl-pct, .wl-wims, .wl-status, .wl-lab, .wl-lab span, .wl-footer { font-family: Nunito, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif !important; }
    .app-loading.bg-gray-50 { background: radial-gradient(120% 90% at 50% 38%, #e6f0ff 0%, #f5f9ff 48%, #ffffff 100%); }
    .wl-grid { position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(37, 99, 235, 0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(37, 99, 235, 0.07) 1px, transparent 1px); background-size: 34px 34px; -webkit-mask-image: radial-gradient(60% 55% at 50% 45%, #000 10%, transparent 75%); mask-image: radial-gradient(60% 55% at 50% 45%, #000 10%, transparent 75%); }
    .wl-corner { position: absolute; width: 46px; height: 46px; border: 0 solid rgba(37, 99, 235, 0.7); filter: drop-shadow(0 0 5px rgba(59, 130, 246, 0.6)); }
    .wl-tl { top: 9%; left: 8%; border-top-width: 2px; border-left-width: 2px; border-top-left-radius: 4px; }
    .wl-tr { top: 9%; right: 8%; border-top-width: 2px; border-right-width: 2px; border-top-right-radius: 4px; }
    .wl-bl { bottom: 9%; left: 8%; border-bottom-width: 2px; border-left-width: 2px; border-bottom-left-radius: 4px; }
    .wl-br { bottom: 9%; right: 8%; border-bottom-width: 2px; border-right-width: 2px; border-bottom-right-radius: 4px; }
    .app-loading:not(.wl-font) .wl-center, .app-loading:not(.wl-font) .wl-status, .app-loading:not(.wl-font) .wl-footer { visibility: hidden; }
    .wl-stage { position: relative; z-index: 1; display: grid; place-items: center; width: min(420px, 90vw); height: 120px; }
    .wl-flare { position: absolute; left: -6%; right: -6%; top: -20%; bottom: -20%; z-index: 0; pointer-events: none; background: radial-gradient(ellipse at 50% 55%, rgba(59, 130, 246, 0.16) 0%, rgba(96, 197, 250, 0.08) 42%, rgba(96, 197, 250, 0) 72%); animation: wl-breathe 3.2s ease-in-out infinite; }
    @keyframes wl-breathe { 0%, 100% { opacity: 0.7; transform: scale(0.97); } 50% { opacity: 1; transform: scale(1.04); } }
    /* five rounded squares in a row, deep blue to light blue: they hop one after another in a wave, and light up (20% each) as WIMS loads */
    .wl-sqs { position: relative; z-index: 2; display: flex; align-items: center; justify-content: center; gap: 16px; height: 100%; }
    .wl-sqs i { display: block; width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(145deg, var(--c1), var(--c2)); box-shadow: 0 12px 18px -10px var(--c2), inset 0 1px 0 rgba(255, 255, 255, 0.45); opacity: 0.22; transform: translateY(0) scale(0.9); animation: wl-hop 1.6s cubic-bezier(0.45, 0, 0.35, 1) infinite; animation-delay: calc(var(--i) * 0.13s); transition: opacity 0.25s linear; }
    .wl-sqs i:nth-child(1) { --c1: #2f7bf3; --c2: #3b82f6; } .wl-sqs i:nth-child(2) { --c1: #3b8cf5; --c2: #4a93f7; } .wl-sqs i:nth-child(3) { --c1: #4a9df7; --c2: #55a5f8; } .wl-sqs i:nth-child(4) { --c1: #57b0f9; --c2: #5fb6f9; } .wl-sqs i:nth-child(5) { --c1: #64c0fa; --c2: #6cc8fb; }
    @keyframes wl-hop { 0%, 55%, 100% { transform: translateY(0) scale(0.9); } 25% { transform: translateY(-16px) scale(1.08); } }
    @media (max-width: 420px) { .wl-sqs { gap: 11px; } .wl-sqs i { width: 30px; height: 30px; border-radius: 8px; } }
    .wl-meta { position: relative; z-index: 2; display: grid; justify-items: center; gap: 2px; margin-top: 4px; }
    .wl-wims { font-size: 0.75rem; font-weight: 800; letter-spacing: 0.32em; padding-left: 0.32em; color: #3b6fd6; opacity: 0.85; }
    .wl-pct { font-size: 2.585rem; font-weight: 400; line-height: 1; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; color: #1d4ed8; text-shadow: 0 0 22px rgba(59, 130, 246, 0.5), 0 0 16px rgba(251, 191, 36, 0.35), 0 0 4px rgba(147, 197, 253, 0.9); }
    .wl-status { position: relative; z-index: 1; margin-top: 14px; font-size: 0.88rem; font-weight: 700; letter-spacing: 0.04em; color: #2f5fc4; }
    .wl-status i { font-style: normal; display: inline-block; width: 1.4em; overflow: hidden; vertical-align: bottom; text-align: left; animation: wl-dots 1.3s steps(4, end) infinite; }
    @keyframes wl-dots { from { width: 0; } to { width: 1.4em; } }
      background: linear-gradient(180deg, #ffe08a 0%, #f5b83d 45%, #d98e12 100%); box-shadow: inset 0 2px 3px rgba(255, 255, 255, 0.55), inset 0 -3px 4px rgba(120, 60, 0, 0.35); }
    /* the status text: each message is written letter by letter, rising into place with a small tilt, and the old one lifts away in the same way */
    .wl-status { min-height: 1.5em; }
    .wl-labwrap { position: relative; height: 1.5em; width: min(380px, 80vw); margin: 0 auto; overflow: hidden; }
    .wl-lab { position: absolute; left: 0; right: 0; top: 0; text-align: center; white-space: nowrap; font-weight: 600; }
    .wl-lab span { display: inline-block; opacity: 0; transform: translateY(115%) rotate(9deg); animation: wl-char-in 0.55s cubic-bezier(0.2, 0.9, 0.3, 1.25) forwards; animation-delay: calc(var(--i) * 32ms); }
    .wl-lab.wl-leave span { animation: wl-char-out 0.38s ease-in forwards; animation-delay: calc(var(--i) * 14ms); }
    @keyframes wl-char-in { to { opacity: 1; transform: none; } }
    @keyframes wl-char-out { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateY(-115%) rotate(-7deg); } }

    /* dark theme: the same HUD on deep navy */
    .dark .app-loading, html.dark .app-loading { background: radial-gradient(120% 90% at 50% 38%, #0b2260 0%, #050b22 55%, #02050f 100%) !important; }
    .dark .wl-grid { background-image: linear-gradient(rgba(96, 165, 250, 0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(96, 165, 250, 0.1) 1px, transparent 1px); }
    .dark .wl-corner { border-color: rgba(96, 200, 255, 0.85); }
    .dark .wl-wims { color: #8fc2ff; } .dark .wl-pct { color: #cfe6ff; text-shadow: 0 0 20px rgba(56, 189, 248, 0.9), 0 0 4px rgba(191, 227, 255, 0.9); } .dark .wl-status { color: #7dd3fc; }
    @media (prefers-reduced-motion: reduce) { .wl-flare, .wl-sqs i, .wl-status i, .wl-lab span { animation: none; transform: none; } }
    /* footer label, the same wording as the dashboard footer */
    .wl-footer { position: absolute; left: 0; right: 0; bottom: max(14px, env(safe-area-inset-bottom)); z-index: 1; padding: 0 14px; text-align: center; font-size: 12px; line-height: 1.5; color: #9ca3af; }
    .wl-footer p { margin: 0; }
    .wl-footer .wl-lic { font-weight: 700; background: linear-gradient(90deg, #16a34a, #f59e0b, #16a34a); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: wl-shimmer 6s linear infinite; }
    .wl-footer .wl-credit { font-weight: 600; background: linear-gradient(90deg, #9ca3af, #38bdf8, #9ca3af); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: wl-shimmer 8s linear infinite; }
    .wl-footer .wl-terms { display: inline-flex; align-items: center; gap: 5px; margin-top: 2px; color: #9ca3af; }
    .wl-footer .wl-terms svg { width: 13px; height: 13px; fill: currentColor; }
    @keyframes wl-shimmer { from { background-position: 0 0; } to { background-position: 250% 0; } }
    .dark .wl-footer, .dark .wl-footer .wl-terms { color: #94a3b8; }
    @media (max-width: 639px) { .wl-footer .wl-long { display: none; } }
    @media (min-width: 640px) { .wl-footer .wl-short { display: none; } }
    @media (prefers-reduced-motion: reduce) { .wl-footer .wl-lic, .wl-footer .wl-credit { animation: none; } }
  </style>
  {!! \App\Support\ZiggyScript::tag() !!}
  @vite('resources/js/app.js', '')
  {!! \App\Support\Typography::styleHtml() !!}
  @inertiaHead
</head>

<body class="font-sans antialiased">
  <div id="app-loading" class="app-loading bg-gray-50 dark:bg-gray-900">
    <div class="wl-grid"></div>
    <span class="wl-corner wl-tl"></span><span class="wl-corner wl-tr"></span><span class="wl-corner wl-bl"></span><span class="wl-corner wl-br"></span>
    <div class="wl-stage" aria-hidden="true">
      <div class="wl-flare"></div>
      <div class="wl-sqs" id="wl-sqs"><i style="--i: 0"></i><i style="--i: 1"></i><i style="--i: 2"></i><i style="--i: 3"></i><i style="--i: 4"></i></div>
    </div>
    <div class="wl-meta"><div class="wl-wims">WIMS</div><div class="wl-pct" id="wims-loader-percent">0%</div></div>
    <div class="wl-status"><div class="wl-labwrap" id="wl-labwrap" aria-live="polite"></div></div>
    @php($wlSettings = $page['props']['settings'] ?? [])
    @php($wlName = $wlSettings['app_name'] ?? 'RANS Workflow Integrated Management System')
    @php($wlVersion = $wlSettings['version_label'] ?? 'V7.0')
    @php($wlCredit = $wlSettings['footer_credit'] ?? 'Developed by Extrion')
    @php($wlLicence = $page['props']['licenceStatus']['label'] ?? null)
    <div class="wl-footer">
      <p class="wl-long">{{ $wlName }} @if($wlLicence)<span class="wl-lic">({{ $wlLicence }})</span>@endif, ({{ $wlVersion }}). <span class="wl-credit">{{ $wlCredit }}</span></p>
      <p class="wl-short">RANS WIMS @if($wlLicence)<span class="wl-lic">({{ $wlLicence }})</span>@endif ({{ $wlVersion }}). <span class="wl-credit">{{ $wlCredit }}</span></p>
      <p class="wl-terms"><svg viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg><span>Terms and Conditions</span></p>
    </div>
  </div>
  <script>
    (function () {
      var percent = 0;
      var target = 90;
      var finished = false;
      var lastTime = null;
      var rafId = null;
      // show the loader's text only once the WIMS font (Nunito) is ready, so the percentage is never drawn in a fallback face (1.5 s at most)
      (function () {
        var wrap = document.getElementById('app-loading');
        var show = function () { if (wrap) wrap.classList.add('wl-font'); };
        if (document.fonts && document.fonts.load) {
          Promise.all([document.fonts.load('400 38px Nunito'), document.fonts.load('600 12px Nunito')]).then(show, show);
          setTimeout(show, 1500);
        } else { show(); }
      })();
      // three messages that take turns for as long as WIMS is still loading, then start again from the first; each is written letter by letter
      var labels = ['Loading WIMS Modules...', 'Loading WIMS Data...', 'Checking Your Access...'];
      var labelIdx = 0;
      var labelWrap = document.getElementById('wl-labwrap');
      function showLabel(text) {
        if (!labelWrap) return;
        var old = labelWrap.querySelector('.wl-lab:not(.wl-leave)');
        if (old) { old.classList.add('wl-leave'); setTimeout(function () { if (old.parentNode) old.parentNode.removeChild(old); }, 900); }
        var el = document.createElement('b');
        el.className = 'wl-lab';
        for (var i = 0; i < text.length; i++) {
          var c = document.createElement('span');
          c.style.setProperty('--i', i);
          c.textContent = text.charAt(i) === ' ' ? '\u00a0' : text.charAt(i);
          el.appendChild(c);
        }
        labelWrap.appendChild(el);
      }
      showLabel(labels[0]);
      var labelTimer = setInterval(function () {
        labelIdx = (labelIdx + 1) % labels.length;
        showLabel(labels[labelIdx]);
      }, 2300);
      var sqEls = Array.prototype.slice.call(document.querySelectorAll('#wl-sqs i'));
      var percentEl = document.getElementById('wims-loader-percent');
      var finishSpin = function () {};
      // each square lights up as its fifth of the progress is reached (the one being filled glows partly)
      function render() {
        var p = Math.min(percent, 100) / 20;
        sqEls.forEach(function (el, i) { el.style.opacity = (0.22 + 0.78 * Math.max(0, Math.min(1, p - i))).toFixed(2); });
        if (percentEl) percentEl.textContent = Math.round(percent) + '%';
      }

      // Delta-time driven easing (not a fixed per-frame step) so the fill
      // moves at the same real-world speed no matter the display's refresh
      // rate — a 60Hz and 120Hz screen both reach ~90% in about the same time.
      function tick(now) {
        if (finished) return;
        if (lastTime == null) lastTime = now;
        var dt = Math.min(now - lastTime, 100);
        lastTime = now;

        var remaining = target - percent;
        percent += remaining * (1 - Math.pow(0.001, dt / 1000)) + dt * 0.01;
        if (percent > target) percent = target;

        render();
        rafId = requestAnimationFrame(tick);
      }

      render();
      rafId = requestAnimationFrame(tick);

      function onFullyFilled() {
        var wrap = document.getElementById('app-loading');
        if (!wrap) return;
        wrap.style.transition = 'opacity 0.4s ease';
        wrap.style.opacity = '0';
        setTimeout(function () {
          wrap.style.display = 'none';
        }, 400);
      }

      // Real completion is signalled by __wimsFinishLoading() once Vue
      // actually mounts. This completes synchronously rather than just
      // retargeting to 100 and waiting for the rAF loop to catch up —
      // requestAnimationFrame is throttled or fully paused by the browser
      // whenever the tab isn't in the foreground, which left the loader
      // stuck forever (Vue had mounted and the page was already usable
      // underneath it) any time that happened. The CSS transition on the
      // clip-path still gives a smooth finish in the normal foregrounded
      // case; it just no longer gates completion.
      window.__wimsFinishLoading = function () {
        if (finished) return;
        finished = true;
        finishSpin();
        clearInterval(labelTimer);
        if (rafId != null) {
          cancelAnimationFrame(rafId);
          rafId = null;
        }
        percent = 100;
        render();
        onFullyFilled();
      };
    })();
  </script>
  @inertia
</body>

</html>
