<template>
  <admin-layout :title="$t('Opening Balance') + ' ' + a.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="folder" :title="$t('Opening Balance') + ' · ' + a.reference" :subtitle="a.contact + ' · ' + a.title">
        <Link :href="route('subcontracts.opening.index')" class="lg-btn lg-btn-soft"><icons name="arrow-left" class="w-4 h-4" /><span>{{ $t('Opening Balances') }}</span></Link>
        <Link :href="route('subcontracts.agreements.show', a.id)" class="lg-btn t-violet"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Agreement') }}</span></Link>
        <span class="lg-chip" :class="chip(a.status)">{{ $t(a.status) }}</span>
      </page-header>

      <div class="sc-figs op-figs">
        <div class="sc-fig"><small>{{ $t('Contract Value') }}</small><b class="amt-rev">{{ m(state.contract, a.currency) }}</b><em>{{ $t('now') }}</em></div>
        <div class="sc-fig"><small>{{ $t('Opening Paid') }}</small><b class="amt-paid">{{ m(state.opening_paid, a.currency) }}</b><em>{{ state.opening_rows > 1 ? state.opening_rows + ' ' + $t('imported lines') : $t('one opening line') }}</em></div>
        <div class="sc-fig"><small>{{ $t('Paid Since') }}</small><b class="amt-paid">{{ m(state.paid_live, a.currency) }}</b><em>{{ $t('through vouchers') }}</em></div>
        <div class="sc-fig is-warn"><small>{{ $t('Outstanding') }}</small><b :class="neg(state.outstanding) ? 'amt-neg' : 'amt-out'">{{ m(state.outstanding, a.currency) }}</b><em>{{ neg(state.outstanding) ? $t('Negative balance carried forward') : $t('contract minus paid') }}</em></div>
      </div>

      <div class="op-grid">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Opening Figures') }}</h2><p class="lg-card-sub">{{ $t('Where this account starts: the contract value and what has been paid up to the date below.') }}</p></div></header>
          <form class="op-form" @submit.prevent="save">
            <div class="ac-f"><label>{{ $t('Contract Value') }} ({{ a.currency }}) *</label><input v-model="form.contract" inputmode="decimal" :disabled="!can.manage" /><span class="ac-err">{{ err('contract') }}</span></div>
            <div class="ac-f"><label>{{ $t('Paid To Date') }} ({{ a.currency }}) *</label><input v-model="form.paid" inputmode="decimal" :disabled="!can.manage" /><span class="ac-err">{{ err('paid') }}</span></div>
            <div class="ac-f"><label>{{ $t('As At') }}</label><input v-model="form.as_at" type="date" :disabled="!can.manage" /><span class="ac-err">{{ err('as_at') }}</span></div>
            <div class="ac-f op-wide"><label>{{ $t('Note') }}</label><input v-model="form.note" maxlength="500" :placeholder="$t('Where the figures come from, for example the Excel sheet and its date')" :disabled="!can.manage" /></div>
            <div class="op-result op-wide" :class="{ 'is-neg': outNeg }">
              <icons :name="outNeg ? 'exclamation' : 'clock'" class="w-5 h-5" />
              <span>{{ $t('Outstanding after saving') }}: <b :class="outNeg ? 'amt-neg' : 'amt-out'">{{ m(outstanding, a.currency) }}</b><template v-if="outNeg"> · {{ $t('the account is paid above its contract: a negative balance carried forward') }}</template></span>
            </div>
            <div v-if="state.opening_rows > 1" class="lg-note lg-note-amber op-wide"><icons name="info" class="w-4 h-4" /><span>{{ $t('Saving replaces the :n imported payment lines of this account with one opening line.', { n: state.opening_rows }) }}</span></div>
            <div class="op-wide ac-actions"><button v-if="can.manage" type="submit" class="lg-btn lg-btn-green" :disabled="saving"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving...') : $t('Save Opening Balance') }}</button><span v-else class="lg-sub">{{ $t('This account is read-only.') }}</span></div>
          </form>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Previous Data') }}</h2><p class="lg-card-sub">{{ $t('Attach the Excel sheet (or PDF, pictures) with this account\'s history, so it is one click away. Open any file with Preview in a new tab.') }}</p></div><span class="lg-chip lg-chip-slate">{{ files.length }}</span></header>

          <form v-if="can.manage" class="op-attach" @submit.prevent="upload">
            <acct-dropzone v-model="up.attachments" :max-size="16" :max="10" accept=".xlsx,.xls,.csv,.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt" :label="$t('Drop the Excel file here or click to choose')" :hint="$t('Excel, CSV, PDF, pictures and Word files up to 16 MB each.')" />
            <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
            <div class="op-attach-row">
              <input v-model="up.note" class="op-note" maxlength="300" :placeholder="$t('A short note about these files (optional)')" />
              <button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ up.processing ? $t('Uploading...') : $t('Attach :n File(s)', { n: up.attachments.length }) }}</button>
            </div>
          </form>

          <ul v-if="files.length" class="op-files">
            <li v-for="f in files" :key="f.id" class="op-file" :class="'tone-' + kind(f).tone">
              <span class="op-file-ico"><icons :name="kind(f).icon" class="w-5 h-5" /></span>
              <div class="op-file-text"><b :title="f.title">{{ f.title }}</b><small><span class="op-kind">{{ kind(f).label }}</span>{{ size(f.size) }} · {{ f.at }}<template v-if="f.note"> · {{ f.note }}</template></small></div>
              <span class="op-file-act">
                <a v-if="f.previewable && can.documents" :href="f.preview_url" target="_blank" rel="noopener" class="lg-btn t-teal"><icons name="eye-flat" class="w-4 h-4" /><span>{{ $t('Preview') }}</span></a>
                <a v-if="can.documents" :href="f.download_url" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
                <button v-if="can.manage" type="button" class="lg-btn t-red" @click="remove(f)"><icons name="trash" class="w-4 h-4" /><span>{{ $t('Remove') }}</span></button>
              </span>
            </li>
          </ul>
          <div v-else class="op-empty"><icons name="clip" class="w-8 h-8" /><b>{{ $t('No previous data attached yet') }}</b><span>{{ $t('Attach the Excel sheet of this account so its history stays one click away.') }}</span></div>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import { fileKind, fileSize } from '@/Pages/Accounts/fileKinds';
