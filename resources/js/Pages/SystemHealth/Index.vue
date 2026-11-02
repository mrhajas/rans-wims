<template>
  <admin-layout :title="$t('System Health')">
    <div class="lg-page px-4 md:px-0 sh">
      <page-header icon="chart-pie" :title="$t('System Health')" :subtitle="$t('How WIMS and your connection are doing right now')">
        <div class="sh-actions">
          <span class="sh-live" :class="{ 'is-busy': busy }"><i></i>{{ busy ? $t('Reading...') : $t('Updated') + ' ' + ago }}</span>
          <div class="sh-btns">
            <button type="button" class="sh-btn is-blue" :disabled="busy" :aria-label="$t('Refresh')" @click="load(true)"><span class="sh-btn-ic"><icons name="refresh" class="w-4 h-4" :class="{ 'sh-spin': busy }" /></span><span class="sh-lbl is-hide-sm">{{ $t('Refresh') }}</span></button>
            <button v-if="canOptimize" type="button" class="sh-btn is-amber" @click="openOptimize"><span class="sh-btn-ic"><icons name="sparkles" class="w-4 h-4" /></span><span class="sh-lbl">{{ $t('Optimize All') }}</span></button>
            <button type="button" class="sh-btn is-violet" @click="openReport"><span class="sh-btn-ic"><icons name="send" class="w-4 h-4" /></span><span class="sh-lbl">{{ $t('Send Report') }}</span></button>
          </div>
        </div>
      </page-header>

      <!-- the whole picture -->
      <section class="sh-over">
        <div class="sh-orb" :class="'is-' + overallRating">
          <div class="sh-ring-wrap">
            <svg viewBox="0 0 120 120" class="sh-ring" aria-hidden="true">
              <circle class="sh-ring-bg" cx="60" cy="60" r="50" pathLength="100" />
              <circle class="sh-ring-fg" cx="60" cy="60" r="50" pathLength="100" :stroke-dasharray="ringDash(ready ? shownScore : 0)" transform="rotate(-90 60 60)" />
              <circle class="sh-ring-orbit" cx="60" cy="60" r="57" pathLength="100" />
            </svg>
            <div class="sh-ring-mid"><b>{{ shownScore }}</b><small>/ 100</small></div>
          </div>
          <div class="sh-orb-text">
            <span class="sh-kicker">{{ $t('Overall health') }}</span>
            <h2>{{ verdictTitle }}</h2>
            <p>{{ verdictText }}</p>
            <div class="sh-tally"><span v-for="t in tally" :key="t.key" :class="'is-' + t.key"><i></i>{{ t.n }} {{ t.label }}</span></div>
          </div>
          <waves class="sh-orb-wave" />
        </div>

        <div class="sh-todo-card">
          <div class="sh-card-t"><icons name="adjustments" class="w-4 h-4" />{{ $t('Most useful things to do now') }}</div>
          <ul v-if="topActions.length" class="sh-todo">
            <li v-for="(a, i) in topActions" :key="i" :class="'is-' + a.rating">
              <span class="sh-todo-n">{{ i + 1 }}</span>
              <div class="min-w-0"><small>{{ a.title }}</small>
                <Link v-if="a.route" :href="route(a.route)" class="sh-todo-t">{{ a.text }}</Link>
                <button v-else-if="a.action" type="button" class="sh-todo-t" @click="runAction(a.action)">{{ a.text }}</button>
                <span v-else class="sh-todo-t is-plain">{{ a.text }}</span>
              </div>
            </li>
          </ul>
          <p v-else class="sh-happy"><icons name="check-double" class="w-5 h-5" />{{ $t('Nothing needs doing. Every part is in good shape.') }}</p>
        </div>
      </section>

      <!-- each part of the system, one tile -->
      <nav class="sh-pills" :aria-label="$t('Parts of the system')">
        <a v-for="p in pills" :key="p.id" :href="'#sh-' + p.id" class="sh-pill" :class="'is-' + p.rating" @click.prevent="jump(p.id)"><health-icon :type="p.icon" :tone="p.rating" class="sh-pill-ic" /><span>{{ p.title }}</span><b>{{ p.label }}</b></a>
      </nav>

      <section class="sh-grid">
        <!-- connection -->
        <health-card id="connection" icon="connection" :title="$t('Your connection')" :subtitle="$t('Measured from this device, right now')" :section="connSection" @action="runAction">
          <div class="sh-gauge-wrap">
            <span class="sh-radiate" :style="{ '--c': netColor }"><i></i><i></i><i></i></span>
            <svg viewBox="0 0 180 104" class="sh-gauge" aria-hidden="true">
              <path class="sh-arc-bg" d="M12 92 A78 78 0 0 1 168 92" pathLength="100" />
              <path class="sh-arc-fg" d="M12 92 A78 78 0 0 1 168 92" pathLength="100" :stroke-dasharray="ringDash(netPct)" :style="{ stroke: netColor }" />
              <g v-for="t in [0, 25, 50, 75, 100]" :key="t"><line :x1="tick(t, 70).x" :y1="tick(t, 70).y" :x2="tick(t, 78).x" :y2="tick(t, 78).y" class="sh-tick" /></g>
            </svg>
            <div class="sh-gauge-mid" :style="{ color: netColor }"><b>{{ net.percent === null ? '--' : net.percent }}<em v-if="net.percent !== null">%</em></b><small>{{ netLabel }}</small></div>
          </div>
          <svg viewBox="0 0 200 44" class="sh-spark" preserveAspectRatio="none" aria-hidden="true">
            <defs><linearGradient id="shspark" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="netColor" stop-opacity="0.35" /><stop offset="1" :stop-color="netColor" stop-opacity="0" /></linearGradient></defs>
            <polygon v-if="sparkPoints" :points="sparkPoints + ' 200,44 0,44'" fill="url(#shspark)" />
            <polyline v-if="sparkPoints" :points="sparkPoints" class="sh-spark-line" :style="{ stroke: netColor }" />
            <circle v-for="(m, i) in sparkMisses" :key="i" :cx="m" cy="40" r="2.4" class="sh-spark-miss" />
          </svg>
          <dl class="sh-facts">
            <div><dt>{{ $t('Answer time') }}</dt><dd>{{ net.rtt === null ? '--' : net.rtt + ' ms' }}</dd></div>
            <div><dt>{{ $t('Jumpiness') }}</dt><dd>{{ net.rtt === null ? '--' : net.jitter + ' ms' }}</dd></div>
            <div><dt>{{ $t('Lost') }}</dt><dd>{{ net.loss }}%</dd></div>
            <div><dt>{{ $t('Link') }}</dt><dd>{{ linkText }}</dd></div>
          </dl>
        </health-card>

        <!-- speed of the server -->
        <health-card id="speed" icon="speed" :title="$t('Speed of the server')" :subtitle="$t('Database, cache and disk answer times')" :section="sec('speed')" @action="runAction">
          <template v-if="ok(d.speed)">
            <div class="sh-dials">
              <div v-for="s in dials" :key="s.key" class="sh-dial" :class="'is-' + s.state">
                <svg viewBox="0 0 100 62" aria-hidden="true">
                  <path class="sh-arc-bg" d="M8 56 A42 42 0 0 1 92 56" pathLength="100" />
                  <path class="sh-arc-fg" d="M8 56 A42 42 0 0 1 92 56" pathLength="100" :stroke-dasharray="ringDash(ready ? s.pct : 0)" />
                </svg>
                <b>{{ s.ms }}<em>ms</em></b>
                <span>{{ s.label }}</span>
              </div>
            </div>
            <button type="button" class="sh-btn is-teal sh-test" :disabled="testing" @click="runTest"><span class="sh-btn-ic"><icons name="chart-bar" class="w-4 h-4" :class="{ 'sh-pulse-ic': testing }" /></span>{{ testing ? $t('Testing...') : $t('Test now') }}</button>
            <p class="sh-note">{{ $t('This page was put together in :ms ms (database and files included).', { ms: d.build_ms }) }}</p>
          </template>
          <p v-else class="sh-na">{{ $t('Not available') }}</p>
        </health-card>

        <!-- system -->
        <health-card id="system" icon="system" :title="$t('Speed of the system')" :subtitle="$t('Memory, how busy the machine is, versions')" :section="sec('system')" @action="runAction">
          <template v-if="ok(d.server)">
            <div class="sh-bar-row"><span>{{ $t('Memory in use') }}</span><div class="sh-bar"><i :style="{ width: memPct + '%' }" :class="memPct > 85 ? 'is-bad' : memPct > 65 ? 'is-warn' : 'is-ok'"></i></div><b>{{ d.server.memory_now_mb }} / {{ d.server.memory_limit_mb || '?' }} MB</b></div>
            <div v-for="(l, i) in (d.server.load || [])" :key="i" class="sh-bar-row"><span>{{ [$t('Busy, 1 min'), $t('Busy, 5 min'), $t('Busy, 15 min')][i] }}</span><div class="sh-bar"><i :style="{ width: loadPct(l) + '%' }" :class="d.server.cores ? (loadPct(l) > 85 ? 'is-bad' : loadPct(l) > 65 ? 'is-warn' : 'is-ok') : 'is-a'"></i></div><b>{{ l }}<template v-if="d.server.cores"> / {{ d.server.cores }}</template></b></div>
            <p v-if="!d.server.load" class="sh-na">{{ $t('This host does not report its load.') }}</p>
            <div class="sh-chips">
              <span>PHP {{ d.server.php }}</span><span>Laravel {{ d.server.laravel }}</span>
              <span :class="d.server.opcache.on ? 'is-ok' : 'is-warn'">OPcache {{ d.server.opcache.on ? $t('on') : $t('off') }}</span>
              <span>{{ $t('Cache') }}: {{ d.server.cache_driver }}</span>
            </div>
          </template>
          <p v-else class="sh-na">{{ $t('Not available') }}</p>
        </health-card>

        <!-- database -->
        <health-card id="database" icon="database" :title="$t('Database space')" :subtitle="$t('What the records and their indexes take')" :section="sec('database')" @action="runAction">
          <template v-if="ok(d.database)">
            <div class="sh-donut-row">
              <div class="sh-donut">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                  <circle class="sh-ring-bg" cx="60" cy="60" r="46" pathLength="100" />
                  <circle class="sh-seg is-a" cx="60" cy="60" r="46" pathLength="100" :stroke-dasharray="ready ? dbSplit.data + ' ' + (100 - dbSplit.data) : '0 100'" transform="rotate(-90 60 60)" />
                  <circle class="sh-seg is-b" cx="60" cy="60" r="46" pathLength="100" :stroke-dasharray="ready ? dbSplit.index + ' ' + (100 - dbSplit.index) : '0 100'" :stroke-dashoffset="-dbSplit.data" transform="rotate(-90 60 60)" />
                  <circle class="sh-orbit-dots" cx="60" cy="60" r="55" pathLength="100" />
                </svg>
                <div class="sh-donut-mid"><b>{{ fmtMb(d.database.size_mb) }}</b><small>{{ d.database.quota_mb ? $t('of') + ' ' + fmtMb(d.database.quota_mb) : $t('in use') }}</small></div>
              </div>
              <ul class="sh-legend"><li class="is-a">{{ $t('Records') }} <b>{{ fmtMb(d.database.data_mb) }}</b></li><li class="is-b">{{ $t('Search indexes') }} <b>{{ fmtMb(d.database.index_mb) }}</b></li><li>{{ $t('Tables') }} <b>{{ d.database.tables }}</b></li></ul>
            </div>
            <div v-for="t in d.database.top" :key="t.name" class="sh-bar-row sh-tight"><span :title="t.name">{{ t.name }}</span><div class="sh-bar"><i class="is-a" :style="{ width: ready ? Math.max(2, t.mb / d.database.top[0].mb * 100) + '%' : '0%' }"></i></div><b>{{ fmtMb(t.mb) }}</b></div>
          </template>
          <p v-else class="sh-na">{{ $t('Not available') }}</p>
        </health-card>

        <!-- disk -->
        <health-card id="disk" icon="disk" :title="$t('Server disk and files')" :subtitle="$t('Room left for uploads and documents')" :section="sec('disk')" @action="runAction">
          <template v-if="ok(d.disk) && d.disk.total_gb">
            <div class="sh-donut-row">
              <div class="sh-donut">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                  <circle class="sh-ring-bg" cx="60" cy="60" r="46" pathLength="100" />
                  <circle class="sh-seg" :class="diskUsed > 92 ? 'is-bad' : diskUsed > 80 ? 'is-warn' : 'is-ok'" cx="60" cy="60" r="46" pathLength="100" :stroke-dasharray="ready ? diskUsed + ' ' + (100 - diskUsed) : '0 100'" transform="rotate(-90 60 60)" />
                  <circle class="sh-orbit-dots is-slow" cx="60" cy="60" r="55" pathLength="100" />
                </svg>
                <div class="sh-donut-mid"><b>{{ diskUsed }}%</b><small>{{ $t('used') }}</small></div>
              </div>
              <ul class="sh-legend"><li>{{ $t('Free') }} <b>{{ d.disk.free_gb }} GB</b></li><li>{{ $t('Whole disk') }} <b>{{ d.disk.total_gb }} GB</b></li></ul>
            </div>
          </template>
          <p v-else class="sh-na">{{ $t('Not available') }}</p>
          <template v-if="ok(d.disk) && d.disk.sizes_mb">
            <div v-for="(v, k) in d.disk.sizes_mb" :key="k" class="sh-bar-row sh-tight"><span>{{ { uploads: $t('Uploaded files'), documents: $t('Stored documents'), logs: $t('Log files') }[k] || k }}</span><div class="sh-bar"><i class="is-b" :style="{ width: ready && v ? Math.max(2, v.mb / maxFileMb * 100) + '%' : '0%' }"></i></div><b>{{ v ? fmtMb(v.mb) + (v.partial ? '+' : '') : '--' }}</b></div>
          </template>
        </health-card>

        <!-- SMS battery -->
        <health-card id="sms" icon="sms" :title="$t('SMS credit and sending')" :subtitle="$t('Credit left with the SMS provider')" :section="smsSection" @action="runAction">
          <div class="sh-batt-wrap">
            <div class="sh-batt" :class="smsState">
              <div class="sh-batt-body">
                <div class="sh-batt-fill" :style="{ width: ready ? smsPct + '%' : '0%' }"><i class="sh-batt-stripes"></i><i class="sh-batt-gloss"></i></div>
                <span v-for="n in 4" :key="n" class="sh-batt-cut" :style="{ left: n * 20 + '%' }"></span>
                <div class="sh-batt-num"><icons name="sms" class="w-5 h-5 sh-bolt" /><b>{{ cloud ? shownSms : '--' }}<em v-if="cloud">%</em></b></div>
              </div>
              <span class="sh-batt-cap"></span>
            </div>
            <div class="sh-batt-info">
              <template v-if="cloud && cloud.sms && cloud.sms.supported && cloud.sms.ok"><b class="sh-big">{{ fmtNum(cloud.sms.balance) }}</b><small>{{ $t('SMS credit left') }}</small><small class="sh-fine">{{ $t('Full level: :n', { n: fmtNum(cloud.sms.full_credit) }) }}</small></template>
              <template v-else-if="cloud && cloud.sms && cloud.sms.supported"><b class="sh-big is-na">--</b><small>{{ cloud.sms.error || $t('The provider did not answer.') }}</small></template>
              <template v-else-if="cloud"><b class="sh-big is-na">--</b><small>{{ $t('The balance can be read for Beem Africa only.') }}</small></template>
              <template v-else><i class="sh-skel w1"></i><i class="sh-skel w2"></i></template>
            </div>
          </div>
          <div v-if="ok(d.sms_usage)" class="sh-split">
            <component :is="canLogs ? 'Link' : 'div'" v-bind="canLogs ? { href: smsLogs('SENT,DELIVERED', true), title: $t('Open the Delivery Logs') } : {}" class="sh-st"><span class="sh-st-ic"><icons name="send" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.sms_usage.sent_today == null ? '--' : fmtNum(d.sms_usage.sent_today) }}</b><small>{{ $t('Sent today') }}</small></span><icons v-if="canLogs" name="forward" class="sh-st-go w-3 h-3" /></component>
            <component :is="canLogs ? 'Link' : 'div'" v-bind="canLogs ? { href: smsLogs('FAILED', true), title: $t('Open the Delivery Logs') } : {}" class="sh-st" :class="{ 'is-bad': d.sms_usage.failed_today > 0 }"><span class="sh-st-ic"><icons name="x-circle" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.sms_usage.failed_today == null ? '--' : fmtNum(d.sms_usage.failed_today) }}</b><small>{{ $t('Failed today') }}</small></span><icons v-if="canLogs" name="forward" class="sh-st-go w-3 h-3" /></component>
            <component :is="canLogs ? 'Link' : 'div'" v-bind="canLogs ? { href: smsLogs('SENT,DELIVERED', false), title: $t('Open the Delivery Logs') } : {}" class="sh-st"><span class="sh-st-ic"><icons name="check-double" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.sms_usage.sent_month == null ? '--' : fmtNum(d.sms_usage.sent_month) }}</b><small>{{ $t('Sent this month') }}</small></span><icons v-if="canLogs" name="forward" class="sh-st-go w-3 h-3" /></component>
            <component :is="canLogs ? 'Link' : 'div'" v-bind="canLogs ? { href: smsLogs('FAILED', false), title: $t('Open the Delivery Logs') } : {}" class="sh-st" :class="{ 'is-bad': d.sms_usage.failed_month > 0 }"><span class="sh-st-ic"><icons name="exclamation" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.sms_usage.failed_month == null ? '--' : fmtNum(d.sms_usage.failed_month) }}</b><small>{{ $t('Failed this month') }}</small></span><icons v-if="canLogs" name="forward" class="sh-st-go w-3 h-3" /></component>
          </div>
          <div v-if="ok(d.sms_usage) && d.sms_usage.week" class="sh-week" role="img" :aria-label="$t('SMS sent and failed in the last 7 days')">
            <div class="sh-week-h"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Last 7 days') }}<span class="sh-week-key"><i class="is-ok"></i>{{ $t('Sent') }}<i class="is-bad"></i>{{ $t('Failed') }}</span></div>
            <div class="sh-week-cols">
              <div v-for="w in d.sms_usage.week" :key="w.date" class="sh-week-col" :title="w.date + ': ' + w.sent + ' ' + $t('sent') + ', ' + w.failed + ' ' + $t('failed')">
                <span class="sh-week-n">{{ w.sent + w.failed }}</span>
                <div class="sh-week-bar"><i class="is-bad" :style="{ height: ready ? w.failed / smsWeekMax * 100 + '%' : '0%' }"></i><i class="is-ok" :style="{ height: ready ? w.sent / smsWeekMax * 100 + '%' : '0%' }"></i></div>
                <small>{{ w.day }}</small>
              </div>
            </div>
          </div>
        </health-card>

        <!-- cloud drive -->
        <health-card id="drive" icon="drive" :title="$t('Cloud drive space')" :subtitle="$t('Where Site Diary photos and videos go')" :section="driveSection" @action="runAction">
          <template v-if="cloud && cloud.drive">
            <template v-if="cloud.drive.connected">
              <!-- the whole drive as a ring: site files, the bin, other Google services, and what is free -->
              <div class="sh-donut-row">
                <div class="sh-donut">
                  <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="sh-ring-bg" cx="60" cy="60" r="46" pathLength="100" />
                    <circle v-for="g in driveSegs" :key="g.key" class="sh-seg" :class="g.cls" cx="60" cy="60" r="46" pathLength="100" :stroke-dasharray="ready ? g.pct + ' ' + (100 - g.pct) : '0 100'" :stroke-dashoffset="-g.start" transform="rotate(-90 60 60)" />
                    <circle class="sh-orbit-dots is-slow" cx="60" cy="60" r="55" pathLength="100" />
                  </svg>
                  <div class="sh-donut-mid"><b>{{ cloud.drive.limit_gb ? drivePct + '%' : cloud.drive.used_gb + ' GB' }}</b><small>{{ cloud.drive.limit_gb ? $t('used') : $t('no limit') }}</small></div>
                </div>
                <ul class="sh-legend sh-legend-icons">
                  <li class="is-a"><icons name="upload" class="w-3 h-3" />{{ $t('Site Diary and Drive files') }} <b>{{ fmtGb(cloud.drive.files_gb) }}</b></li>
                  <li class="is-warn"><icons name="archive-box" class="w-3 h-3" />{{ $t('In the bin') }} <b>{{ fmtGb(cloud.drive.trash_gb) }}</b></li>
                  <li class="is-b"><icons name="users" class="w-3 h-3" />{{ $t('Other Google services') }} <b>{{ fmtGb(cloud.drive.other_gb) }}</b></li>
                  <li class="is-free"><icons name="collection" class="w-3 h-3" />{{ $t('Still free') }} <b>{{ cloud.drive.left_gb === null ? $t('no limit') : fmtGb(cloud.drive.left_gb) }}</b></li>
                </ul>
              </div>
              <div class="sh-split">
                <div><span class="sh-st-ic"><icons name="chart-pie" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ fmtGb(cloud.drive.used_gb) }}</b><small>{{ $t('Used on') }} {{ cloud.drive.provider }}</small></span></div>
                <div><span class="sh-st-ic"><icons name="database" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ cloud.drive.limit_gb ? fmtGb(cloud.drive.limit_gb) : $t('No limit') }}</b><small>{{ $t('Whole drive') }}</small></span></div>
              </div>
              <p v-if="cloud.drive.trash_gb > 0" class="sh-note">{{ $t(':n GB of it is in the drive bin.', { n: cloud.drive.trash_gb }) }}</p>
            </template>
            <!-- not working: say exactly why, and where to fix it -->
            <div v-else class="sh-drive-state" :class="'is-' + (cloud.drive.state || 'off')">
              <span class="sh-ds-ic"><icons :name="['rejected', 'unreachable'].includes(cloud.drive.state) ? 'exclamation' : 'upload'" class="w-6 h-6" /></span>
              <div class="min-w-0">
                <b>{{ driveStateTitle }}</b>
                <p>{{ driveStateText }}</p>
                <Link v-if="cloud.drive.state !== 'off' || $super" :href="route('site-diary.settings')" class="sh-btn is-blue sh-ds-btn"><span class="sh-btn-ic"><icons name="adjustments" class="w-4 h-4" /></span>{{ $t('Open Site Diary settings') }}</Link>
              </div>
            </div>
          </template>
          <template v-else><i class="sh-skel w3"></i><i class="sh-skel w1"></i></template>
          <div class="sh-ghost"><icons name="upload" class="w-4 h-4" />{{ $t('OneDrive: not connected to WIMS') }}</div>
        </health-card>

        <!-- backups -->
        <health-card id="backups" icon="backups" :title="$t('Backups and scheduled work')" :subtitle="$t('The safety copy and the clock that runs the tasks')" :section="sec('backups')" @action="runAction">
          <template v-if="ok(d.jobs)">
            <svg viewBox="0 0 240 46" class="sh-ecg" :class="'is-' + beatState" aria-hidden="true"><polyline points="0,26 50,26 62,26 70,8 80,42 90,22 98,26 150,26 162,26 170,8 180,42 190,22 198,26 240,26" /></svg>
            <p class="sh-beat">{{ beatText }}</p>
            <div class="sh-split"><div :class="{ 'is-warn': d.jobs.pending > 20 }"><span class="sh-st-ic"><icons name="clock" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.jobs.pending === null ? '--' : d.jobs.pending }}</b><small>{{ $t('Waiting in the queue') }}</small></span></div><div :class="{ 'is-bad': d.jobs.failed > 0 }"><span class="sh-st-ic"><icons name="x-circle" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.jobs.failed === null ? '--' : d.jobs.failed }}</b><small>{{ $t('Failed jobs') }}</small></span></div></div>
          </template>
          <p v-else class="sh-na">{{ $t('Not available') }}</p>
          <template v-if="ok(d.backups)">
            <div class="sh-bar-row"><span>{{ $t('Last backup') }}</span><div class="sh-bar"><i :style="{ width: ready ? backupFresh + '%' : '0%' }" :class="backupFresh < 30 ? 'is-bad' : backupFresh < 70 ? 'is-warn' : 'is-ok'"></i></div><b>{{ d.backups.hours_ago === null ? $t('none yet') : agoHours(d.backups.hours_ago) }}</b></div>
            <p class="sh-note">{{ d.backups.auto_enabled ? $t('Automatic backups are on.') : $t('Automatic backups are off.') }}</p>
          </template>
        </health-card>

        <!-- people and safety -->
        <health-card id="safety" icon="safety" :title="$t('People and safety')" :subtitle="$t('Who is using WIMS, errors and sign-ins')" :section="sec('safety')" @action="runAction">
          <template v-if="ok(d.people)">
            <div class="sh-radar-row">
              <div class="sh-radar" aria-hidden="true">
                <i class="sh-radar-sweep"></i>
                <span v-for="(b, i) in blips" :key="i" class="sh-blip" :style="{ left: b.x + '%', top: b.y + '%', animationDelay: b.d + 's' }"></span>
              </div>
              <div class="sh-online"><b>{{ d.people.online_now }}</b><small>{{ $t('people using WIMS now') }}</small><small class="sh-fine">{{ $t(':n active accounts', { n: d.people.active_users }) }}</small></div>
            </div>
            <div class="sh-vs" v-if="d.people.logins_today !== null"><i class="is-ok" :style="{ flexGrow: Math.max(d.people.logins_today, 0.05) }"></i><i class="is-bad" :style="{ flexGrow: d.people.failed_logins_today }"></i></div>
            <div class="sh-split" v-if="d.people.logins_today !== null"><div><span class="sh-st-ic"><icons name="key" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.people.logins_today }}</b><small>{{ $t('Sign-ins today') }}</small></span></div><div :class="{ 'is-warn': d.people.failed_logins_today >= 10 }"><span class="sh-st-ic"><icons name="exclamation" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.people.failed_logins_today }}</b><small>{{ $t('Failed sign-ins') }}</small></span></div></div>
          </template>
          <div v-if="ok(d.logs)" class="sh-split"><div :class="{ 'is-bad': d.logs.errors_today > 20, 'is-warn': d.logs.errors_today > 0 }"><span class="sh-st-ic"><icons name="exclamation" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.logs.errors_today === null ? '--' : d.logs.errors_today }}</b><small>{{ $t('Errors logged today') }}</small></span></div><div v-if="ok(d.slow)" :class="{ 'is-warn': d.slow.pages_24h >= 5 }"><span class="sh-st-ic"><icons name="clock" class="w-4 h-4" /></span><span class="sh-st-tx"><b>{{ d.slow.pages_24h === null ? '--' : d.slow.pages_24h }}</b><small>{{ $t('Slow pages, 24 h') }}</small></span></div></div>
        </health-card>

        <!-- licence -->
        <health-card v-if="sec('licence')" id="licence" icon="licence" :title="$t('Licence')" :subtitle="$t('How long WIMS is licensed to run')" :section="sec('licence')" @action="runAction">
          <div class="sh-donut-row">
            <div class="sh-donut">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="sh-ring-bg" cx="60" cy="60" r="46" pathLength="100" />
                <circle class="sh-seg" :class="d.licence.days_left < 8 ? 'is-bad' : d.licence.days_left < 31 ? 'is-warn' : 'is-ok'" cx="60" cy="60" r="46" pathLength="100" :stroke-dasharray="ready ? Math.min(100, Math.max(2, d.licence.days_left / 365 * 100)) + ' 100' : '0 100'" transform="rotate(-90 60 60)" />
                <circle class="sh-orbit-dots" cx="60" cy="60" r="55" pathLength="100" />
              </svg>
              <div class="sh-donut-mid"><b>{{ d.licence.days_left }}</b><small>{{ $t('days left') }}</small></div>
            </div>
            <ul class="sh-legend"><li>{{ $t('Status') }} <b>{{ d.licence.label || '--' }}</b></li></ul>
          </div>
        </health-card>

        <!-- you -->
        <health-card id="you" icon="you" plain :title="$t('You, and where you are in WIMS')" :subtitle="$t('What you can see from here')" :section="youSection" wide @action="runAction">
          <div v-if="ok(d.you)" class="sh-me">
            <div class="sh-me-id"><span class="sh-me-av">{{ initials }}</span><div><b>{{ d.you.name }}</b><small>{{ d.you.roles.join(', ') || $t('No role') }}</small></div></div>
            <dl class="sh-facts sh-facts-wide">
              <div><dt>{{ $t('Projects and warehouses you can see') }}</dt><dd>{{ d.you.scope_all ? $t('All') : d.you.scope_count }}</dd></div>
              <div><dt>{{ $t('Working in now') }}</dt><dd>{{ currentWarehouse }}</dd></div>
              <div v-if="d.you.pending_transfers !== null"><dt>{{ $t('Transfers waiting for you to approve') }}</dt><dd>{{ d.you.pending_transfers }}</dd></div>
              <div v-if="d.you.unread_notifications !== null"><dt>{{ $t('Unread alerts') }}</dt><dd>{{ d.you.unread_notifications }}</dd></div>
              <div><dt>{{ $t('This device') }}</dt><dd>{{ deviceText }}</dd></div>
              <div><dt>{{ $t('Your address') }}</dt><dd>{{ d.you.ip }}</dd></div>
              <div><dt>{{ $t('Auto sign-out after') }}</dt><dd>{{ d.you.idle_limit_minutes ? d.you.idle_limit_minutes + ' ' + $t('min idle') : $t('Never') }}</dd></div>
              <div v-if="d.you.previous_login_at"><dt>{{ $t('Your previous sign-in') }}</dt><dd>{{ $datetime(d.you.previous_login_at) }}<small v-if="d.you.previous_login_device"> · {{ d.you.previous_login_device }}</small></dd></div>
            </dl>
            <div v-if="d.you.scope_names.length" class="sh-chips"><span v-for="n in d.you.scope_names" :key="n">{{ n }}</span><span v-if="d.you.scope_count > d.you.scope_names.length">+{{ d.you.scope_count - d.you.scope_names.length }}</span></div>
          </div>
        </health-card>
      </section>

      <!-- Send Report -->
      <div v-if="rep.open" class="sh-modal" role="dialog" aria-modal="true" @keydown.esc="closeReport">
        <div class="sh-modal-bg" @click="closeReport"></div>
        <div class="sh-modal-card">
          <div class="sh-modal-head"><span class="sh-modal-ic is-violet"><icons name="send" class="w-5 h-5" /></span><div><h3>{{ $t('Send Report') }}</h3><p>{{ rep.stage === 'ask' ? $t('A full System Health report goes by e-mail, in the WIMS style.') : rep.sent.length ? $t('The report was sent.') : $t('The report could not be sent.') }}</p></div></div>
          <template v-if="rep.stage === 'ask'">
            <label class="sh-pwl">{{ $t('To') }}</label>
            <div class="sh-to"><span class="sh-to-chip is-fixed"><icons name="lock-closed" class="w-3.5 h-3.5" />{{ reportTo }}<small>{{ $t('always included') }}</small></span></div>
            <label class="sh-pwl" for="sh-rep-extra">{{ $t('Add another e-mail (optional)') }}</label>
            <input id="sh-rep-extra" ref="extra" v-model="rep.extra" type="text" inputmode="email" class="sh-pw" :placeholder="$t('name@example.com (up to 3, separated by commas)')" autocomplete="off" @keydown.enter="sendReport" />
            <ul class="sh-incl">
              <li><icons name="tick" class="w-3.5 h-3.5" />{{ $t('The rating and figures of every part of the system') }}</li>
              <li><icons name="tick" class="w-3.5 h-3.5" />{{ $t('The errors and details behind each rating') }}</li>
              <li><icons name="tick" class="w-3.5 h-3.5" />{{ $t('What can be done about each part') }}</li>
              <li><icons name="tick" class="w-3.5 h-3.5" />{{ $t('The measurement of your own connection') }}</li>
            </ul>
            <p class="sh-note">{{ $t('Passwords, keys, e-mail addresses and query values are removed from the error text before it is sent.') }}</p>
            <p v-if="rep.error" class="sh-err" role="alert">{{ rep.error }}</p>
            <div class="sh-modal-actions"><button type="button" class="sh-btn is-plain" @click="closeReport">{{ $t('Cancel') }}</button><button type="button" class="sh-btn is-violet" :disabled="rep.busy" @click="sendReport"><span class="sh-btn-ic"><icons name="send" class="w-4 h-4" :class="{ 'sh-fly': rep.busy }" /></span>{{ rep.busy ? $t('Sending...') : $t('Send report') }}</button></div>
          </template>
          <template v-else>
            <ul class="sh-sent"><li v-for="a in rep.sent" :key="a" class="is-ok"><icons name="tick" class="w-4 h-4" />{{ a }}</li><li v-for="a in rep.failed" :key="a" class="is-bad"><icons name="cross" class="w-4 h-4" />{{ a }} <small>{{ $t('not delivered') }}</small></li></ul>
            <p v-if="rep.error" class="sh-err">{{ rep.error }}</p>
            <div class="sh-modal-actions"><button type="button" class="sh-btn is-blue" @click="closeReport"><span class="sh-btn-ic"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Close') }}</button></div>
          </template>
        </div>
      </div>

      <!-- Optimize All -->
      <div v-if="opt.open" class="sh-modal" role="dialog" aria-modal="true" @keydown.esc="closeOptimize">
        <div class="sh-modal-bg" @click="closeOptimize"></div>
        <div class="sh-modal-card">
          <div class="sh-modal-head"><span class="sh-modal-ic"><icons name="sparkles" class="w-5 h-5" /></span><div><h3>{{ $t('Optimize All') }}</h3><p>{{ opt.stage === 'ask' ? $t('Makes the data already on the server smaller and faster. No record is changed or deleted.') : opt.stage === 'run' ? $t('Working. You can keep this page open; please do not close the browser.') : opt.failed ? $t('It stopped. What was done is kept in the history.') : $t('Done.') }}</p></div></div>
          <ul class="sh-steps">
            <li v-for="s in opt.steps" :key="s.key" :class="'is-' + s.state"><span class="sh-step-dot"><icons v-if="s.state === 'done'" name="tick" class="w-3 h-3" /><icons v-else-if="s.state === 'error'" name="cross" class="w-3 h-3" /></span><div><b>{{ s.title }}</b><small>{{ s.summary || s.what }}</small></div></li>
          </ul>
          <template v-if="opt.stage === 'ask'">
            <label class="sh-pwl" for="sh-opt-pw">{{ $t('Your password, to confirm') }}</label>
            <input id="sh-opt-pw" ref="pw" v-model="opt.password" type="password" class="sh-pw" autocomplete="current-password" @keydown.enter="startOptimize" />
            <p v-if="opt.error" class="sh-err" role="alert">{{ opt.error }}</p>
            <div class="sh-modal-actions"><button type="button" class="sh-btn is-plain" @click="closeOptimize">{{ $t('Cancel') }}</button><button type="button" class="sh-btn is-amber" :disabled="opt.busy" @click="startOptimize"><span class="sh-btn-ic"><icons name="sparkles" class="w-4 h-4" /></span>{{ opt.busy ? $t('Starting...') : $t('Start') }}</button></div>
          </template>
          <template v-else-if="opt.stage === 'done'">
            <div v-if="!opt.failed" class="sh-result"><b>{{ opt.saved }}</b><small>{{ $t('saved') }} · {{ opt.rating }}</small></div>
            <p v-if="opt.error" class="sh-err">{{ opt.error }}</p>
            <div class="sh-modal-actions"><Link v-if="opt.url" :href="opt.url" class="sh-btn is-plain">{{ $t('See the report') }}</Link><button type="button" class="sh-btn is-blue" @click="closeOptimize"><span class="sh-btn-ic"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Close') }}</button></div>
          </template>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import HealthCard from './HealthCard.vue';
