<template>
  <admin-layout :title="$t('Item Trail')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="table"
        :title="$t(item.name)"
        :subtitle="item.code + ' - ' + $t('Every stock movement for this item, newest first. Balance is the stock left in that warehouse after the movement.')"
      >
        <span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ item.code }}</span>
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Items') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <!-- Current stock per warehouse -->
      <section v-if="stockChips.length" class="lg-card" :aria-label="$t('Current stock by warehouse')">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Current stock by warehouse') }}</h2>
          </div>
        </header>
        <div class="trl-stock">
          <div v-for="s in stockChips" :key="s.warehouse" class="trl-stock-chip">
            <span class="trl-stock-name">{{ $t(s.warehouse) }}</span>
            <span class="trl-stock-qty">{{ $number(s.quantity) }}</span>
          </div>
          <div v-if="hiddenStock" class="trl-stock-chip trl-stock-more">+{{ hiddenStock }} {{ $t('more') }}</div>
        </div>
      </section>

      <!-- Timeline table -->
      <section class="lg-card trl-table-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Movement') }}</h2>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('When') }}</th>
                <th>{{ $t('Movement') }}</th>
                <th>{{ $t('Warehouse') }}</th>
                <th class="num">{{ $t('Change') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th>{{ $t('Done By') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="trail in trails.data"
                :key="trail.id"
                :class="{ 'is-click': trail.transaction_route, 'is-missing': trail.source_missing }"
                @click="openTransaction(trail)"
              >
                <td :data-label="$t('When')">
                  <div class="lg-main">{{ $datetime(trail.created_at) }}</div>
                </td>
                <td :data-label="$t('Movement')">
                  <div class="trl-kind">
                    <span class="trl-dot" :class="dirClass(trail)"></span>
                    <span class="lg-main">{{ kind(trail.type) }}</span>
                  </div>
                  <div v-if="trail.transaction_reference" class="trl-ref">{{ trail.transaction_reference }}</div>
                  <div v-else-if="trail.source_missing" class="trl-ref trl-ref-missing lg-tip tip-left" tabindex="0" :data-tip="$t('The document behind this movement was permanently deleted, so it can no longer be opened.')">
                    <icons name="exclamation" class="w-3 h-3" />{{ $t('Source deleted') }}
                  </div>
                </td>
                <td :data-label="$t('Warehouse')">{{ $t(trail.warehouse.name) }}</td>
                <td class="num" :data-label="$t('Change')">
                  <span class="trl-change" :class="dirClass(trail)">
                    <icons v-if="trail.change > 0" name="up" class="w-3 h-3" />
                    <icons v-else-if="trail.change < 0" name="down" class="w-3 h-3" />
                    {{ trail.change > 0 ? '+' : '' }}{{ $number(trail.change) }}
                  </span>
                </td>
                <td class="num lg-main" :data-label="$t('Balance')">{{ $number(trail.quantity) }}</td>
                <td :data-label="$t('Unit')">{{ trail.unit ? trail.unit.name : '—' }}</td>
                <td :data-label="$t('Done By')">{{ trail.done_by || '—' }}</td>
              </tr>
              <tr v-if="trails.data.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-6" :meta="trails.meta" :links="trails.links" />
    </div>
  </admin-layout>
</template>

<script>
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  props: { item: Object, trails: Object, currentStock: { type: Array, default: () => [] } },

  components: { Pagination, AdminLayout, PageHeader, StatTiles },

  computed: {
    tiles() {
      const list = [{ label: this.$t('On hand now'), value: this.$number(this.totalOnHand), icon: 'collection', tone: 'navy' }];
      const total = this.trails && this.trails.meta ? this.trails.meta.total : null;
      if (total !== null && total !== undefined) {
        list.push({ label: this.$t('Movement'), value: total, icon: 'refresh', tone: 'indigo' });
      }
      list.push({ label: this.$t('Warehouse'), value: this.stockRows.length, icon: 'building', tone: 'teal' });
      return list;
    },
    totalOnHand() {
      return this.currentStock.reduce((sum, s) => sum + (Number(s.quantity) || 0), 0);
    },
    stockRows() {
      return this.currentStock.filter(s => Number(s.quantity) !== 0).sort((a, b) => b.quantity - a.quantity);
    },
    stockChips() {
      return this.stockRows.slice(0, 8);
    },
    hiddenStock() {
      return Math.max(0, this.stockRows.length - 8);
    },
  },

  methods: {
    // "Updating Checkin Item" / "Editing Adjustment Item Variant" → "Checkin" / "Adjustment"
    kind(type) {
      return String(type || '')
        .replace(/^(Updating|Editing)\s+/, '')
        .replace(/\s+Item(\s+Variant)?$/, '');
    },
    dirClass(trail) {
      return trail.change > 0 ? 'is-in' : trail.change < 0 ? 'is-out' : 'is-flat';
    },
    openTransaction(trail) {
      if (!trail.transaction_route || !trail.transaction_id) {
        return;
      }

      this.$inertia.visit(this.route(trail.transaction_route, trail.transaction_id));
    },
  },
};
</script>

<style scoped>
.trl-stock { display: flex; flex-wrap: wrap; gap: 8px; }
.trl-stock-chip { display: inline-flex; align-items: center; gap: 8px; max-width: 100%; padding: 6px 12px; border-radius: 9999px; font-size: 0.76rem; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.trl-stock-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lg-mute); font-weight: 700; }
.trl-stock-qty { font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.trl-stock-more { color: var(--lg-faint); font-weight: 800; }

.lg-table tbody tr.is-missing td { background: repeating-linear-gradient(135deg, rgba(245, 158, 11, 0.06) 0 8px, transparent 8px 16px); }
.trl-kind { display: flex; align-items: center; gap: 8px; }
.trl-ref { margin-top: 2px; margin-left: 17px; font-size: 0.72rem; font-weight: 700; color: #4f46e5; }
html.dark .trl-ref { color: #a5b4fc; }
.trl-ref-missing { display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; border-radius: 9999px; color: #92400e; background: rgba(245, 158, 11, 0.16); cursor: help; }
html.dark .trl-ref-missing { color: #fcd34d; }
.trl-dot { width: 9px; height: 9px; flex: none; border-radius: 9999px; background: var(--lg-faint); }
.trl-dot.is-in { background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18); }
.trl-dot.is-out { background: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.16); }
.trl-change { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 9999px; font-weight: 800; font-variant-numeric: tabular-nums; }
.trl-change.is-in { color: #047857; background: rgba(16, 185, 129, 0.12); }
.trl-change.is-out { color: #b91c1c; background: rgba(239, 68, 68, 0.12); }
.trl-change.is-flat { color: var(--lg-mute); background: var(--lg-soft); }
html.dark .trl-change.is-in { color: #6ee7b7; }
html.dark .trl-change.is-out { color: #fda4af; }
@media (max-width: 767.98px) {
  .trl-ref { margin-left: 0; }
  .trl-kind { justify-content: flex-end; }
}
</style>
