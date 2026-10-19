<template>
  <admin-layout :title="$t('Salary Vouchers')">
    <salary-page>
      <page-header icon="doc-text" :title="$t('Salary Vouchers')" />

      <section class="lg-card sl-filters">
        <div class="lg-fields sl-filter-grid">
          <text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Voucher number…')" />
        </div>
      </section>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Voucher No.') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Period') }}</th>
                <th>{{ $t('Employee') }}</th>
                <th class="num">{{ $t('Net Salary') }}</th>
                <th class="num">{{ $t('Current Payment') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in vouchers.data" :key="v.id" class="is-click" @click="$inertia.visit(route('salary.vouchers.show', v.id))">
                <td :data-label="$t('Voucher No.')" class="lg-main">{{ v.voucher_number }}</td>
                <td :data-label="$t('Type')"><span class="lg-chip lg-chip-slate">{{ v.type }}</span></td>
                <td :data-label="$t('Period')">{{ v.period_label }}</td>
                <td :data-label="$t('Employee')">{{ v.staff_name || '—' }}</td>
                <td :data-label="$t('Net Salary')" class="num">{{ $money(v.net_salary) }}</td>
                <td :data-label="$t('Current Payment')" class="num lg-main is-accent">{{ $money(v.current_payment) }}</td>
              </tr>
              <tr v-if="vouchers.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="vouchers.data.length" class="mt-6" :meta="vouchers" :links="{ prev: vouchers.prev_page_url, next: vouchers.next_page_url }" />
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';

export default {
  components: { AdminLayout, TextInput, Pagination, SalaryPage, PageHeader },
  props: { filters: Object, vouchers: Object },
  data() {
    return { form: { search: this.filters.search || '' } };
  },
  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('salary.vouchers.index', query), { preserveState: true, preserveScroll: true, replace: true });
      }, 200),
      deep: true,
    },
  },
};
</script>

<style scoped>
.sl-filters { position: relative; z-index: 20; }
.sl-filter-grid { grid-template-columns: minmax(0, 420px); }
</style>
