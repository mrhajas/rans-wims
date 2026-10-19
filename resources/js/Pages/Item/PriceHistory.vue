<template>
  <admin-layout :title="$t('Price Inflation')">
    <div class="lg-page ph px-4 md:px-0">
      <page-header
        icon="chart-bar"
        :title="$t(item.name)"
        :subtitle="item.code + ' - ' + $t('Track price changes over time and apply a past price back as the current price')"
      >
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Items') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <!-- Chart -->
      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="chart-pie" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Price Inflation') }}</h2>
            <p class="lg-card-sub">{{ $t('Please review the chart below') }}</p>
          </div>
        </header>
        <div class="overflow-x-auto">
          <vue-highcharts type="chart" :options="priceChartData" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" style="min-width: 520px" />
        </div>
      </section>

      <!-- Add entry -->
      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="plus" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Add Price History Entry') }}</h2>
            <p class="lg-card-sub">{{ $t('Record the price that applied for a period. Leave "Effective To" empty for an open-ended price.') }}</p>
          </div>
        </header>
        <form @submit.prevent="submit">
          <div class="lg-fields">
            <div><text-input type="date" v-model="form.effective_from" :error="form.errors.effective_from" :label="$t('Effective From')" /></div>
            <div><text-input type="date" v-model="form.effective_to" :error="form.errors.effective_to" :label="$t('Effective To')" /></div>
            <div><text-input type="number" min="0" v-model="form.price" :error="form.errors.price" :label="$t('Price')" /></div>
            <div class="lg-span-all"><textarea-input v-model="form.remarks" :error="form.errors.remarks" :label="$t('Remarks')" /></div>
          </div>
          <div class="ph-form-foot">
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons name="plus" class="w-4 h-4" />{{ form.processing ? $t('Saving…') : $t('Add Entry') }}
            </button>
          </div>
        </form>
      </section>

      <!-- History -->
      <section class="lg-card ph-card-table">
        <header class="lg-card-head print:hidden">
          <span class="lg-card-ico lg-ico-navy"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Price History') }}</h2>
            <p class="lg-card-sub">{{ filteredHistories.length }} / {{ priceHistories.length }} {{ $t('Entries') }}</p>
          </div>
        </header>
        <div class="ph-toolbar print:hidden">
          <div class="ph-search">
            <span class="ph-search-ico"><icons name="search" class="w-4 h-4" /></span>
            <input v-model="search" type="text" :placeholder="$t('Search by date, price or remarks')" />
          </div>
          <div class="flex items-center gap-2">
            <button type="button" class="lg-btn lg-btn-dark" @click="printHistory"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
            <button type="button" class="lg-btn lg-btn-green" @click="downloadHistory"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</button>
          </div>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Effective From') }}</th>
                <th>{{ $t('Effective To') }}</th>
                <th class="num">{{ $t('Price') }}</th>
                <th class="num">{{ $t('Change') }}</th>
                <th>{{ $t('Remarks') }}</th>
                <th>{{ $t('Added By') }}</th>
                <th class="print:hidden num">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in filteredHistories" :key="row.id">
                <tr v-if="editingId === row.id" class="is-editing">
                  <td :data-label="$t('Effective From')"><input type="date" v-model="editForm.effective_from" class="ph-input" /></td>
                  <td :data-label="$t('Effective To')"><input type="date" v-model="editForm.effective_to" class="ph-input" /></td>
                  <td :data-label="$t('Price')"><input type="number" min="0" step="0.01" v-model="editForm.price" class="ph-input text-right" /></td>
                  <td class="ph-hide-m"></td>
                  <td :data-label="$t('Remarks')"><input type="text" v-model="editForm.remarks" class="ph-input" /></td>
                  <td :data-label="$t('Added By')">{{ row.added_by || '—' }}</td>
                  <td class="print:hidden">
                    <div class="lg-actions">
                      <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Save')" :aria-label="$t('Save')" :disabled="editForm.processing" @click="saveEdit(row)"><icons name="tick" class="w-4 h-4" /></button>
                      <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Cancel')" :aria-label="$t('Cancel')" @click="cancelEdit"><icons name="cross" class="w-4 h-4" /></button>
                    </div>
                  </td>
                </tr>
                <tr v-else :class="{ 'is-current': isCurrent(row) }">
                  <td class="ph-date" :data-label="$t('Effective From')">{{ row.effective_from }}</td>
                  <td class="ph-date" :data-label="$t('Effective To')">{{ row.effective_to || '—' }}</td>
                  <td class="num" :data-label="$t('Price')">
                    <span class="ph-price">{{ $number(row.price) }} <small>Tsh</small></span>
                    <span v-if="isCurrent(row)" class="lg-chip lg-chip-green ph-badge">{{ $t('Current') }}</span>
                  </td>
                  <td class="num" :data-label="$t('Change')">
                    <span v-if="deltaFor(row) !== null" class="ph-delta" :class="trendClass(deltaFor(row))">
                      <icons v-if="deltaFor(row) > 0" name="up" class="w-3 h-3" />
                      <icons v-else-if="deltaFor(row) < 0" name="down" class="w-3 h-3" />
                      {{ pct(deltaFor(row)) }}
                    </span>
                    <span v-else class="ph-faint">—</span>
                  </td>
                  <td class="ph-remarks" :data-label="$t('Remarks')">{{ row.remarks || '—' }}</td>
                  <td :data-label="$t('Added By')">{{ row.added_by || '—' }}</td>
                  <td class="print:hidden">
                    <div class="lg-actions">
                      <button type="button" class="lg-icon-btn ph-amber lg-tip" :data-tip="$t('Apply as Current Price')" :aria-label="$t('Apply as Current Price')" @click="applyPrice(row)"><icons name="refresh" class="w-4 h-4" /></button>
                      <button type="button" class="lg-icon-btn ph-blue lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="startEdit(row)"><icons name="edit" class="w-4 h-4" /></button>
                      <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="deleteEntry(row)"><icons name="trash" class="w-4 h-4" /></button>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="filteredHistories.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import VueHighcharts from 'vue3-highcharts';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TecButton from '@/Jetstream/Button.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';

