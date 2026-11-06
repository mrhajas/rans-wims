<template>
  <modal :show="show" max-width="3xl" :closeable="true" @close="$emit('close')">
    <div class="rv" :style="{ '--tone': lk.tone, '--tone2': lk.tone2 }">
      <!-- who this record belongs to, first thing on the page -->
      <div v-if="whoShown" class="rv-who" :class="{ 'is-none': !user }">
        <i class="rv-av" :style="{ '--h': hue }">{{ user ? initials : '-' }}</i>
        <span class="rv-who-t"><small>{{ whoLabel }}</small><b>{{ user ? user.name : $t('No user (done by the system)') }}</b></span>
        <span v-if="user && user.role" class="rv-role">{{ user.role }}</span>
        <span v-if="restored" class="rv-back"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Put back in the live log') }}</span>
      </div>
      <header class="rv-head">
        <span class="rv-ico"><icons :name="lk.icon" class="w-5 h-5" /></span>
        <div class="rv-title">
          <small>{{ label }}</small>
          <b>#{{ recordId }}<span v-if="when" class="rv-when">{{ when }}</span></b>
          <span v-if="summary" class="rv-sum">{{ summary }}</span>
        </div>
        <div class="rv-tools">
          <button type="button" class="rv-nav lg-tip" :data-tip="$t('Previous record')" :aria-label="$t('Previous record')" :disabled="!hasPrev" @click="$emit('nav', -1)"><icons name="arrow-left" class="w-4 h-4" /></button>
          <button type="button" class="rv-nav lg-tip" :data-tip="$t('Next record')" :aria-label="$t('Next record')" :disabled="!hasNext" @click="$emit('nav', 1)"><icons name="arrow-left" class="w-4 h-4 rv-flip" /></button>
          <button v-if="canRestore && !restored" type="button" class="rv-btn is-restore lg-tip" :data-tip="$t('Put back into the live log')" :aria-label="$t('Put back into the live log')" :disabled="!hasRow" @click="$emit('restore')"><icons name="refresh" class="w-4 h-4" /></button>
          <button type="button" class="rv-btn lg-tip" :class="{ 'is-done': copied }" :data-tip="copied ? $t('Copied') : $t('Copy the record')" :aria-label="$t('Copy the record')" :disabled="!hasRow" @click="copyJson"><icons :name="copied ? 'tick' : 'duplicate'" class="w-4 h-4" /></button>
          <button type="button" class="rv-btn lg-tip" :data-tip="$t('Download as a file')" :aria-label="$t('Download as a file')" :disabled="!hasRow" @click="saveJson"><icons name="download" class="w-4 h-4" /></button>
          <button type="button" class="rv-btn is-close" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-4 h-4" /></button>
        </div>
      </header>

      <div v-if="loading" class="rv-skel" aria-busy="true"><i></i><i></i><i></i><i></i></div>
      <p v-else-if="error" class="rv-err"><icons name="exclamation" class="w-5 h-5" />{{ error }}</p>
      <template v-else-if="hasRow">
        <nav class="rv-tabs" role="tablist">
          <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="rv-tab" :class="{ 'is-on': tab === t.key }" :aria-selected="tab === t.key" @click="tab = t.key"><icons :name="t.icon" class="w-3.5 h-3.5" />{{ t.label }}</button>
          <span v-if="total > 1" class="rv-pos">{{ position }} / {{ total }}</span>
        </nav>

        <!-- the important facts first, then the content -->
        <div v-if="tab === 'overview'" class="rv-body" :key="'o' + recordId">
          <div v-if="facts.length" class="rv-facts">
            <div v-for="(f, i) in facts" :key="f.key" class="rv-fact" :style="{ '--i': i }">
              <small>{{ f.label }}</small>
              <span v-if="f.kind === 'chip'" class="rv-chip" :class="'is-' + f.tone">{{ f.text }}</span>
              <b v-else :title="f.raw">{{ f.text }}</b>
            </div>
          </div>
          <section v-for="(b, i) in blocks" :key="b.key" class="rv-block" :style="{ '--i': i + facts.length }">
            <header><span>{{ b.label }}</span><button type="button" class="rv-mini" @click="copyText(b.raw, b.key)"><icons :name="copiedKey === b.key ? 'tick' : 'duplicate'" class="w-3 h-3" />{{ copiedKey === b.key ? $t('Copied') : $t('Copy') }}</button></header>
            <iframe v-if="b.kind === 'html'" class="rv-mail" sandbox="" :srcdoc="mailDoc(b.raw)" :title="b.label"></iframe>
            <pre v-else-if="b.kind === 'json'" class="rv-json" v-html="highlight(b.pretty)"></pre>
            <pre v-else class="rv-text">{{ b.raw }}</pre>
          </section>
          <p v-if="!facts.length && !blocks.length" class="rv-none">{{ $t('This record has nothing more to show. See All fields.') }}</p>
        </div>

        <!-- every column, empty ones faint -->
        <div v-else-if="tab === 'fields'" class="rv-body" :key="'f' + recordId">
          <div class="rv-grid">
            <div v-for="(f, i) in allFields" :key="f.key" class="rv-cell" :class="{ 'is-empty': f.empty, 'is-wide': f.wide }" :style="{ '--i': Math.min(i, 14) }" @click="!f.empty && copyText(f.raw, f.key)">
              <small>{{ f.label }}<icons v-if="!f.empty" :name="copiedKey === f.key ? 'tick' : 'duplicate'" class="w-3 h-3 rv-cp" /></small>
              <span v-if="f.empty" class="rv-null">-</span>
              <code v-else-if="f.wide">{{ f.short }}</code>
              <b v-else :title="f.raw">{{ f.text }}</b>
            </div>
          </div>
        </div>

        <!-- the record exactly as stored -->
        <div v-else class="rv-body" :key="'j' + recordId"><pre class="rv-json rv-raw" v-html="highlight(rawJson)"></pre></div>
      </template>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import { logLook } from '@/Core/wimsLogLook';

