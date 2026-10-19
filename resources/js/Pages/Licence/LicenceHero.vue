<template>
  <section class="lic-hero" :class="'st-' + licence.state">
    <span class="lic-aurora a1" aria-hidden="true"></span><span class="lic-aurora a2" aria-hidden="true"></span>
    <div class="lic-hero__keys" aria-hidden="true"><icons name="key" /><icons name="lock-closed" /><icons name="key" /></div>

    <div class="lic-ring" role="img" :aria-label="$t('Days remaining') + ': ' + shownDays">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <defs>
          <linearGradient id="lic-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="var(--st)" /><stop offset="100%" stop-color="var(--st2)" /></linearGradient>
        </defs>
        <circle class="orbit" cx="60" cy="60" r="56" />
        <circle class="track" cx="60" cy="60" r="48" />
        <circle class="arc" cx="60" cy="60" r="48" :stroke-dasharray="circ" :stroke-dashoffset="offset_" />
      </svg>
      <div class="lic-ring__center">
        <span class="lic-ring__num">{{ shownDays }}</span>
        <span class="lic-ring__lbl">{{ isLive ? $t('days left') : $t(stateTitle) }}</span>
      </div>
    </div>

    <div class="min-w-0">
      <span class="lic-live"><i></i>{{ isLive ? $t('Live status') : $t('Status') }}</span>
      <h2 class="lic-state"><span>{{ $t(stateTitle) }}</span></h2>
      <p class="lic-sub">{{ subtitle }}</p>

      <div v-if="isLive || licence.state === 'scheduled'" class="lic-count" aria-live="off">
        <div v-for="u in units" :key="u.k"><b :key="u.k + u.v" class="tick">{{ u.v }}</b><span>{{ $t(u.l) }}</span></div>
      </div>

      <div class="lic-line">
        <div class="lic-line__bar">
          <div class="lic-line__fill" :style="{ width: barWidth + '%' }"></div>
          <div class="lic-line__now" :style="{ left: barWidth + '%' }" :title="$t('Today')"></div>
        </div>
        <div class="lic-line__ends">
          <div><small>{{ $t('Valid from') }}</small><b>{{ $date(licence.starts_at) }}</b></div>
          <div><small>{{ $t('Valid until') }}</small><b>{{ $date(licence.expires_at) }}</b></div>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  props: { licence: Object, serverNow: String },
  data() {
    return { now: Date.now(), offset: this.serverNow ? Date.parse(this.serverNow) - Date.now() : 0, drawn: false, timer: null };
  },
  computed: {
    circ() { return 2 * Math.PI * 48; },
    isLive() { return ['active', 'expiring'].includes(this.licence.state); },
    remainingPct() { return Math.max(0, Math.min(100, 100 - (this.licence.elapsed ?? 0))); },
    offset_() { return this.drawn ? this.circ * (1 - this.remainingPct / 100) : this.circ; },
    barWidth() { return this.drawn ? Math.min(100, this.licence.elapsed ?? 0) : 0; },
    left() { return Math.max(0, Date.parse(this.licence.ends_at_moment) - (this.now + this.offset)); },
    shownDays() { return this.isLive ? Math.max(0, Math.floor(this.left / 86400000)) : '0'; },
    units() {
      const l = this.left;
      const pad = n => String(n).padStart(2, '0');
      return [
        { k: 'd', v: Math.floor(l / 86400000), l: 'Days' },
        { k: 'h', v: pad(Math.floor(l / 3600000) % 24), l: 'Hours' },
        { k: 'm', v: pad(Math.floor(l / 60000) % 60), l: 'Minutes' },
        { k: 's', v: pad(Math.floor(l / 1000) % 60), l: 'Seconds' },
      ];
    },
    stateTitle() {
      return { active: 'Licence Active', expiring: 'Expiring Soon', expired: 'Licence Expired', deactivated: 'Licence Deactivated', scheduled: 'Licence Scheduled', issued: 'Licence Issued', superseded: 'Licence Replaced' }[this.licence.state] || 'Licence';
    },
    subtitle() {
      const l = this.licence;
      return {
        active: `${l.licensee} · ${l.edition} edition · ${l.duration}`,
        expiring: `${l.licensee}: renew before ${this.$date(l.expires_at)} to avoid interruption.`,
        expired: `Expired on ${this.$date(l.expires_at)}. Only the Licence Manager can sign in.`,
        deactivated: `Deactivated${l.deactivated_at ? ' on ' + this.$date(l.deactivated_at) : ''}${l.deactivation_reason ? ': ' + l.deactivation_reason : ''}.`,
        scheduled: `Starts on ${this.$date(l.starts_at)}.`,
        issued: 'Issued, not yet activated on this system.',
        superseded: 'Replaced by a newer licence.',
      }[l.state];
    },
  },
  mounted() {
    requestAnimationFrame(() => setTimeout(() => (this.drawn = true), 120));
    if (this.isLive || this.licence.state === 'scheduled') this.timer = setInterval(() => (this.now = Date.now()), 1000);
  },
  beforeUnmount() { clearInterval(this.timer); },
};
</script>
