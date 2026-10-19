<template>
  <admin-layout :title="$t('Digital Attendance')">
    <div class="lg-page px-4 md:px-0 da-shell">
      <page-header icon="clock" :title="$t('Digital Attendance')" :subtitle="$t('Clock in and out, and keep track of your hours.')">
        <Link :href="route('digital-attendance.history')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('History') }}</Link>
        <Link :href="route('digital-attendance.calendar')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Calendar') }}</Link>
      </page-header>

      <div v-if="$offlineBlocked" class="lg-note lg-note-amber mb-4">
        <icons name="wifi" class="w-4 h-4" />
        <div class="min-w-0">
          <div class="font-bold">{{ $t('WIMS is offline.') }}</div>
          <div>{{ $t('Attendance actions require an active connection.') }}</div>
        </div>
      </div>

      <div v-else-if="!settings.enabled" class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <div class="min-w-0">{{ $t('Digital Attendance is currently disabled for your account. Contact an administrator.') }}</div>
      </div>

      <section class="da-hero-card" :class="isClockedIn ? 'is-in' : isMissingClockOut ? 'is-warn' : 'is-idle'">
        <div class="da-hero-glow" aria-hidden="true"></div>
        <div class="da-hero-top">
          <div class="min-w-0">
            <div class="da-hero-kicker">{{ greeting }}</div>
            <h2 class="da-hero-name">{{ $user.name }}</h2>
          </div>
          <span class="da-status-chip">
            <span class="da-status-dot" :class="{ 'is-live': isClockedIn }"></span>
            <template v-if="isClockedIn">{{ $t('CLOCKED IN') }}</template>
            <template v-else-if="isMissingClockOut">{{ $t('MISSING CLOCK-OUT') }}</template>
            <template v-else-if="hasTodayRecord">{{ $t('CLOCKED OUT') }}</template>
            <template v-else>{{ $t('NOT CLOCKED IN') }}</template>
          </span>
        </div>

        <div class="da-clock" aria-live="off">
          <span class="da-clock-main">{{ clockParts.main }}</span><span class="da-clock-secs">{{ clockParts.secs }}</span><span class="da-clock-ampm">{{ clockParts.ampm }}</span>
        </div>
        <div class="da-clock-date">{{ dateLabel }}</div>

        <template v-if="isClockedIn">
          <div class="da-worked-row">
            <div class="da-worked-cell">
              <div class="da-worked-label">{{ $t('Clocked in at') }}</div>
              <div class="da-worked-value">{{ clockInLabel }}</div>
            </div>
            <div class="da-worked-cell">
              <div class="da-worked-label">{{ $t('Worked') }}</div>
              <div class="da-worked-value da-worked-accent">{{ liveWorkedLabel }}</div>
            </div>
          </div>
        </template>
        <template v-else-if="isMissingClockOut">
          <div class="da-worked-row">
            <div class="da-worked-cell">
              <div class="da-worked-label">{{ $t('Clocked in at') }}</div>
              <div class="da-worked-value">{{ clockInLabel }}</div>
            </div>
          </div>
          <div class="da-hero-notice">
            {{ $t('You still have an open session from') }} {{ $date(today.attendance_date) }} {{ $t('without a clock-out. WIMS never fabricates an exit time. Please ask an administrator to review and correct this record before you can clock in again.') }}
          </div>
        </template>
        <template v-else>
          <div class="da-hero-msg">{{ hasTodayRecord ? $t("You've already clocked out today.") : $t("You haven't clocked in today.") }}</div>
        </template>

        <div v-if="isClockedIn || (hasTodayRecord && !isMissingClockOut)" class="da-meta-row">
          <a
            v-if="today.clock_in_location_status === 'captured' && today.clock_in_latitude"
            :href="mapsUrl(today.clock_in_latitude, today.clock_in_longitude)"
            target="_blank"
            rel="noopener"
            class="da-chip da-chip-link"
            :class="locationChipClass(today.clock_in_location_status)"
          >
            <icons name="map-pin" class="w-3.5 h-3.5" />
            {{ today.clock_in_location_label || ($t('Near') + ' ' + today.clock_in_latitude.toFixed(3) + ', ' + today.clock_in_longitude.toFixed(3)) }}
          </a>
          <div v-else class="da-chip" :class="locationChipClass(today.clock_in_location_status)">
            <icons name="map-pin" class="w-3.5 h-3.5" />
            {{ locationChipText(today.clock_in_location_status) }}
          </div>
          <div v-if="today.clock_in_ip" class="da-chip lg-tip tip-left" :data-tip="$t('Public IP')">
            <icons name="wifi" class="w-3.5 h-3.5" />
            <span class="da-chip-label">{{ $t('Public') }}</span> {{ today.clock_in_ip }}
          </div>
          <div v-if="today.clock_in_local_ip" class="da-chip lg-tip tip-left" :data-tip="$t('Local Network IP')">
            <icons name="wifi" class="w-3.5 h-3.5" />
            <span class="da-chip-label">{{ $t('Local') }}</span> {{ today.clock_in_local_ip }}
          </div>
          <div v-if="today.clock_in_device || today.clock_in_browser" class="da-chip lg-tip tip-left" :data-tip="$t('Device')">
            <icons name="mobile" class="w-3.5 h-3.5" />
            {{ [today.clock_in_device, today.clock_in_browser].filter(Boolean).join(' · ') }}
          </div>
        </div>

        <div class="da-action">
          <button v-if="isClockedIn" type="button" class="da-btn da-btn-out" :disabled="$offlineBlocked || processing" @click="clockOut">
            <icons name="lock-closed" class="w-5 h-5" />
            {{ processing ? $t('Please wait…') : $t('CLOCK OUT') }}
          </button>
          <button
            v-else-if="!isMissingClockOut && (!hasTodayRecord || allowMultipleSessions)"
            type="button"
            class="da-btn da-btn-in"
            :disabled="$offlineBlocked || processing || !settings.enabled"
            @click="clockIn"
          >
            <icons name="lock-open" class="w-5 h-5" />
            {{ processing ? $t('Please wait…') : $t('CLOCK IN') }}
          </button>
          <div v-if="locationNotice && !isClockedIn && !isMissingClockOut" class="da-location-notice">{{ locationNotice }}</div>
        </div>
      </section>

      <stat-tiles :items="statTiles" />

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg-card lg:col-span-2">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t("What WIMS can see about this session") }}</div>
              <div class="lg-card-sub">{{ $t('Captured automatically the moment you clock in or out. This is a live preview, not stored until then.') }}</div>
            </div>
          </div>
          <div class="da-session-grid">
            <div class="da-session-item">
              <div class="da-session-item-label"><icons name="wifi" class="w-3.5 h-3.5" /> {{ $t('Public IP') }}</div>
              <div class="da-session-item-value">{{ clientContext.ip || $t('Unavailable') }}</div>
            </div>
            <div class="da-session-item">
              <div class="da-session-item-label"><icons name="wifi" class="w-3.5 h-3.5" /> {{ $t('Local IP') }}</div>
              <div class="da-session-item-value">{{ sessionLocalIp === undefined ? $t('Detecting…') : (sessionLocalIp || $t('Unavailable')) }}</div>
            </div>
            <div class="da-session-item">
              <div class="da-session-item-label"><icons name="map-pin" class="w-3.5 h-3.5" /> {{ $t('Location') }}</div>
              <div class="da-session-item-value">
                <a v-if="sessionLocation && sessionLocation.latitude" :href="mapsUrl(sessionLocation.latitude, sessionLocation.longitude)" target="_blank" rel="noopener" class="da-session-item-link">
                  {{ sessionLocation.label || ($t('Near') + ' ' + sessionLocation.latitude.toFixed(3) + ', ' + sessionLocation.longitude.toFixed(3)) }}
                </a>
                <span v-else-if="sessionLocation === undefined">{{ $t('Detecting…') }}</span>
                <span v-else>{{ sessionLocation === null ? $t('Permission denied') : $t('Unavailable') }}</span>
              </div>
            </div>
            <div class="da-session-item">
              <div class="da-session-item-label"><icons name="mobile" class="w-3.5 h-3.5" /> {{ $t('Device') }}</div>
              <div class="da-session-item-value">{{ [clientContext.device, clientContext.browser, clientContext.os].filter(Boolean).join(' · ') || $t('Unavailable') }}</div>
            </div>
          </div>
        </div>

        <div class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="chart-pie" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Weekly Progress') }}</div>
            </div>
          </div>
          <div class="da-chart-wrap">
            <vue-highcharts type="chart" :options="weekChart" />
            <div class="da-chart-center">
              <div class="da-chart-center-value">{{ weekHoursDisplay }}</div>
              <div class="da-chart-center-label">{{ $t('this week') }}</div>
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
import VueHighcharts from 'vue3-highcharts';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const WEEKLY_TARGET_HOURS = 40;