import HealthIcon from './HealthIcon.vue';
import Waves from './Waves.vue';
import { net, LEVELS, startNetworkSignal, watchNetworkClosely } from '@/Core/networkSignal';

// System Health: every figure comes from the server (App\Support\SystemHealth\Collector) or, for the signal, from this device's own measurement. Nothing here is a
// made-up number; a figure that could not be read says "Not available". Each part of the system has its own health rating and what can be done about it.
const RATE = s => (s === null || s === undefined ? 'unknown' : s >= 92 ? 'excellent' : s >= 80 ? 'very_good' : s >= 65 ? 'good' : s >= 45 ? 'fair' : 'bad');

export default {
  components: { AdminLayout, PageHeader, HealthCard, HealthIcon, Waves, Link },
  props: { core: Object, reportTo: String },
  data() {
    return {
      d: this.core, cloud: null, busy: false, testing: false, ready: false, shownScore: 0, shownSms: 0, timer: null, now: Date.now(), net, updatedAt: Date.now(), tickTimer: null,
      rep: { open: false, stage: 'ask', extra: '', busy: false, error: '', sent: [], failed: [] },
      opt: { open: false, stage: 'ask', password: '', error: '', busy: false, steps: [], failed: false, saved: '', rating: '', url: '' },
    };
  },
  computed: {
    canOptimize() { return this.$can('run-optimizer'); },
    // Delivery Logs is a Super Admin page, so the SMS tiles link to it for the Super Admin only
    canLogs() { return this.$super && this.$can('read-wims-connect'); },
    // ---- ratings
    connSection() {
      if (this.net.percent === null) return { score: null, rating: 'unknown', note: this.$t('Measuring...'), actions: [] };
      const score = this.net.percent;
      const actions = [];
      if (score < 65) {
        actions.push({ text: this.$t('Move closer to the Wi-Fi router, or plug in a cable.') });
        actions.push({ text: this.$t('If everybody on this line is slow, the problem is the internet line, not WIMS.') });
      }
      if (this.net.loss >= 10) actions.push({ text: this.$t('Requests are being lost: the link drops out. Try another connection.') });
      return { score, rating: RATE(score), note: '', actions };
    },
    smsSection() { return this.cloud ? this.cloud.sections.sms : { rating: 'unknown', score: null, note: this.$t('Reading the credit...'), actions: [] }; },
    driveSection() { return this.cloud ? this.cloud.sections.drive : { rating: 'unknown', score: null, note: this.$t('Reading the drive...'), actions: [] }; },
    youSection() { return null; },
    allSections() {
      const own = this.d.sections || {};
      const list = [
        { id: 'connection', icon: 'connection', title: this.$t('Connection'), s: this.connSection },
        { id: 'speed', icon: 'speed', title: this.$t('Server speed'), s: own.speed },
        { id: 'system', icon: 'system', title: this.$t('System'), s: own.system },
        { id: 'database', icon: 'database', title: this.$t('Database'), s: own.database },
        { id: 'disk', icon: 'disk', title: this.$t('Disk'), s: own.disk },
        { id: 'sms', icon: 'sms', title: this.$t('SMS credit'), s: this.smsSection },
        { id: 'drive', icon: 'drive', title: this.$t('Cloud drive'), s: this.driveSection },
        { id: 'backups', icon: 'backups', title: this.$t('Backups'), s: own.backups },
        { id: 'safety', icon: 'safety', title: this.$t('Safety'), s: own.safety },
        { id: 'licence', icon: 'licence', title: this.$t('Licence'), s: own.licence },
      ];
      return list.filter(x => x.s);
    },
    pills() { return this.allSections.map(x => ({ id: x.id, icon: x.icon, title: x.title, rating: x.s.rating, label: this.ratingLabel(x.s.rating) })); },
    overallScore() {
      const rated = this.allSections.filter(x => x.s.score !== null && x.s.score !== undefined);
      if (!rated.length) return (this.d.score || {}).value || 0;
      const worst = Math.min(...rated.map(x => x.s.score));
      const mean = rated.reduce((a, x) => a + x.s.score, 0) / rated.length;
      const whole = Math.round(mean * 0.6 + worst * 0.4); // one weak part pulls the whole down, but does not erase the good ones
      return this.unreadable ? Math.min(79, whole) : whole; // a part that could not be read means the whole cannot be called better than Good
    },
    unreadable() { return this.allSections.filter(x => x.s.unreadable).length; },
    overallRating() { return RATE(this.overallScore); },
    verdictTitle() { return { excellent: this.$t('Excellent shape'), very_good: this.$t('In very good shape'), good: this.$t('In good shape'), fair: this.$t('Fair: a few things to fix'), bad: this.$t('Needs attention'), unknown: this.$t('Reading...') }[this.overallRating]; },
    verdictText() {
      const bad = this.allSections.filter(x => x.s.rating === 'bad').length;
      const fair = this.allSections.filter(x => x.s.rating === 'fair').length;
      const base = bad || fair ? this.$t(':a part(s) to fix and :b to watch.', { a: bad, b: fair }) : this.$t('Every part is rated Good or better.');
      return this.unreadable ? base + ' ' + this.$t(':n part(s) could not be read.', { n: this.unreadable }) : base;
    },
    tally() {
      return ['excellent', 'very_good', 'good', 'fair', 'bad'].map(k => ({ key: k, n: this.allSections.filter(x => x.s.rating === k).length, label: this.ratingLabel(k) })).filter(t => t.n > 0);
    },
    topActions() {
      const out = [];
      const seen = new Set();
      [...this.allSections].filter(x => x.s.actions && x.s.actions.length).sort((a, b) => (a.s.score ?? 100) - (b.s.score ?? 100)).forEach(x => {
        x.s.actions.forEach(a => {
          const key = a.action || a.text;
          if (!seen.has(key) && out.length < 5 && (x.s.score ?? 100) < 80) { seen.add(key); out.push({ ...a, title: x.title, rating: x.s.rating }); }
        });
      });
      return out;
    },
    ago() { const s = Math.max(0, Math.round((this.now - this.updatedAt) / 1000)); return s < 5 ? this.$t('just now') : s + ' s'; },
    // ---- connection
    netPct() { return this.net.percent === null ? 0 : this.net.percent; },
    netColor() { return (LEVELS[this.net.level] || LEVELS.unknown).color; },
    netLabel() { return { excellent: this.$t('Excellent'), good: this.$t('Good'), fair: this.$t('Fair'), weak: this.$t('Weak'), poor: this.$t('Very weak'), offline: this.$t('No network'), unknown: this.$t('Measuring...') }[this.net.level]; },
    linkText() {
      const t = { wifi: this.$t('Wi-Fi'), ethernet: this.$t('Cable'), cellular: this.$t('Mobile data'), other: this.$t('Other') }[this.net.type] || this.$t('Not reported');
      return t + (this.net.effective ? ' ' + String(this.net.effective).toUpperCase() : '');
    },
    sparkPoints() {
      const v = this.net.samples.map(x => (typeof x === 'number' ? x : null));
      const nums = v.filter(x => x !== null);
      if (nums.length < 2) return '';
      const max = Math.max(...nums, 100);
      const step = 200 / Math.max(1, v.length - 1);
      return v.map((x, i) => (x === null ? null : (i * step).toFixed(1) + ',' + (36 - Math.min(1, x / max) * 30).toFixed(1))).filter(Boolean).join(' ');
    },
    sparkMisses() { const n = this.net.samples.length; const step = 200 / Math.max(1, n - 1); return this.net.samples.map((x, i) => (typeof x === 'number' ? null : i * step)).filter(x => x !== null); },
    // ---- the rest
    dials() {
      const s = this.d.speed || {};
      const mk = (key, label, ms, max, good, fair) => ({ key, label, ms, pct: Math.max(4, Math.min(100, 100 - (ms / max) * 100)), state: ms < good ? 'ok' : ms < fair ? 'warn' : 'bad' });
      return [mk('db', this.$t('Database'), s.db_ms, 60, 8, 40), mk('cache', this.$t('Cache'), s.cache_ms, 30, 5, 20), mk('disk', this.$t('Disk'), s.disk_ms, 30, 5, 20)];
    },
    memPct() { const s = this.d.server || {}; return s.memory_limit_mb ? Math.min(100, Math.round((s.memory_now_mb / s.memory_limit_mb) * 100)) : 0; },
    dbSplit() { const x = this.d.database; const total = (x.data_mb + x.index_mb) || 1; const whole = x.quota_mb ? Math.min(100, (x.size_mb / x.quota_mb) * 100) : 100; return { data: (x.data_mb / total) * whole, index: (x.index_mb / total) * whole }; },
    diskUsed() { const k = this.d.disk; return k && k.total_gb ? Math.round(((k.total_gb - k.free_gb) / k.total_gb) * 100) : 0; },
    maxFileMb() { return Math.max(1, ...Object.values((this.d.disk || {}).sizes_mb || {}).filter(Boolean).map(v => v.mb)); },
    smsPct() { return this.cloud && this.cloud.sms && this.cloud.sms.ok ? Number(this.cloud.sms.percent || 0) : 0; },
    smsState() { if (!(this.cloud && this.cloud.sms && this.cloud.sms.ok)) return 'is-na'; return this.smsPct < 15 ? 'is-bad' : this.smsPct < 40 ? 'is-warn' : 'is-ok'; },
    drivePct() { const c = this.cloud && this.cloud.drive; return c && c.limit_gb ? Math.min(100, Math.round((c.used_gb / c.limit_gb) * 100)) : 4; },
    smsWeekMax() { const w = (this.d && this.d.sms_usage && this.d.sms_usage.week) || []; return Math.max(1, ...w.map(x => x.sent + x.failed)); },
    driveSegs() {
      const c = this.cloud && this.cloud.drive;
      if (!c || !c.connected) return [];
      // with a limit the ring is the whole drive; without one it is split between what is used
      const whole = c.limit_gb || c.used_gb || 1;
      const parts = [['files', c.files_gb, 'is-a'], ['bin', c.trash_gb, 'is-warn'], ['other', c.other_gb, 'is-b']];
      let start = 0;
      return parts.map(([key, gb, cls]) => {
        const pct = Math.max(0, Math.min(100 - start, ((gb || 0) / whole) * 100));
        const seg = { key, cls, pct: Math.round(pct * 10) / 10, start };
        start += pct;
        return seg;
      }).filter(g => g.pct > 0);
    },
    driveStateTitle() {
      const st = this.cloud && this.cloud.drive && this.cloud.drive.state;
      return st === 'rejected' ? this.$t('Google refused the connection') : st === 'unreachable' ? this.$t('Google Drive could not be reached') : st === 'unconfigured' ? this.$t('Google Drive is switched on, not connected yet') : this.$t('Google Drive is switched off');
    },
    driveStateText() {
      const d = (this.cloud && this.cloud.drive) || {};
      if (d.state === 'rejected') return this.$t('Site Diary photos and videos cannot be saved to Google Drive until the connection is renewed. Use Connect Google account in Site Diary settings. If this comes back every week, set the Google consent screen to In production.');
      if (d.state === 'unreachable') return this.$t('This server could not reach Google just now. Check its internet connection; the page tries again in a minute.');
      if (d.state === 'unconfigured') return this.$t('Finish the sign-in and choose the storage folder in Site Diary settings.');
      return this.$t('Turn it on in Site Diary settings to keep photos and videos in Google Drive.');
    },
    driveState() { const p = this.drivePct; return this.cloud.drive.limit_gb ? (p > 90 ? 'is-bad' : p > 75 ? 'is-warn' : 'is-ok') : 'is-ok'; },
    beatState() { const j = this.d.jobs; if (!j || j.scheduler_minutes_ago === null) return 'na'; return j.scheduler_minutes_ago <= 3 ? 'ok' : j.scheduler_minutes_ago <= 15 ? 'warn' : 'bad'; },
    beatText() { const j = this.d.jobs; if (j.scheduler_minutes_ago === null) return this.$t('No heartbeat seen yet from the scheduler (cron).'); return j.scheduler_minutes_ago <= 1 ? this.$t('The scheduler is beating: last beat under a minute ago.') : this.$t('The scheduler last beat :m minutes ago.', { m: j.scheduler_minutes_ago }); },
    backupFresh() { const h = this.d.backups.hours_ago; return h === null ? 0 : Math.max(2, Math.round(100 - Math.min(100, (h / 168) * 100))); },
    blips() { const n = Math.min(14, (this.d.people && this.d.people.online_now) || 0); return Array.from({ length: n }, (_, i) => { const a = (i * 137.5 * Math.PI) / 180; const r = 12 + ((i * 29) % 34); return { x: 50 + Math.cos(a) * r, y: 50 + Math.sin(a) * r, d: (i * 0.37) % 3 }; }); },
    initials() { return String((this.d.you || {}).name || '?').split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); },
    currentWarehouse() { const w = this.$page.props.currentWarehouse; return w && w.name ? w.name : this.$t('All projects'); },
    deviceText() {
      const ua = navigator.userAgent || '';
      const os = /Android/i.test(ua) ? 'Android' : /iPhone|iPad/i.test(ua) ? 'iOS' : /Windows/i.test(ua) ? 'Windows' : /Mac OS/i.test(ua) ? 'macOS' : /Linux/i.test(ua) ? 'Linux' : '';
      const br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
      return [br, os, window.innerWidth + 'x' + window.innerHeight].filter(Boolean).join(' · ');
    },
  },
  watch: {
    overallScore: { immediate: true, handler(to) { this.ease('shownScore', to || 0); } },
    smsPct(to) { this.ease('shownSms', to || 0); },
  },
  mounted() {
    startNetworkSignal();
    watchNetworkClosely(true);
    this.$nextTick(() => setTimeout(() => { this.ready = true; }, 80));
    this.loadCloud();
    this.timer = setInterval(() => { if (document.visibilityState === 'visible') this.load(); }, 30000);
    this.tickTimer = setInterval(() => { this.now = Date.now(); }, 1000);
  },
  beforeUnmount() {
    clearInterval(this.timer);
    clearInterval(this.tickTimer);
    watchNetworkClosely(false);
  },
  methods: {
    // the Delivery Logs list, already filtered to SMS and to the status (and the day) the tile counts
    smsLogs(status, today) {
      const q = { channel: 'sms', status };
      if (today) { const d = new Date(); const p = n => String(n).padStart(2, '0'); q.date_from = q.date_to = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; }
      return this.route('wims-connect.logs.index', q);
    },
    sec(k) { return (this.d.sections || {})[k] || null; },
    ratingLabel(r) { return { excellent: this.$t('Excellent'), very_good: this.$t('Very Good'), good: this.$t('Good'), fair: this.$t('Fair'), bad: this.$t('Bad'), unknown: this.$t('Not rated') }[r]; },
    ok(x) { return x && !x.error; },
    fmtGb(v) { return v == null ? '--' : (v >= 100 ? Math.round(v) : v) + ' GB'; },
    ringDash(p) { const v = Math.max(0, Math.min(100, p || 0)); return v + ' ' + (100 - v); },
    tick(p, r) { const a = Math.PI * (1 - p / 100); return { x: 90 + Math.cos(a) * r, y: 92 - Math.sin(a) * r }; },
    fmtMb(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB'; },
    fmtNum(n) { return Number(n || 0).toLocaleString(); },
    agoHours(h) { return h < 1 ? this.$t('under an hour ago') : h < 48 ? h + ' h' : Math.round(h / 24) + ' ' + this.$t('days'); },
    loadPct(l) { const c = (this.d.server && this.d.server.cores) || 4; return Math.min(100, Math.round((l / c) * 100)); },
    jump(id) { const el = document.getElementById('sh-' + id); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); },
    ease(prop, to) {
      if (typeof document !== 'undefined' && document.hidden) { this[prop] = to; return; }
      const from = this[prop];
      const t0 = performance.now();
      const step = t => { const k = Math.min(1, (t - t0) / 1400); this[prop] = Math.round(from + (to - from) * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step);
    },
    runAction(a) { if (a === 'optimize') this.openOptimize(); },
    async load(manual = false) {
      if (this.busy) return;
      this.busy = true;
      try {
        const { data } = await axios.get(this.route('system-health.data'));
        this.d = data;
        this.updatedAt = Date.now();
        if (manual) this.loadCloud();
      } catch (e) {
        // the page keeps the last reading it had
      } finally {
        this.busy = false;
      }
    },
    async loadCloud() {
      try {
        const { data } = await axios.get(this.route('system-health.cloud'));
        this.cloud = data;
      } catch (e) {
        this.cloud = { sms: { supported: true, ok: false, error: this.$t('Could not read it.') }, drive: { connected: false, provider: 'Google Drive' }, sections: { sms: { rating: 'unknown', score: null, note: this.$t('The credit could not be read.'), actions: [] }, drive: { rating: 'unknown', score: null, note: '', actions: [] } } };
      }
    },
    async runTest() {
      this.testing = true;
      try {
        const { data } = await axios.post(this.route('system-health.speed-test'));
        this.d = { ...this.d, speed: data };
        this.load();
      } catch (e) {
        // keep the previous reading
      } finally {
        this.testing = false;
      }
    },
    // ---- Send Report: always to the default address, plus up to three the person adds; the server checks every address and builds the report from real figures
    openReport() { this.rep = { open: true, stage: 'ask', extra: '', busy: false, error: '', sent: [], failed: [] }; this.$nextTick(() => this.$refs.extra && this.$refs.extra.focus()); },
    closeReport() { if (!this.rep.busy) this.rep.open = false; },
    async sendReport() {
      if (this.rep.busy) return;
      this.rep.busy = true;
      this.rep.error = '';
      const n = this.net;
      const connection = n.percent === null ? null : { percent: n.percent, level: n.level, rtt: n.rtt, jitter: n.jitter, loss: n.loss, type: n.type };
      try {
        const { data } = await axios.post(this.route('system-health.send-report'), { extra: this.rep.extra, connection }, { headers: { 'X-Quiet': '1' }, timeout: 120000 });
        this.rep.sent = data.sent || [];
        this.rep.failed = data.failed || [];
        this.rep.stage = 'done';
      } catch (e) {
        const d = e.response && e.response.data;
        this.rep.error = (d && (d.message || (d.errors && Object.values(d.errors)[0] && Object.values(d.errors)[0][0]))) || this.$t('The report could not be sent.');
        if (e.response && e.response.status === 502) { this.rep.failed = (d && d.failed) || []; this.rep.stage = 'done'; }
        if (e.response && e.response.status === 429) this.rep.error = this.$t('Too many reports were sent. Please wait a few minutes.');
      } finally {
        this.rep.busy = false;
      }
    },
    // ---- Optimize All: the same steps as Settings > Optimize (its own tasks, password and history), shown here with a progress list
    openOptimize() {
      this.opt = { open: true, stage: 'ask', password: '', error: '', busy: false, failed: false, saved: '', rating: '', url: '', steps: [
        { key: 'database', title: this.$t('Rebuild the database tables'), what: this.$t('Wasted space is rebuilt; no record is changed.'), state: 'wait', summary: '' },
        { key: 'images', title: this.$t('Compress uploaded pictures'), what: this.$t('Large pictures are saved again smaller; originals are kept for a few days.'), state: 'wait', summary: '' },
        { key: 'logs', title: this.$t('Compress server log files'), what: this.$t('Older log files are zipped; today\'s is never touched.'), state: 'wait', summary: '' },
        { key: 'technical', title: this.$t('Remove expired technical leftovers'), what: this.$t('Expired sessions, cache entries and temporary files only.'), state: 'wait', summary: '' },
      ] };
      this.$nextTick(() => this.$refs.pw && this.$refs.pw.focus());
    },
    closeOptimize() { if (this.opt.stage !== 'run') this.opt.open = false; },
    async startOptimize() {
      if (this.opt.busy || this.opt.stage !== 'ask') return;
      if (!this.opt.password) { this.opt.error = this.$t('Enter your password to continue.'); return; }
      this.opt.busy = true;
      this.opt.error = '';
      let started;
      try {
        started = (await axios.post(this.route('optimizer.run'), { tasks: this.opt.steps.map(s => s.key), password: this.opt.password }, { headers: { 'X-Quiet': '1' } })).data;
      } catch (e) {
        const er = e.response && e.response.data && e.response.data.errors;
        this.opt.error = (er && (er.password || er.tasks) && (er.password || er.tasks)[0]) || this.$t('Could not do that.');
        this.opt.busy = false;
        return;
      }
      this.opt.password = '';
      this.opt.stage = 'run';
      const guard = e => { e.preventDefault(); e.returnValue = ''; };
      window.addEventListener('beforeunload', guard);
      try {
        for (const step of this.opt.steps) {
          step.state = 'running';
          for (let i = 0; i < 8; i++) {
            const r = (await axios.post(this.route('optimizer.step', started.id), { task: step.key }, { headers: { 'X-Quiet': '1' }, timeout: 0 })).data;
            step.summary = r.summary + (r.bytes ? ' · ' + r.bytes_text : '');
            step.state = r.ok ? 'done' : 'error';
            if (!r.more) break;
            step.state = 'running';
          }
        }
        const f = (await axios.post(this.route('optimizer.finish', started.id), {}, { headers: { 'X-Quiet': '1' } })).data;
        this.opt.failed = f.status === 'failed';
        this.opt.rating = f.rating_label;
        this.opt.saved = f.saved_text;
        this.opt.url = f.url;
      } catch (e) {
        this.opt.failed = true;
        this.opt.error = (e.response && e.response.data && e.response.data.message) || this.$t('The optimisation could not be completed. What was done is kept in the history.');
        this.opt.steps.forEach(s => { if (s.state === 'running') s.state = 'error'; });
      } finally {
        window.removeEventListener('beforeunload', guard);
        this.opt.stage = 'done';
        this.opt.busy = false;
        this.load(true);
      }
    },
  },
};
</script>