// One archived record, read from the archive: the facts that matter first (status, who, when), then the content (message, e-mail, JSON), then every column and the raw record.
// Used for every log; which columns count as "the facts" depends on the log.
const FACTS = {
  weather: ['kind', 'status', 'provider', 'location_key', 'fetched_at'],
  delivery: ['channel', 'status', 'event_key', 'address', 'subject', 'sent_at', 'delivered_at', 'retry_count', 'error_message'],
  downloads: ['action_type', 'module', 'user_name', 'role', 'file_name', 'route_name', 'performed_at'],
  alerts: ['type', 'read_at', 'created_at'],
  system: ['log_name', 'event', 'description', 'subject_type', 'subject_id', 'causer_id', 'created_at'],
  slow: ['kind', 'method', 'path', 'duration_ms', 'queries', 'memory_mb', 'created_at'],
};
const CHIPS = ['status', 'channel', 'kind', 'event', 'action_type', 'type', 'method', 'log_name'];
const GOOD = ['sent', 'delivered', 'ok', 'done', 'success', 'created', 'download'];
const BAD = ['failed', 'error', 'critical', 'deleted', 'rejected'];
const WARN = ['pending', 'retrying', 'queued', 'waiting', 'updated', 'print', 'stale'];

export default {
  components: { Modal },
  props: { show: Boolean, source: String, label: String, recordId: [String, Number], summary: String, user: { type: Object, default: null }, restored: Boolean, canRestore: Boolean, row: { type: Object, default: () => ({}) }, loading: Boolean, error: String, hasPrev: Boolean, hasNext: Boolean, position: Number, total: Number },
  emits: ['close', 'nav', 'restore'],
  data() { return { tab: 'overview', copied: false, copiedKey: '' }; },
  computed: {
    lk() { return logLook(this.source); },
    // a log that has no users at all (weather) shows no band
    whoShown() { return this.source !== 'weather' && this.hasRow; },
    whoLabel() { return ({ delivery: this.$t('Sent to'), alerts: this.$t('Alert for'), system: this.$t('Done by'), downloads: this.$t('Done by'), slow: this.$t('Seen by') })[this.source] || this.$t('User'); },
    initials() { const w = String(this.user ? this.user.name : '?').trim().split(/\s+/); return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase(); },
    hue() { let h = 0; String(this.user ? this.user.name : '').split('').forEach(c => { h = (h * 31 + c.charCodeAt(0)) % 360; }); return h; },
    hasRow() { return this.row && Object.keys(this.row).length > 0; },
    tabs() { return [{ key: 'overview', label: this.$t('Overview'), icon: 'info' }, { key: 'fields', label: this.$t('All fields'), icon: 'list' }, { key: 'json', label: this.$t('Raw record'), icon: 'terminal' }]; },
    when() { const k = ['created_at', 'performed_at', 'fetched_at'].find(x => this.row && this.row[x]); return k ? this.nice(this.row[k]) : ''; },
    rawJson() { return JSON.stringify(this.row, null, 2); },
    entries() { return Object.entries(this.row || {}).map(([key, v]) => this.describe(key, v)); },
    // the columns that tell the story, in the order set for this log
    facts() {
      const order = FACTS[this.source] || [];
      return order.map(k => this.entries.find(e => e.key === k)).filter(e => e && !e.empty && e.kind !== 'block').map(e => ({ ...e, tone: this.chipTone(e) }));
    },
    // long text, JSON and e-mail bodies
    blocks() {
      const taken = new Set(this.facts.map(f => f.key));
      const list = this.entries.filter(e => !e.empty && e.kind === 'block' && !taken.has(e.key));
      // a notification keeps its title and message inside `data`: shown plainly
      return list.sort((a, b) => (a.block === 'html') - (b.block === 'html')).map(e => ({ ...e, kind: e.block }));
    },
    allFields() { return this.entries.map(e => ({ ...e, wide: e.kind === 'block' || (e.raw || '').length > 60, short: (e.raw || '').length > 400 ? e.raw.slice(0, 400) + '...' : e.raw })); },
  },
  watch: {
    recordId() { this.tab = 'overview'; },
    show(v) { if (v) { this.tab = 'overview'; window.addEventListener('keydown', this.onKey); } else window.removeEventListener('keydown', this.onKey); },
  },
  beforeUnmount() { window.removeEventListener('keydown', this.onKey); },
  methods: {
    onKey(e) {
      if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
      if (e.key === 'ArrowLeft' && this.hasPrev) this.$emit('nav', -1);
      if (e.key === 'ArrowRight' && this.hasNext) this.$emit('nav', 1);
    },
    humanize(k) { return k === 'id' ? 'ID' : k.replace(/_/g, ' ').replace(/\b\w/, c => c.toUpperCase()); },
    nice(v) {
      const d = new Date(String(v).replace(' ', 'T'));
      return isNaN(d) ? String(v) : d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    },
    // what a column holds: nothing, a date, a number, short text, or a block (long text, JSON, e-mail)
    describe(key, v) {
      const label = this.humanize(key);
      if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length) || (typeof v === 'object' && !Array.isArray(v) && !Object.keys(v).length) || v === '[]' || v === '{}') return { key, label, empty: true, raw: '', text: '-', kind: 'plain' };
      let raw = typeof v === 'object' ? JSON.stringify(v) : String(v);
      let parsed = null;
      if (typeof v === 'object') parsed = v;
      else if (/^\s*[\[{]/.test(raw)) { try { parsed = JSON.parse(raw); } catch (e) { parsed = null; } }
      if (parsed && typeof parsed === 'object') {
        if (!Object.keys(parsed).length) return { key, label, empty: true, raw: '', text: '-', kind: 'plain' };
        return { key, label, raw: JSON.stringify(parsed, null, 2), pretty: JSON.stringify(parsed, null, 2), kind: 'block', block: 'json', text: '' };
      }
      if (/<(html|table|div|p|body|span|br|a|h\d)[\s>\/]/i.test(raw) && raw.length > 40) return { key, label, raw, kind: 'block', block: 'html', text: '' };
      if (raw.length > 160 || raw.includes('\n')) return { key, label, raw, kind: 'block', block: 'text', text: '' };
      if (/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d/.test(raw)) return { key, label, raw, text: this.nice(raw), kind: 'plain' };
      if (CHIPS.includes(key)) return { key, label, raw, text: raw, kind: 'chip' };
      return { key, label, raw, text: raw, kind: 'plain' };
    },
    chipTone(e) {
      const t = String(e.raw).toLowerCase();
      return GOOD.includes(t) ? 'good' : BAD.includes(t) ? 'bad' : WARN.includes(t) ? 'warn' : 'plain';
    },
    mailDoc(html) { return '<base target="_blank"><style>body{font-family:system-ui,sans-serif;font-size:14px;color:#1e293b;margin:12px}</style>' + html; },
    // JSON with coloured keys and values: the text is escaped first, so nothing in a record can run as a page
    highlight(text) {
      const esc = String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return esc.replace(/("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g, (m, str, colon, lit, num) => {
        if (str) return colon ? '<i class="j-k">' + str + '</i>' + colon : '<i class="j-s">' + str + '</i>';
        if (lit) return '<i class="j-l">' + lit + '</i>';
        return '<i class="j-n">' + num + '</i>';
      });
    },
    flash(done) { done(); },
    write(text, then) {
      const old = () => { const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { if (document.execCommand('copy')) then(); } catch (e) { /* not copied */ } t.remove(); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(then).catch(old); else old();
    },
    copyJson() { this.write(this.rawJson, () => { this.copied = true; clearTimeout(this._t1); this._t1 = setTimeout(() => { this.copied = false; }, 1600); }); },
    copyText(text, key) { this.write(String(text), () => { this.copiedKey = key; clearTimeout(this._t2); this._t2 = setTimeout(() => { this.copiedKey = ''; }, 1400); }); },
    saveJson() {
      const blob = new Blob([this.rawJson], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'wims-log-' + this.source + '-' + this.recordId + '.json';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    },
  },
};
</script>

<style scoped>
.rv { max-height: 84vh; overflow: auto; background: var(--lg-card, #fff); animation: rv-in 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
@keyframes rv-in { from { opacity: 0; transform: translateY(10px); } }
.rv-head { position: sticky; top: 0; z-index: 3; display: flex; align-items: flex-start; gap: 0.8rem; padding: 1rem 1.2rem; background: linear-gradient(135deg, color-mix(in srgb, var(--tone) 14%, var(--lg-card, #fff)), var(--lg-card, #fff)); border-bottom: 1px solid color-mix(in srgb, var(--tone) 22%, transparent); }
.rv-ico { flex: none; width: 2.7rem; height: 2.7rem; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 10px 18px -10px var(--tone); animation: rv-pop 0.5s cubic-bezier(0.3, 1.6, 0.5, 1) both; }
@keyframes rv-pop { from { transform: scale(0.5) rotate(-12deg); opacity: 0; } }
.rv-title { display: grid; gap: 0.1rem; min-width: 0; flex: 1; } .rv-title small { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tone); }
.rv-title b { font-size: 1.15rem; font-weight: 900; display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap; } .rv-when { font-size: 0.78rem; font-weight: 600; color: #64748b; }
.rv-sum { font-size: 0.8rem; color: #64748b; overflow-wrap: anywhere; line-height: 1.35; }
.rv-tools { display: flex; align-items: center; gap: 0.35rem; flex: none; }
.rv-nav, .rv-btn { width: 2.1rem; height: 2.1rem; display: grid; place-items: center; border: 0; border-radius: 0.7rem; cursor: pointer; color: color-mix(in srgb, var(--tone) 80%, #0f172a); background: color-mix(in srgb, var(--tone) 12%, transparent); transition: transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), background 0.2s ease, color 0.2s ease; }
.rv-nav:hover:not(:disabled), .rv-btn:hover:not(:disabled) { color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); transform: translateY(-2px) scale(1.08); } .rv-nav:active, .rv-btn:active { transform: scale(0.92); } .rv-nav:disabled, .rv-btn:disabled { opacity: 0.35; cursor: default; }
.rv-flip { transform: rotate(180deg); } .rv-btn.is-done { color: #fff; background: #059669; } .rv-btn.is-close { color: #be123c; background: rgba(225, 29, 72, 0.12); margin-left: 0.2rem; } .rv-btn.is-close:hover { background: #e11d48; color: #fff; }
.rv-tabs { display: flex; align-items: center; gap: 0.4rem; padding: 0.7rem 1.2rem 0; flex-wrap: wrap; }
.rv-tab { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.85rem; border: 0; border-radius: 999px; font-size: 0.78rem; font-weight: 800; cursor: pointer; color: #64748b; background: rgba(148, 163, 184, 0.14); transition: all 0.2s ease; } .rv-tab:hover { color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, transparent); }
.rv-tab.is-on { color: #fff; background: linear-gradient(135deg, var(--tone), var(--tone2)); box-shadow: 0 8px 14px -8px var(--tone); } .rv-pos { margin-left: auto; font-size: 0.72rem; font-weight: 800; color: #64748b; font-variant-numeric: tabular-nums; }
.rv-body { padding: 0.9rem 1.2rem 1.3rem; display: grid; gap: 0.8rem; }
.rv-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.55rem; }
.rv-fact { display: grid; gap: 0.2rem; align-content: start; padding: 0.6rem 0.8rem; border-radius: 0.95rem; background: color-mix(in srgb, var(--tone) 7%, transparent); border: 1px solid color-mix(in srgb, var(--tone) 16%, transparent); animation: rv-up 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(var(--i, 0) * 40ms); min-width: 0; }
.rv-fact small, .rv-cell small { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; } .rv-fact b { font-size: 0.88rem; overflow-wrap: anywhere; line-height: 1.3; }
@keyframes rv-up { from { opacity: 0; transform: translateY(8px); } }
.rv-chip { justify-self: start; padding: 0.15rem 0.65rem; border-radius: 999px; font-size: 0.76rem; font-weight: 800; color: color-mix(in srgb, var(--tone) 85%, #0f172a); background: color-mix(in srgb, var(--tone) 16%, transparent); }
.rv-chip.is-good { color: #047857; background: rgba(5, 150, 105, 0.16); } .rv-chip.is-bad { color: #be123c; background: rgba(225, 29, 72, 0.14); } .rv-chip.is-warn { color: #b45309; background: rgba(245, 158, 11, 0.18); }
.rv-block { border-radius: 1rem; border: 1px solid color-mix(in srgb, var(--tone) 18%, transparent); overflow: hidden; animation: rv-up 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(var(--i, 0) * 40ms); }
.rv-block > header { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.45rem 0.8rem; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tone); background: color-mix(in srgb, var(--tone) 9%, transparent); }
.rv-mini { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border: 0; border-radius: 999px; font-size: 0.68rem; font-weight: 800; text-transform: none; letter-spacing: 0; cursor: pointer; color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); } .rv-mini:hover { color: #fff; background: var(--tone); }
.rv-text, .rv-json { margin: 0; padding: 0.8rem 1rem; max-height: 18rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; line-height: 1.55; background: rgba(148, 163, 184, 0.07); }
.rv-raw { max-height: 60vh; border-radius: 1rem; border: 1px solid color-mix(in srgb, var(--tone) 18%, transparent); }
.rv-json :deep(.j-k) { font-style: normal; color: #4338ca; font-weight: 700; } .rv-json :deep(.j-s) { font-style: normal; color: #047857; } .rv-json :deep(.j-n) { font-style: normal; color: #b45309; } .rv-json :deep(.j-l) { font-style: normal; color: #be185d; font-weight: 700; }
html.dark .rv-json :deep(.j-k) { color: #a5b4fc; } html.dark .rv-json :deep(.j-s) { color: #6ee7b7; } html.dark .rv-json :deep(.j-n) { color: #fcd34d; } html.dark .rv-json :deep(.j-l) { color: #f9a8d4; }
.rv-mail { display: block; width: 100%; height: 22rem; border: 0; background: #fff; }
.rv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
.rv-cell { display: grid; gap: 0.2rem; padding: 0.55rem 0.8rem; border-radius: 0.9rem; background: rgba(148, 163, 184, 0.09); cursor: copy; min-width: 0; animation: rv-up 0.35s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(var(--i, 0) * 25ms); transition: background 0.2s ease, transform 0.2s ease; }
.rv-cell:hover:not(.is-empty) { background: color-mix(in srgb, var(--tone) 10%, transparent); transform: translateY(-1px); } .rv-cell.is-empty { opacity: 0.55; cursor: default; } .rv-cell.is-wide { grid-column: 1 / -1; }
.rv-cell b { font-size: 0.86rem; overflow-wrap: anywhere; } .rv-cell code { font-size: 0.74rem; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 7rem; overflow: auto; display: block; } .rv-null { color: #94a3b8; font-weight: 700; } .rv-cp { margin-left: 0.3rem; opacity: 0; transition: opacity 0.2s ease; vertical-align: -1px; } .rv-cell:hover .rv-cp { opacity: 0.8; }
.rv-skel { display: grid; gap: 0.6rem; padding: 1.2rem; } .rv-skel i { display: block; height: 3rem; border-radius: 0.9rem; background: linear-gradient(90deg, rgba(148, 163, 184, 0.16) 25%, rgba(148, 163, 184, 0.3) 50%, rgba(148, 163, 184, 0.16) 75%); background-size: 200% 100%; animation: rv-shimmer 1.2s linear infinite; } @keyframes rv-shimmer { to { background-position: -200% 0; } }
.rv-err { display: flex; align-items: center; gap: 0.5rem; margin: 1.2rem; font-weight: 700; color: #dc2626; } .rv-none { margin: 0; padding: 1rem 0; color: #64748b; font-weight: 600; }
@media (max-width: 640px) { .rv { max-height: 90vh; } .rv-head { flex-wrap: wrap; padding: 0.8rem; } .rv-ico { display: none; } .rv-tools { width: 100%; justify-content: flex-end; } .rv-tabs, .rv-body { padding-left: 0.8rem; padding-right: 0.8rem; } .rv-grid { grid-template-columns: 1fr; } .rv-facts { grid-template-columns: 1fr 1fr; } .rv-mail { height: 16rem; } }
@media (prefers-reduced-motion: reduce) { .rv, .rv-ico, .rv-fact, .rv-block, .rv-cell, .rv-skel i { animation: none; } .rv-nav, .rv-btn, .rv-cell, .rv-tab { transition: none; } }

/* ---- the user band, and more room ---- */
.rv-who { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; padding: 0.85rem 1.4rem; background: linear-gradient(90deg, color-mix(in srgb, var(--tone) 18%, var(--lg-card, #fff)), color-mix(in srgb, var(--tone2) 8%, var(--lg-card, #fff))); border-bottom: 1px solid color-mix(in srgb, var(--tone) 18%, transparent); animation: rv-up 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; }
.rv-av { flex: none; width: 2.6rem; height: 2.6rem; display: grid; place-items: center; border-radius: 50%; font-style: normal; font-size: 0.9rem; font-weight: 900; color: hsl(var(--h) 55% 30%); background: linear-gradient(135deg, hsl(var(--h) 80% 90%), hsl(calc(var(--h) + 40) 80% 80%)); box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.8), 0 8px 14px -8px rgba(15, 23, 42, 0.5); } html.dark .rv-av { color: hsl(var(--h) 80% 88%); background: linear-gradient(135deg, hsl(var(--h) 45% 28%), hsl(calc(var(--h) + 40) 45% 20%)); box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.08); }
.rv-who.is-none .rv-av { color: #94a3b8; background: rgba(148, 163, 184, 0.2); box-shadow: none; }
.rv-who-t { display: grid; gap: 0.05rem; min-width: 0; flex: 1; } .rv-who-t small { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; } .rv-who-t b { font-size: 1.05rem; font-weight: 900; overflow-wrap: anywhere; } .rv-who.is-none .rv-who-t b { color: #64748b; font-weight: 700; font-size: 0.9rem; }
.rv-role { padding: 0.2rem 0.75rem; border-radius: 999px; font-size: 0.74rem; font-weight: 800; color: color-mix(in srgb, var(--tone) 85%, #0f172a); background: color-mix(in srgb, var(--tone) 14%, transparent); }
.rv-back { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.7rem; border-radius: 999px; font-size: 0.74rem; font-weight: 800; color: #047857; background: rgba(5, 150, 105, 0.16); }
.rv-btn.is-restore { color: #0f766e; background: rgba(15, 118, 110, 0.14); } .rv-btn.is-restore:hover:not(:disabled) { color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); transform: rotate(120deg) scale(1.1); }
.rv-head { padding: 1.1rem 1.4rem; gap: 1rem; } .rv-tabs { padding: 0.9rem 1.4rem 0; gap: 0.5rem; } .rv-body { padding: 1.1rem 1.4rem 1.6rem; gap: 1rem; }
.rv-facts { grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: 0.75rem; } .rv-fact { padding: 0.8rem 1rem; gap: 0.3rem; border-radius: 1.1rem; }
.rv-grid { gap: 0.7rem; } .rv-cell { padding: 0.75rem 1rem; gap: 0.3rem; border-radius: 1rem; } .rv-block > header { padding: 0.6rem 1rem; } .rv-text, .rv-json { padding: 1rem 1.2rem; line-height: 1.65; }
@media (max-width: 640px) { .rv-who { padding: 0.7rem 0.8rem; } .rv-head { padding: 0.8rem; } .rv-tabs, .rv-body { padding-left: 0.8rem; padding-right: 0.8rem; } .rv-facts { grid-template-columns: 1fr; } }

/* the round tool buttons: true circles, icon dead centre, whatever the page-wide button rules say */
.rv-nav, .rv-btn { box-sizing: border-box; width: 2.1rem !important; height: 2.1rem !important; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; margin: 0 !important; aspect-ratio: 1 / 1; border-radius: 50% !important; display: grid !important; place-items: center; line-height: 1; flex: none; }
.rv-nav svg, .rv-btn svg, .rv-nav > div, .rv-btn > div { display: block; margin: 0 !important; }
.rv-btn.is-restore:hover:not(:disabled) { transform: scale(1.12); }
.rv-mini, .rv-tab { min-height: 0 !important; line-height: 1; }
</style>
