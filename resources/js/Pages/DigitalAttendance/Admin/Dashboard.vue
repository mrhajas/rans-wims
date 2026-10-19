<template>
  <admin-layout :title="$t('Digital Attendance Dashboard')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clock" :title="$t('Digital Attendance Dashboard')" :subtitle="$t('Who is in, who is late, and how the week is trending.')">
        <span class="lg-chip lg-chip-red"><span class="da-live-dot"></span>{{ $t('LIVE') }}</span>
        <Link :href="route('digital-attendance-admin.records')" class="lg-btn lg-btn-soft"><icons name="table" class="w-4 h-4" />{{ $t('Records') }}</Link>
        <Link :href="route('digital-attendance-reports.hub')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Reports') }}</Link>
        <Link v-if="$can('manage-digital-attendance-settings')" :href="route('digital-attendance-settings.edit')" class="lg-btn lg-btn-soft"><icons name="cog" class="w-4 h-4" />{{ $t('Settings') }}</Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="goTile" />

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg-card lg:col-span-2">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Attendance Trend') }}</div>
              <div class="lg-card-sub">{{ $t('last 14 days') }}</div>
            </div>
          </div>
          <vue-highcharts type="chart" :options="trendChart" />
        </div>
        <div class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Status Distribution') }}</div>
              <div class="lg-card-sub">{{ $t('last 30 days') }}</div>
            </div>
          </div>
          <vue-highcharts type="chart" :options="statusChart" />
        </div>
      </div>

      <div class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="lock-open" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Currently Clocked In') }}</div>
          </div>
          <span class="lg-chip lg-chip-red ml-auto"><span class="da-live-dot"></span>{{ liveList.length }}</span>
        </div>
        <div v-if="!liveList.length" class="lg-empty">{{ $t('No one is currently clocked in.') }}</div>
        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          <div v-for="p in liveList" :key="p.id" class="da-presence-card da-presence-card-live">
            <div class="flex items-center gap-2">
              <span class="da-presence-dot da-presence-dot-pulse"></span>
              <span class="lg-main text-sm truncate">{{ p.user?.name }}</span>
              <span v-if="p.sessions_count > 1" class="lg-chip lg-chip-cyan lg-tip ml-auto" :data-tip="$t('Multiple sessions today')">&times;{{ p.sessions_count }}</span>
            </div>
            <div class="da-presence-line mt-1">
              <icons name="clock" class="w-3 h-3 flex-none opacity-60" />{{ p.clock_in_label }} &middot; {{ p.worked_label }}
            </div>
            <div v-if="p.location && p.location.label" class="da-presence-line mt-1">
              <icons name="map-pin" class="w-3 h-3 flex-none opacity-60" /><span class="truncate">{{ p.location.label }}</span>
            </div>
            <div v-if="p.ip" class="da-presence-line lg-mono mt-1">
              <icons name="wifi" class="w-3 h-3 flex-none opacity-50" />{{ p.ip }}
            </div>
            <div class="flex items-center flex-wrap gap-1.5 mt-2">
              <span v-if="p.is_late" class="lg-chip lg-chip-amber">{{ $t('Late') }}</span>
              <span class="lg-chip" :class="presenceBadgeClass(p.presence)">{{ presenceLabel(p.presence) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="wifi" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Currently Online') }}</div>
            <div class="lg-card-sub">{{ $t('any WIMS session, not just clocked in') }}</div>
          </div>
          <span class="lg-chip lg-chip-red ml-auto"><span class="da-live-dot"></span>{{ onlineList.length }}</span>
        </div>
        <div v-if="!onlineList.length" class="lg-empty">{{ $t('No one else is online right now.') }}</div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div
            v-for="u in onlineList"
            :key="u.id"
            class="da-online-card"
            :class="{ 'da-online-card-open': buzzOpenFor === u.id }"
            @click="toggleBuzz(u.id)"
          >
            <div class="flex items-center gap-2">
              <span class="da-presence-dot" :class="{ 'da-presence-dot-dim': u.presence !== 'ACTIVE_NOW' }"></span>
              <span class="lg-main text-sm truncate">{{ u.name }}</span>
              <icons v-if="buzzSentFlash === u.id" name="tick" class="w-3.5 h-3.5 text-emerald-500 ml-auto flex-none" />
              <icons v-else name="bell" class="w-3.5 h-3.5 text-gray-300 ml-auto flex-none da-buzz-bell" />
            </div>
            <div class="lg-subtext mt-1">{{ u.last_seen_label }}</div>

            <div v-if="buzzOpenFor === u.id" class="da-buzz-composer" @click.stop>
              <div class="flex flex-wrap gap-1 mb-2">
                <button v-for="e in quickEmojis" :key="e" type="button" class="da-emoji-btn" @click="buzzText += e">{{ e }}</button>
              </div>
              <div class="flex gap-1.5">
                <input
                  v-model="buzzText"
                  type="text"
                  maxlength="200"
                  :placeholder="$t('Say something…')"
                  class="da-buzz-input"
                  @keyup.enter="sendBuzz(u)"
                  @click.stop
                />
                <button type="button" class="da-buzz-send" :disabled="!buzzText.trim() || buzzSending" @click.stop="sendBuzz(u)">
                  <icons name="send" class="w-[1.15rem] h-[1.15rem]" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import VueHighcharts from 'vue3-highcharts';

export default {
  components: { AdminLayout, Link, PageHeader, StatTiles, VueHighcharts },
  props: { stats: Object, trend: Array, statusDistribution: Object },

  data() {
    return {
      liveList: [],
      onlineList: [],
      pollTimer: null,
      onlinePollTimer: null,
      refreshTimer: null,
      buzzOpenFor: null,
      buzzText: '',
      buzzSending: false,
      buzzSentFlash: null,
      quickEmojis: ['👋', '☕', '⏰', '✅', '🎉'],
    };
  },

  computed: {
    statTiles() {
      const s = this.stats || {};
      const records = 'digital-attendance-admin.records';
      return [
        { icon: 'users', tone: 'navy', label: this.$t('Total Staff'), value: s.total_staff, active: false, href: this.route(records) },
        { icon: 'lock-open', tone: 'emerald', label: this.$t('Clocked In'), value: s.clocked_in, active: false, href: this.route(records, { status: 'CLOCKED_IN' }) },
        { icon: 'lock-closed', tone: 'slate', label: this.$t('Clocked Out'), value: s.clocked_out, active: false, href: this.route(records, { status: 'CLOCKED_OUT' }) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: s.late, active: false, href: this.route(records) },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Missing Clock-Out'), value: s.missing_clock_out, active: false, href: this.route(records, { status: 'MISSING_CLOCK_OUT' }) },
        { icon: 'chart-bar', tone: 'sky', label: this.$t('Total Hours Today'), value: s.total_hours_today_label, active: false, href: this.route('digital-attendance-reports.hub') },
      ];
    },
    trendChart() {
      return {
        chart: { type: 'column', height: 260 },
        title: { text: null },
        credits: { enabled: false },
        xAxis: { categories: this.trend.map(t => t.date.slice(5)) },
        yAxis: [
          { title: { text: this.$t('Days') } },
          { title: { text: this.$t('Hours') }, opposite: true },
        ],
        series: [
          { name: this.$t('Attendance Days'), data: this.trend.map(t => t.count), color: '#34d399' },
          { name: this.$t('Total Hours'), type: 'line', yAxis: 1, data: this.trend.map(t => t.hours), color: '#0ea5e9' },
        ],
        legend: { enabled: true },
      };
    },
    statusChart() {
      const map = { CLOCKED_IN: this.$t('Clocked In'), CLOCKED_OUT: this.$t('Clocked Out'), MISSING_CLOCK_OUT: this.$t('Missing Clock-Out'), VOIDED: this.$t('Voided') };
      const colors = { CLOCKED_IN: '#34d399', CLOCKED_OUT: '#94a3b8', MISSING_CLOCK_OUT: '#f87171', VOIDED: '#cbd5e1' };
      const data = Object.entries(this.statusDistribution).map(([key, value]) => ({ name: map[key] || key, y: value, color: colors[key] }));

      return {
        chart: { type: 'pie', height: 260 },
        title: { text: null },
        credits: { enabled: false },
        series: [{ name: this.$t('Records'), data }],
      };
    },
  },

  mounted() {
    this.fetchLive();
    this.fetchOnline();
    this.pollTimer = setInterval(this.fetchLive, 20000);
    this.onlinePollTimer = setInterval(this.fetchOnline, 20000);
    this.refreshTimer = setInterval(() => this.$inertia.reload({ only: ['stats', 'trend', 'statusDistribution'], preserveScroll: true }), 30000);
  },
  beforeUnmount() {
    if (this.pollTimer) clearInterval(this.pollTimer);
    if (this.onlinePollTimer) clearInterval(this.onlinePollTimer);
    if (this.refreshTimer) clearInterval(this.refreshTimer);
  },

  methods: {
    goTile(item) {
      if (item.href) this.$inertia.visit(item.href);
    },
    fetchLive() {
      axios.get(this.route('digital-attendance-admin.currently-clocked-in')).then(res => {
        this.liveList = res.data.records;
      });
    },
    fetchOnline() {
      axios.get(this.route('digital-attendance-admin.currently-online')).then(res => {
        this.onlineList = res.data.users;
      });
    },
    presenceLabel(p) {
      if (p === 'ACTIVE_NOW') return this.$t('Active Now');
      if (p === 'RECENTLY_ACTIVE') return this.$t('Recently Active');
      return this.$t('Not Active');
    },
    presenceBadgeClass(p) {
      if (p === 'ACTIVE_NOW') return 'lg-chip-green';
      if (p === 'RECENTLY_ACTIVE') return 'lg-chip-blue';
      return 'lg-chip-slate';
    },
    toggleBuzz(id) {
      this.buzzOpenFor = this.buzzOpenFor === id ? null : id;
      this.buzzText = '';
    },
    sendBuzz(u) {
      const message = this.buzzText.trim();
      if (!message || this.buzzSending) return;
      this.buzzSending = true;
      axios
        .post(this.route('digital-attendance-admin.buzz'), { user_id: u.id, message })
        .then(() => {
          this.buzzOpenFor = null;
          this.buzzText = '';
          this.buzzSentFlash = u.id;
          setTimeout(() => {
            if (this.buzzSentFlash === u.id) this.buzzSentFlash = null;
          }, 2500);
        })
        .finally(() => {
          this.buzzSending = false;
        });
    },
  },
};
</script>

<style scoped>
.da-live-dot { display: inline-block; width: 0.45rem; height: 0.45rem; border-radius: 9999px; background: #dc2626; animation: da-blink 1.6s ease-in-out infinite; }
@keyframes da-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
@media (prefers-reduced-motion: reduce) { .da-live-dot { animation: none; } }

.da-presence-card, .da-online-card { min-width: 0; padding: 0.8rem 0.95rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease; }
.da-presence-card-live:hover { transform: translateY(-2px); box-shadow: 0 10px 22px -12px rgba(16, 185, 129, 0.4); border-color: #a7f3d0; }
.da-presence-line { display: flex; align-items: center; gap: 0.35rem; min-width: 0; font-size: 0.74rem; color: var(--lg-mute); }
.da-presence-dot { flex: none; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #10b981; }
.da-presence-dot-pulse { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55); animation: da-dot-pulse 1.8s ease-out infinite; }
@keyframes da-dot-pulse {
  0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55); }
  70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
@media (prefers-reduced-motion: reduce) { .da-presence-dot-pulse { animation: none; } }
.da-presence-dot-dim { background: #cbd5e1; }

.da-online-card { position: relative; cursor: pointer; }
.da-online-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -12px rgba(15, 23, 42, 0.25); border-color: #cbd5e1; }
.da-online-card-open { border-color: #a7f3d0; box-shadow: 0 10px 20px -12px rgba(16, 185, 129, 0.35); }
.da-buzz-bell { transition: color 0.15s ease, transform 0.15s ease; }
.da-online-card:hover .da-buzz-bell { color: #f59e0b; transform: rotate(-12deg); }

.da-buzz-composer { margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px dashed var(--lg-line); cursor: default; }
.da-emoji-btn { font-size: 0.95rem; line-height: 1; padding: 0.3rem 0.4rem; border-radius: 0.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); transition: transform 0.12s ease, background 0.12s ease; }
.da-emoji-btn:hover { background: var(--lg-soft); transform: scale(1.15); }
.da-buzz-input { flex: 1; min-width: 0; height: 2.5rem; font-size: 0.8rem; padding: 0.4rem 0.8rem; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 9999px; outline: none; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.da-buzz-input:focus { border-color: #6ee7b7; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14); }
.da-buzz-send { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2.5rem; height: 2.5rem; border: 0; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 10px 18px -10px rgba(4, 120, 87, 0.9); transition: transform 0.12s ease, opacity 0.12s ease; }
.da-buzz-send:hover:not(:disabled) { transform: translateY(-1px); }
.da-buzz-send:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
</style>
