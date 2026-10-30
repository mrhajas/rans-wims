<template>
  <admin-layout :title="$t('My ID')">
    <div class="lg-page sc-ui px-4 md:px-0 myid" :style="{ '--c': card ? card.color : '#1565c0' }">
      <div class="myid-bg" aria-hidden="true"><i></i><i></i><i></i></div>

      <page-header icon="user" :title="$t('My ID')" :subtitle="$t('Your RANS Staff ID card, front and back.')" />

      <section v-if="!card" class="myid-empty">
        <span class="myid-empty-ico"><icons name="user" class="w-9 h-9" /></span>
        <h2>{{ linked ? $t('No RANS ID has been issued to you yet') : $t('Your login is not linked to a staff record') }}</h2>
        <p>{{ linked ? $t('When the office issues your Staff ID it appears here.') : $t('Ask the office to link your login to your staff record, then your ID appears here.') }}</p>
      </section>

      <template v-else>
        <!-- the scan before the card appears -->
        <transition name="myid-swap" mode="out-in">
          <section v-if="!ready" key="load" class="myid-load" role="status" aria-live="polite" @click="ready = true">
            <div class="myid-scan">
              <span class="br tl"></span><span class="br tr"></span><span class="br bl"></span><span class="br bb"></span>
              <div class="myid-ghost">
                <i class="g-photo"></i><i class="g-band"></i><i class="g-qr"><b v-for="n in 16" :key="n" :style="{ '--d': (n * 0.11) + 's' }"></b></i><i class="g-no"></i>
              </div>
              <span class="myid-laser"></span>
            </div>
            <h2>{{ $t('Opening your ID') }}</h2>
            <transition name="myid-step" mode="out-in"><p :key="step" class="myid-step">{{ steps[step] }}</p></transition>
            <div class="myid-ring"><i></i></div>
          </section>

          <div v-else key="ready" class="myid-ready">
            <section class="myid-hero">
              <span class="myid-hero-ico"><icons name="user" class="w-6 h-6" /></span>
              <div class="min-w-0"><small>{{ $t('Welcome back') }}</small><h2>{{ firstName }}</h2></div>
              <span class="myid-badge" :class="'st-' + card.status_raw.replace(/\s/g, '')"><icons :name="statusIcon(card.status)" class="w-4 h-4" />{{ $t(card.status) }}</span>
            </section>
            <section v-if="card.status_raw !== 'Active'" class="lg-note lg-note-amber myid-note-top"><icons :name="statusIcon(card.status)" class="w-4 h-4" /><span>{{ $t('This ID is not active (:s). Please see the office.', { s: $t(card.status).toLowerCase() }) }}</span></section>

            <div class="myid-cardwrap">
              <id-cutout :front="front" :back="back" :css="css" :color="card.color" :size="270" :can-print="card.can_print" :can-download="card.can_download" :blocked="card.status !== 'Active'" :blocked-text="$t('Only an active ID can be printed or downloaded.')" @print="make('print')" @download="make('pdf')" />
              <p class="myid-tip"><icons name="info" class="w-4 h-4" />{{ $t('Tap the card to turn it over.') }}</p>
            </div>

            <section class="myid-tiles">
              <div class="t" style="--t: #2563eb"><span><icons name="hash" class="w-5 h-5" /></span><div><small>{{ $t('ID number') }}</small><b>{{ card.number }}</b></div></div>
              <div class="t" style="--t: #7c3aed"><span><icons name="user" class="w-5 h-5" /></span><div><small>{{ $t('Name') }}</small><b>{{ card.name }}</b></div></div>
              <div v-if="card.title" class="t" style="--t: #0d9488"><span><icons name="bag" class="w-5 h-5" /></span><div><small>{{ $t('Title') }}</small><b>{{ card.title }}</b></div></div>
              <div class="t" style="--t: #d97706"><span><icons name="calendar" class="w-5 h-5" /></span><div><small>{{ $t('Issued') }}</small><b>{{ card.issued_at ? $date(card.issued_at) : '-' }}</b></div></div>
              <div class="t" style="--t: #16a34a"><span><icons name="clock" class="w-5 h-5" /></span><div><small>{{ $t('Valid until') }}</small><b>{{ card.validity_text }}</b></div></div>
            </section>

            <section v-if="card.expires_at && card.days_left !== null" class="myid-valid">
              <div class="v-top"><b>{{ card.days_left >= 0 ? $t(':n days left', { n: card.days_left }) : $t('Expired') }}</b><small>{{ $date(card.issued_at) }} - {{ $date(card.expires_at) }}</small></div>
              <div class="v-bar" :class="{ low: card.days_left <= 30 }"><i :style="{ width: validPct + '%' }"></i></div>
            </section>

            <p class="myid-note"><icons name="qr-code" class="w-4 h-4" />{{ $t('The QR code on your ID is checked by security. If your ID is lost or broken, tell the office so it can be blocked and replaced.') }}</p>
            <a v-if="card.detail_url" :href="card.detail_url" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="eye-flat" class="w-4 h-4" /></span>{{ $t('Open the full record') }}</a>
          </div>
        </transition>
      </template>
      <id-make-overlay />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import IdCutout from './Parts/IdCutout.vue';