import { sc } from '../sc';

export default {
  components: { AdminLayout, PageHeader, Link, AcctDropzone },
  mixins: [sc],
  props: { a: Object, state: Object, files: Array, can: Object, today: String },
  data() {
    return {
      saving: false,
      form: { contract: this.state.contract, paid: this.state.opening_paid, as_at: this.state.as_at || this.today, note: '' },
      up: this.$inertia.form({ attachments: [], note: '' }),
    };
  },
  computed: {
    // contract minus paid in total (opening plus what vouchers paid since), as the ledger computes it
    outstanding() {
      const c = this.cents(this.form.contract); const p = this.cents(this.form.paid); const live = this.cents(this.state.paid_live);
      if (c === null || p === null) return this.state.outstanding;
      const n = c - p - live; const neg = n < 0n; const s = (neg ? -n : n).toString().padStart(3, '0');
      return (neg ? '-' : '') + s.slice(0, -2) + '.' + s.slice(-2);
    },
    outNeg() { return this.neg(this.outstanding); },
  },
  methods: {
    kind(f) { return fileKind(f.title); },
    size(b) { return fileSize(b); },
    cents(v) {
      const s = String(v === null || v === undefined ? '' : v).replace(/[ ,]/g, '');
      if (!/^\d+(\.\d{1,6})?$/.test(s)) return null;
      const [i, d = ''] = s.split('.');
      let n = BigInt(i + (d + '00').slice(0, 2));
      if ((d + '000')[2] >= '5') n += 1n;
      return n;
    },
    save() {
      this.saving = true;
      this.$inertia.put(route('subcontracts.opening.save', this.a.id), this.form, { preserveScroll: true, onFinish: () => { this.saving = false; } });
    },
    upload() { this.up.post(route('subcontracts.opening.attach', this.a.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { this.up.reset('attachments', 'note'); } }); },
    remove(f) { this.$confirm(this.$t('Remove :f?', { f: f.title })).then(ok => { if (ok) this.$inertia.delete(f.remove_url, { preserveScroll: true }); }); },
  },
};
</script>

<style scoped>
.op-figs { margin-bottom: 14px; display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
.op-figs .sc-fig { display: grid; gap: 3px; align-content: start; min-width: 0; } .op-figs .sc-fig b { font-size: 1.12rem; overflow-wrap: anywhere; } .op-figs .sc-fig em { font-style: normal; font-size: 0.74rem; color: var(--sc-mute, #64748b); }
.op-figs .sc-fig.is-warn { box-shadow: inset 0 0 0 1px rgba(209, 26, 42, 0.35); }
.op-grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 1100px) { .op-grid { grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); align-items: start; } }
.op-form { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
.op-wide { grid-column: 1 / -1; }
.op-result { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 16px; background: rgba(209, 26, 42, 0.07); box-shadow: inset 0 0 0 1px rgba(209, 26, 42, 0.28); font-weight: 600; }
.op-result.is-neg { background: rgba(209, 26, 42, 0.12); }
.op-attach { display: grid; gap: 8px; margin-bottom: 12px; }
.op-attach-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.op-note { flex: 1 1 220px; height: 36px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--sc-line, #d1d5db); background: var(--sc-soft, transparent); font-size: 0.84rem; color: inherit; }
.op-files { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.op-file { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 14px; border-radius: 18px; background: var(--sc-card, #fff); box-shadow: 0 0 0 1px var(--sc-line, #e3e9f2), 0 12px 20px -16px rgba(31, 70, 130, 0.5); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.op-file:hover { transform: translateY(-1px); box-shadow: 0 0 0 1px rgba(47, 143, 230, 0.45), 0 16px 24px -16px rgba(31, 70, 130, 0.55); }
.op-file-ico { width: 44px; height: 44px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; flex: none; background: #e8eef7; color: #566277; box-shadow: 0 10px 16px -10px rgba(31, 70, 130, 0.5); }
.tone-emerald .op-file-ico { background: #dcf7e8; color: #0d7a43; } .tone-teal .op-file-ico { background: #d9f5f2; color: #0d766d; } .tone-rose .op-file-ico { background: #ffe5ec; color: #c0264f; } .tone-sky .op-file-ico { background: #e2f3ff; color: #0b6fb8; } .tone-navy .op-file-ico { background: #e4edff; color: #2650c4; }
.op-file-text { min-width: 0; flex: 1 1 200px; display: grid; gap: 2px; } .op-file-text b { overflow-wrap: anywhere; } .op-file-text small { color: var(--sc-mute, #64748b); }
.op-kind { display: inline-block; margin-right: 8px; padding: 1px 8px; border-radius: 999px; background: #eef1f6; font-weight: 800; font-size: 0.68rem; letter-spacing: 0.04em; }
.op-file-act { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.op-empty { display: grid; justify-items: center; gap: 4px; text-align: center; padding: 28px 12px; color: var(--sc-mute, #64748b); border-radius: 18px; border: 2px dashed var(--sc-line, #d7dfeb); }
.op-empty b { color: var(--sc-ink, #0f2540); }
</style>