<style scoped>
.sh { --ok: #16a34a; --warn: #d97706; --bad: #dc2626; --na: #94a3b8; --a: #2563eb; --b: #7c3aed; --card: #fff; --line: #e5e7eb; --ink: #0f172a; --mute: #64748b; --track: #eef2f7; }
html.dark .sh { --card: #0f172a; --line: rgba(255, 255, 255, 0.1); --ink: #e2e8f0; --mute: #94a3b8; --track: rgba(255, 255, 255, 0.08); --ok: #4ade80; --warn: #fbbf24; --bad: #f87171; --a: #60a5fa; --b: #a78bfa; }
.sh-card-t { display: flex; align-items: center; gap: 0.45rem; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--mute); margin-bottom: 0.8rem; }
.sh-na { font-size: 0.82rem; color: var(--mute); }
.sh-note { font-size: 0.76rem; color: var(--mute); margin-top: 0.5rem; }
.sh-spin { animation: sh-rot 0.9s linear infinite; } .sh-pulse-ic { animation: sh-beat 0.8s ease-in-out infinite; }
.sh-actions { display: flex; align-items: center; gap: 0.55rem; flex-wrap: nowrap; max-width: 100%; overflow-x: auto; padding: 0.35rem 0.2rem 0.85rem; scrollbar-width: none; } .sh-actions::-webkit-scrollbar { display: none; } .sh-actions > * { flex: none; } .sh-btns { display: flex; align-items: center; gap: 0.55rem; }
.sh-live { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.74rem; font-weight: 700; color: var(--mute); }
.sh-live i { width: 0.55rem; height: 0.55rem; border-radius: 999px; background: var(--ok); animation: sh-ping 2s infinite; } .sh-live.is-busy i { background: var(--warn); }

/* buttons: colour, icon chip, shadow, a shine that crosses them */
.sh-btn { position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.95rem 0.5rem 0.55rem; border-radius: 0.85rem; border: 0; font-size: 0.82rem; font-weight: 800; color: #fff; cursor: pointer; text-decoration: none; transition: transform 0.18s, box-shadow 0.18s, filter 0.18s; }
.sh-btn::after { content: ''; position: absolute; top: 0; bottom: 0; width: 45%; left: -70%; background: linear-gradient(105deg, transparent, rgba(255, 255, 255, 0.2), transparent); transform: skewX(-18deg); animation: sh-btn-shine 4.2s ease-in-out infinite; }
.sh-btn-ic { display: inline-flex; align-items: center; justify-content: center; width: 1.7rem; height: 1.7rem; border-radius: 0.55rem; background: rgba(255, 255, 255, 0.22); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35); }
.sh-btn:hover:not(:disabled) { transform: translateY(-2px); filter: saturate(1.1); } .sh-btn:active:not(:disabled) { transform: translateY(0); }
.sh-btn:disabled { opacity: 0.65; cursor: wait; }
.sh-btn.is-blue { background: linear-gradient(135deg, #3b82f6, #4f46e5); box-shadow: 0 10px 22px -10px rgba(79, 70, 229, 0.8), 0 2px 0 rgba(255, 255, 255, 0.25) inset; }
.sh-btn.is-teal { background: linear-gradient(135deg, #14b8a6, #059669); box-shadow: 0 10px 22px -10px rgba(5, 150, 105, 0.8), 0 2px 0 rgba(255, 255, 255, 0.25) inset; }
.sh-btn.is-violet { background: linear-gradient(135deg, #8b5cf6, #6d28d9); box-shadow: 0 10px 22px -10px rgba(109, 40, 217, 0.85), 0 2px 0 rgba(255, 255, 255, 0.25) inset; }
.sh-btn.is-amber { background: linear-gradient(135deg, #f59e0b, #ea580c); box-shadow: 0 10px 22px -10px rgba(234, 88, 12, 0.85), 0 2px 0 rgba(255, 255, 255, 0.25) inset; }
.sh-btn.is-plain { background: var(--card); color: var(--ink); border: 1px solid var(--line); box-shadow: 0 6px 14px -10px rgba(15, 23, 42, 0.5); padding-left: 0.95rem; } .sh-btn.is-plain::after { display: none; }
.sh-test { margin-top: 0.8rem; }

/* the whole picture */
.sh-over { display: grid; grid-template-columns: 1.4fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.sh-orb { position: relative; overflow: hidden; display: flex; gap: 1.3rem; align-items: center; padding: 1.2rem 1.3rem 2rem; border-radius: 1.2rem; border: 1px solid var(--line); box-shadow: 0 16px 36px -22px rgba(15, 23, 42, 0.5); color: var(--ink); --tone: var(--ok); }
.sh-orb.is-excellent { --tone: #059669; } .sh-orb.is-very_good { --tone: #16a34a; } .sh-orb.is-good { --tone: #2563eb; } .sh-orb.is-fair { --tone: #d97706; } .sh-orb.is-bad { --tone: #dc2626; } .sh-orb.is-unknown { --tone: #64748b; }
.sh-orb { background: linear-gradient(135deg, color-mix(in srgb, var(--tone) 11%, transparent), transparent 60%), var(--card); }
.sh-orb::after { content: ''; position: absolute; top: 0; bottom: 0; width: 35%; left: -50%; background: linear-gradient(105deg, transparent, rgba(255, 255, 255, 0.12), transparent); transform: skewX(-18deg); animation: sh-sweep 8s ease-in-out infinite; pointer-events: none; }
.sh-ring-wrap { position: relative; width: 9.8rem; flex: none; }
.sh-ring { width: 100%; display: block; overflow: visible; }
.sh-ring-bg { fill: none; stroke: var(--track); stroke-width: 11; }
.sh-ring-fg { fill: none; stroke: var(--tone); stroke-width: 11; stroke-linecap: round; transition: stroke-dasharray 1.4s cubic-bezier(0.2, 0.8, 0.2, 1); filter: drop-shadow(0 0 6px color-mix(in srgb, var(--tone) 55%, transparent)); }
.sh-ring-orbit { fill: none; stroke: var(--tone); stroke-width: 1.4; stroke-dasharray: 1.2 5; opacity: 0.55; transform-origin: 60px 60px; animation: sh-rot 22s linear infinite; }
.sh-ring-mid { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.sh-ring-mid b { font-size: 2.4rem; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; } .sh-ring-mid small { font-size: 0.7rem; color: var(--mute); font-weight: 700; }
.sh-orb-text { min-width: 0; position: relative; }
.sh-kicker { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--tone); }
.sh-orb-text h2 { font-size: 1.3rem; font-weight: 900; line-height: 1.15; } .sh-orb-text p { font-size: 0.82rem; color: var(--mute); margin: 0.2rem 0 0.7rem; }
.sh-tally { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.sh-tally span { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.2rem 0.65rem; border-radius: 999px; font-size: 0.72rem; font-weight: 800; background: var(--track); }
.sh-tally i { width: 0.5rem; height: 0.5rem; border-radius: 999px; background: var(--na); }
.sh-tally .is-excellent i { background: #059669; } .sh-tally .is-very_good i { background: #16a34a; } .sh-tally .is-good i { background: #2563eb; } .sh-tally .is-fair i { background: #d97706; } .sh-tally .is-bad i { background: #dc2626; animation: sh-ping 1.4s infinite; }
.sh-todo-card { padding: 1.1rem 1.2rem; border-radius: 1.2rem; border: 1px solid var(--line); background: var(--card); box-shadow: 0 16px 36px -22px rgba(15, 23, 42, 0.5); color: var(--ink); min-width: 0; }
.sh-todo { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.sh-todo li { display: flex; gap: 0.65rem; align-items: flex-start; padding: 0.5rem 0.65rem; border-radius: 0.8rem; background: var(--track); transition: transform 0.2s; } .sh-todo li:hover { transform: translateX(3px); }
.sh-todo-n { width: 1.5rem; height: 1.5rem; flex: none; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.74rem; font-weight: 900; color: #fff; background: var(--warn); }
.sh-todo .is-bad .sh-todo-n { background: var(--bad); } .sh-todo .is-fair .sh-todo-n { background: var(--warn); } .sh-todo .is-good .sh-todo-n { background: var(--a); }
.sh-todo small { display: block; font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--mute); }
.sh-todo-t { font-size: 0.8rem; font-weight: 700; color: var(--ink); text-align: left; line-height: 1.3; text-decoration: none; } .sh-todo-t:not(.is-plain):hover { color: var(--a); text-decoration: underline; }
.sh-happy { display: flex; align-items: center; gap: 0.6rem; font-size: 0.86rem; font-weight: 700; color: var(--ok); }

/* tiles that jump to each part */
.sh-pills { display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.3rem 0.1rem 0.9rem; scrollbar-width: thin; }
.sh-pill { flex: none; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0.8rem 0.3rem 0.35rem; border-radius: 999px; border: 1px solid var(--line); background: var(--card); color: var(--ink); text-decoration: none; font-size: 0.76rem; font-weight: 800; box-shadow: 0 8px 18px -14px rgba(15, 23, 42, 0.55); transition: transform 0.2s, box-shadow 0.2s; }
.sh-pill:hover { transform: translateY(-2px); box-shadow: 0 12px 22px -12px rgba(15, 23, 42, 0.6); } .sh-pill b { font-size: 0.68rem; padding: 0.1rem 0.5rem; border-radius: 999px; }
.sh-pill-ic { width: 1.9rem !important; height: 1.9rem !important; border-radius: 999px !important; }
.sh-pill.is-excellent b { background: rgba(5, 150, 105, 0.14); color: #059669; } .sh-pill.is-very_good b { background: rgba(22, 163, 74, 0.14); color: #16a34a; } .sh-pill.is-good b { background: rgba(37, 99, 235, 0.14); color: #2563eb; } .sh-pill.is-fair b { background: rgba(217, 119, 6, 0.15); color: #d97706; } .sh-pill.is-bad b { background: rgba(220, 38, 38, 0.15); color: #dc2626; } .sh-pill.is-unknown b { background: var(--track); color: var(--mute); }

.sh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(21rem, 1fr)); gap: 1rem; align-items: stretch; }

/* connection */
.sh-gauge-wrap { position: relative; max-width: 15rem; margin: 0.2rem auto 0; }
.sh-radiate { position: absolute; left: 50%; bottom: 0; width: 1px; height: 1px; pointer-events: none; } .sh-radiate i { position: absolute; left: -3.5rem; top: -3.5rem; width: 7rem; height: 7rem; border-radius: 999px; border: 2px solid var(--c); opacity: 0; animation: sh-radiate 3.2s ease-out infinite; } .sh-radiate i:nth-child(2) { animation-delay: 1.05s; } .sh-radiate i:nth-child(3) { animation-delay: 2.1s; }
.sh-gauge { position: relative; width: 100%; display: block; }
.sh-arc-bg { fill: none; stroke: var(--track); stroke-width: 12; stroke-linecap: round; }
.sh-arc-fg { fill: none; stroke: var(--ok); stroke-width: 12; stroke-linecap: round; transition: stroke-dasharray 0.8s cubic-bezier(0.2, 0.8, 0.2, 1), stroke 0.4s; }
.sh-tick { stroke: var(--mute); stroke-width: 1.4; opacity: 0.5; }
.sh-gauge-mid { position: absolute; left: 0; right: 0; bottom: 0; text-align: center; display: flex; flex-direction: column; align-items: center; }
.sh-gauge-mid b { font-size: 2rem; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; } .sh-gauge-mid em { font-size: 1rem; font-style: normal; } .sh-gauge-mid small { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
.sh-spark { width: 100%; height: 2.6rem; margin-top: 0.6rem; } .sh-spark-line { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; } .sh-spark-miss { fill: var(--bad); }
.sh-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.5rem; margin: 0.6rem 0 0; } .sh-facts div { min-width: 0; }
.sh-facts dt { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: var(--mute); } .sh-facts dd { font-size: 0.86rem; font-weight: 800; margin: 0; overflow-wrap: anywhere; } .sh-facts dd small { font-weight: 600; color: var(--mute); }
.sh-facts-wide { grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.8rem; }

/* dials, bars, rings */
.sh-dials { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; }
.sh-dial { text-align: center; position: relative; } .sh-dial svg { width: 100%; display: block; } .sh-dial .sh-arc-bg, .sh-dial .sh-arc-fg { stroke-width: 9; }
.sh-dial.is-ok .sh-arc-fg { stroke: var(--ok); } .sh-dial.is-warn .sh-arc-fg { stroke: var(--warn); } .sh-dial.is-bad .sh-arc-fg { stroke: var(--bad); }
.sh-dial b { display: block; font-size: 1.15rem; font-weight: 900; margin-top: -1.1rem; font-variant-numeric: tabular-nums; } .sh-dial b em { font-style: normal; font-size: 0.7rem; color: var(--mute); margin-left: 2px; }
.sh-dial span { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--mute); }
.sh-bar-row { display: grid; grid-template-columns: minmax(5rem, 9rem) 1fr auto; align-items: center; gap: 0.6rem; font-size: 0.78rem; margin-top: 0.5rem; }
.sh-bar-row span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--mute); font-weight: 700; } .sh-bar-row b { font-variant-numeric: tabular-nums; white-space: nowrap; } .sh-tight { margin-top: 0.3rem; }
.sh-bar { height: 0.6rem; border-radius: 999px; background: var(--track); overflow: hidden; position: relative; }
.sh-bar i { display: block; height: 100%; border-radius: 999px; background: var(--a); transition: width 1s cubic-bezier(0.2, 0.8, 0.2, 1); position: relative; overflow: hidden; }
.sh-bar i::after { content: ''; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 30%, rgba(255, 255, 255, 0.2) 50%, transparent 70%); transform: translateX(-100%); animation: sh-bar-shine 3.4s ease-in-out infinite; }
.sh-bar i.is-ok { background: var(--ok); } .sh-bar i.is-warn { background: var(--warn); } .sh-bar i.is-bad { background: var(--bad); } .sh-bar i.is-a { background: var(--a); } .sh-bar i.is-b { background: var(--b); }
.sh-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.7rem; } .sh-chips span { padding: 0.18rem 0.6rem; border-radius: 999px; background: var(--track); font-size: 0.7rem; font-weight: 700; } .sh-chips span.is-ok { color: var(--ok); } .sh-chips span.is-warn { color: var(--warn); }
.sh-donut-row { display: flex; align-items: center; gap: 1rem; margin-bottom: 0.4rem; }
.sh-donut { position: relative; width: 7.6rem; flex: none; } .sh-donut svg { width: 100%; display: block; overflow: visible; }
.sh-seg { fill: none; stroke-width: 13; transition: stroke-dasharray 1.1s cubic-bezier(0.2, 0.8, 0.2, 1); stroke: var(--a); } .sh-seg.is-a { stroke: var(--a); } .sh-seg.is-b { stroke: var(--b); } .sh-seg.is-ok { stroke: var(--ok); } .sh-seg.is-warn { stroke: var(--warn); } .sh-seg.is-bad { stroke: var(--bad); }
.sh-donut .sh-ring-bg { stroke-width: 13; }
.sh-orbit-dots { fill: none; stroke: var(--mute); stroke-width: 1.4; stroke-dasharray: 1 5; opacity: 0.5; transform-origin: 60px 60px; animation: sh-rot 18s linear infinite; } .sh-orbit-dots.is-slow { animation-duration: 40s; }
.sh-donut-mid { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; } .sh-donut-mid b { font-size: 1.05rem; font-weight: 900; } .sh-donut-mid small { font-size: 0.64rem; color: var(--mute); font-weight: 700; }
.sh-legend { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; font-size: 0.78rem; min-width: 0; } .sh-legend li { display: flex; justify-content: space-between; gap: 0.6rem; color: var(--mute); font-weight: 700; } .sh-legend b { color: var(--ink); }
.sh-legend li.is-a::before, .sh-legend li.is-b::before { content: ''; width: 0.6rem; height: 0.6rem; border-radius: 3px; margin-right: 0.4rem; align-self: center; background: var(--a); } .sh-legend li.is-b::before { background: var(--b); } .sh-legend li.is-a, .sh-legend li.is-b { justify-content: flex-start; } .sh-legend li.is-a b, .sh-legend li.is-b b { margin-left: auto; }

/* the SMS battery */
.sh-batt-wrap { display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap; }
.sh-batt { display: flex; align-items: center; flex: 1 1 11rem; min-width: 10rem; --bc: var(--ok); } .sh-batt.is-warn { --bc: var(--warn); } .sh-batt.is-bad { --bc: var(--bad); } .sh-batt.is-na { --bc: var(--na); }
.sh-batt-body { position: relative; flex: 1; height: 4.2rem; border-radius: 1rem; border: 3px solid color-mix(in srgb, var(--bc) 70%, var(--line)); background: var(--track); overflow: hidden; box-shadow: inset 0 3px 8px rgba(15, 23, 42, 0.12), 0 8px 20px -12px color-mix(in srgb, var(--bc) 70%, transparent); }
.sh-batt-fill { position: absolute; left: 0; top: 0; bottom: 0; background: linear-gradient(180deg, color-mix(in srgb, var(--bc) 82%, #fff), var(--bc)); transition: width 1.8s cubic-bezier(0.2, 0.8, 0.2, 1); overflow: hidden; }
.sh-batt-stripes { position: absolute; inset: 0; background: repeating-linear-gradient(115deg, rgba(255, 255, 255, 0.14) 0 10px, transparent 10px 22px); background-size: 44px 100%; animation: sh-stripes 1.2s linear infinite; }
.sh-batt-gloss { position: absolute; left: 0; right: 0; top: 0; height: 45%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.2), transparent); border-radius: 0 0 40% 40%; }
.sh-batt-cut { position: absolute; top: 0; bottom: 0; width: 2px; background: rgba(255, 255, 255, 0.55); }
.sh-batt-num { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem; color: #fff; text-shadow: 0 1px 4px rgba(0, 0, 0, 0.45); }
.sh-batt-num b { font-size: 1.8rem; font-weight: 900; font-variant-numeric: tabular-nums; } .sh-batt-num em { font-style: normal; font-size: 1rem; }
.sh-bolt { animation: sh-bolt 1.6s ease-in-out infinite; }
.sh-batt-cap { width: 0.55rem; height: 1.7rem; margin-left: 2px; border-radius: 0 0.4rem 0.4rem 0; background: color-mix(in srgb, var(--bc) 70%, var(--line)); }
.sh-batt-info { min-width: 7rem; } .sh-big { display: block; font-size: 1.7rem; font-weight: 900; line-height: 1.05; font-variant-numeric: tabular-nums; } .sh-big.is-na { color: var(--na); } .sh-batt-info small { display: block; font-size: 0.76rem; color: var(--mute); font-weight: 700; } .sh-fine { font-size: 0.68rem !important; font-weight: 600 !important; }
.sh-split { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem; margin-top: 0.8rem; } .sh-split > div, .sh-split > a { display: flex; align-items: center; gap: 0.6rem; min-width: 0; padding: 0.55rem 0.7rem; border-radius: 0.8rem; background: var(--track); color: inherit; text-decoration: none; }
.sh-split > a { transition: transform 0.2s ease, box-shadow 0.2s ease; } .sh-split > a:hover { transform: translateY(-2px); box-shadow: 0 10px 18px -12px rgba(15, 23, 42, 0.5); } .sh-split > a:focus-visible { outline: 2px solid var(--a); outline-offset: 2px; }
.sh-st-ic { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 0.65rem; color: var(--a); background: color-mix(in srgb, var(--a) 14%, transparent); } .sh-split .is-bad .sh-st-ic { color: var(--bad); background: color-mix(in srgb, var(--bad) 14%, transparent); } .sh-split .is-warn .sh-st-ic { color: var(--warn); background: color-mix(in srgb, var(--warn) 16%, transparent); }
.sh-st-tx { min-width: 0; flex: 1; } .sh-st-go { flex: none; color: var(--mute); opacity: 0.7; }
.sh-split b { display: block; font-size: 1.15rem; font-weight: 900; font-variant-numeric: tabular-nums; } .sh-split small { font-size: 0.68rem; color: var(--mute); font-weight: 700; } .sh-split .is-bad b { color: var(--bad); } .sh-split .is-warn b { color: var(--warn); }
.sh-legend.sh-legend-icons li { justify-content: flex-start; align-items: center; gap: 0.4rem; } .sh-legend.sh-legend-icons li::before { display: none; } .sh-legend-icons b { margin-left: auto; padding-left: 0.4rem; } .sh-legend-icons svg { flex: none; }
.sh-legend-icons .is-a svg { color: var(--a); } .sh-legend-icons .is-warn svg { color: var(--warn); } .sh-legend-icons .is-b svg { color: var(--b); } .sh-legend-icons .is-free svg { color: var(--mute); }
.sh-drive-state { display: flex; gap: 0.9rem; align-items: flex-start; padding: 0.9rem; border-radius: 1rem; background: var(--track); border: 1.5px solid var(--line); } .sh-drive-state b { display: block; font-size: 0.95rem; font-weight: 900; } .sh-drive-state p { margin: 0.25rem 0 0.7rem; font-size: 0.78rem; color: var(--mute); font-weight: 600; overflow-wrap: anywhere; }
.sh-ds-ic { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 1rem; color: var(--na); background: color-mix(in srgb, var(--na) 16%, transparent); } .sh-drive-state.is-rejected { border-color: color-mix(in srgb, var(--bad) 45%, var(--line)); } .sh-drive-state.is-rejected .sh-ds-ic { color: var(--bad); background: color-mix(in srgb, var(--bad) 14%, transparent); } .sh-drive-state.is-unreachable .sh-ds-ic, .sh-drive-state.is-unconfigured .sh-ds-ic { color: var(--warn); background: color-mix(in srgb, var(--warn) 16%, transparent); }
.sh-ds-btn { display: inline-flex; text-decoration: none; }
.sh-week { margin-top: 0.8rem; padding: 0.65rem 0.75rem 0.5rem; border-radius: 0.9rem; background: var(--track); } .sh-week-h { display: flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; font-weight: 800; color: var(--mute); } .sh-week-key { margin-left: auto; display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.66rem; } .sh-week-key i { width: 0.55rem; height: 0.55rem; border-radius: 3px; margin-left: 0.4rem; } .sh-week-key i.is-ok, .sh-week-bar i.is-ok { background: var(--ok); } .sh-week-key i.is-bad, .sh-week-bar i.is-bad { background: var(--bad); }
.sh-week-cols { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.35rem; margin-top: 0.5rem; } .sh-week-col { display: flex; flex-direction: column; align-items: center; gap: 0.15rem; min-width: 0; } .sh-week-n { font-size: 0.66rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--ink); } .sh-week-col small { font-size: 0.64rem; font-weight: 700; color: var(--mute); }
.sh-week-bar { width: 100%; max-width: 1.6rem; height: 3.6rem; display: flex; flex-direction: column; justify-content: flex-end; border-radius: 0.4rem; overflow: hidden; background: color-mix(in srgb, var(--mute) 12%, transparent); } .sh-week-bar i { display: block; width: 100%; min-height: 0; transition: height 1.1s cubic-bezier(0.2, 0.8, 0.2, 1); }
.sh-battery { position: relative; height: 2.8rem; border-radius: 0.8rem; border: 2px solid var(--line); background: var(--track); overflow: hidden; --bc: var(--a); } .sh-battery.is-warn { --bc: var(--warn); } .sh-battery.is-bad { --bc: var(--bad); }
.sh-battery-fill { position: relative; height: 100%; background: linear-gradient(180deg, color-mix(in srgb, var(--bc) 80%, #fff), var(--bc)); transition: width 1.6s cubic-bezier(0.2, 0.8, 0.2, 1); overflow: hidden; }
.sh-battery span { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 0.9rem; color: #fff; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5); }
.sh-ghost { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.9rem; padding: 0.55rem 0.7rem; border-radius: 0.8rem; border: 1.5px dashed var(--line); font-size: 0.76rem; font-weight: 700; color: var(--mute); }

/* backups, radar */
.sh-ecg { width: 100%; height: 3rem; } .sh-ecg polyline { fill: none; stroke: var(--ok); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 520; stroke-dashoffset: 520; animation: sh-ecg 2.4s linear infinite; filter: drop-shadow(0 0 3px color-mix(in srgb, var(--ok) 60%, transparent)); } .sh-ecg.is-warn polyline { stroke: var(--warn); animation-duration: 4s; } .sh-ecg.is-bad polyline { stroke: var(--bad); animation-duration: 7s; } .sh-ecg.is-na polyline { stroke: var(--na); animation: none; stroke-dashoffset: 0; stroke-dasharray: 4 6; filter: none; }
.sh-beat { font-size: 0.78rem; color: var(--mute); margin: 0.2rem 0 0; }
.sh-radar-row { display: flex; align-items: center; gap: 1rem; }
.sh-radar { position: relative; width: 7.4rem; height: 7.4rem; flex: none; border-radius: 999px; border: 2px solid color-mix(in srgb, var(--ok) 55%, var(--line)); background: radial-gradient(circle, transparent 28%, color-mix(in srgb, var(--ok) 20%, transparent) 29%, transparent 31%, transparent 58%, color-mix(in srgb, var(--ok) 20%, transparent) 59%, transparent 61%), color-mix(in srgb, var(--ok) 6%, transparent); overflow: hidden; }
.sh-radar-sweep { position: absolute; inset: 0; border-radius: 999px; background: conic-gradient(from 0deg, color-mix(in srgb, var(--ok) 55%, transparent), transparent 28%); animation: sh-rot 3.6s linear infinite; }
.sh-blip { position: absolute; width: 0.5rem; height: 0.5rem; margin: -0.25rem; border-radius: 999px; background: var(--ok); animation: sh-blip 3.6s ease-in-out infinite; }
.sh-online b { display: block; font-size: 2rem; font-weight: 900; line-height: 1; } .sh-online small { display: block; font-size: 0.74rem; color: var(--mute); font-weight: 700; }
.sh-vs { display: flex; height: 0.6rem; border-radius: 999px; overflow: hidden; margin-top: 0.8rem; gap: 2px; background: var(--track); } .sh-vs i { display: block; } .sh-vs .is-ok { background: var(--ok); } .sh-vs .is-bad { background: var(--bad); }
.sh-me { display: grid; gap: 0.9rem; } .sh-me-id { display: flex; align-items: center; gap: 0.7rem; } .sh-me-av { width: 2.8rem; height: 2.8rem; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; color: #fff; background: linear-gradient(145deg, #3b82f6, #7c3aed); box-shadow: 0 8px 18px -8px rgba(124, 58, 237, 0.8); } .sh-me-id b { display: block; font-size: 1rem; } .sh-me-id small { color: var(--mute); font-weight: 700; font-size: 0.76rem; }
.sh-skel { display: block; height: 1rem; border-radius: 0.5rem; margin-top: 0.6rem; background: linear-gradient(90deg, var(--track), rgba(148, 163, 184, 0.3), var(--track)); background-size: 200% 100%; animation: sh-skel 1.2s linear infinite; } .sh-skel.w1 { width: 60%; } .sh-skel.w2 { width: 40%; } .sh-skel.w3 { width: 100%; height: 2.6rem; }

/* Optimize All */
.sh-modal { position: fixed; inset: 0; z-index: 999990; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.sh-modal-bg { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); }
.sh-modal-card { position: relative; width: 100%; max-width: 30rem; max-height: 100%; overflow: auto; padding: 1.3rem; border-radius: 1.1rem; background: var(--card); color: var(--ink); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); animation: sh-pop 0.25s cubic-bezier(0.34, 1.4, 0.64, 1) both; }
.sh-modal-head { display: flex; gap: 0.8rem; align-items: center; margin-bottom: 0.9rem; } .sh-modal-head h3 { font-size: 1.05rem; font-weight: 900; } .sh-modal-head p { font-size: 0.78rem; color: var(--mute); }
.sh-modal-ic.is-violet { background: linear-gradient(135deg, #8b5cf6, #6d28d9); box-shadow: 0 10px 20px -10px rgba(109, 40, 217, 0.9); }
.sh-to { margin-bottom: 0.8rem; } .sh-to-chip { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.4rem 0.8rem; border-radius: 999px; font-size: 0.82rem; font-weight: 800; background: var(--track); } .sh-to-chip small { font-size: 0.66rem; font-weight: 700; color: var(--mute); }
.sh-incl { list-style: none; margin: 0.8rem 0 0.4rem; padding: 0; display: grid; gap: 0.3rem; font-size: 0.78rem; font-weight: 600; } .sh-incl li { display: flex; align-items: center; gap: 0.5rem; } .sh-incl svg { color: var(--ok); flex: none; }
.sh-sent { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; } .sh-sent li { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.8rem; border-radius: 0.8rem; font-weight: 700; font-size: 0.85rem; animation: sh-pop 0.35s both; } .sh-sent .is-ok { background: color-mix(in srgb, var(--ok) 13%, transparent); color: var(--ok); } .sh-sent .is-bad { background: color-mix(in srgb, var(--bad) 13%, transparent); color: var(--bad); } .sh-sent small { font-weight: 600; opacity: 0.8; }
.sh-fly { animation: sh-fly 0.9s ease-in-out infinite; }
.sh-modal-ic { width: 2.6rem; height: 2.6rem; border-radius: 0.85rem; display: inline-flex; align-items: center; justify-content: center; color: #fff; flex: none; background: linear-gradient(135deg, #f59e0b, #ea580c); box-shadow: 0 10px 20px -10px rgba(234, 88, 12, 0.9); }
.sh-steps { list-style: none; margin: 0 0 1rem; padding: 0; display: grid; gap: 0.5rem; }
.sh-steps li { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.55rem 0.7rem; border-radius: 0.8rem; background: var(--track); } .sh-steps b { display: block; font-size: 0.82rem; } .sh-steps small { font-size: 0.72rem; color: var(--mute); }
.sh-step-dot { width: 1.25rem; height: 1.25rem; flex: none; margin-top: 2px; border-radius: 999px; border: 2px solid var(--na); display: inline-flex; align-items: center; justify-content: center; color: #fff; }
.sh-steps .is-running .sh-step-dot { border-color: var(--warn); border-top-color: transparent; animation: sh-rot 0.8s linear infinite; } .sh-steps .is-done .sh-step-dot { background: var(--ok); border-color: var(--ok); } .sh-steps .is-error .sh-step-dot { background: var(--bad); border-color: var(--bad); }
.sh-pwl { display: block; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: var(--mute); margin-bottom: 0.3rem; }
.sh-pw { width: 100%; padding: 0.6rem 0.8rem; border-radius: 0.7rem; border: 1.5px solid var(--line); background: var(--card); color: var(--ink); } .sh-pw:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.18); }
.sh-err { font-size: 0.78rem; font-weight: 700; color: var(--bad); margin-top: 0.4rem; }
.sh-modal-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; flex-wrap: wrap; }
.sh-result { text-align: center; padding: 0.6rem; border-radius: 0.9rem; background: color-mix(in srgb, var(--ok) 12%, transparent); } .sh-result b { display: block; font-size: 1.6rem; font-weight: 900; color: var(--ok); } .sh-result small { font-size: 0.78rem; color: var(--mute); font-weight: 700; }

@keyframes sh-ping { 0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.45); } 70% { box-shadow: 0 0 0 7px rgba(22, 163, 74, 0); } 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); } }
@keyframes sh-rot { to { transform: rotate(360deg); } }
@keyframes sh-beat { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
@keyframes sh-btn-shine { 0%, 60% { left: -70%; } 100% { left: 130%; } }
@keyframes sh-sweep { 0%, 55% { left: -50%; } 100% { left: 130%; } }
@keyframes sh-radiate { 0% { transform: scale(0.35); opacity: 0.55; } 100% { transform: scale(1.6); opacity: 0; } }
@keyframes sh-bar-shine { 0%, 40% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
@keyframes sh-stripes { to { background-position: 44px 0; } }
@keyframes sh-bolt { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.85); } }
@keyframes sh-ecg { 0% { stroke-dashoffset: 520; } 55%, 100% { stroke-dashoffset: 0; } }
@keyframes sh-blip { 0%, 100% { opacity: 0.25; transform: scale(0.7); } 50% { opacity: 1; transform: scale(1.3); box-shadow: 0 0 8px var(--ok); } }
@keyframes sh-skel { to { background-position: -200% 0; } }
@keyframes sh-fly { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(4px, -4px); } }
@keyframes sh-pop { from { opacity: 0; transform: translateY(10px) scale(0.96); } to { opacity: 1; transform: none; } }
@media (max-width: 960px) { .sh-over { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .sh-actions { flex-wrap: wrap; overflow: visible; width: 100%; gap: 0.35rem; padding: 0.2rem 0 0.7rem; } .sh-live { flex: 1 1 100%; font-size: 0.68rem; } .sh-btns { flex: 1 1 100%; gap: 0.4rem; width: 100%; } .sh-btns .sh-btn { flex: 1 1 0; min-width: 0; justify-content: center; padding: 0.32rem 0.4rem 0.32rem 0.32rem; font-size: 0.72rem; gap: 0.3rem; white-space: nowrap; } .sh-btns .sh-btn:first-child { flex: 0 0 auto; padding-right: 0.32rem; } .sh-btns .sh-btn-ic { width: 1.4rem; height: 1.4rem; flex: none; } .is-hide-sm { display: none; } .sh-lbl { overflow: hidden; text-overflow: ellipsis; } .sh-orb { flex-direction: column; text-align: center; } .sh-tally { justify-content: center; } .sh-facts { grid-template-columns: repeat(2, 1fr); } .sh-grid { grid-template-columns: 1fr; } .sh-bar-row { grid-template-columns: 5.5rem 1fr auto; } .sh-ring-wrap { width: 8.4rem; } }
@media (prefers-reduced-motion: reduce) { .sh-live i, .sh-spin, .sh-pulse-ic, .sh-skel, .sh-btn::after, .sh-orb::after, .sh-ring-orbit, .sh-orbit-dots, .sh-radiate i, .sh-bar i::after, .sh-batt-stripes, .sh-bolt, .sh-radar-sweep, .sh-blip, .sh-tally i, .sh-modal-card { animation: none !important; } .sh-ring-fg, .sh-arc-fg, .sh-seg, .sh-bar i, .sh-batt-fill, .sh-battery-fill, .sh-btn { transition: none; } .sh-ecg polyline { stroke-dashoffset: 0; } }
</style>