export default {
  components: { AdminLayout, Link, VueHighcharts, PageHeader, StatTiles },

  props: {
    today: { type: Object, default: null },
    week: { type: Object, required: true },
    month: { type: Object, required: true },
    attendance_settings: { type: Object, required: true },
    serverTime: { type: String, required: true },
    client_context: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      processing: false,
      clientContext: this.client_context,
      // undefined = still detecting, null = denied/unavailable, value = found
      sessionLocalIp: undefined,
      sessionLocation: undefined,
      now: new Date(this.serverTime),
      clockInterval: null,
      locationNotice: '',
      pendingLocation: null,
    };
  },

  computed: {
    // Local alias only — the prop itself can't be named "settings" because
    // that collides with the app-wide shared Setting object.
    settings() {
      return this.attendance_settings;
    },
    greeting() {
      const h = this.now.getHours();
      if (h < 12) return this.$t('Good Morning');
      if (h < 17) return this.$t('Good Afternoon');
      return this.$t('Good Evening');
    },
    hasTodayRecord() {
      return !!this.today;
    },
    isClockedIn() {
      return this.today && this.today.status === 'CLOCKED_IN';
    },
    isMissingClockOut() {
      return this.today && this.today.status === 'MISSING_CLOCK_OUT';
    },
    allowMultipleSessions() {
      return false;
    },
    clockParts() {
      const parts = new Intl.DateTimeFormat([], { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }).formatToParts(this.now);
      const get = type => (parts.find(p => p.type === type) || {}).value || '';
      return { main: `${get('hour')}:${get('minute')}`, secs: `:${get('second')}`, ampm: get('dayPeriod') };
    },
    dateLabel() {
      return this.now.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    },
    statTiles() {
      const wk = this.$t('This Week');
      const mo = this.$t('This Month');
      return [
        { icon: 'calendar', tone: 'emerald', label: `${wk} · ${this.$t('Days')}`, value: this.week.days },
        { icon: 'clock', tone: 'navy', label: `${wk} · ${this.$t('Total')}`, value: this.week.total_label },
        { icon: 'chart-bar', tone: 'teal', label: `${wk} · ${this.$t('Avg/Day')}`, value: this.avgLabel(this.week.average_minutes) },
        { icon: 'calendar', tone: 'indigo', label: `${mo} · ${this.$t('Days')}`, value: this.month.days },
        { icon: 'clock', tone: 'sky', label: `${mo} · ${this.$t('Total')}`, value: this.month.total_label },
        { icon: 'chart-bar', tone: 'violet', label: `${mo} · ${this.$t('Avg/Day')}`, value: this.avgLabel(this.month.average_minutes) },
      ];
    },
    nowLabel() {
      return this.now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    },
    clockInLabel() {
      if (!this.today || !this.today.clock_in_at) return '';
      return new Date(this.today.clock_in_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    },
    liveWorkedLabel() {
      if (!this.today || !this.today.clock_in_at) return '00h 00m';
      const start = new Date(this.today.clock_in_at).getTime();
      const minutes = Math.max(0, Math.floor((this.now.getTime() - start) / 60000));
      const h = Math.floor(minutes / 60);
      const m = minutes % 60;
      return `${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m`;
    },
    weekHoursDisplay() {
      return `${(this.week.total_minutes / 60).toFixed(1)}h`;
    },
    weekChart() {
      const worked = Math.min(this.week.total_minutes / 60, WEEKLY_TARGET_HOURS);
      const remaining = Math.max(0, WEEKLY_TARGET_HOURS - worked);
      return {
        chart: { type: 'pie', height: 160, backgroundColor: 'transparent', margin: [0, 0, 0, 0] },
        title: { text: null },
        credits: { enabled: false },
        tooltip: { pointFormat: '{point.y:.1f}h' },
        plotOptions: {
          pie: {
            innerSize: '78%',
            borderWidth: 0,
            dataLabels: { enabled: false },
            states: { hover: { halo: { size: 4 } } },
          },
        },
        series: [
          {
            name: this.$t('Hours'),
            data: [
              { name: this.$t('Worked'), y: worked, color: '#10b981' },
              { name: this.$t('Remaining'), y: remaining, color: '#e2e8f0' },
            ],
          },
        ],
      };
    },
  },

  mounted() {
    this.clockInterval = setInterval(() => (this.now = new Date()), 1000);
    this.detectSessionInfo();
  },
  beforeUnmount() {
    if (this.clockInterval) clearInterval(this.clockInterval);
  },

  methods: {
    avgLabel(minutes) {
      const h = Math.floor(minutes / 60);
      const m = minutes % 60;
      return `${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m`;
    },
    locationChipClass(status) {
      return {
        'da-location-chip-ok': status === 'captured',
        'da-location-chip-warn': status === 'denied' || status === 'unavailable',
      };
    },
    locationChipText(status) {
      if (status === 'captured') return this.$t('Location Captured');
      if (status === 'denied') return this.$t('Location Permission Denied');
      return this.$t('Location Unavailable');
    },
    mapsUrl(lat, lng) {
      return `https://www.google.com/maps?q=${lat},${lng}`;
    },
    captureLocation() {
      return new Promise(resolve => {
        if (!this.settings.location_capture || !navigator.geolocation) {
          resolve({ location_status: 'unavailable' });
          return;
        }
        navigator.geolocation.getCurrentPosition(
          position => {
            resolve({
              location_status: 'captured',
              latitude: position.coords.latitude,
              longitude: position.coords.longitude,
              accuracy: position.coords.accuracy,
            });
          },
          error => {
            resolve({ location_status: error.code === 1 ? 'denied' : 'unavailable' });
          },
          { enableHighAccuracy: true, timeout: 10000 }
        );
      });
    },
    // Always-on preview for the "what WIMS can see about this session" panel
    // — separate from captureLocation()/detectLocalIp(), which only run at
    // the moment of an actual clock-in/out. This runs on page load so the
    // panel isn't empty until the user takes an action.
    detectSessionInfo() {
      this.detectLocalIp().then(ip => (this.sessionLocalIp = ip));

      if (!navigator.geolocation) {
        this.sessionLocation = null;
        return;
      }
      navigator.geolocation.getCurrentPosition(
        position => {
          const { latitude, longitude } = position.coords;
          this.sessionLocation = { latitude, longitude, label: null };
          axios
            .get(this.route('digital-attendance.preview-location'), { params: { latitude, longitude } })
            .then(res => {
              if (this.sessionLocation) this.sessionLocation.label = res.data.label;
            })
            .catch(() => {});
        },
        () => {
          this.sessionLocation = null;
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    },
    // Best-effort local-network IP via a WebRTC ICE-candidate probe — modern
    // Chrome increasingly hides this behind an mDNS hostname (privacy
    // mitigation for local-network fingerprinting), and some browsers block
    // it outright, so this resolves to null rather than hanging when nothing
    // usable turns up within 1.5s.
    detectLocalIp() {
      return new Promise(resolve => {
        if (typeof RTCPeerConnection === 'undefined') {
          resolve(null);
          return;
        }
        let done = false;
        const finish = ip => {
          if (done) return;
          done = true;
          clearTimeout(timer);
          try { pc.close(); } catch (e) {}
          resolve(ip);
        };
        let pc;
        try {
          pc = new RTCPeerConnection({ iceServers: [] });
          pc.createDataChannel('');
          pc.onicecandidate = e => {
            if (!e.candidate) { finish(null); return; }
            const match = /([0-9]{1,3}(?:\.[0-9]{1,3}){3})/.exec(e.candidate.candidate);
            if (match && !match[1].startsWith('0.')) finish(match[1]);
          };
          pc.createOffer().then(offer => pc.setLocalDescription(offer)).catch(() => finish(null));
        } catch (e) {
          resolve(null);
          return;
        }
        var timer = setTimeout(() => finish(null), 1500);
      });
    },
    async clockIn() {
      this.locationNotice = '';
      this.processing = true;
      const [location, localIp] = await Promise.all([this.captureLocation(), this.detectLocalIp()]);
      location.local_ip = localIp;

      if (this.settings.require_location && location.location_status !== 'captured') {
        this.processing = false;
        this.locationNotice = this.$t('Digital Attendance requires your location to record attendance. Please allow location access and try again.');
        return;
      }

      this.$inertia.post(this.route('digital-attendance.clock-in'), location, {
        preserveScroll: true,
        onFinish: () => {
          this.processing = false;
          // Navbar.vue keeps its own independently-fetched attendance state
          // for the topbar indicator (it's a persistent layout, not a child
          // of this page) — without this it only learns about a clock-in/out
          // that happened here on its next 60s poll.
          window.dispatchEvent(new Event('digital-attendance:refresh'));
        },
      });
    },
    async clockOut() {
      this.processing = true;
      const [location, localIp] = await Promise.all([this.captureLocation(), this.detectLocalIp()]);
      location.local_ip = localIp;

      this.$inertia.post(this.route('digital-attendance.clock-out'), location, {
        preserveScroll: true,
        onFinish: () => {
          this.processing = false;
          window.dispatchEvent(new Event('digital-attendance:refresh'));
        },
      });
    },
  },
};
</script>

<style scoped>
.da-shell { position: relative; }

/* ---- Hero: the clock-in card ---- */
.da-hero-card {
  position: relative; overflow: hidden; margin-bottom: 1rem; padding: 1.4rem 1.6rem 1.6rem; border-radius: 26px; text-align: center; color: #fff;
  background: linear-gradient(140deg, #16324f 0%, #0f2540 55%, #0b1b30 100%);
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.08), 0 28px 50px -24px rgba(15, 37, 64, 0.75);
}
.da-hero-card.is-in { background: linear-gradient(140deg, #047857 0%, #0f5f55 45%, #0f2540 100%); }
.da-hero-card.is-warn { background: linear-gradient(140deg, #b45309 0%, #7c3a0d 50%, #1f2937 100%); }
.da-hero-glow { position: absolute; inset: 0; pointer-events: none; background: radial-gradient(520px 260px at 85% -10%, rgba(255, 255, 255, 0.16), transparent 70%), radial-gradient(420px 240px at 0% 110%, rgba(56, 189, 248, 0.2), transparent 70%); }
.da-hero-card > * { position: relative; }
.da-hero-card > .da-hero-glow { position: absolute; }
.da-hero-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; text-align: left; }
.da-hero-kicker { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.75; }
.da-hero-name { margin-top: 2px; font-size: 1.25rem; font-weight: 800; line-height: 1.2; overflow-wrap: anywhere; }
.da-status-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.28); backdrop-filter: blur(6px); }
.da-status-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: #cbd5e1; }
.is-in .da-status-dot { background: #6ee7b7; }
.is-warn .da-status-dot { background: #fcd34d; }
.da-status-dot.is-live::after { content: ''; position: absolute; inset: -5px; border-radius: 9999px; border: 2px solid #6ee7b7; opacity: 0.6; animation: da-pulse-ring 1.8s ease-out infinite; }
@keyframes da-pulse-ring { 0% { transform: scale(0.7); opacity: 0.7; } 100% { transform: scale(1.8); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .da-status-dot.is-live::after { animation: none; } }

.da-clock { margin-top: 1.1rem; display: flex; align-items: baseline; justify-content: center; flex-wrap: wrap; gap: 0 4px; font-variant-numeric: tabular-nums; line-height: 1; letter-spacing: -0.03em; }
.da-clock-main { font-size: clamp(3rem, 15vw, 5.6rem); font-weight: 800; }
.da-clock-secs { font-size: clamp(1.4rem, 7vw, 2.4rem); font-weight: 700; opacity: 0.6; }
.da-clock-ampm { margin-left: 4px; font-size: clamp(1rem, 4.5vw, 1.5rem); font-weight: 800; letter-spacing: 0.02em; opacity: 0.8; }
.da-clock-date { margin-top: 0.55rem; font-size: 0.86rem; font-weight: 600; opacity: 0.75; }
.da-hero-msg { margin-top: 1rem; font-size: 0.95rem; font-weight: 700; opacity: 0.9; }

.da-worked-row { display: flex; justify-content: center; flex-wrap: wrap; gap: 14px 40px; margin-top: 1.1rem; }
.da-worked-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.7; }
.da-worked-value { margin-top: 2px; font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.da-worked-accent { color: #a7f3d0; }
.da-hero-notice { max-width: 34rem; margin: 1rem auto 0; padding: 0.8rem 1rem; border-radius: 14px; font-size: 0.8rem; line-height: 1.55; text-align: left; background: rgba(0, 0, 0, 0.22); border: 1px solid rgba(255, 255, 255, 0.2); }

.da-meta-row { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 1.1rem; }
.da-chip { position: relative; display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 12px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; font-variant-numeric: tabular-nums; color: #fff; background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.2); overflow-wrap: anywhere; text-align: left; }
.da-chip-link { cursor: pointer; transition: transform 0.15s ease, background 0.15s ease; }
.da-chip-link:hover { transform: translateY(-1px); background: rgba(255, 255, 255, 0.24); }
.da-chip-label { font-size: 0.6rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; }
.da-chip.da-location-chip-ok { background: rgba(110, 231, 183, 0.22); border-color: rgba(110, 231, 183, 0.45); }
.da-chip.da-location-chip-warn { background: rgba(252, 211, 77, 0.22); border-color: rgba(252, 211, 77, 0.5); }

.da-action { margin-top: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.da-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; max-width: 340px; min-height: 56px; padding: 0.9rem 2rem; border: 0; border-radius: 9999px; font-weight: 800; font-size: 1.05rem; letter-spacing: 0.04em; color: #fff; transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease; }
.da-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.da-btn:not(:disabled):hover { transform: translateY(-2px); filter: brightness(1.05); }
.da-btn-in { background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 16px 30px -12px rgba(2, 6, 23, 0.7), 0 0 0 4px rgba(255, 255, 255, 0.14); }
.da-btn-out { background: linear-gradient(145deg, #f87171, #dc2626); box-shadow: 0 16px 30px -12px rgba(2, 6, 23, 0.7), 0 0 0 4px rgba(255, 255, 255, 0.14); }
.da-location-notice { max-width: 28rem; padding: 0.6rem 0.9rem; border-radius: 14px; font-size: 0.78rem; line-height: 1.5; color: #fef3c7; background: rgba(0, 0, 0, 0.24); border: 1px solid rgba(252, 211, 77, 0.5); }

/* ---- Session panel ---- */
.da-session-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.9rem 1rem; }
@media (min-width: 768px) { .da-session-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 400px) { .da-session-grid { grid-template-columns: minmax(0, 1fr); } }
.da-session-item { padding: 0.7rem 0.85rem; border-radius: 14px; background: var(--lg-soft); }
.da-session-item-label { display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.25rem; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.da-session-item-value { font-size: 0.82rem; font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.da-session-item-link { color: #047857; }
.da-session-item-link:hover { text-decoration: underline; }
html.dark .da-session-item-link { color: #6ee7b7; }

/* ---- Weekly chart ---- */
.da-chart-wrap { position: relative; display: flex; align-items: center; justify-content: center; }
.da-chart-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none; }
.da-chart-center-value { font-size: 1.25rem; font-weight: 800; color: #059669; font-variant-numeric: tabular-nums; }
.da-chart-center-label { margin-top: 0.1rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }

@media (max-width: 400px) { .da-hero-card { padding: 1.1rem 1rem 1.3rem; border-radius: 22px; } }
</style>
