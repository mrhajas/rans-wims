<template>
  <tec-dialog-modal :show="show" max-width="4xl" @close="$emit('close')">
    <template #title>
      <div class="ph-title">
        <span class="ph-title__ico"><icons name="clock" class="w-5 h-5" /></span>
        <div>
          <b>{{ $t('History of :n', { n: pkg ? pkg.name : '' }) }}</b>
          <small>{{ pkg ? pkg.route : '' }} · {{ $t('every change of the amounts and rates, and every request made from this package') }}</small>
        </div>
      </div>
    </template>

    <template #content>
      <div class="ph">
        <div v-if="loading && !rows.length" class="ph-skel"><div v-for="n in 4" :key="n"><i></i><i></i></div></div>
        <p v-else-if="error" class="ph-empty is-err"><icons name="exclamation" class="w-5 h-5" />{{ error }}</p>
        <p v-else-if="!rows.length" class="ph-empty"><icons name="info" class="w-5 h-5" />{{ $t('Nothing is recorded yet.') }}</p>

        <ol v-else class="ph-list" :class="{ 'is-busy': loading }">
          <li v-for="r in rows" :key="r.id" class="ph-item" :style="{ '--tone': tone(r.action).c }">
            <div class="ph-head">
              <span class="ph-act"><icons :name="tone(r.action).i" class="w-3.5 h-3.5" />{{ tone(r.action).l }}</span>
              <time class="ph-time" :title="r.at">{{ $datetime(r.at) }}</time>
              <span class="ph-by"><icons name="user" class="w-3 h-3" />{{ r.by || $t('Unknown') }}</span>
              <span v-if="r.ip" class="ph-ip">{{ r.ip }}</span>
            </div>

            <p v-if="r.action === 'requested'" class="ph-req">
              <icons name="plane" class="w-4 h-4" />
              <a v-if="r.details.travel_id" :href="route('travels.show', r.details.travel_id)">{{ r.details.travel }}</a><template v-else>{{ r.details.travel }}</template>
              · {{ r.details.people }} {{ $t('people') }} · {{ $t('departs') }} {{ r.details.depart_date }} · <b>{{ r.currency }} {{ money(r.details.total) }}</b>
            </p>

            <div class="ph-grid">
              <div v-for="c in cols" :key="c.k" class="ph-cell" :class="{ 'is-changed': changed(r, c) }" :style="{ '--c': c.c }">
                <small><icons :name="c.i" class="w-3 h-3" />{{ c.l }}</small>
                <b>{{ money(r[c.k]) }}</b>
                <em v-if="changed(r, c)"><s>{{ money(changed(r, c)[0]) }}</s> <icons name="arrow-left" class="w-3 h-3 ph-arrow" /> {{ money(changed(r, c)[1]) }}</em>
              </div>
              <div class="ph-cell is-total" :class="{ 'is-changed': totalChanged(r) }">
                <small><icons name="calculator" class="w-3 h-3" />{{ $t('Total per person') }}</small>
                <b>{{ r.currency }} {{ money(r.total_per_person) }}</b>
              </div>
              <div class="ph-cell is-rate" :class="{ 'is-changed': rateChange(r) }">
                <small><icons name="refresh" class="w-3 h-3" />{{ $t('Rate') }}</small>
                <b>{{ r.currency === 'TZS' ? '1' : rate(r.exchange_rate) }}</b>
                <em v-if="rateChange(r)"><s>{{ rate(rateChange(r)[0]) }}</s> <icons name="arrow-left" class="w-3 h-3 ph-arrow" /> {{ rate(rateChange(r)[1]) }}</em>
              </div>
            </div>

            <ul v-if="otherChanges(r).length" class="ph-other">
              <li v-for="[label, v] in otherChanges(r)" :key="label"><span>{{ $t(label) }}</span><s>{{ v[0] || '-' }}</s><icons name="arrow-left" class="w-3 h-3 ph-arrow" /><b>{{ v[1] || '-' }}</b></li>
            </ul>
          </li>
        </ol>

        <div v-if="meta && meta.last_page > 1" class="ph-pager">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="loading || meta.current_page <= 1" @click="go(meta.current_page - 1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Newer') }}</button>
          <span>{{ meta.from }}-{{ meta.to }} {{ $t('of') }} {{ meta.total }}</span>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="loading || meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ $t('Older') }}<icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
        </div>
      </div>
    </template>

    <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')">{{ $t('Close') }}</button></template>
  </tec-dialog-modal>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { TecDialogModal },
  emits: ['close'],
  props: { show: { type: Boolean, default: false }, packageId: { type: Number, default: null } },
  data() { return { rows: [], meta: null, pkg: null, loading: false, error: '', page: 1 }; },
  computed: {
    cols() {
      return [
        { k: 'ticket_amount', l: this.$t('Ticket'), i: 'plane', c: '#0284c7', key: 'Ticket' },
        { k: 'accommodation_amount', l: this.$t('Accommodation'), i: 'building', c: '#7c3aed', key: 'Accommodation' },
        { k: 'per_diem_amount', l: this.$t('Expenses / Per Diem'), i: 'cash', c: '#d97706', key: 'Expenses / Per Diem' },
        { k: 'transport_amount', l: this.$t('Inside Transport'), i: 'car', c: '#0d9488', key: 'Inside Transport' },
      ];
    },
  },
  watch: { show(v) { if (v) { this.rows = []; this.page = 1; this.load(); } } },
  mounted() { if (this.show) this.load(); },
  methods: {
    tone(a) {
      return {
        created: { l: this.$t('Created'), c: '#059669', i: 'plus' }, updated: { l: this.$t('Changed'), c: '#2563eb', i: 'edit' }, activated: { l: this.$t('Switched on'), c: '#16a34a', i: 'tick' },
        deactivated: { l: this.$t('Switched off'), c: '#b45309', i: 'pause' }, requested: { l: this.$t('Travel Request'), c: '#7c3aed', i: 'plane' }, deleted: { l: this.$t('Deleted'), c: '#dc2626', i: 'trash' },
      }[a] || { l: a, c: '#64748b', i: 'info' };
    },
    money(v) { return v === null || v === undefined || v === '' ? '-' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    rate(v) { return v === null || v === undefined ? '-' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 }); },
    changed(r, c) { const ch = r.changes && r.changes[c.key]; return ch ? ch : null; },
    rateChange(r) { return (r.changes && r.changes['Exchange rate']) || null; },
    totalChanged(r) { return this.cols.some(c => this.changed(r, c)) || !!this.rateChange(r); },
    otherChanges(r) { return Object.entries(r.changes || {}).filter(([k]) => !['Ticket', 'Accommodation', 'Expenses / Per Diem', 'Inside Transport', 'Exchange rate'].includes(k)); },
    go(p) { this.page = p; this.load(); },
    async load() {
      if (!this.packageId) return;
      this.loading = true; this.error = '';
      try {
        const { data } = await axios.get(this.route('travels.packages.history', this.packageId), { params: { page: this.page } });
        this.rows = data.data; this.meta = data.meta; this.pkg = data.package;
      } catch (e) {
        this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The history could not be loaded.');
      } finally { this.loading = false; }
    },
  },
};
</script>

