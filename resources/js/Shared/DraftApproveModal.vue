<template>
  <div v-if="state.visible" class="dam-overlay" role="dialog" aria-modal="true" aria-labelledby="dam-title" @keydown.esc="answer(false)">
    <div class="dam-backdrop" @click="answer(false)"></div>
    <div ref="card" class="dam-card" tabindex="-1">
      <div class="dam-panel">
        <div class="dam-head">
          <span class="dam-icon" :class="{ 'is-blocked': blocked }"><icons :name="blocked ? 'exclamation' : state.plain ? 'tick' : 'edit'" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 id="dam-title" class="dam-title">{{ state.title || (blocked ? $t('This draft cannot be finalised yet') : state.plain ? $t('Approve this record?') : $t('This record is still a draft')) }}</h3>
            <p v-if="state.record" class="dam-record">{{ state.record }}</p>
          </div>
        </div>

        <p v-if="state.intro || !state.plain" class="dam-lead">{{ state.intro || (state.plain ? '' : $t('A draft is not finished yet, so it cannot be approved as it is.')) }}</p>

        <!-- where the record is now and where it will be: the draft step is crossed, then the approval follows -->
        <ul v-if="blocked" class="dam-problems">
          <li v-for="(p, i) in state.problems" :key="i">
            <span v-if="p.row" class="dam-row">{{ $t('Item') }} {{ p.row }}</span>
            <span class="dam-msg"><strong v-if="p.item && !(p.message || '').startsWith(p.item)">{{ p.item }}</strong> {{ p.message }}</span>
          </li>
        </ul>

        <ol v-if="!blocked && !state.plain" class="dam-flow" aria-hidden="true">
          <li v-for="(step, i) in stages" :key="step + i" :class="['dam-node', i === 0 ? 'is-now' : i === stages.length - 1 ? 'is-goal' : 'is-mid']">
            <span class="dam-dot"><icons v-if="i === stages.length - 1" name="tick" class="w-3 h-3" /><template v-else-if="i === 0">&bull;</template><template v-else>{{ i + 1 }}</template></span>
            <span class="dam-label">{{ step }}</span>
          </li>
        </ol>

        <ul v-if="!blocked && state.effects && state.effects.length" class="dam-effects">
          <li v-for="line in state.effects" :key="line"><icons name="tick" class="w-3.5 h-3.5 flex-none" /><span>{{ line }}</span></li>
        </ul>

        <remark-combobox v-if="!blocked && state.remarkOptions" v-model="state.remark" class="dam-remark" :options="state.remarkOptions" :label="$t('Approval remark')" :placeholder="$t('Search a remark or write your own')" id="dam-remark" />

        <p class="dam-question">{{ state.question || (blocked ? $t('Open it and correct the items above?') : state.plain ? $t('Approve it now?') : $t('Remove the draft status and approve it now?')) }}</p>

        <div class="dam-actions">
          <button type="button" class="dam-no" @click="answer(false)">{{ state.cancelText || (blocked ? $t('Not now') : $t('No, keep as draft')) }}</button>
          <button ref="yes" type="button" class="dam-yes" :class="{ 'is-fix': blocked }" @click="answer(true)"><icons :name="blocked ? 'edit' : 'tick'" class="w-4 h-4" />{{ state.confirmText || (blocked ? $t('Open and fix') : $t('Yes, approve')) }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';
import RemarkCombobox from '@/Shared/RemarkCombobox.vue';
import { draftApproveState, resolveDraftApprove } from '@/Core/draftApprove';

// The prompt an approver gets when they try to approve a record that is still a draft. Nothing is changed until they answer "Yes": the page then
// asks the server to take the draft status off and approve in one step (each module decides what that means, so each page passes its own wording).
export default {
  components: { Icons, RemarkCombobox },
  data() { return { state: draftApproveState }; },
  computed: {
    blocked() { return !!(this.state.problems && this.state.problems.length); },
    stages() { return this.state.flow && this.state.flow.length >= 2 ? this.state.flow : [this.$t('Draft'), this.$t('Final'), this.$t('Approved')]; },
  },
  watch: {
    'state.visible'(v) { if (v) this.$nextTick(() => this.$refs.yes && this.$refs.yes.focus()); },
  },
  methods: {
    answer(v) { resolveDraftApprove(v); },
  },
};
</script>

<style scoped>
.dam-overlay { position: fixed; inset: 0; z-index: 999998; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.dam-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); }
.dam-card { position: relative; width: 100%; max-width: 28rem; max-height: 100%; overflow: auto; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45); animation: dam-rise 0.2s cubic-bezier(0.34, 1.4, 0.64, 1) both; outline: none; }
.dam-panel { padding: 1.5rem; background: linear-gradient(165deg, #ffffff, #fffbeb); }
.dam-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; }
.dam-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.6rem; height: 2.6rem; flex: none; border-radius: 0.8rem; color: #fff; background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 8px 16px -8px rgba(217, 119, 6, 0.6); animation: dam-pop 0.35s cubic-bezier(0.2, 1.4, 0.4, 1) both; }
.dam-title { font-weight: 800; font-size: 1.02rem; line-height: 1.2; color: #111827; }
.dam-record { margin-top: 2px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.02em; color: #b45309; }
.dam-lead { font-size: 0.86rem; line-height: 1.5; color: #4b5563; margin-bottom: 1rem; }

.dam-flow { display: flex; align-items: flex-start; justify-content: space-between; list-style: none; margin: 0 0 1rem; padding: 0.85rem 0.9rem; border-radius: 0.8rem; background: rgba(251, 191, 36, 0.1); position: relative; }
.dam-node { position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.35rem; text-align: center; }
.dam-node:not(:last-child)::after { content: ''; position: absolute; top: 0.8rem; left: calc(50% + 0.9rem); right: calc(-50% + 0.9rem); height: 2px; background: repeating-linear-gradient(90deg, #d97706 0 5px, transparent 5px 9px); opacity: 0.55; }
.dam-dot { display: inline-flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 999px; font-size: 0.78rem; font-weight: 800; border: 2px solid transparent; }
.dam-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; }
.is-now .dam-dot { background: #fff; border-color: #94a3b8; color: #64748b; }
.is-now .dam-label { color: #64748b; text-decoration: line-through; text-decoration-color: rgba(100, 116, 139, 0.55); }
.is-mid .dam-dot { background: #fff; border-color: #f59e0b; color: #b45309; }
.is-goal .dam-dot { background: #16a34a; border-color: #16a34a; color: #fff; animation: dam-glow 1.8s ease-in-out infinite; }
.is-goal .dam-label { color: #15803d; }

.dam-icon.is-blocked { background: linear-gradient(145deg, #f87171, #b91c1c); box-shadow: 0 8px 16px -8px rgba(185, 28, 28, 0.6); }
.dam-problems { list-style: none; margin: 0 0 1rem; padding: 0; display: grid; gap: 0.45rem; max-height: 14rem; overflow: auto; }
.dam-problems li { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.55rem 0.7rem; border-radius: 0.65rem; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.22); font-size: 0.8rem; line-height: 1.4; color: #7f1d1d; }
.dam-row { flex: none; padding: 1px 8px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; }
.dam-yes.is-fix { background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 8px 18px -8px rgba(217, 119, 6, 0.65); }
.dam-effects { list-style: none; margin: 0 0 1rem; padding: 0; display: grid; gap: 0.4rem; }
.dam-effects li { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.82rem; line-height: 1.4; color: #374151; }
.dam-effects li svg { margin-top: 2px; color: #16a34a; }

.dam-remark { margin-bottom: 1rem; }
.dam-question { font-size: 0.95rem; font-weight: 800; color: #111827; margin-bottom: 1.1rem; }
.dam-actions { display: flex; justify-content: flex-end; gap: 0.7rem; flex-wrap: wrap; }
.dam-no { padding: 0.6rem 1.15rem; border-radius: 0.65rem; border: 1px solid #d1d5db; background: #fff; color: #374151; font-size: 0.85rem; font-weight: 700; transition: background 0.15s, border-color 0.15s; }
.dam-no:hover { background: #f9fafb; border-color: #9ca3af; }
.dam-yes { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.6rem 1.25rem; border-radius: 0.65rem; border: 0; color: #fff; font-size: 0.85rem; font-weight: 800; background: linear-gradient(145deg, #22c55e, #15803d); box-shadow: 0 8px 18px -8px rgba(21, 128, 61, 0.65); transition: transform 0.15s, box-shadow 0.15s; }
.dam-yes:hover { transform: translateY(-1px); box-shadow: 0 12px 22px -10px rgba(21, 128, 61, 0.75); }

@keyframes dam-rise { from { opacity: 0; transform: translateY(8px) scale(0.96); } to { opacity: 1; transform: none; } }
@keyframes dam-pop { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes dam-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45); } 50% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); } }
@media (prefers-reduced-motion: reduce) { .dam-card, .dam-icon, .is-goal .dam-dot { animation: none; } }
@media (max-width: 480px) { .dam-panel { padding: 1.15rem; } .dam-actions > button { flex: 1 1 100%; justify-content: center; } }

html.dark .dam-panel { background: linear-gradient(165deg, #0f172a, #2a1f08); }
html.dark .dam-title, html.dark .dam-question { color: #f1f5f9; }
html.dark .dam-lead, html.dark .dam-effects li { color: #cbd5e1; }
html.dark .dam-problems li { color: #fecaca; background: rgba(239, 68, 68, 0.12); }
html.dark .dam-record { color: #fcd34d; }
html.dark .dam-flow { background: rgba(251, 191, 36, 0.08); }
html.dark .is-now .dam-dot, html.dark .is-mid .dam-dot { background: #0f172a; }
html.dark .dam-no { background: transparent; border-color: rgba(255, 255, 255, 0.18); color: #e2e8f0; }
html.dark .dam-no:hover { background: rgba(255, 255, 255, 0.06); }
</style>
