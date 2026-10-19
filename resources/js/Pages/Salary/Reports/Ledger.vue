<template>
  <admin-layout :title="title">
    <salary-page>
      <page-header icon="doc-text" :title="title" :subtitle="staff.name + ' · ' + staff.staff_number">
        <a :href="route('salary.reports.ledger.print', staff.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('salary.reports.ledger.pdf', staff.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
      </page-header>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Description') }}</th>
                <th class="num">{{ $t('Debit') }}</th>
                <th class="num">{{ $t('Credit') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in entries" :key="i">
                <td :data-label="$t('Date')">{{ e.date }}</td>
                <td :data-label="$t('Reference')"><span class="lg-mono">{{ e.reference }}</span></td>
                <td :data-label="$t('Description')" class="rl-desc">{{ e.description }}</td>
                <td :data-label="$t('Debit')" class="num">{{ e.debit > 0 ? $money(e.debit) : '' }}</td>
                <td :data-label="$t('Credit')" class="num is-good">{{ e.credit > 0 ? $money(e.credit) : '' }}</td>
                <td :data-label="$t('Balance')" class="num lg-main">{{ $money(e.balance) }}</td>
              </tr>
              <tr v-if="entries.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('No salary transactions recorded yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="rl-closing">
        <span class="rl-closing-label">{{ $t('Closing Outstanding Balance') }}</span>
        <span class="rl-closing-value">{{ $money(closing_balance) }}</span>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, SalaryPage, PageHeader },
  props: { title: String, staff: Object, entries: Array, closing_balance: Number },
};
</script>

<style scoped>
.rl-desc { white-space: normal; min-width: 14rem; }
.rl-closing { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 0.9rem 1.3rem; border-radius: 22px; background: linear-gradient(145deg, #1c3f66, #0f2540); color: #fff; box-shadow: 0 18px 34px -18px rgba(15, 37, 64, 0.8); }
.rl-closing-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; opacity: 0.8; }
.rl-closing-value { font-size: 1.3rem; font-weight: 800; font-variant-numeric: tabular-nums; }
</style>