<style scoped>
.ph-title { display: flex; align-items: center; gap: 12px; } .ph-title b { display: block; font-size: 1.05rem; } .ph-title small { display: block; margin-top: 2px; font-size: 0.78rem; font-weight: 500; color: #64748b; }
.ph-title__ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #7c3aed, #1d4ed8); box-shadow: 0 10px 16px -10px #4c1d95; } .ph-title__ico > div { flex: none; margin: 0; }
.ph-list { list-style: none; margin: 0; padding: 0 4px 0 0; display: grid; gap: 10px; max-height: 58vh; overflow-y: auto; } .ph-list.is-busy { opacity: 0.55; }
.ph-item { padding: 12px 14px; border-radius: 18px; background: color-mix(in srgb, var(--tone) 5%, #fff); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tone) 20%, transparent); }
.ph-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-bottom: 8px; }
.ph-act { display: inline-flex; align-items: center; gap: 6px; padding: 3px 11px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--tone), color-mix(in srgb, var(--tone) 62%, #000)); } .ph-act :deep(div) { flex: none; margin: 0; }
.ph-time { font-size: 0.8rem; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; } .ph-by { display: inline-flex; align-items: center; gap: 4px; font-size: 0.76rem; font-weight: 700; color: #475569; } .ph-by :deep(div) { flex: none; margin: 0; } .ph-ip { margin-left: auto; font-size: 0.7rem; color: #94a3b8; }
.ph-req { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 8px; font-size: 0.84rem; color: #334155; } .ph-req a { font-weight: 800; color: #6d28d9; text-decoration: underline; } .ph-req :deep(div) { flex: none; margin: 0; }
.ph-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(128px, 1fr)); gap: 8px; }
.ph-cell { display: grid; gap: 1px; padding: 8px 10px; border-radius: 13px; background: #fff; box-shadow: inset 0 0 0 1px #e2e8f0; border-top: 3px solid var(--c, #94a3b8); } .ph-cell small { display: flex; align-items: center; gap: 4px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--c, #64748b); } .ph-cell small :deep(div) { flex: none; margin: 0; }
.ph-cell b { font-size: 0.98rem; color: #0f172a; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; } .ph-cell em { font-style: normal; font-size: 0.72rem; font-weight: 700; color: #b45309; } .ph-cell em s { color: #94a3b8; }
.ph-cell.is-changed { background: #fffbeb; box-shadow: inset 0 0 0 1.5px #f59e0b; }
.ph-cell.is-total { --c: #1c3f66; } .ph-cell.is-rate { --c: #64748b; }
.ph-arrow { display: inline-block; transform: rotate(180deg); vertical-align: middle; color: #94a3b8; }
.ph-other { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 3px; } .ph-other li { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 0.8rem; } .ph-other span { min-width: 7rem; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; } .ph-other s { color: #94a3b8; } .ph-other b { color: #0f172a; }
.ph-empty { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 34px 12px; border-radius: 16px; font-size: 0.86rem; font-weight: 700; color: #64748b; background: #f8fafc; } .ph-empty.is-err { color: #b91c1c; background: #fef2f2; }
.ph-skel { display: grid; gap: 8px; } .ph-skel div { display: grid; gap: 6px; padding: 14px; border-radius: 16px; background: #f1f5f9; } .ph-skel i { height: 10px; border-radius: 6px; background: linear-gradient(90deg, #e2e8f0, #f8fafc, #e2e8f0); background-size: 200% 100%; animation: ph-sh 1.2s linear infinite; } .ph-skel i:last-child { width: 60%; } @keyframes ph-sh { to { background-position: -200% 0; } }
.ph-pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; font-size: 0.8rem; font-weight: 700; color: #64748b; }
html.dark .ph-item { background: color-mix(in srgb, var(--tone) 14%, #11172d); } html.dark .ph-cell { background: #1b2342; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); } html.dark .ph-cell b, html.dark .ph-time, html.dark .ph-other b { color: #eef0fa; } html.dark .ph-cell.is-changed { background: #3a2a08; }
html.dark .ph-empty, html.dark .ph-skel div { background: #1b2342; }
@media (max-width: 560px) { .ph-grid { grid-template-columns: 1fr 1fr; } .ph-ip { margin-left: 0; } }
@media (prefers-reduced-motion: reduce) { .ph-skel i { animation: none; } }
</style>
