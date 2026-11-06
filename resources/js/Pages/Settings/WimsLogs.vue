<template>
  <admin-layout :title="$t('WIMS Logs')">
    <div class="lg-page px-4 md:px-0 wl-page">
      <page-header icon="archive-box" :title="$t('WIMS Logs')" :subtitle="$t('Old log records are moved out of the live database into compressed archives. Nothing is lost: every record can be read and downloaded here.')">
        <Link :href="route('wims-logs.browse')" class="lg-btn lg-btn-primary"><icons name="doc-text" class="w-4 h-4" />{{ $t('Read archived records') }}</Link>
        <Link :href="route('settings')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to Settings') }}</Link>
      </page-header>

      <!-- the numbers that matter, as cards -->
      <section class="wl-kpis" :aria-label="$t('Summary')">
        <article v-for="(k, i) in kpis" :key="k.label" class="wl-kpi" :class="{ 'is-in': grown }" :style="{ '--tone': k.tone, '--tone2': k.tone2, '--d': i * 90 + 'ms' }">
          <span class="wl-kpi-ico"><icons :name="k.icon" class="w-5 h-5" /></span>
          <div class="wl-kpi-main">
            <b class="wl-kpi-v">{{ k.text }}</b>
            <span class="wl-kpi-l">{{ k.label }}</span>
          </div>
          <i v-if="k.pct !== null" class="wl-kpi-bar"><u :style="{ width: grown ? k.pct + '%' : '0%' }"></u></i>
          <small class="wl-kpi-n">{{ k.note }}</small>
        </article>
      </section>

      <!-- the whole space -->
      <section class="lg-card wl-space" :class="{ 'is-in': grown }" id="wl-space">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="database" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('The whole space') }}</h2>
            <p class="lg-card-sub">{{ $t('The live database and the archive together: :n. Only the live database is read by every page, so the smaller it is, the faster WIMS is.', { n: fmt(overview.whole) }) }}</p>
          </div>
        </header>

        <div class="wl-space-top">
          <div class="wl-ring" role="img" :aria-label="$t('Saved by compression') + ' ' + (overview.saved_pct || 0) + '%'">
            <svg viewBox="0 0 100 100" aria-hidden="true">
              <defs><linearGradient id="wl-ring-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#14b8a6" /><stop offset="1" stop-color="#6366f1" /></linearGradient></defs>
              <circle class="wl-ring-track" cx="50" cy="50" r="42" />
              <circle class="wl-ring-val" cx="50" cy="50" r="42" :stroke-dasharray="ringC" :stroke-dashoffset="grown ? ringC * (1 - Math.min(100, overview.saved_pct || 0) / 100) : ringC" />
            </svg>
            <span class="wl-ring-n"><b>{{ Math.round(anim.pct) }}%</b><small>{{ $t('saved') }}</small></span>
          </div>
          <div class="wl-bar-wrap">
            <div class="wl-bar" role="img" :aria-label="$t('Live database and archive')">
              <i class="wl-seg is-db" :style="{ width: grown ? pct(overview.db_total, overview.whole) + '%' : '0%' }"><b v-if="pct(overview.db_total, overview.whole) > 14">{{ $t('Live database') }} · {{ fmt(overview.db_total) }}</b></i>
              <i class="wl-seg is-ar" :style="{ width: grown ? pct(overview.stored, overview.whole) + '%' : '0%' }"><b v-if="pct(overview.stored, overview.whole) > 14">{{ $t('Archive') }} · {{ fmt(overview.stored) }}</b></i>
              <span class="wl-bar-shine" aria-hidden="true"></span>
            </div>
            <div class="wl-legend">
              <span><i class="is-db"></i>{{ $t('Live database') }} · {{ fmt(anim.db) }} ({{ Math.round(pct(overview.db_total, overview.whole)) }}%)</span>
              <span><i class="is-ar"></i>{{ $t('Archive') }} · {{ fmt(anim.stored) }} ({{ Math.round(pct(overview.stored, overview.whole)) }}%)</span>
            </div>
          </div>
        </div>

        <div class="wl-facts">
          <div v-for="(f, i) in facts" :key="f.label" :style="{ '--tone': f.tone, '--tone2': f.tone2, '--d': 250 + i * 80 + 'ms' }">
            <span class="wl-fact-ico"><icons :name="f.icon" class="w-4 h-4" /></span>
            <span class="wl-fact-body"><b :class="{ 'is-good': f.good }">{{ f.text }}<em v-if="f.extra"> ({{ f.extra }})</em></b><span>{{ f.label }}</span></span>
          </div>
        </div>

        <div v-if="trend.length" class="wl-trend" :aria-label="$t('Each run: before and after')">
          <div v-for="(t, ti) in trend" :key="t.ref" class="wl-trend-col" :title="t.ref + ' · ' + fmt(t.original) + ' > ' + fmt(t.stored)" :style="{ '--i': ti }">
            <span class="wl-trend-bars"><i class="is-before" :style="{ height: pct(t.original, trendMax) + '%' }"></i><i class="is-after" :style="{ height: pct(t.stored, trendMax) + '%' }"></i></span>
            <small>{{ t.at ? t.at.slice(5) : t.ref }}</small>
          </div>
          <span class="wl-trend-key"><i class="is-before"></i>{{ $t('before') }}<i class="is-after"></i>{{ $t('after') }}</span>
        </div>
      </section>

      <!-- each log -->
      <section class="lg-card" id="wl-logs">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="archive-box" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('The logs') }}</h2>
            <p class="lg-card-sub">{{ $t('For each log: what is live now, what is waiting to be moved, and what has already been archived.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="loading" @click="refresh"><icons name="refresh" class="w-4 h-4" :class="{ 'wl-spin': loading }" />{{ $t('Refresh') }}</button>
        </header>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive wl-table">
            <thead>
              <tr>
                <th>{{ $t('Log') }}</th><th>{{ $t('Live now') }}</th><th>{{ $t('Waiting to move') }}</th><th>{{ $t('Archived') }}</th><th>{{ $t('Saved') }}</th><th>{{ $t('Period archived') }}</th><th>{{ $t('Stays live') }}</th><th>{{ $t('On') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in rows" :key="l.key" :class="{ 'is-off': !cfg.sources[l.key].on }">
                <td class="wl-logcell"><span class="wl-logico" :style="{ '--tone': look(l.key).tone, '--tone2': look(l.key).tone2 }"><icons :name="look(l.key).icon" class="w-5 h-5" /></span><span><b>{{ l.label }}</b><small class="wl-help">{{ l.help }}</small></span></td>
                <td class="wl-nw">{{ num(l.live_rows) }} <small>{{ $t('records') }}</small><small>{{ fmt(l.live_bytes) }}</small></td>
                <td class="wl-nw"><span class="wl-pill" :class="l.due_rows > 0 ? 'is-due' : 'is-none'">{{ num(l.due_rows) }}</span> <small>{{ $t('records') }}</small><small>~ {{ fmt(l.due_bytes) }}</small></td>
                <td class="wl-nw"><b>{{ num(l.archived_rows) }}</b> <small>{{ $t('records') }}</small><small>{{ fmt(l.original) }} > {{ fmt(l.stored) }}</small><i v-if="l.original" class="wl-mini" :title="$t('Size in the archive compared with the size in the database')"><u :style="{ width: Math.max(3, Math.round(l.stored / l.original * 100)) + '%', background: 'linear-gradient(90deg,' + look(l.key).tone + ',' + look(l.key).tone2 + ')' }"></u></i><Link v-if="l.archived_rows" :href="route('wims-logs.browse', { source: l.key })" class="wl-read"><icons name="search" class="w-3 h-3" />{{ $t('Read them') }}</Link></td>
                <td class="wl-nw"><b class="is-good">{{ fmt(l.saved) }}</b><small v-if="l.saved_pct !== null">{{ l.saved_pct }}%</small></td>
                <td class="wl-nw"><template v-if="l.date_from">{{ day(l.date_from) }} - {{ day(l.date_to) }}</template><template v-else>-</template></td>
                <td class="wl-nw">
                  <span class="wl-input"><input v-model.number="cfg.sources[l.key].keep_days" type="number" :min="keepLimits[0]" :max="keepLimits[1]" inputmode="numeric" :disabled="!canRun" :aria-label="$t('Days this log stays live')" /><em>{{ $t('days') }}</em></span>
                </td>
                <td><label class="wl-switch"><input v-model="cfg.sources[l.key].on" type="checkbox" :disabled="!canRun" :aria-label="$t('Archive this log')" /><i></i></label></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- when it runs -->
      <section class="lg-card" id="wl-schedule">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('When it runs') }}</h2>
            <p class="lg-card-sub">
              <template v-if="cfg.general.wimslogs_enabled && next">{{ $t('It runs by itself every :n day(s) at night. Next run: :d.', { n: cfg.general.wimslogs_every_days, d: $datetime(next.at) }) }}</template>
              <template v-else>{{ $t('It does not run by itself: it runs only when a Super Admin runs it.') }}</template>
            </p>
          </div>
          <button v-if="canRun" type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" :disabled="saving" @click="save"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button>
        </header>
        <div class="wl-grid">
          <label class="wl-field">
            <span class="wl-label"><icons name="refresh" class="w-4 h-4" />{{ $t('Run by itself') }}</span>
            <span class="wl-check"><label class="wl-switch"><input v-model="enabledFlag" type="checkbox" :disabled="!canRun" /><i></i></label>{{ enabledFlag ? $t('Yes, at night') : $t('No, only by hand') }}</span>
            <small>{{ $t('It uses the nightly scheduler that already runs on the server.') }}</small>
          </label>
          <label class="wl-field">
            <span class="wl-label"><icons name="calendar" class="w-4 h-4" />{{ $t('Every') }}</span>
            <span class="wl-input"><input v-model.number="cfg.general.wimslogs_every_days" type="number" :min="limits.wimslogs_every_days[0]" :max="limits.wimslogs_every_days[1]" inputmode="numeric" :disabled="!canRun" /><em>{{ $t('days') }}</em></span>
            <small>{{ $t('For example 5 or 10. A run only moves what is older than the days set for each log.') }}</small>
          </label>
          <label class="wl-field">
            <span class="wl-label"><icons name="database" class="w-4 h-4" />{{ $t('Compact the tables afterwards') }}</span>
            <span class="wl-check"><label class="wl-switch"><input v-model="optimizeFlag" type="checkbox" :disabled="!canRun" /><i></i></label>{{ optimizeFlag ? $t('Yes') : $t('No') }}</span>
            <small>{{ $t('Without it the space stays reserved inside the database files and the files do not get smaller.') }}</small>
          </label>
          <label class="wl-field">
            <span class="wl-label"><icons name="clock" class="w-4 h-4" />{{ $t('One step works for at most') }}</span>
            <span class="wl-input"><input v-model.number="cfg.general.wimslogs_seconds" type="number" :min="limits.wimslogs_seconds[0]" :max="limits.wimslogs_seconds[1]" inputmode="numeric" :disabled="!canRun" /><em>{{ $t('seconds') }}</em></span>
            <small>{{ $t('A long job is done in steps of this length, so the server is never held for long.') }}</small>
          </label>
        </div>
        <p v-if="errors" class="wl-err">{{ errors }}</p>
        <div class="wl-actions">
          <span v-if="canRun" class="wl-hint"><icons name="info" class="w-4 h-4" />{{ $t('Each record is written to the archive, read back and compared with the original before it is removed from the live table. Messages still waiting, retrying or failed, and alerts not yet read, are never moved.') }}</span>
          <span v-else class="wl-hint"><icons name="lock-closed" class="w-4 h-4" />{{ $t('You can read the archives. Running needs the permission "WIMS Logs: run".') }}</span>
          <button v-if="canRun" type="button" class="lg-btn tone tone-solid" style="--tone: #0f766e" :disabled="busy" @click="ask"><span class="tone-ico"><icons name="archive-box" class="w-4 h-4" /></span>{{ dueNow ? $t('Run now') + ' (' + num(dueNow) + ' ' + $t('records') + ')' : $t('Run now') }}</button>
        </div>
      </section>

      <!-- history -->
      <section class="lg-card" id="wl-history">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Runs') }}</h2>
            <p class="lg-card-sub">{{ $t(':n run(s) on record. Open one to see its archives and read every record in them.', { n: runs.total }) }}</p>
          </div>
        </header>
        <div v-if="!runs.data.length" class="wl-empty"><icons name="archive-box" class="w-5 h-5" />{{ $t('Nothing has been archived yet.') }}</div>
        <div v-else class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('When') }}</th><th>{{ $t('How') }}</th><th>{{ $t('Records') }}</th><th>{{ $t('Before') }}</th><th>{{ $t('Archive') }}</th><th>{{ $t('Database made smaller') }}</th><th>{{ $t('Speed') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in runs.data" :key="r.id">
                <td class="wl-nw"><b>{{ r.ref }}</b></td>
                <td class="wl-nw">{{ $datetime(r.at) }}<small>{{ r.user }}</small></td>
                <td>{{ r.trigger === 'schedule' ? $t('Schedule') : $t('By hand') }}</td>
                <td class="wl-nw">{{ num(r.rows) }}</td>
                <td class="wl-nw">{{ fmt(r.original) }}</td>
                <td class="wl-nw">{{ fmt(r.stored) }}</td>
                <td class="wl-nw"><b class="is-good">{{ fmt(r.freed) }}</b></td>
                <td class="wl-nw">{{ r.speed_pct === null ? '-' : (r.speed_pct > 0 ? '+' : '') + r.speed_pct + '%' }}</td>
                <td><span class="wl-status" :class="'is-' + r.status">{{ statusText(r.status) }}</span></td>
                <td class="wl-row-actions">
                  <Link :href="route('wims-logs.show', r.id)" class="lg-btn lg-btn-soft wl-sm">{{ $t('View') }}</Link>
                  <a :href="route('wims-logs.print', r.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft wl-sm"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="runs.last_page > 1" class="wl-pager">
          <Link v-if="runs.prev_page_url" :href="runs.prev_page_url" preserve-scroll class="lg-btn lg-btn-soft wl-sm">{{ $t('Previous') }}</Link>
          <span>{{ $t('Page :a of :b', { a: runs.current_page, b: runs.last_page }) }}</span>
          <Link v-if="runs.next_page_url" :href="runs.next_page_url" preserve-scroll class="lg-btn lg-btn-soft wl-sm">{{ $t('Next') }}</Link>
        </div>
      </section>

      <!-- the run, step by step -->
      <teleport to="body">
        <div v-if="ov.open" class="wl-ov" role="dialog" aria-modal="true" :aria-label="$t('Archiving')">
          <div class="wl-ov-card" :class="{ 'is-done': ov.finished && !ov.failed, 'is-fail': ov.failed }">
            <h2>{{ ov.finished ? (ov.failed ? $t('The run stopped') : $t('Archiving finished')) : $t('Compressing the logs') }}</h2>
            <!-- records leave the live database, are squeezed by the press and land in the archive -->
            <div class="wlx-stage" :class="{ 'is-run': !ov.finished, 'is-done': ov.finished && !ov.failed, 'is-fail': ov.failed }" aria-hidden="true">
              <svg class="wlx-st-db" viewBox="0 0 64 72">
                <defs><linearGradient id="wl-g-db" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#818cf8" /><stop offset="1" stop-color="#4338ca" /></linearGradient></defs>
                <path d="M8 14v44c0 6 10 10 24 10s24-4 24-10V14z" fill="url(#wl-g-db)" />
                <path d="M8 30c0 5 10 9 24 9s24-4 24-9M8 45c0 5 10 9 24 9s24-4 24-9" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="2" />
                <ellipse cx="32" cy="14" rx="24" ry="9" fill="#a5b4fc" />
              </svg>
              <div class="wlx-lane">
                <i v-for="n in 10" :key="n" class="wlx-chipx" :style="{ '--n': n }"></i>
                <span class="wlx-press wlx-press-top"></span><span class="wlx-press wlx-press-bot"></span><span class="wlx-press-flash"></span>
              </div>
              <div class="wlx-st-boxwrap">
                <svg class="wlx-st-box" viewBox="0 0 64 64">
                  <defs><linearGradient id="wl-g-box" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2dd4bf" /><stop offset="1" stop-color="#0f766e" /></linearGradient></defs>
                  <rect x="5" y="9" width="54" height="17" rx="5" fill="#5eead4" />
                  <path d="M10 26h44v28a5 5 0 0 1-5 5H15a5 5 0 0 1-5-5z" fill="url(#wl-g-box)" />
                  <rect x="24" y="34" width="16" height="5" rx="2.5" fill="rgba(255,255,255,.75)" />
                </svg>
                <span class="wlx-st-tick"><icons :name="ov.failed ? 'x-circle' : 'tick'" class="w-6 h-6" /></span>
                <i v-for="n in 8" :key="'sp' + n" class="wlx-spark" :style="{ '--a': n * 45 + 'deg' }"></i>
              </div>
            </div>
            <p class="wlx-st-line"><b>{{ ovPercent }}%</b> · {{ ov.finished ? (ov.failed ? $t('Stopped. What was archived is kept.') : $t('Every record is safe in the archive.')) : runningTitle }}</p>
            <div class="wl-ov-bar" role="progressbar" :aria-valuenow="ovPercent"><i :style="{ width: ovPercent + '%' }"></i></div>
            <ul class="wl-ov-steps">
              <li v-for="s in ov.steps" :key="s.key" :class="'is-' + s.state">
                <span class="wl-ov-ico"><icons v-if="s.state === 'done'" name="tick" class="w-4 h-4" /><icons v-else-if="s.state === 'error'" name="x-circle" class="w-4 h-4" /><i v-else-if="s.state === 'running'" class="wl-ov-spin"></i><i v-else class="wl-ov-idle"></i></span>
                <span class="wl-ov-txt"><b>{{ s.title }}</b><small v-if="s.summary">{{ s.summary }}</small></span>
              </li>
            </ul>
            <p v-if="ov.finished && !ov.failed" class="wl-ov-result"><b>{{ ov.rows }}</b> {{ $t('records archived') }} · <b>{{ ov.saved }}</b> {{ $t('saved by compression') }} · <b>{{ ov.freed }}</b> {{ $t('database made smaller') }}</p>
            <p v-if="!ov.finished" class="wl-ov-warn"><icons name="info" class="w-4 h-4" />{{ $t('Please keep this page open. Nothing is removed before it is safely in the archive.') }}</p>
            <p v-if="ov.error" class="wl-err">{{ ov.error }}</p>
          </div>
        </div>
      </teleport>

      <!-- the password: running moves data out of the live tables -->
      <tec-dialog-modal :show="pw.open" max-width="lg" @close="pw.open = false">
        <template #title>
          <span class="wl-dlg-head">
            <span class="wl-dlg-badge"><icons name="archive-box" class="w-5 h-5" /></span>
            <span><b>{{ $t('Run WIMS Logs now') }}</b><small>{{ $t('Whatever the schedule says') }}</small></span>
          </span>
        </template>
        <template #content>
          <p class="wl-dlg-text">{{ $t('Choose the logs and, for this run only, how many days each one stays live. Everything older is written to a compressed archive, checked, and then removed from the live table. Your saved settings are not changed.') }}</p>
          <ul class="wl-dlg-list">
            <li v-for="(l, li) in rows" :key="l.key" :class="{ 'is-off': !pw.sources.includes(l.key), 'has-due': est(l.key).rows > 0 }" :style="{ '--tone': look(l.key).tone, '--tone2': look(l.key).tone2, '--d': li * 55 + 'ms' }">
              <label class="wl-dlg-pick">
                <input v-model="pw.sources" type="checkbox" :value="l.key" class="wl-chk-in" />
                <i class="wl-chk" aria-hidden="true"><icons name="tick" class="w-3 h-3" /></i>
                <span class="wl-logico is-sm" :style="{ '--tone': look(l.key).tone, '--tone2': look(l.key).tone2 }"><icons :name="look(l.key).icon" class="w-4 h-4" /></span>
                <b>{{ l.label }}</b>
              </label>
              <span class="wl-step" role="group" :aria-label="$t('Days to keep live')">
                <button type="button" class="wl-step-b" :aria-label="$t('One day less')" @click="bumpDays(l.key, -1)"><icons name="minus" class="w-3 h-3" /></button>
                <input v-model.number="pw.days[l.key]" type="number" :min="keepLimits[0]" :max="keepLimits[1]" inputmode="numeric" :aria-label="$t('Days to keep live')" />
                <button type="button" class="wl-step-b" :aria-label="$t('One day more')" @click="bumpDays(l.key, 1)"><icons name="plus" class="w-3 h-3" /></button>
                <em>{{ $t('days live') }}</em>
              </span>
              <span class="wl-dlg-est" :class="{ 'is-due': est(l.key).rows > 0 }">
                <span class="wl-dlg-bar"><u :style="{ width: estPct(l.key) + '%' }"></u></span>
                <span>{{ pw.loading ? '...' : num(est(l.key).rows) }} {{ $t('records') }} · ~ {{ fmt(est(l.key).bytes) }}</span>
              </span>
            </li>
          </ul>
          <div class="wl-dlg-total" :class="{ 'is-none': !estTotal }">
            <span class="wl-dlg-total-ico"><icons :name="estTotal ? 'archive-box' : 'info'" class="w-5 h-5" /></span>
            <span v-if="estTotal"><b>{{ num(estTotal) }}</b> {{ $t('records will be compressed') }} · ~ <b>{{ fmt(estBytes) }}</b> {{ $t('leave the live database') }}</span>
            <span v-else-if="pw.loading">{{ $t('Counting...') }}</span>
            <span v-else>{{ $t('Nothing is older than that. Lower the days to move more.') }}</span>
          </div>
          <div class="wl-pass">
            <icons name="lock-closed" class="w-4 h-4 wl-pass-ico" />
            <input v-model="pw.password" :type="pw.show ? 'text' : 'password'" class="wl-dlg-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="run" />
            <button type="button" class="wl-pass-eye" :aria-label="pw.show ? $t('Hide the password') : $t('Show the password')" @click="pw.show = !pw.show"><icons :name="pw.show ? 'eye-off' : 'eye'" class="w-4 h-4" /></button>
          </div>
          <p v-if="pw.error" class="wl-err">{{ pw.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="pw.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn tone tone-solid wl-go" style="--tone: #0f766e" :disabled="!pw.password || !pw.sources.length || !estTotal || pw.loading || busy" @click="run"><span class="tone-ico"><icons name="archive-box" class="w-4 h-4" /></span>{{ $t('Compress now') }}<template v-if="estTotal"> ({{ num(estTotal) }})</template></button>
        </template>
      </tec-dialog-modal>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import { Link } from '@inertiajs/vue3';
import { logLook } from '@/Core/wimsLogLook';

export default {
  components: { AdminLayout, PageHeader, TecDialogModal, Link },
  // not named "settings": that name is the app-wide shared prop the layout and menu read
  props: { conf: Object, limits: Object, keepLimits: Array, logs: Array, overview: Object, trend: Array, runs: Object, canRun: Boolean, next: Object },
  data() {
    return {
      rows: this.logs,
      cfg: JSON.parse(JSON.stringify(this.conf)), // a copy: the screen edits it and Save sends it
      loading: false,
      saving: false,
      busy: false,
      errors: '',
      pw: { open: false, password: '', error: '', sources: [], days: {}, estimates: {}, loading: false, show: false },
      grown: false,
      anim: { db: 0, stored: 0, saved: 0, freed: 0, rows: 0, original: 0, free: 0, pct: 0 },
      ov: { open: false, steps: [], finished: false, failed: false, error: '', rows: 0, saved: '', freed: '' },
    };
  },
  computed: {
    enabledFlag: {
      get() { return !!this.cfg.general.wimslogs_enabled; },
      set(v) { this.cfg.general.wimslogs_enabled = v ? 1 : 0; },
    },
    optimizeFlag: {
      get() { return !!this.cfg.general.wimslogs_optimize; },
      set(v) { this.cfg.general.wimslogs_optimize = v ? 1 : 0; },
    },
    estBytes() { return this.pw.sources.reduce((a, k) => a + (this.est(k).bytes || 0), 0); },
    runningTitle() { const r = this.ov.steps.find(x => x.state === 'running'); return r ? r.title : this.$t('Getting ready'); },
    estTotal() { return this.pw.sources.reduce((a, k) => a + (this.est(k).rows || 0), 0); },
    dueNow() { return this.rows.filter(l => this.cfg.sources[l.key].on).reduce((a, l) => a + l.due_rows, 0); },
    trendMax() { return Math.max(1, ...this.trend.map(t => t.original)); },
    ovPercent() {
      const n = this.ov.steps.length || 1;
      return Math.round((this.ov.steps.filter(s => s.state === 'done' || s.state === 'error').length / n) * 100);
    },
    ringC() { return 2 * Math.PI * 42; },
    kpis() {
      const o = this.overview;
      const a = this.anim;
      return [
        { icon: 'database', tone: '#7c3aed', tone2: '#a78bfa', label: this.$t('Live database'), text: this.fmt(a.db), pct: this.pct(o.db_total, o.whole), note: this.$t(':n% of the whole space', { n: Math.round(this.pct(o.db_total, o.whole)) }) },
        { icon: 'archive-box', tone: '#0891b2', tone2: '#22d3ee', label: this.$t('Archive on disk'), text: this.fmt(a.stored), pct: this.pct(o.stored, o.whole), note: this.$t(':n% of the whole space', { n: Math.round(this.pct(o.stored, o.whole)) }) },
        { icon: 'tick', tone: '#059669', tone2: '#34d399', label: this.$t('Saved by compression'), text: this.fmt(a.saved), pct: Math.min(100, o.saved_pct || 0), note: o.saved_pct !== null ? this.$t(':n% smaller than in the database', { n: o.saved_pct }) : '' },
        { icon: 'download', tone: '#0d9488', tone2: '#2dd4bf', label: this.$t('Database made smaller'), text: this.fmt(a.freed), pct: this.pct(o.freed, (o.db_total || 0) + (o.freed || 0)), note: this.$t('by compacting the tables') },
        { icon: 'doc-text', tone: '#ea580c', tone2: '#fb923c', label: this.$t('Records archived'), text: this.num(Math.round(a.rows)), pct: null, note: this.$t(':n as they were in the database', { n: this.fmt(o.original) }) },
      ];
    },
    facts() {
      const o = this.overview;
      const a = this.anim;
      return [
        { icon: 'database', tone: '#7c3aed', tone2: '#a78bfa', text: this.fmt(a.original), label: this.$t('archived records, as they were in the database') },
        { icon: 'archive-box', tone: '#0891b2', tone2: '#22d3ee', text: this.fmt(a.stored), label: this.$t('the same records in the archive (data and index)') },
        { icon: 'tick', tone: '#059669', tone2: '#34d399', good: true, text: this.fmt(a.saved), extra: o.saved_pct !== null ? o.saved_pct + '%' : '', label: this.$t('saved by compression') },
        { icon: 'download', tone: '#0d9488', tone2: '#2dd4bf', good: true, text: this.fmt(a.freed), label: this.$t('database files really made smaller') },
        { icon: 'server', tone: '#64748b', tone2: '#94a3b8', text: this.fmt(a.free), label: this.$t('free disk space on the server') },
      ];
    },
  },
  watch: {
    // the numbers count up again whenever the overview is refreshed
    overview: { deep: true, handler() { this.countUp(); } },
    // the number of live days typed in the window: what that would move is asked again a moment after the last key
    'pw.days': { deep: true, handler() { if (this.pw.open) { clearTimeout(this._estT); this.pw.loading = true; this._estT = setTimeout(this.estimate, 450); } } },
  },
  mounted() {
    // one frame later, so the bars and the ring start from nothing and grow
    this._raf0 = requestAnimationFrame(() => { this.grown = true; });
    this.countUp();
  },
  beforeUnmount() { cancelAnimationFrame(this._raf0); cancelAnimationFrame(this._raf1); },
  methods: {
    targets() {
      const o = this.overview;
      return { db: o.db_total || 0, stored: o.stored || 0, saved: o.saved || 0, freed: o.freed || 0, rows: o.rows || 0, original: o.original || 0, free: o.disk_free || 0, pct: o.saved_pct || 0 };
    },
    // numbers rise from where they are to the real value (no motion when the person asked for none)
    countUp() {
      const to = this.targets();
      if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { this.anim = { ...to }; return; }
      const from = { ...this.anim };
      cancelAnimationFrame(this._raf1);
      const t0 = performance.now();
      const dur = 1300;
      const step = now => {
        const k = Math.min(1, (now - t0) / dur);
        const e = 1 - Math.pow(1 - k, 3);
        const cur = {};
        Object.keys(to).forEach(key => { cur[key] = from[key] + (to[key] - from[key]) * e; });
        this.anim = cur;
        if (k < 1) this._raf1 = requestAnimationFrame(step);
      };
      this._raf1 = requestAnimationFrame(step);
    },
    // each log has its own colour and icon (the same everywhere)
    look: logLook,
    // the little bar in a row: this log's share of everything that would move
    estPct(key) {
      const max = Math.max(1, ...this.rows.map(l => this.est(l.key).rows || 0));
      const n = this.est(key).rows || 0;
      return n ? Math.max(4, Math.round((n / max) * 100)) : 0;
    },
    bumpDays(key, d) {
      const v = Number(this.pw.days[key]) || this.keepLimits[0];
      this.pw.days[key] = Math.min(this.keepLimits[1], Math.max(this.keepLimits[0], v + d));
    },
    est(key) { return this.pw.estimates[key] || { rows: 0, bytes: 0 }; },
    estimate() {
      const days = {};
      this.rows.forEach(l => { const d = Number(this.pw.days[l.key]); if (d >= this.keepLimits[0]) days[l.key] = d; });
      window.axios.get(this.route('wims-logs.preview'), { params: { days }, headers: { 'X-Quiet': '1' } })
        .then(r => {
          const e = {};
          r.data.logs.forEach(l => { e[l.key] = { rows: l.due_rows, bytes: l.due_bytes }; });
          this.pw.estimates = e;
        })
        .finally(() => { this.pw.loading = false; });
    },
    fmt(b) {
      b = Number(b || 0);
      const neg = b < 0; b = Math.abs(b);
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (neg ? '-' : '') + (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    num(n) { return Number(n || 0).toLocaleString(); },
    pct(a, b) { return b > 0 ? Math.max(0, Math.min(100, (a / b) * 100)) : 0; },
    day(d) { return d ? String(d).slice(0, 10) : '-'; },
    statusText(s) { return { done: this.$t('Completed'), partial: this.$t('Partly done'), failed: this.$t('Failed'), running: this.$t('Running') }[s] || s; },
    refresh() {
      this.loading = true;
      window.axios.get(this.route('wims-logs.preview'), { headers: { 'X-Quiet': '1' } })
        .then(r => { this.rows = r.data.logs; })
        .finally(() => { this.loading = false; });
    },
    save() {
      this.saving = true;
      this.errors = '';
      this.$inertia.put(this.route('wims-logs.settings'), { general: this.cfg.general, sources: this.cfg.sources }, {
        preserveScroll: true,
        onError: e => { this.errors = Object.values(e)[0] || this.$t('The settings could not be saved.'); },
        onSuccess: () => this.refresh(),
        onFinish: () => (this.saving = false),
      });
    },
    ask() {
      const days = {};
      const estimates = {};
      this.rows.forEach(l => { days[l.key] = this.cfg.sources[l.key].keep_days; estimates[l.key] = { rows: l.due_rows, bytes: l.due_bytes }; });
      this.pw = { open: true, show: false, password: '', error: '', sources: this.rows.filter(l => this.cfg.sources[l.key].on && l.due_rows > 0).map(l => l.key), days, estimates, loading: false };
      // the numbers shown are for the saved days; if nothing is due that way, every log is ticked so the days can simply be lowered
      if (!this.pw.sources.length) this.pw.sources = this.rows.map(l => l.key);
    },
    // starts the run (the password is checked there), then asks for each log in turn so the window can show what is happening; a log that reached its time limit is asked again
    async run() {
      if (!this.pw.password || !this.pw.sources.length || !this.estTotal || this.busy) return;
      this.busy = true;
      this.pw.error = '';
      let started;
      try {
        const keep = {};
        this.pw.sources.forEach(k => { keep[k] = Number(this.pw.days[k]); });
        started = (await window.axios.post(this.route('wims-logs.run'), { sources: this.pw.sources, keep_days: keep, password: this.pw.password }, { headers: { 'X-Quiet': '1' } })).data;
      } catch (e) {
        const er = e.response && e.response.data && e.response.data.errors;
        this.pw.error = (er && (er.password || er.sources) && (er.password || er.sources)[0]) || this.$t('Could not do that.');
        this.busy = false;
        return;
      }
      this.pw.open = false;
      this.ov = { open: true, steps: started.steps.map(s => ({ key: s.key, title: s.title, state: 'wait', summary: '' })), finished: false, failed: false, error: '', rows: 0, saved: '', freed: '' };
      const guard = e => { e.preventDefault(); e.returnValue = ''; };
      window.addEventListener('beforeunload', guard);
      try {
        for (const step of this.ov.steps) {
          step.state = 'running';
          let total = 0;
          for (let i = 0; i < 2000; i++) {
            const r = (await window.axios.post(this.route('wims-logs.step', started.id), { source: step.key }, { headers: { 'X-Quiet': '1' }, timeout: 0 })).data;
            total += r.rows || 0;
            step.summary = step.key === 'optimize' ? r.summary : (total ? this.num(total) + ' ' + this.$t('records moved') : r.summary);
            step.state = r.ok ? 'done' : 'error';
            if (!r.more) break;
            step.state = 'running';
          }
        }
        const f = (await window.axios.post(this.route('wims-logs.finish', started.id), {}, { headers: { 'X-Quiet': '1' } })).data;
        this.ov.finished = true;
        this.ov.failed = f.status === 'failed';
        this.ov.rows = this.num(f.rows);
        this.ov.saved = f.saved_text;
        this.ov.freed = f.freed_text;
        await new Promise(r => setTimeout(r, 2200));
        window.removeEventListener('beforeunload', guard);
        this.ov.open = false;
        this.$inertia.visit(f.url);
      } catch (e) {
        window.removeEventListener('beforeunload', guard);
        this.ov.finished = true;
        this.ov.failed = true;
        this.ov.error = (e.response && e.response.data && e.response.data.message) || this.$t('The run could not be completed. What was archived is kept and listed in the history.');
        this.ov.steps.forEach(s => { if (s.state === 'running') s.state = 'error'; });
        setTimeout(() => { this.ov.open = false; this.$inertia.visit(this.route('wims-logs.index')); }, 4500);
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.wl-page { --tone: #0f766e; }
.wl-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr)); gap: 0.8rem; margin: 1rem 0 1.1rem; }
.wl-kpi { position: relative; display: grid; grid-template-columns: auto 1fr; align-items: center; gap: 0.2rem 0.8rem; padding: 0.9rem 1rem 0.8rem; border-radius: 1.2rem; overflow: hidden; border: 1.5px solid color-mix(in srgb, var(--tone) 26%, transparent); background: linear-gradient(135deg, color-mix(in srgb, var(--tone) 13%, var(--lg-card, #fff)), color-mix(in srgb, var(--tone2) 5%, var(--lg-card, #fff))); animation: wl-kpi-in 0.55s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: var(--d); transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease; }
.wl-kpi:hover { transform: translateY(-3px); box-shadow: 0 16px 30px -18px var(--tone); border-color: var(--tone); }
@keyframes wl-kpi-in { from { opacity: 0; transform: translateY(14px) scale(0.98); } }
.wl-kpi::after { content: ''; position: absolute; right: -2.2rem; top: -2.2rem; width: 6rem; height: 6rem; border-radius: 50%; background: radial-gradient(circle, color-mix(in srgb, var(--tone2) 28%, transparent), transparent 70%); pointer-events: none; }
.wl-kpi-ico { width: 2.9rem; height: 2.9rem; display: grid; place-items: center; border-radius: 1rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 8px 16px -8px var(--tone); }
.wl-kpi-main { display: grid; min-width: 0; } .wl-kpi-v { font-size: 1.45rem; font-weight: 900; line-height: 1.1; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; } .wl-kpi-l { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
.wl-kpi-bar { grid-column: 1 / -1; display: block; height: 0.32rem; margin-top: 0.45rem; border-radius: 999px; background: color-mix(in srgb, var(--tone) 14%, transparent); overflow: hidden; } .wl-kpi-bar u { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--tone), var(--tone2)); transition: width 1.3s cubic-bezier(0.2, 0.8, 0.2, 1) 0.2s; }
.wl-kpi-n { grid-column: 1 / -1; font-size: 0.72rem; color: #64748b; font-weight: 600; }

.wl-space-top { display: flex; align-items: center; gap: 1.4rem; margin: 0.2rem 0 1.1rem; flex-wrap: wrap; }
.wl-ring { position: relative; flex: none; width: 7.2rem; height: 7.2rem; } .wl-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.wl-ring-track { fill: none; stroke: rgba(148, 163, 184, 0.22); stroke-width: 9; } .wl-ring-val { fill: none; stroke: url(#wl-ring-g); stroke-width: 9; stroke-linecap: round; transition: stroke-dashoffset 1.6s cubic-bezier(0.2, 0.8, 0.2, 1) 0.15s; filter: drop-shadow(0 0 6px rgba(20, 184, 166, 0.45)); }
.wl-ring-n { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; } .wl-ring-n b { font-size: 1.5rem; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; } .wl-ring-n small { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
.wl-bar-wrap { flex: 1; min-width: 16rem; }
.wl-bar { position: relative; display: flex; height: 2.8rem; border-radius: 1rem; overflow: hidden; background: rgba(148, 163, 184, 0.18); box-shadow: inset 0 1px 3px rgba(15, 23, 42, 0.08); }
.wl-seg { display: flex; align-items: center; padding: 0 0.8rem; min-width: 0; transition: width 1.5s cubic-bezier(0.2, 0.8, 0.2, 1) 0.1s; }
.wl-seg b { font-size: 0.78rem; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wl-seg.is-db { background: linear-gradient(135deg, #6366f1, #4338ca); } .wl-seg.is-ar { background: linear-gradient(135deg, #14b8a6, #0f766e); }
.wl-bar-shine { position: absolute; inset: 0; pointer-events: none; background: linear-gradient(100deg, transparent 30%, rgba(255, 255, 255, 0.38) 50%, transparent 70%); transform: translateX(-120%); will-change: transform; animation: wl-sweep 3.2s ease-in-out 1.4s 2 both; }
.wl-legend { display: flex; flex-wrap: wrap; gap: 0.4rem 1.2rem; margin-top: 0.55rem; font-size: 0.76rem; font-weight: 700; color: #475569; } .wl-legend span { display: inline-flex; align-items: center; gap: 0.4rem; font-variant-numeric: tabular-nums; } .wl-legend i { width: 0.7rem; height: 0.7rem; border-radius: 4px; } .wl-legend i.is-db { background: #4f46e5; } .wl-legend i.is-ar { background: #0f9488; }
html.dark .wl-legend { color: #cbd5e1; }

.wl-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(12.5rem, 1fr)); gap: 0.7rem; }
.wl-facts > div { display: flex; align-items: center; gap: 0.7rem; padding: 0.7rem 0.85rem; border-radius: 1rem; background: color-mix(in srgb, var(--tone) 8%, transparent); border: 1px solid color-mix(in srgb, var(--tone) 18%, transparent); animation: wl-fact-in 0.5s ease backwards; animation-delay: var(--d); transition: transform 0.25s ease, box-shadow 0.25s ease; }
.wl-facts > div:hover { transform: translateY(-2px); box-shadow: 0 12px 22px -16px var(--tone); }
@keyframes wl-fact-in { from { opacity: 0; transform: translateY(10px); } }
.wl-fact-ico { flex: none; width: 2rem; height: 2rem; display: grid; place-items: center; border-radius: 0.7rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); }
.wl-fact-body { display: grid; min-width: 0; } .wl-fact-body b { font-size: 1.1rem; font-weight: 900; font-variant-numeric: tabular-nums; } .wl-fact-body b em { font-style: normal; font-size: 0.78rem; font-weight: 800; } .wl-fact-body > span { font-size: 0.72rem; color: #64748b; font-weight: 600; }

.wl-trend { display: flex; align-items: flex-end; gap: 0.6rem; margin-top: 1rem; padding-top: 0.8rem; border-top: 1px dashed rgba(148, 163, 184, 0.4); overflow-x: auto; }
.wl-trend-col { display: grid; gap: 0.25rem; justify-items: center; min-width: 2.6rem; } .wl-trend-col small { font-size: 0.64rem; color: #64748b; }
.wl-trend-bars { display: flex; align-items: flex-end; gap: 3px; height: 4.5rem; }
.wl-trend-bars i, .wl-trend-key i { display: block; width: 0.8rem; border-radius: 4px 4px 0 0; min-height: 2px; } .is-before { background: #a5b4fc; } .is-after { background: linear-gradient(180deg, #2dd4bf, #0f766e); }
.wl-trend-bars i { transform-origin: bottom; animation: wl-rise 0.9s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(0.5s + var(--i, 0) * 90ms); }
.wl-trend-key { margin-left: auto; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.7rem; color: #64748b; white-space: nowrap; } .wl-trend-key i { width: 0.6rem; height: 0.6rem; border-radius: 3px; min-height: 0; }
@keyframes wl-sweep { 0%, 55% { transform: translateX(-120%); } 100% { transform: translateX(120%); } }
@keyframes wl-rise { from { transform: scaleY(0); } }
.wl-table td small, .wl-table th small { display: block; color: #64748b; font-weight: 600; font-size: 0.72rem; } .wl-help { max-width: 22rem; white-space: normal; line-height: 1.35; }
.wl-nw { white-space: nowrap; }
tr.is-off { opacity: 0.55; }
.wl-input { display: inline-flex; align-items: center; gap: 0.4rem; } .wl-input input { width: 5.2rem; border-radius: 0.6rem; border: 1.5px solid #e2e8f0; padding: 0.35rem 0.55rem; font-weight: 700; font-size: 0.85rem; background: #fff; }
html.dark .wl-input input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; } .wl-input em { font-style: normal; font-size: 0.74rem; color: #64748b; }
.wl-switch { position: relative; display: inline-block; width: 2.6rem; height: 1.5rem; cursor: pointer; } .wl-switch input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 2; }
.wl-switch i { position: absolute; inset: 0; border-radius: 999px; background: #cbd5e1; transition: background 0.2s ease; } .wl-switch i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 1.1rem; height: 1.1rem; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.35); transition: transform 0.2s ease; }
.wl-switch input:checked + i { background: #0f766e; } .wl-switch input:checked + i::after { transform: translateX(1.1rem); } .wl-switch input:focus-visible + i { outline: 3px solid rgba(15, 118, 110, 0.4); outline-offset: 2px; } .wl-switch input:disabled + i { opacity: 0.5; }
.wl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0.9rem; padding: 0.2rem 0 0.4rem; }
.wl-field { display: grid; gap: 0.35rem; align-content: start; } .wl-label { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
.wl-check { display: inline-flex; align-items: center; gap: 0.6rem; font-weight: 700; font-size: 0.88rem; } .wl-field small { font-size: 0.72rem; color: #64748b; line-height: 1.35; }
.wl-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.8rem 1rem; justify-content: space-between; margin-top: 0.6rem; } .wl-hint { display: inline-flex; gap: 0.45rem; align-items: flex-start; font-size: 0.76rem; color: #64748b; flex: 1 1 20rem; line-height: 1.4; }
.wl-err { color: #dc2626; font-size: 0.8rem; font-weight: 700; margin: 0.4rem 0 0; } .wl-empty { display: flex; align-items: center; gap: 0.6rem; padding: 1.2rem 0.4rem; color: #64748b; font-weight: 600; }
.wl-status { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; background: #e2e8f0; color: #334155; } .wl-status.is-done { background: #d1fae5; color: #065f46; } .wl-status.is-partial { background: #fef3c7; color: #92400e; } .wl-status.is-failed { background: #fee2e2; color: #991b1b; }
.wl-row-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; } .wl-sm { padding: 0.3rem 0.7rem !important; font-size: 0.74rem !important; } .wl-pager { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 0.8rem; font-size: 0.8rem; }
.wl-spin { animation: wl-rot 0.9s linear infinite; } @keyframes wl-rot { to { transform: rotate(360deg); } }
.wl-ov { position: fixed; inset: 0; z-index: 400; display: grid; place-items: center; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(6px); padding: 1rem; }
.wl-ov-card { width: min(34rem, 100%); max-height: 90vh; overflow: auto; border-radius: 1.4rem; padding: 1.4rem; background: #fff; box-shadow: 0 30px 70px -20px rgba(0, 0, 0, 0.6); } html.dark .wl-ov-card { background: #0f172a; color: #e2e8f0; }
.wl-ov-card h2 { margin: 0 0 0.8rem; font-size: 1.15rem; font-weight: 900; } .wl-ov-card.is-done h2 { color: #047857; } .wl-ov-card.is-fail h2 { color: #dc2626; }
.wl-ov-bar { height: 0.55rem; border-radius: 999px; background: rgba(148, 163, 184, 0.25); overflow: hidden; margin-bottom: 0.9rem; } .wl-ov-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #14b8a6, #0f766e); transition: width 0.4s ease; }
.wl-ov-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; } .wl-ov-steps li { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.5rem 0.65rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); }
.wl-ov-steps li.is-running { background: rgba(20, 184, 166, 0.14); } .wl-ov-steps li.is-done .wl-ov-ico { color: #059669; } .wl-ov-steps li.is-error .wl-ov-ico { color: #dc2626; }
.wl-ov-ico { flex: none; width: 1.2rem; height: 1.2rem; display: inline-flex; align-items: center; justify-content: center; margin-top: 0.1rem; } .wl-ov-txt { display: grid; min-width: 0; } .wl-ov-txt b { font-size: 0.86rem; } .wl-ov-txt small { font-size: 0.74rem; color: #64748b; overflow-wrap: anywhere; }
.wl-ov-spin { width: 1rem; height: 1rem; border-radius: 50%; border: 2.5px solid rgba(20, 184, 166, 0.3); border-top-color: #0f766e; animation: wl-rot 0.8s linear infinite; } .wl-ov-idle { width: 0.7rem; height: 0.7rem; border-radius: 50%; background: #cbd5e1; }
.wl-ov-result { margin: 0.9rem 0 0; font-size: 0.88rem; } .wl-ov-warn { display: flex; gap: 0.45rem; margin: 0.9rem 0 0; font-size: 0.76rem; color: #64748b; }
.wl-dlg-title { display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 800; } .wl-dlg-text { font-size: 0.86rem; color: #475569; margin: 0 0 0.7rem; line-height: 1.45; }
.wl-dlg-list { list-style: none; margin: 0 0 0.8rem; padding: 0; display: grid; gap: 0.4rem; } .wl-dlg-list li { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0.15rem 0.8rem; align-items: center; padding: 0.45rem 0.6rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); }
.wl-dlg-pick { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; min-width: 0; } .wl-dlg-est { grid-column: 1 / -1; color: #64748b; font-size: 0.74rem; font-weight: 600; } .wl-dlg-est.is-due { color: #b45309; }
.wl-dlg-none { display: flex; align-items: center; gap: 0.45rem; margin: 0 0 0.7rem; font-size: 0.78rem; color: #92400e; font-weight: 600; }
.wl-dlg-input { width: 100%; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.55rem 0.75rem; font-size: 0.9rem; }
@media (prefers-reduced-motion: reduce) { .wl-seg, .wl-ov-bar i, .wl-spin, .wl-ov-spin, .wl-kpi, .wl-kpi-bar u, .wl-ring-val, .wl-facts > div, .wl-trend-bars i { transition: none; animation: none; } .wl-kpi, .wl-facts > div { opacity: 1; transform: none; } .wl-trend-bars i { transform: none; } .wl-bar-shine, .wl-seg.is-ar { animation: none; } }
.wl-read { display: inline-flex; align-items: center; gap: 0.25rem; margin-top: 0.2rem; font-size: 0.74rem; font-weight: 800; color: #4f46e5; } html.dark .wl-read { color: #a5b4fc; }

.wl-logcell { display: flex; align-items: center; gap: 0.7rem; min-width: 14rem; } .wl-logcell > span:last-child { min-width: 0; }
.wl-logico { flex: none; width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: 0.85rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 6px 14px -7px var(--tone); } .wl-logico.is-sm { width: 1.7rem; height: 1.7rem; border-radius: 0.55rem; box-shadow: none; }
.wl-pill { display: inline-block; min-width: 1.8rem; text-align: center; padding: 0.05rem 0.55rem; border-radius: 999px; font-weight: 900; font-size: 0.8rem; } .wl-pill.is-due { background: #fef3c7; color: #92400e; } .wl-pill.is-none { background: rgba(148, 163, 184, 0.16); color: #64748b; } html.dark .wl-pill.is-due { background: rgba(245, 158, 11, 0.22); color: #fcd34d; }
.wl-mini { display: block; height: 0.32rem; margin-top: 0.3rem; border-radius: 999px; background: rgba(148, 163, 184, 0.22); overflow: hidden; } .wl-mini u { display: block; height: 100%; border-radius: 999px; }
.wl-dlg-pick { gap: 0.45rem; }

/* ---- the Run now window ---- */
.wl-dlg-head { display: flex; align-items: center; gap: 0.75rem; } .wl-dlg-head b { display: block; font-size: 1.1rem; font-weight: 900; } .wl-dlg-head small { display: block; font-size: 0.74rem; font-weight: 600; color: #64748b; }
.wl-dlg-badge { flex: none; width: 2.6rem; height: 2.6rem; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); box-shadow: 0 10px 18px -10px #0f766e; }
.wl-dlg-text { margin: 0 0 0.9rem; font-size: 0.84rem; line-height: 1.5; color: #475569; } html.dark .wl-dlg-text { color: #cbd5e1; }
.wl-dlg-list { list-style: none; margin: 0 0 0.8rem; padding: 0; display: grid; gap: 0.5rem; }
.wl-dlg-list li { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0.35rem 0.8rem; align-items: center; padding: 0.6rem 0.75rem 0.6rem 0.95rem; border-radius: 1rem; overflow: hidden; background: color-mix(in srgb, var(--tone) 7%, transparent); border: 1.5px solid color-mix(in srgb, var(--tone) 20%, transparent); transition: opacity 0.25s ease, border-color 0.25s ease, background 0.25s ease, transform 0.25s ease; animation: wl-li-in 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: var(--d); }
.wl-dlg-list li::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg, var(--tone), var(--tone2)); } .wl-dlg-list li.is-off { opacity: 0.55; } .wl-dlg-list li.is-off::before { opacity: 0.35; } .wl-dlg-list li.has-due:not(.is-off) { border-color: color-mix(in srgb, var(--tone) 55%, transparent); background: color-mix(in srgb, var(--tone) 11%, transparent); }
@keyframes wl-li-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.wl-dlg-pick { display: flex; align-items: center; gap: 0.55rem; cursor: pointer; min-width: 0; } .wl-dlg-pick b { font-size: 0.88rem; }
.wl-chk-in { position: absolute; opacity: 0; width: 0; height: 0; } .wl-chk { flex: none; width: 1.35rem; height: 1.35rem; display: grid; place-items: center; border-radius: 0.45rem; border: 2px solid #cbd5e1; color: transparent; background: #fff; transition: all 0.2s cubic-bezier(0.3, 1.5, 0.5, 1); }
.wl-chk-in:checked + .wl-chk { color: #fff; border-color: transparent; background: linear-gradient(135deg, var(--tone), var(--tone2)); transform: scale(1.08); } .wl-chk-in:focus-visible + .wl-chk { outline: 3px solid color-mix(in srgb, var(--tone) 45%, transparent); outline-offset: 2px; }
.wl-step { display: inline-flex; align-items: center; gap: 0.3rem; } .wl-step em { font-style: normal; font-size: 0.72rem; font-weight: 700; color: #64748b; margin-left: 0.2rem; }
.wl-step input { width: 3.4rem; text-align: center; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.3rem 0.2rem; font-size: 0.9rem; font-weight: 800; background: #fff; -moz-appearance: textfield; } .wl-step input::-webkit-outer-spin-button, .wl-step input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; } html.dark .wl-step input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; }
.wl-step-b { width: 1.7rem; height: 1.7rem; display: grid; place-items: center; border: 0; border-radius: 0.55rem; cursor: pointer; color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); transition: transform 0.15s ease, background 0.2s ease, color 0.2s ease; } .wl-step-b:hover { color: #fff; background: var(--tone); transform: scale(1.1); } .wl-step-b:active { transform: scale(0.9); }
.wl-dlg-est { grid-column: 1 / -1; display: flex; align-items: center; gap: 0.6rem; font-size: 0.74rem; font-weight: 600; color: #64748b; } .wl-dlg-est.is-due { color: #b45309; }
.wl-dlg-bar { flex: 0 0 5.5rem; height: 0.38rem; border-radius: 999px; background: color-mix(in srgb, var(--tone) 15%, transparent); overflow: hidden; } .wl-dlg-bar u { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--tone), var(--tone2)); transition: width 0.6s cubic-bezier(0.2, 0.8, 0.2, 1); }
.wl-dlg-total { display: flex; align-items: center; gap: 0.7rem; margin: 0 0 0.8rem; padding: 0.65rem 0.85rem; border-radius: 1rem; font-size: 0.82rem; font-weight: 600; color: #134e4a; background: linear-gradient(135deg, rgba(20, 184, 166, 0.16), rgba(99, 102, 241, 0.1)); } .wl-dlg-total.is-none { color: #92400e; background: rgba(245, 158, 11, 0.12); } html.dark .wl-dlg-total { color: #99f6e4; } html.dark .wl-dlg-total.is-none { color: #fcd34d; }
.wl-dlg-total-ico { flex: none; width: 2.1rem; height: 2.1rem; display: grid; place-items: center; border-radius: 0.75rem; color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); } .wl-dlg-total.is-none .wl-dlg-total-ico { background: linear-gradient(135deg, #f59e0b, #b45309); } .wl-dlg-total b { font-variant-numeric: tabular-nums; }
.wl-pass { position: relative; } .wl-pass .wl-dlg-input { padding-left: 2.3rem; padding-right: 2.6rem; } .wl-pass-ico { position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); color: #64748b; pointer-events: none; } .wl-pass-eye { position: absolute; right: 0.4rem; top: 50%; transform: translateY(-50%); width: 2rem; height: 2rem; display: grid; place-items: center; border: 0; border-radius: 0.6rem; cursor: pointer; color: #64748b; background: transparent; } .wl-pass-eye:hover { background: rgba(148, 163, 184, 0.2); color: #0f766e; }
.wl-go { transition: transform 0.2s ease, box-shadow 0.2s ease; } .wl-go:hover:not(:disabled) { transform: translateY(-2px); }

/* ---- the compression scene ---- */
.wl-ov-card { animation: wl-pop 0.45s cubic-bezier(0.2, 1.2, 0.4, 1) both; } @keyframes wl-pop { from { opacity: 0; transform: translateY(14px) scale(0.94); } to { opacity: 1; transform: none; } }
.wlx-stage { position: relative; display: grid; grid-template-columns: 4rem minmax(0, 1fr) 4.4rem; align-items: center; gap: 0.5rem; height: 8rem; margin: 0 0 0.7rem; padding: 0.5rem 0.9rem; border-radius: 1.2rem; overflow: hidden; background: radial-gradient(circle at 12% 50%, rgba(99, 102, 241, 0.18), transparent 55%), radial-gradient(circle at 90% 50%, rgba(20, 184, 166, 0.2), transparent 55%), rgba(148, 163, 184, 0.08); }
.wlx-st-db, .wlx-st-box { width: 100%; height: auto; filter: drop-shadow(0 8px 10px rgba(15, 23, 42, 0.25)); } .wlx-stage.is-run .wlx-st-db { animation: wl-db 1.3s ease-in-out infinite; } @keyframes wl-db { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05, 0.96); } }
.wlx-lane { position: relative; height: 100%; }
.wlx-chipx { position: absolute; left: 0; top: 50%; width: 1.5rem; height: 0.95rem; margin-top: -0.475rem; border-radius: 0.3rem; background: #6366f1; opacity: 0; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.45) inset; animation: wl-flow 2.6s linear infinite; animation-delay: calc(var(--n) * -0.26s); }
.wlx-stage:not(.is-run) .wlx-chipx { animation-play-state: paused; opacity: 0 !important; }
@keyframes wl-flow {
  0% { left: 0; opacity: 0; transform: scale(1, 1); background: #6366f1; }
  8% { opacity: 1; }
  38% { left: 38%; transform: scale(1, 1); background: #6366f1; }
  50% { left: 47%; transform: scale(0.55, 1.25); background: #a78bfa; }
  58% { left: 52%; transform: scale(0.3, 0.55); background: #2dd4bf; }
  70% { left: 66%; transform: scale(0.34, 0.5); background: #14b8a6; opacity: 1; }
  100% { left: 98%; transform: scale(0.28, 0.45); background: #0f766e; opacity: 0; }
}
.wlx-press { position: absolute; left: 50%; width: 1.1rem; margin-left: -0.55rem; border-radius: 0.5rem; background: linear-gradient(180deg, #94a3b8, #475569); box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.3) inset; } .wlx-press-top { top: 0; height: 34%; border-top-left-radius: 0; border-top-right-radius: 0; } .wlx-press-bot { bottom: 0; height: 34%; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.wlx-stage.is-run .wlx-press-top { animation: wl-clamp-t 1.3s ease-in-out infinite; } .wlx-stage.is-run .wlx-press-bot { animation: wl-clamp-b 1.3s ease-in-out infinite; }
@keyframes wl-clamp-t { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(46%); } } @keyframes wl-clamp-b { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-46%); } }
.wlx-press-flash { position: absolute; left: 50%; top: 50%; width: 2.2rem; height: 2.2rem; margin: -1.1rem 0 0 -1.1rem; border-radius: 50%; background: radial-gradient(circle, rgba(94, 234, 212, 0.9), transparent 70%); opacity: 0; } .wlx-stage.is-run .wlx-press-flash { animation: wl-flash 1.3s ease-in-out infinite; } @keyframes wl-flash { 0%, 40%, 100% { opacity: 0; transform: scale(0.5); } 50% { opacity: 1; transform: scale(1.4); } }
.wlx-st-boxwrap { position: relative; } .wlx-stage.is-run .wlx-st-box { animation: wl-bump 1.3s ease-in-out infinite; } @keyframes wl-bump { 0%, 55%, 100% { transform: scale(1); } 70% { transform: scale(1.09); } }
.wlx-st-tick { position: absolute; left: 50%; top: 58%; width: 2.4rem; height: 2.4rem; margin: -1.2rem 0 0 -1.2rem; display: grid; place-items: center; border-radius: 50%; color: #fff; background: #059669; box-shadow: 0 8px 14px -6px #059669; transform: scale(0); } .wlx-stage.is-done .wlx-st-tick { animation: wl-tick 0.6s cubic-bezier(0.3, 1.6, 0.5, 1) 0.1s forwards; } .wlx-stage.is-fail .wlx-st-tick { background: #dc2626; box-shadow: 0 8px 14px -6px #dc2626; animation: wl-tick 0.5s ease forwards; } @keyframes wl-tick { to { transform: scale(1); } }
.wlx-stage.is-fail .wlx-st-box { filter: grayscale(1) drop-shadow(0 8px 10px rgba(15, 23, 42, 0.25)); }
.wlx-stage.is-done .wlx-st-box { animation: wl-celebrate 0.7s cubic-bezier(0.3, 1.5, 0.5, 1); } @keyframes wl-celebrate { 0% { transform: scale(1); } 40% { transform: scale(1.2) rotate(-4deg); } 100% { transform: scale(1); } }
.wlx-spark { position: absolute; left: 50%; top: 50%; width: 0.4rem; height: 0.4rem; margin: -0.2rem; border-radius: 50%; background: #fbbf24; opacity: 0; } .wlx-stage.is-done .wlx-spark { animation: wlx-spark 0.9s ease-out forwards; } .wlx-spark:nth-of-type(even) { background: #34d399; }
@keyframes wlx-spark { 0% { opacity: 1; transform: rotate(var(--a)) translateX(0.8rem) scale(1); } 100% { opacity: 0; transform: rotate(var(--a)) translateX(3.2rem) scale(0.3); } }
.wlx-st-line { margin: 0 0 0.6rem; text-align: center; font-size: 0.82rem; font-weight: 700; color: #475569; } .wlx-st-line b { color: #0f766e; font-variant-numeric: tabular-nums; } html.dark .wlx-st-line { color: #cbd5e1; }
.wl-ov-bar i { background-image: linear-gradient(135deg, rgba(255, 255, 255, 0.25) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.25) 50%, rgba(255, 255, 255, 0.25) 75%, transparent 75%); background-size: 1rem 1rem; animation: wl-stripes 0.8s linear infinite; } @keyframes wl-stripes { to { background-position: 1rem 0; } }
@media (prefers-reduced-motion: reduce) { .wl-ov-card, .wl-dlg-list li, .wl-ov-bar i, .wlx-chipx, .wlx-press, .wlx-press-flash, .wlx-st-db, .wlx-st-box, .wlx-spark { animation: none !important; } .wlx-chipx { opacity: 0.6; left: 40%; } .wlx-st-tick { transform: scale(1); } }

@media (max-width: 560px) {
  .wl-dlg-list li { grid-template-columns: 1fr; padding-left: 0.85rem; } .wl-dlg-pick b { font-size: 0.86rem; } .wl-step { justify-content: flex-start; } .wl-step input { width: 4rem; }
  .wl-dlg-est { flex-wrap: wrap; gap: 0.3rem 0.6rem; } .wl-dlg-bar { flex: 1 1 100%; }
  .wl-dlg-head b { font-size: 1rem; } .wl-dlg-total { align-items: flex-start; }
  .wl-go { width: 100%; justify-content: center; }
}
</style>
