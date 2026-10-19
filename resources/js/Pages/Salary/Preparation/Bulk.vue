<template>
  <admin-layout :title="$t('Prepare Bulk Salary')">
    <salary-page>
      <page-header icon="users" :title="$t('Prepare Bulk Salary')" :subtitle="period.label + ' · ' + period.batch_reference" />

      <section class="lg-card pb-select">
        <label class="sl-check">
          <input type="checkbox" :checked="allSelected" @change="toggleAll" />
          {{ $t('Select All') }} ({{ eligibleStaff.length }})
        </label>
        <span class="lg-chip lg-chip-indigo">{{ selected.length }} {{ $t('selected') }}</span>
      </section>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="pb-check"></th>
                <th>{{ $t('Employee') }}</th>
                <th>{{ $t('Position') }}</th>
                <th class="num">{{ $t('Basic') }}</th>
                <th class="num">{{ $t('Allowances') }}</th>
                <th class="num">{{ $t('Deductions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in eligibleStaff" :key="s.id" :class="{ 'pb-row-on': selected.includes(s.id) }">
                <td class="pb-check-cell"><input type="checkbox" :value="s.id" v-model="selected" :aria-label="s.name" /></td>
                <td :data-label="$t('Employee')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(s.name) }}</span>
                    <span class="lg-main">{{ s.name }}</span>
                  </div>
                </td>
                <td :data-label="$t('Position')">{{ s.position }}</td>
                <td :data-label="$t('Basic')" class="num">{{ $money(s.basic_salary) }}</td>
                <td :data-label="$t('Allowances')" class="num">{{ $money(s.allowances) }}</td>
                <td :data-label="$t('Deductions')" class="num">{{ $money(s.deductions) }}</td>
              </tr>
              <tr v-if="eligibleStaff.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('Every active employee already has a salary record for this period.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="lg-actionbar">
        <span class="lg-actionbar-note">{{ selected.length }} {{ $t('selected') }}</span>
        <div class="lg-actionbar-btns">
          <Link :href="route('salary.periods.show', period.id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
          <loading-button :loading="form.processing" :disabled="selected.length === 0" class="lg-btn lg-btn-primary" @click="submit">
            {{ $t('Calculate & Prepare') }} ({{ selected.length }})
          </loading-button>
        </div>
      </div>
    </salary-page>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, LoadingButton, Link, SalaryPage, PageHeader },
  props: { period: Object, eligibleStaff: Array },
  data() {
    return {
      selected: [],
      form: this.$inertia.form({ salary_period_id: this.period.id, staff_ids: [] }),
    };
  },
  computed: {
    allSelected() {
      return this.eligibleStaff.length > 0 && this.selected.length === this.eligibleStaff.length;
    },
  },
  methods: {
    initials(name) {
      return (name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
    },
    toggleAll() {
      this.selected = this.allSelected ? [] : this.eligibleStaff.map(s => s.id);
    },
    submit() {
      this.form.staff_ids = this.selected;
      this.form.post(route('salary.preparation.bulk.store'));
    },
  },
};
</script>

<style scoped>
.pb-select { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 0.8rem 1.1rem; }
.pb-check { width: 3rem; }
.pb-check-cell input, .pb-select input { width: 1.05rem; height: 1.05rem; accent-color: #1c3f66; }
.pb-row-on td { background: rgba(59, 130, 246, 0.07); }
@media (max-width: 767.98px) {
  .lg-table.lg-responsive tbody tr:not(.lg-empty-row) td.pb-check-cell { justify-content: flex-start; }
}
</style>