export default {
  props: { item: Object, priceHistories: Array },

  components: { AdminLayout, TecButton, TextInput, TextareaInput, TecSectionTitle, VueHighcharts, PageHeader, StatTiles },

  data() {
    return {
      search: '',
      editingId: null,
      form: this.$inertia.form({
        price: '',
        effective_from: '',
        effective_to: '',
        remarks: '',
      }),
      editForm: this.$inertia.form({
        price: '',
        effective_from: '',
        effective_to: '',
        remarks: '',
      }),
    };
  },

  computed: {
    tiles() {
      const list = [{ label: this.$t('Current Price'), value: this.$number(this.item.price) + ' Tsh', icon: 'cash', tone: 'emerald' }];
      if (this.priceHistories.length) {
        list.push({ label: this.$t('Entries'), value: this.priceHistories.length, icon: 'list', tone: 'navy' });
        list.push({ label: this.$t('Lowest'), value: this.$number(this.stats.min), icon: 'down', tone: 'teal' });
        list.push({ label: this.$t('Highest'), value: this.$number(this.stats.max), icon: 'up', tone: 'amber' });
        list.push({
          label: this.$t('Change since first entry'),
          value: this.pct(this.stats.totalPct),
          icon: 'chart-bar',
          tone: this.stats.totalPct > 0 ? 'rose' : this.stats.totalPct < 0 ? 'emerald' : 'slate',
          valueClass: this.stats.totalPct > 0 ? 'is-bad' : this.stats.totalPct < 0 ? 'is-good' : '',
        });
      }
      return list;
    },
    filteredHistories() {
      const needle = String(this.search || '').trim().toLowerCase();
      if (!needle) {
        return this.priceHistories;
      }

      return this.priceHistories.filter(row => {
        return (
          String(row.effective_from || '').toLowerCase().includes(needle) ||
          String(row.effective_to || '').toLowerCase().includes(needle) ||
          String(row.price || '').toLowerCase().includes(needle) ||
          String(row.remarks || '').toLowerCase().includes(needle) ||
          String(row.added_by || '').toLowerCase().includes(needle)
        );
      });
    },
    // Oldest → newest, used for the "change vs previous entry" column and the summary tiles.
    chronological() {
      return [...this.priceHistories].sort((a, b) => new Date(a.effective_from) - new Date(b.effective_from) || a.id - b.id);
    },
    changeById() {
      const map = {};
      this.chronological.forEach((row, i) => {
        const prev = i ? Number(this.chronological[i - 1].price) : null;
        map[row.id] = prev ? ((Number(row.price) - prev) / prev) * 100 : null;
      });
      return map;
    },
    stats() {
      const prices = this.priceHistories.map(r => Number(r.price));
      const first = this.chronological.length ? Number(this.chronological[0].price) : 0;
      const last = this.chronological.length ? Number(this.chronological[this.chronological.length - 1].price) : 0;
      return {
        min: prices.length ? Math.min(...prices) : 0,
        max: prices.length ? Math.max(...prices) : 0,
        totalPct: first ? ((last - first) / first) * 100 : 0,
      };
    },
    priceChartData() {
      const points = [...this.priceHistories]
        .slice()
        .sort((a, b) => new Date(a.effective_from) - new Date(b.effective_from))
        .map(row => [Date.parse(row.effective_from), Number(row.price)]);

      return {
        chart: {
          type: 'spline',
          spacingTop: 20,
          style: { fontFamily: 'Nunito' },
          backgroundColor: 'transparent',
        },
        credits: { enabled: false },
        title: { text: null },
        legend: { enabled: false },
        colors: ['#4f46e5'],
        xAxis: {
          type: 'datetime',
          title: { text: this.$t('Effective From') },
        },
        yAxis: {
          min: 0,
          title: { text: this.$t('Price') + ' (Tsh)' },
        },
        tooltip: {
          shared: true,
          shadow: false,
          useHTML: true,
          borderRadius: '5',
          borderColor: '#1F2937',
          style: { color: '#fff' },
          backgroundColor: '#1F2937',
          xDateFormat: '%Y-%m-%d',
        },
        series: [
          {
            type: 'spline',
            name: this.$t('Price'),
            data: points,
          },
        ],
      };
    },
  },

  methods: {
    deltaFor(row) {
      const v = this.changeById[row.id];
      return v === null || v === undefined ? null : v;
    },
    trendClass(v) {
      return v > 0 ? 'is-up' : v < 0 ? 'is-down' : 'is-flat';
    },
    pct(v) {
      const n = Number(v) || 0;
      return (n > 0 ? '+' : '') + n.toFixed(1) + '%';
    },
    // The entry whose price is the item's live price and whose period includes today.
    isCurrent(row) {
      const today = new Date().toISOString().slice(0, 10);
      return Number(row.price) === Number(this.item.price) && row.effective_from <= today && (!row.effective_to || row.effective_to >= today);
    },
    submit() {
      this.form.post(this.route('items.price-history.store', this.item.id), {
        preserveScroll: true,
        onSuccess: () => this.form.reset(),
      });
    },
    async applyPrice(row) {
      if (!(await this.$confirm(this.$t('Apply this price as the current item price?')))) {
        return;
      }

      this.$inertia.patch(this.route('items.price-history.apply', { item: this.item.id, price_history: row.id }), {}, { preserveScroll: true });
    },
    startEdit(row) {
      this.editingId = row.id;
      this.editForm.price = row.price;
      this.editForm.effective_from = row.effective_from;
      this.editForm.effective_to = row.effective_to;
      this.editForm.remarks = row.remarks;
      this.editForm.clearErrors();
    },
    cancelEdit() {
      this.editingId = null;
      this.editForm.clearErrors();
    },
    saveEdit(row) {
      this.editForm.put(this.route('items.price-history.update', { item: this.item.id, price_history: row.id }), {
        preserveScroll: true,
        onSuccess: () => {
          this.editingId = null;
        },
      });
    },
    async deleteEntry(row) {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this price history entry?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(this.route('items.price-history.destroy', { item: this.item.id, price_history: row.id }), { preserveScroll: true });
    },
    printHistory() {
      window.open(this.route('items.price-history.index', { item: this.item.id, output: 'print' }), '_blank');
    },
    downloadHistory() {
      window.open(this.route('items.price-history.index', { item: this.item.id, output: 'pdf' }), '_blank');
    },
  },
};
</script>

