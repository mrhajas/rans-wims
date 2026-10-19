<template>
  <div class="apt" :class="'is-' + key">
    <span class="apt-stamp" :class="'is-' + key"><icons v-if="key === 'done'" name="tick" class="apt-tick" /><i v-else aria-hidden="true"></i>{{ label }}</span>

    <ol v-if="steps.length" class="apt-track" :aria-label="$t('Approval progress')">
      <li v-for="(ph, i) in steps" :key="ph.phase" class="apt-step" :class="[ph.done ? 'is-done' : 'is-wait', 'p' + ph.phase, { 'is-next': !ph.done && ph.phase === nextPhase }]" :title="ph.done ? detail(ph) : $t('Not given yet')">
        <span class="apt-node" aria-hidden="true">{{ ph.phase }}</span>
        <span v-if="i < steps.length - 1" class="apt-bar" :class="barClass(i)"></span>
        <div class="apt-body">
          <div class="apt-line">
            <small class="apt-cap">{{ $t('Phase :n', { n: ph.phase }) }}</small>
            <b v-if="ph.done" class="apt-who">{{ ph.user || '-' }}</b>
            <b v-else class="apt-who is-none">{{ ph.phase === nextPhase ? $t('Waiting for approval') : $t('Not yet') }}</b>
          </div>
          <span v-if="ph.done" class="apt-when">{{ when(ph.at) }}</span>
        </div>
      </li>
    </ol>

    <div v-else-if="by" class="apt-by">{{ by }}</div>
  </div>
</template>

<script>
// How a Super Adjustment (or any two-approval record) shows its progress in a list: the status as a stamp, and the approval phases as a small track,
// one numbered node per phase joined by a bar. Phase 1 is orange, phase 2 green; a phase not given yet is a dashed outline. Rows read the same on every list.
export default {
  props: {
    status: { type: String, default: '' },
    phases: { type: Array, default: () => [] },
    approver: { type: Object, default: null },
    approverRole: { type: String, default: '' },
    rejecter: { type: Object, default: null },
  },
  computed: {
    key() { return { Draft: 'pending', 'Awaiting Phase 2': 'await', Approved: 'done', Rejected: 'rejected' }[this.status] || 'pending'; },
    label() {
      const s = this.status;
      return s === 'Draft' ? this.$t('Pending approval') : s === 'Awaiting Phase 2' ? this.$t('Awaiting 2nd approval') : s === 'Approved' ? this.$t('Approved and Completed') : s === 'Rejected' ? this.$t('Rejected') : this.$t(s);
    },
    steps() { return this.status === 'Rejected' || !Array.isArray(this.phases) ? [] : this.phases; },
    nextPhase() { const n = this.steps.find(p => !p.done); return n ? n.phase : 0; },
    by() {
      if (this.status === 'Approved' && this.approver) return this.approver.name + (this.approverRole ? ' · ' + this.approverRole : '');
      if (this.status === 'Rejected' && this.rejecter) return this.rejecter.name;
      return '';
    },
  },
  methods: {
    when(at) { try { return at ? this.$datetime(at) : ''; } catch (e) { return String(at || ''); } },
    detail(ph) { return [ph.user, ph.role, this.when(ph.at), ph.note].filter(Boolean).join(' · '); },
    // the bar after a node: solid when that phase is given and the next one too, solid then dashed when only the first is given, dashed when none
    barClass(i) {
      const a = this.steps[i]; const b = this.steps[i + 1];
      return a.done && b && b.done ? 'is-full p' + a.phase : a.done ? 'is-half p' + a.phase : 'is-none';
    },
  },
};
</script>