import IdMakeOverlay from './Parts/IdMakeOverlay.vue';
import { statusIcon } from './Parts/status';
import { idMakeFile } from '@/Core/idMake';

// "My ID": the signed-in person's own card. A short scan plays first (the card outline is read, its QR squares assemble, a laser sweeps), then the card drops in. Tap/click skips the scan.
export default {
  components: { AdminLayout, PageHeader, IdCutout, IdMakeOverlay },
  props: { card: Object, linked: Boolean, front: String, back: String, css: String },
  data() { return { ready: !this.card || (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches), step: 0, timers: [] }; },
  computed: {
    firstName() { return String((this.card && this.card.name) || '').split(' ')[0]; },
    steps() { return [this.$t('Finding your record'), this.$t('Checking your ID is valid'), this.$t('Preparing your card')]; },
    validPct() { const c = this.card; if (!c || c.days_left === null || !c.span_days) return 0; return Math.max(3, Math.min(100, Math.round(((c.span_days - Math.max(0, c.days_left)) / c.span_days) * 100))); },
  },
  mounted() {
    if (!this.ready) {
      this.timers.push(setInterval(() => { this.step = Math.min(this.steps.length - 1, this.step + 1); }, 650));
      this.timers.push(setTimeout(() => { this.ready = true; }, 1900));
    }
  },
  beforeUnmount() { this.timers.forEach(t => { clearInterval(t); clearTimeout(t); }); },
  methods: {
    statusIcon,
    async make(kind) {
      const c = this.card;
      if (c.print_count > 0) { window.location = c.detail_url || route('id-passes.my-id'); return; }
      const ok = await idMakeFile(kind === 'print' ? c.print_url : c.pdf_url, { mode: kind, color: c.color, title: kind === 'print' ? this.$t('Preparing :n for printing', { n: c.number }) : this.$t('Creating the PDF of :n', { n: c.number }), doneText: c.number, fail: this.$t('The file could not be made.') });
      if (ok) this.$inertia.reload({ only: ['card'], preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.myid { position: relative; max-width: 820px; margin: 0 auto; isolation: isolate; }
.myid-bg { position: absolute; inset: 0; z-index: -1; overflow: hidden; border-radius: 32px; pointer-events: none; }
.myid-bg i { position: absolute; border-radius: 50%; filter: blur(46px); opacity: 0.4; background: var(--c); animation: myid-drift 14s ease-in-out infinite; }
.myid-bg i:nth-child(1) { width: 260px; height: 260px; left: -60px; top: 80px; } .myid-bg i:nth-child(2) { width: 220px; height: 220px; right: -50px; top: 300px; background: #6366f1; animation-delay: -5s; } .myid-bg i:nth-child(3) { width: 180px; height: 180px; left: 40%; bottom: 40px; background: #f59e0b; opacity: 0.22; animation-delay: -9s; }
@keyframes myid-drift { 50% { transform: translate(26px, -22px) scale(1.1); } }

/* ---------- empty ---------- */
.myid-empty { display: grid; gap: 8px; justify-items: center; text-align: center; padding: 46px 20px; color: var(--lg-mute, #64748b); }
.myid-empty h2 { margin: 6px 0 0; font-size: 1.15rem; color: var(--lg-ink, #0f2540); }
.myid-empty-ico { display: grid; place-items: center; width: 72px; height: 72px; border-radius: 24px; color: #fff; background: linear-gradient(145deg, #64748b, #334155); box-shadow: 0 18px 26px -16px rgba(15, 37, 64, 0.8); animation: myid-bob 3s ease-in-out infinite; }
@keyframes myid-bob { 50% { transform: translateY(-5px); } }

/* ---------- the scan ---------- */
.myid-load { display: grid; justify-items: center; gap: 10px; padding: 30px 10px 36px; text-align: center; cursor: pointer; }
.myid-load h2 { margin: 10px 0 0; font-size: 1.15rem; font-weight: 800; }
.myid-step { margin: 0; min-height: 1.3em; font-weight: 600; font-size: 0.9rem; opacity: 0.7; }
.myid-step-enter-active, .myid-step-leave-active { transition: opacity 0.2s, transform 0.2s; } .myid-step-enter-from { opacity: 0; transform: translateY(6px); } .myid-step-leave-to { opacity: 0; transform: translateY(-6px); }
.myid-scan { position: relative; width: 190px; aspect-ratio: 55 / 85; margin: 6px 0 4px; }
.myid-scan .br { position: absolute; width: 26px; height: 26px; border: 0 solid var(--c); animation: myid-brk 1.2s ease-in-out infinite; }
.br.tl { left: -14px; top: -14px; border-width: 4px 0 0 4px; border-top-left-radius: 12px; } .br.tr { right: -14px; top: -14px; border-width: 4px 4px 0 0; border-top-right-radius: 12px; } .br.bl { left: -14px; bottom: -14px; border-width: 0 0 4px 4px; border-bottom-left-radius: 12px; } .br.bb { right: -14px; bottom: -14px; border-width: 0 4px 4px 0; border-bottom-right-radius: 12px; }
@keyframes myid-brk { 50% { transform: scale(1.18); opacity: 0.6; } }
.myid-ghost { position: absolute; inset: 0; border-radius: 12px; overflow: hidden; background: linear-gradient(160deg, color-mix(in srgb, var(--c) 18%, #eef2f8), #e3eaf4); box-shadow: 0 26px 34px -22px rgba(8, 20, 40, 0.6); }
.myid-ghost i { position: absolute; display: block; } .g-photo { right: 10%; top: 8%; width: 44%; height: 40%; border-radius: 9px; background: rgba(100, 116, 139, 0.35); animation: myid-pulse 1.4s ease-in-out infinite; }
.g-band { left: 0; top: 47%; width: 74%; height: 12%; border-radius: 0 9px 0 0; background: color-mix(in srgb, var(--c) 55%, #fff); animation: myid-pulse 1.4s 0.2s ease-in-out infinite; }
.g-qr { left: 6%; top: 64%; width: 40%; aspect-ratio: 1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 3px; } .g-qr b { background: rgba(30, 68, 103, 0.7); border-radius: 2px; animation: myid-pop 0.5s var(--d) both; }
.g-no { right: 8%; top: 66%; width: 34%; height: 8%; border-radius: 4px; background: rgba(30, 68, 103, 0.4); animation: myid-pulse 1.4s 0.4s ease-in-out infinite; }
@keyframes myid-pulse { 50% { opacity: 0.55; } } @keyframes myid-pop { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.myid-laser { position: absolute; left: -10px; right: -10px; height: 3px; border-radius: 3px; background: linear-gradient(90deg, transparent, var(--c), #fff, var(--c), transparent); box-shadow: 0 0 18px 4px color-mix(in srgb, var(--c) 65%, transparent); animation: myid-sweep 1.3s ease-in-out infinite alternate; }
@keyframes myid-sweep { from { top: 2%; } to { top: 98%; } }
.myid-ring { width: 150px; height: 5px; border-radius: 5px; background: rgba(100, 116, 139, 0.2); overflow: hidden; margin-top: 4px; } .myid-ring i { display: block; height: 100%; width: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--c), #6366f1); transform-origin: left; animation: myid-fill 1.9s linear both; }
@keyframes myid-fill { from { transform: scaleX(0); } to { transform: scaleX(1); } }
.myid-swap-enter-active, .myid-swap-leave-active { transition: opacity 0.3s, transform 0.3s; } .myid-swap-enter-from { opacity: 0; transform: translateY(14px); } .myid-swap-leave-to { opacity: 0; transform: scale(0.96); }

/* ---------- the ID ---------- */
.myid-ready { display: grid; gap: 18px; justify-items: center; }
.myid-ready > * { width: 100%; }
.myid-hero { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 24px; color: #fff; background: linear-gradient(120deg, color-mix(in srgb, var(--c) 90%, #0f2540), color-mix(in srgb, var(--c) 55%, #1e1b4b)); box-shadow: 0 22px 32px -20px color-mix(in srgb, var(--c) 80%, #000); animation: myid-in 0.6s both; position: relative; overflow: hidden; }
.myid-hero::after { content: ''; position: absolute; inset: 0 auto 0 -40%; width: 30%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent); animation: myid-glint 3.6s 0.8s ease-in-out infinite; }
@keyframes myid-glint { 0% { left: -40%; } 45%, 100% { left: 140%; } }
.myid-hero-ico { display: grid; place-items: center; width: 46px; height: 46px; border-radius: 16px; background: rgba(255, 255, 255, 0.2); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); flex: none; }
.myid-hero > div { flex: 1; } .myid-hero small { opacity: 0.8; font-weight: 600; } .myid-hero h2 { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -0.01em; }
.myid-badge { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; font-weight: 800; font-size: 0.82rem; background: #fff; color: #166534; box-shadow: 0 8px 14px -8px rgba(0, 0, 0, 0.5); }
.myid-badge.st-PendingApproval, .myid-badge.st-Expired { color: #92400e; } .myid-badge.st-Suspended { color: #9a3412; } .myid-badge.st-Revoked, .myid-badge.st-Replaced, .myid-badge.st-Rejected { color: #991b1b; }
.myid-note-top { margin: 0; }
@keyframes myid-in { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
.myid-cardwrap { display: grid; justify-items: center; gap: 12px; padding: 16px 0 4px; }
.myid-tip { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-size: 0.8rem; font-weight: 600; color: var(--lg-mute, #64748b); }

.myid-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); gap: 12px; }
.myid-tiles .t { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px color-mix(in srgb, var(--t) 18%, transparent), 0 14px 22px -18px color-mix(in srgb, var(--t) 70%, transparent); animation: myid-in 0.5s both; transition: transform 0.18s, box-shadow 0.18s; }
.myid-tiles .t:nth-child(2) { animation-delay: 0.06s; } .myid-tiles .t:nth-child(3) { animation-delay: 0.12s; } .myid-tiles .t:nth-child(4) { animation-delay: 0.18s; } .myid-tiles .t:nth-child(5) { animation-delay: 0.24s; }
.myid-tiles .t:hover { transform: translateY(-3px); box-shadow: 0 0 0 1px color-mix(in srgb, var(--t) 40%, transparent), 0 18px 26px -16px color-mix(in srgb, var(--t) 80%, transparent); }
.myid-tiles .t > span { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; flex: none; color: #fff; background: linear-gradient(145deg, var(--t), color-mix(in srgb, var(--t) 60%, #000)); box-shadow: 0 10px 14px -8px var(--t); }
.myid-tiles .t div { display: grid; min-width: 0; } .myid-tiles small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .myid-tiles b { font-weight: 800; color: var(--lg-ink, #0f2540); overflow-wrap: anywhere; }

.myid-valid { padding: 14px 16px; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0), 0 14px 22px -18px rgba(15, 37, 64, 0.5); }
.v-top { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; } .v-top small { color: var(--lg-mute, #64748b); font-weight: 600; }
.v-bar { height: 10px; border-radius: 10px; background: rgba(100, 116, 139, 0.18); overflow: hidden; } .v-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #22c55e, #16a34a); animation: myid-fill2 1.1s 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) both; transform-origin: left; } .v-bar.low i { background: linear-gradient(90deg, #f59e0b, #dc2626); }
@keyframes myid-fill2 { from { transform: scaleX(0); } to { transform: scaleX(1); } }
.myid-note { display: flex; gap: 10px; align-items: flex-start; margin: 0; padding: 12px 14px; border-radius: 18px; font-size: 0.84rem; color: #1e4467; background: color-mix(in srgb, var(--c) 9%, #fff); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 22%, transparent); } .myid-note svg { flex: none; margin-top: 2px; }
.myid-ready > .lg-btn { width: auto; justify-self: center; }
@media (max-width: 560px) { .myid-hero { flex-wrap: wrap; } .myid-badge { margin-left: auto; } }
@media (prefers-reduced-motion: reduce) { .myid-bg i, .myid-hero, .myid-hero::after, .myid-tiles .t, .v-bar i, .myid-empty-ico { animation: none !important; } }
</style>