<style scoped>
.ph-form-foot { display: flex; justify-content: flex-end; margin-top: 1rem; }
.ph-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 1rem; }
.ph-search { position: relative; flex: 1 1 260px; max-width: 420px; }
.ph-search-ico { position: absolute; left: 12px; top: 50%; display: inline-flex; transform: translateY(-50%); color: var(--lg-faint); pointer-events: none; }
.ph-search input { width: 100%; padding: 8px 12px 8px 34px; border-radius: 9999px; font-size: 0.82rem; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-ink); }
.ph-search input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }

.lg-table tr.is-current td { background: rgba(16, 185, 129, 0.06); }
.lg-table tr.is-editing td { background: rgba(99, 102, 241, 0.05); }
.ph-date { font-variant-numeric: tabular-nums; font-weight: 700; }
.ph-price { font-weight: 800; font-variant-numeric: tabular-nums; }
.ph-price small { font-size: 0.68rem; color: var(--lg-faint); }
.ph-badge { margin-left: 8px; padding: 1px 9px; font-size: 0.64rem; }
.ph-remarks { max-width: 280px; overflow: hidden; text-overflow: ellipsis; color: var(--lg-mute); }
.ph-faint { color: var(--lg-faint); }
.ph-delta { display: inline-flex; align-items: center; gap: 3px; padding: 2px 9px; border-radius: 9999px; font-size: 0.76rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.ph-delta.is-up { color: #b45309; background: rgba(245, 158, 11, 0.15); }
.ph-delta.is-down { color: #047857; background: rgba(16, 185, 129, 0.13); }
.ph-delta.is-flat { color: var(--lg-mute); background: var(--lg-soft); }
html.dark .ph-delta.is-up { color: #fcd34d; }
html.dark .ph-delta.is-down { color: #6ee7b7; }
.ph-input { width: 100%; min-width: 120px; padding: 7px 10px; border-radius: 10px; font-size: 0.82rem; background: var(--lg-card); border: 1px solid var(--lg-line); color: var(--lg-ink); }
.ph-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
.ph-amber { color: #b45309; background: rgba(245, 158, 11, 0.15); }
.ph-blue { color: #1d4ed8; background: rgba(59, 130, 246, 0.13); }
html.dark .ph-amber { color: #fcd34d; }
html.dark .ph-blue { color: #93c5fd; }

@media (max-width: 767.98px) {
  .ph-remarks { max-width: 100%; }
  .ph-hide-m { display: none !important; }
  .ph-input { min-width: 0; width: 100%; max-width: 220px; }
}
@media print {
  .ph-card-table { box-shadow: none; border: 0; padding: 0; }
}
</style>
