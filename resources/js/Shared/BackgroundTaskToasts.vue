<template>
  <div class="bgt-host" aria-live="polite">
    <transition-group name="bgt" tag="div" class="bgt-stack">
      <div v-for="t in cards" :key="t.id" class="bgt-card" :class="['is-' + t.status]" role="status">
        <span class="bgt-badge" aria-hidden="true">
          <i v-if="t.status === 'queued' || t.status === 'running'" class="bgt-spin"></i>
          <svg v-else-if="t.status === 'done'" class="bgt-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17" /></svg>
        </span>
        <div class="bgt-body">
          <strong class="bgt-title">{{ title(t) }}</strong>
          <span class="bgt-sub">{{ sub(t) }}</span>
          <span v-if="t.status === 'queued' || t.status === 'running'" class="bgt-bar" aria-hidden="true"><i></i></span>
        </div>
        <a v-if="t.status === 'done' && t.has_file" class="bgt-go" :href="downloadUrl(t)" @click="afterDownload(t)">{{ $t('Download') }}</a>
        <button v-if="t.status !== 'queued' && t.status !== 'running'" type="button" class="bgt-x" :aria-label="$t('Close')" @click="close(t)">
          <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none" /></svg>
        </button>
      </div>
    </transition-group>
  </div>
</template>

<script>
import { bg, dismissTask } from '@/Core/backgroundTasks';

// The toast of Background Tasks: fixed at the top right, over every page, so the person can move around while a report is being made. A running task shows a
// moving bar and how long it has been going; when it ends the same card says which task is ready (with its Download button) or why it failed.
export default {
  data() { return { state: bg, now: Date.now(), tick: null, autoClose: {} }; },
  computed: {
    cards() { return this.state.on ? this.state.tasks : []; },
  },
  watch: {
    // a finished card closes itself after 30 seconds (a failed one stays until it is read and closed)
    cards: {
      deep: true,
      handler(list) {
        list.forEach(t => {
          if (t.status === 'done' && !this.autoClose[t.id]) this.autoClose[t.id] = setTimeout(() => { this.close(t); }, 30000);
        });
      },
    },
  },
  mounted() { this.tick = setInterval(() => { this.now = Date.now(); }, 1000); },
  beforeUnmount() { clearInterval(this.tick); Object.values(this.autoClose).forEach(clearTimeout); },
  methods: {
    seconds(t) { return Math.max(0, Math.floor((this.now - new Date(t.started_at).getTime()) / 1000)); },
    elapsed(t) { const s = this.seconds(t); return s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + String(s % 60).padStart(2, '0') + ' s'; },
    title(t) {
      if (t.status === 'done') return this.$t(':label is ready', { label: t.label });
      if (t.status === 'failed') return this.$t(':label could not be made', { label: t.label });
      return this.$t('Preparing :label', { label: t.label });
    },
    sub(t) {
      if (t.status === 'failed') return t.error || this.$t('Something went wrong. Try again.');
      if (t.status === 'done') return t.file_size ? this.$t('Finished. :size', { size: this.size(t.file_size) }) : this.$t('Finished.');
      return (t.message || this.$t('Working')) + ' · ' + this.elapsed(t) + ' · ' + this.$t('you can keep working');
    },
    size(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; },
    close(t) { clearTimeout(this.autoClose[t.id]); delete this.autoClose[t.id]; dismissTask(t.id); },
    downloadUrl(t) { return window.route('background-tasks.download', t.id); },
    afterDownload() { /* the card stays so the person can download again; it closes by itself after 30 seconds */ },
  },
};
</script>

<style scoped>
.bgt-host { position: fixed; top: 76px; right: 16px; z-index: 999994; width: min(390px, calc(100vw - 32px)); pointer-events: none; }
.bgt-stack { display: grid; gap: 10px; }
.bgt-card { pointer-events: auto; position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; background: rgba(255, 255, 255, 0.92); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border: 1px solid rgba(15, 23, 42, 0.08); box-shadow: 0 14px 34px -14px rgba(15, 23, 42, 0.45); overflow: hidden; --c: #2563eb; }
.bgt-card.is-done { --c: #16a34a; } .bgt-card.is-failed { --c: #dc2626; }
.bgt-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c); }
.bgt-badge { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: var(--c); }
.bgt-badge svg { width: 20px; height: 20px; }
.bgt-spin { width: 18px; height: 18px; border-radius: 50%; border: 3px solid rgba(255, 255, 255, 0.4); border-top-color: #fff; animation: bgt-rot 0.8s linear infinite; }
.bgt-tick path { stroke-dasharray: 24; stroke-dashoffset: 24; animation: bgt-draw 0.45s 0.05s ease-out forwards; }
.bgt-body { min-width: 0; display: grid; gap: 2px; flex: 1; }
.bgt-title { font-size: 0.86rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bgt-sub { font-size: 0.74rem; color: #475569; line-height: 1.35; overflow-wrap: anywhere; }
.bgt-bar { position: relative; height: 4px; margin-top: 6px; border-radius: 99px; background: rgba(37, 99, 235, 0.16); overflow: hidden; }
.bgt-bar i { position: absolute; top: 0; bottom: 0; width: 38%; border-radius: 99px; background: linear-gradient(90deg, #60a5fa, #2563eb); animation: bgt-slide 1.3s ease-in-out infinite; }
.bgt-go { flex: none; padding: 7px 12px; border-radius: 10px; background: #16a34a; color: #fff; font-size: 0.78rem; font-weight: 800; text-decoration: none; box-shadow: 0 8px 16px -8px rgba(22, 163, 74, 0.7); }
.bgt-go:hover { background: #15803d; }
.bgt-x { flex: none; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 99px; color: #64748b; }
.bgt-x:hover { background: rgba(100, 116, 139, 0.16); }
.bgt-enter-active, .bgt-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.bgt-enter-from, .bgt-leave-to { opacity: 0; transform: translateY(-10px) scale(0.97); }
@keyframes bgt-rot { to { transform: rotate(360deg); } }
@keyframes bgt-slide { 0% { left: -40%; } 100% { left: 102%; } }
@keyframes bgt-draw { to { stroke-dashoffset: 0; } }
@media (max-width: 700px) { .bgt-host { top: 132px; right: 16px; left: 16px; width: auto; } }
@media (prefers-reduced-motion: reduce) { .bgt-spin { animation-duration: 2.4s; } .bgt-bar i { animation: none; left: 0; width: 100%; opacity: 0.6; } .bgt-tick path { animation: none; stroke-dashoffset: 0; } .bgt-enter-active, .bgt-leave-active { transition: none; } }
html.dark .bgt-card { background: rgba(15, 23, 42, 0.92); border-color: rgba(255, 255, 255, 0.1); }
html.dark .bgt-title { color: #f1f5f9; } html.dark .bgt-sub { color: #94a3b8; }
</style>
