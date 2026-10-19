<template>
  <admin-layout :title="$t('Statement') + ' / ' + supplier.name">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="doc-text"
        :title="supplier.name + ' / ' + $t('Statement')"
        :subtitle="$t('Running account ledger of invoices and payments.')"
      >
        <Link :href="route('supplier-records.suppliers.show', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Profile') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card sp-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th class="num">{{ $t('Debit') }}</th>
                <th class="num">{{ $t('Credit') }}</th>
                <th class="num">{{ $t('Running Balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(entry, index) in ledger" :key="index">
                <td :data-label="$t('Date')">{{ $date(entry.date) }}</td>
                <td :data-label="$t('Type')">
                  <span class="lg-chip" :class="entry.type === 'Payment' ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">{{ entry.type }}</span>
                </td>
                <td :data-label="$t('Reference')"><span class="lg-main">{{ entry.reference }}</span></td>
                <td :data-label="$t('Debit')" class="num" :class="entry.debit ? 'sp-debit' : 'sp-nil'">{{ entry.debit ? $money(entry.debit, supplier.currency) : '-' }}</td>
                <td :data-label="$t('Credit')" class="num" :class="entry.credit ? 'sp-credit' : 'sp-nil'">{{ entry.credit ? $money(entry.credit, supplier.currency) : '-' }}</td>
                <td :data-label="$t('Running Balance')" class="num"><span class="sp-balance">{{ $money(entry.balance, supplier.currency) }}</span></td>
              </tr>
              <tr v-if="ledger.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">
                  <icons name="doc-text" class="w-8 h-8 mx-auto mb-2 opacity-60" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },

  props: {
    supplier: Object,
    openingBalance: Number,
    closingBalance: Number,
    ledger: Array,
  },

  computed: {
    totalDebits() {
      return this.ledger.reduce((sum, e) => sum + (Number(e.debit) || 0), 0);
    },
    totalCredits() {
      return this.ledger.reduce((sum, e) => sum + (Number(e.credit) || 0), 0);
    },
    statTiles() {
      return [
        { icon: 'calendar', tone: 'slate', label: this.$t('Opening Balance'), value: this.$money(this.openingBalance, this.supplier.currency) },
        { icon: 'up', tone: 'rose', label: this.$t('Total Debits'), value: this.$money(this.totalDebits, this.supplier.currency) },
        { icon: 'down', tone: 'emerald', label: this.$t('Total Credits'), value: this.$money(this.totalCredits, this.supplier.currency) },
        { icon: 'cash', tone: this.closingBalance > 0 ? 'amber' : 'emerald', label: this.$t('Closing Balance'), value: this.$money(this.closingBalance, this.supplier.currency), valueClass: this.closingBalance > 0 ? '' : 'is-good' },
      ];
    },
  },
};
</script>


<style scoped>
.sp-debit { color: #b45309; font-weight: 700; }
.sp-credit { color: #047857; font-weight: 700; }
html.dark .sp-debit { color: #fbbf24; }
html.dark .sp-credit { color: #6ee7b7; }
.sp-nil { color: var(--lg-faint); }
.sp-balance { display: inline-block; padding: 2px 10px; border-radius: 9px; font-weight: 800; color: var(--lg-ink); background: var(--lg-soft); }
@media (max-width: 767.98px) {
  .sp-table-card { padding: 0; background: transparent; border: 0; box-shadow: none; }
}
</style>
