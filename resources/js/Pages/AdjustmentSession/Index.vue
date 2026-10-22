<template>
  <admin-layout :title="$t('Adjustment Sessions')">
    <div class="lg-page px-4 md:px-0 asn-ui">
      <page-header icon="adjustments" :title="$t('Adjustment Sessions')" :subtitle="$t('A physical count of one Project / Warehouse that takes several days and several Super Adjustments. When it ends you see (and can clear) the items nobody counted.')">
        <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft"><icons name="tick" class="w-4 h-4" />{{ $t('Super Adjustments') }}</Link>
        <button v-if="can.start" type="button" class="lg-btn lg-btn-primary" @click="openStart"><icons name="plus" class="w-4 h-4" />{{ $t('Start Adjustment Session') }}</button>
      </page-header>

      <stat-tiles :items="statItems" @select="pickTile" />

      <section class="lg-card lg-filters">
        <div class="lg-fields asn-filters">
          <text-input v-model="form.search" :label="$t('Search')" :placeholder="$t('Reference, name or Project / Warehouse')" />
          <div class="ac-f"><label>{{ $t('Project / Warehouse') }}</label><auto-complete v-model="form.warehouse_id" :suggestions="warehouseOptions" :placeholder="$t('All')" :clearable="true" /></div>
          <div class="asn-reset"><button type="button" class="lg-btn lg-btn-soft" :disabled="!hasFilters" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button></div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive asn-tab">
          <thead>
            <tr>
              <th class="asn-no">{{ $t('No') }}</th>
              <th>{{ $t('Session') }}</th>
              <th>{{ $t('Project / Warehouse') }}</th>
              <th>{{ $t('Started') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="num">{{ $t('Super Adjustments') }}</th>
              <th class="num">{{ $t('Counted') }}</th>
              <th class="num">{{ $t('Not counted') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(s, i) in sessions.data" :key="s.id" class="is-click" @click="$inertia.visit(route('adjustment-sessions.show', s.id))">
              <td :data-label="$t('No')" class="asn-no">{{ ((sessions.meta && sessions.meta.from) || 1) + i }}</td>
              <td :data-label="$t('Session')" class="asn-wrap"><div class="asn-cell"><span class="lg-main lg-mono">{{ s.reference }}</span><div v-if="s.name" class="lg-subtext">{{ s.name }}</div></div></td>
              <td :data-label="$t('Warehouse')" class="asn-wrap">{{ s.warehouse }}</td>
              <td :data-label="$t('Started')" class="asn-wrap"><div class="asn-cell"><div>{{ $datetime(s.started_at) }}</div><div class="lg-subtext">{{ s.started_by }}</div></div></td>
              <td :data-label="$t('Status')"><div class="asn-cell"><span class="asn-pill" :class="s.status === 'Open' ? 'is-open' : 'is-ended'"><icons :name="s.status === 'Open' ? 'clock' : 'tick'" class="asn-pill__ico" />{{ $t(s.status) }}</span><div v-if="s.halted" class="asn-halt-chip" :title="$t('Stock movement halted by :n', { n: s.halted_by })"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Stock halted') }} · {{ s.halted_by }}</div><div v-if="s.ended_at" class="lg-subtext">{{ $datetime(s.ended_at) }}</div></div></td>
              <td :data-label="$t('Super Adjustments')" class="num">{{ s.documents }}</td>
              <td :data-label="$t('Counted')" class="num">{{ s.counted }}</td>
              <td :data-label="$t('Not counted')" class="num"><div class="asn-cell"><b :class="s.not_counted ? 'asn-red' : 'asn-green'">{{ s.not_counted }}</b><div v-if="s.cleared" class="lg-subtext">{{ s.cleared }} {{ $t('cleared') }}</div></div></td>
              <td class="lg-sticky-end" @click.stop>
                <div class="lg-actions">
                  <Link :href="route('adjustment-sessions.show', s.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="doc" /></Link>
                  <a v-if="$can('print-adjustment-sessions')" :href="route('adjustment-sessions.print', s.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" /></a>
                  <a v-if="$can('print-adjustment-sessions')" :href="route('adjustment-sessions.pdf', s.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" /></a>
                </div>
              </td>
            </tr>
            <tr v-if="!sessions.data.length" class="lg-empty-row"><td class="lg-empty" colspan="9">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-4" :meta="sessions.meta" :links="sessions.links" />
    </div>

    <modal :show="startOpen" max-width="lg" @close="startOpen = false">
      <div class="asn-dlg">
        <header class="asn-dlg__head"><icons name="adjustments" class="w-5 h-5" /><div><h3>{{ $t('Start Adjustment Session') }}</h3><p>{{ $t('Every Super Adjustment of the chosen Project / Warehouse is added to the session until you end it.') }}</p></div></header>
        <div class="asn-dlg__body">
          <div class="ac-f"><label>{{ $t('Project / Warehouse') }} *</label><auto-complete v-model="startForm.warehouse_id" :suggestions="startOptions" :placeholder="$t('Choose the Project / Warehouse to count')" /><span class="ac-err">{{ startForm.errors.warehouse_id }}</span></div>
          <p v-if="busyWarehouses.length" class="asn-hint">{{ $t('Not listed: Project / Warehouses that already have an open session (:n).', { n: busyWarehouses.length }) }}</p>
          <text-input v-model="startForm.name" :label="$t('Name (optional)')" :placeholder="$t('For example: Godown 1 year-end count')" :error="startForm.errors.name" />
          <div class="ac-f"><label>{{ $t('Note (optional)') }}</label><textarea v-model="startForm.note" rows="3" maxlength="2000" :placeholder="$t('Who counts, which days, anything to remember')"></textarea></div>
          <p class="asn-hint">{{ $t('While the session is open, the question "items not in this count" is not asked on each Super Adjustment: the session keeps track, and you decide at the end.') }}</p>
          <div v-if="can.stop" class="asn-halt-opt" :class="{ 'is-on': startForm.halt_stock }">
            <label class="asn-chk"><input v-model="startForm.halt_stock" type="checkbox" /><icons name="lock-closed" class="w-4 h-4" /> {{ $t('Stop stock movement of this Project / Warehouse during the session') }}</label>
            <p class="asn-hint">{{ $t('Nobody can then check in, check out, transfer, reallocate, issue or return stock to or from it, and it is marked locked in the lists, until the session ends or you release it. The Super Adjustments of the count are not affected.') }}</p>
            <div v-if="startForm.halt_stock" class="ac-f"><label>{{ $t('Your password') }} *</label><input v-model="startForm.password" type="password" autocomplete="current-password" class="asn-input" :placeholder="$t('Enter your password to confirm')" /><span class="ac-err">{{ startForm.errors.password }}</span></div>
          </div>
        </div>
        <footer class="asn-dlg__foot">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="startForm.processing" @click="startOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="startForm.processing || !startForm.warehouse_id || (startForm.halt_stock && !startForm.password)" @click="start"><icons name="tick" class="w-4 h-4" />{{ startForm.processing ? $t('Starting...') : $t('Start session') }}</button>
        </footer>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader, StatTiles, TextInput, AutoComplete, Modal },
  props: { sessions: Object, filters: Object, stats: Object, warehouses: { type: Array, default: () => [] }, can: Object },
  data() {
    return {
      form: { search: this.filters.search || '', status: this.filters.status || null, warehouse_id: this.filters.warehouse_id ? Number(this.filters.warehouse_id) : '' },
      startOpen: false,
      startForm: this.$inertia.form({ warehouse_id: '', name: '', note: '', halt_stock: false, password: '' }),
    };
  },
  computed: {
    hasFilters() { return !!(this.form.search || this.form.status || this.form.warehouse_id); },
    warehouseOptions() { return this.warehouses.map(w => ({ value: w.value, label: w.label })); },
    busyWarehouses() { return this.warehouses.filter(w => w.has_open); },
    startOptions() { return this.warehouses.filter(w => !w.has_open).map(w => ({ value: w.value, label: w.label })); },
    statItems() {
      const s = this.stats || {};
      const tile = (status, icon, tone, label, value) => ({ icon, tone, label, value: Number(value || 0), status, active: (this.form.status || null) === status });
      return [tile(null, 'collection', 'navy', this.$t('Total Sessions'), s.total), tile('Open', 'clock', 'orange', this.$t('Open'), s.open), tile('Ended', 'tick', 'forest', this.$t('Ended'), s.ended)];
    },
  },
  watch: {
    // ticking "stop stock movement" puts the cursor in the password box (and scrolls it into view)
    'startForm.halt_stock'(on) {
      if (on) this.$nextTick(() => { const el = document.querySelector('.asn-halt-opt input[type=password]'); if (el) el.focus(); });
    },
    form: {
      handler: throttle(function () { this.$inertia.get(this.route('adjustment-sessions.index'), pickBy(this.form), { preserveState: true, replace: true }); }, 300),
      deep: true,
    },
  },
  methods: {
    pickTile(tile) { this.form.status = tile.status && this.form.status !== tile.status ? tile.status : null; },
    reset() { this.form = { search: '', status: null, warehouse_id: '' }; },
    openStart() { this.startForm.reset(); this.startForm.clearErrors(); this.startOpen = true; },
    start() { this.startForm.transform(d => ({ ...d, password: d.halt_stock ? d.password : '' })).post(this.route('adjustment-sessions.store'), { onSuccess: () => { this.startOpen = false; }, onFinish: () => { this.startForm.password = ''; } }); },
  },
};
</script>

<style scoped>
.asn-filters { grid-template-columns: minmax(220px, 2fr) minmax(200px, 1.4fr) auto; align-items: end; }
.asn-reset { display: flex; align-items: flex-end; }
.asn-no { width: 1%; white-space: nowrap; font-weight: 700; color: var(--lg-mute, #64748b); }
.asn-wrap { white-space: normal; min-width: 120px; max-width: 260px; }
@media (max-width: 767.98px) { .asn-wrap { min-width: 0; max-width: none; } .asn-cell { flex: 1 1 auto; text-align: right; min-width: 0; } .asn-cell .asn-halt-chip { white-space: normal; } }
@media (min-width: 768px) and (max-width: 1099px) { .asn-tab th, .asn-tab td { padding-left: 8px; padding-right: 8px; } .asn-tab .asn-wrap { min-width: 96px; } .asn-tab th { white-space: normal; } .asn-tab td .asn-halt-chip { white-space: normal; } }
.asn-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.75rem 0.25rem 0.35rem; border-radius: 999px; color: #fff; font-size: 0.72rem; font-weight: 800; white-space: nowrap; }
.asn-pill__ico { width: 0.8rem; height: 0.8rem; padding: 0.13rem; box-sizing: content-box; border-radius: 50%; background: rgba(255, 255, 255, 0.24); }
.asn-pill.is-open { background: linear-gradient(135deg, #f97316, #c2410c); animation: asn-pulse 2.4s ease-in-out infinite; }
.asn-pill.is-ended { background: linear-gradient(135deg, #15803d, #14532d); }
@keyframes asn-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(234, 88, 12, 0.45); } 50% { box-shadow: 0 0 0 5px rgba(234, 88, 12, 0); } }
@media (prefers-reduced-motion: reduce) { .asn-pill.is-open { animation: none; } }
.asn-red { color: #b91c1c; } .asn-green { color: #15803d; }
.asn-dlg { width: 100%; --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-ink: #0f172a; --lg-mute: #64748b; background: var(--lg-card); color: var(--lg-ink); border-radius: 0.5rem; overflow: hidden; }
html.dark .asn-dlg { --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; }
.asn-dlg__head { display: flex; align-items: center; gap: 0.8rem; padding: 0.9rem 1.1rem; color: #fff; background: linear-gradient(135deg, #f97316, #c2410c); } .asn-dlg__head h3 { margin: 0; font-size: 1rem; font-weight: 800; } .asn-dlg__head p { margin: 0.1rem 0 0; font-size: 0.8rem; opacity: 0.95; }
.asn-dlg__body { display: flex; flex-direction: column; gap: 0.8rem; padding: 1rem 1.1rem; max-height: 65vh; overflow-y: auto; } .asn-dlg__body > * { flex: none; max-width: 100%; } .asn-dlg__body .ac-f { display: flex; flex-direction: column; gap: 6px; width: 100%; } .asn-dlg__body textarea { width: 100%; border-radius: 12px; border: 1px solid var(--lg-line); background: transparent; padding: 10px 12px; color: inherit; }
.asn-hint { margin: 0; font-size: 0.8rem; color: var(--lg-mute); line-height: 1.45; }
.asn-halt-chip { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; padding: 1px 8px; border-radius: 999px; background: #b91c1c; color: #fff; font-size: 0.68rem; font-weight: 800; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.asn-halt-opt { display: flex; flex-direction: column; gap: 8px; padding: 10px 12px; border-radius: 12px; border: 1px dashed var(--lg-line); } .asn-halt-opt.is-on { border: 1px solid rgba(185, 28, 28, 0.55); background: rgba(254, 226, 226, 0.45); }
html.dark .asn-halt-opt.is-on { background: rgba(185, 28, 28, 0.14); }
.asn-chk { display: inline-flex; align-items: flex-start; gap: 8px; font-weight: 800; font-size: 0.86rem; cursor: pointer; }
.asn-chk input { margin-top: 3px; }
.asn-input { width: 100%; border-radius: 12px; border: 1px solid var(--lg-line); background: transparent; padding: 10px 12px; color: inherit; }
.ac-err { color: #b91c1c; font-size: 0.78rem; font-weight: 700; }
.asn-dlg__foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; padding: 0.8rem 1.1rem 1rem; border-top: 1px solid var(--lg-line); }
@media (max-width: 720px) { .asn-filters { grid-template-columns: 1fr; } .asn-dlg__foot .lg-btn { flex: 1 1 100%; justify-content: center; } }
</style>