<style scoped>
.apt { display: grid; gap: 9px; min-width: 0; --o: #ea7a12; --o-soft: #fde7cf; --g: #14966a; --g-soft: #d6f3e5; --ink: #1c2a44; --mute: #64748b; --line: #cbd5e1; }
html.dark .apt { --o: #f59a45; --o-soft: rgba(245, 154, 69, 0.18); --g: #34c58e; --g-soft: rgba(52, 197, 142, 0.16); --ink: #e8eef9; --mute: #9fb0c7; --line: #475569; }

/* the status: a stamp. Final approval is plain dark green text with a tick, no container */
.apt-stamp { display: inline-flex; align-items: center; gap: 8px; width: fit-content; max-width: 100%; padding: 3px 11px 3px 9px; border-radius: 7px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; line-height: 1.5; border: 1.5px solid currentColor; }
.apt-stamp i { flex: none; width: 7px; height: 7px; border-radius: 2px; background: currentColor; transform: rotate(45deg); }
.apt-stamp.is-pending { color: #b45309; background: rgba(245, 158, 11, 0.1); }
.apt-stamp.is-await { color: #0b6fa8; background: rgba(14, 140, 220, 0.1); }
.apt-stamp.is-rejected { color: #b91c1c; background: rgba(220, 38, 38, 0.08); }
.apt-stamp.is-done { color: #0b5d3b; background: transparent; border: 0; box-shadow: none; padding: 2px 0; font-size: 0.72rem; letter-spacing: 0.09em; }
.apt-stamp .apt-tick { flex: none; width: 15px; height: 15px; }
html.dark .apt-stamp.is-done { color: #4ade9a; } html.dark .apt-stamp.is-pending { color: #fbbf24; } html.dark .apt-stamp.is-await { color: #7dd3fc; } html.dark .apt-stamp.is-rejected { color: #fca5a5; }

/* the phases: a vertical timeline, one numbered node per phase joined by a rail, who and when beside each node (readable at any column width) */
.apt-track { display: grid; margin: 0; padding: 0; list-style: none; min-width: 0; }
.apt-step { position: relative; display: grid; grid-template-columns: 26px minmax(0, 1fr); gap: 0 10px; padding-bottom: 11px; min-width: 0; }
.apt-step:last-child { padding-bottom: 0; }
.apt-node { grid-row: 1 / span 1; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; font-size: 0.74rem; font-weight: 800; border: 2px dashed var(--line); color: var(--mute); background: transparent; z-index: 1; }
.apt-step.is-done.p1 .apt-node { background: var(--o); border: 2px solid var(--o); color: #fff; box-shadow: 0 0 0 4px var(--o-soft); }
.apt-step.is-done.p2 .apt-node { background: var(--g); border: 2px solid var(--g); color: #fff; box-shadow: 0 0 0 4px var(--g-soft); }
.apt-step.is-next .apt-node { border-color: var(--o); color: var(--o); animation: apt-pulse 2.2s ease-in-out infinite; }
.apt-bar { position: absolute; left: 11.5px; top: 29px; bottom: 3px; width: 3px; border-radius: 3px; }
.apt-bar.is-full { background: linear-gradient(180deg, var(--o), var(--g)); }
.apt-bar.is-half { background: linear-gradient(180deg, var(--o) 0 50%, transparent 50%), repeating-linear-gradient(180deg, var(--line) 0 5px, transparent 5px 9px); }
.apt-bar.is-none { background: repeating-linear-gradient(180deg, var(--line) 0 5px, transparent 5px 9px); }
.apt-body { min-width: 0; display: grid; gap: 1px; align-content: center; min-height: 26px; }
.apt-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 8px; min-width: 0; }
.apt-cap { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--mute); white-space: nowrap; }
.apt-step.is-done.p1 .apt-cap { color: var(--o); } .apt-step.is-done.p2 .apt-cap { color: var(--g); }
.apt-who { font-size: 0.8rem; font-weight: 700; line-height: 1.25; color: var(--ink); overflow-wrap: anywhere; min-width: 0; }
.apt-who.is-none { font-weight: 600; font-style: italic; color: var(--mute); }
.apt-when { font-size: 0.68rem; color: var(--mute); font-variant-numeric: tabular-nums; }
.apt-by { font-size: 0.74rem; font-weight: 600; color: var(--mute); }
@keyframes apt-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(234, 122, 18, 0.35); } 50% { box-shadow: 0 0 0 6px rgba(234, 122, 18, 0); } }
@media (prefers-reduced-motion: reduce) { .apt-step.is-next .apt-node { animation: none; } }
</style>
