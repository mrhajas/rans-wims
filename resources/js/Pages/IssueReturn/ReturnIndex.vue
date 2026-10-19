<template>
  <admin-layout :title="$t('Return Material')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="in" :title="$t('Return Material')" :subtitle="$t('Select an open issue and post returned quantities.')" />

      <div class="txn-toolbar flex flex-wrap gap-3 items-center relative z-20 print:hidden">
        <search-filter v-model="form.search" class="w-full sm:max-w-md flex-1 min-w-[220px]" @reset="reset" />
        <span class="lg-subtext ml-auto">{{ issues.meta ? issues.meta.total : issues.data.length }} {{ $t('result(s)') }}</span>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Issue Number') }}</th>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
              <th>{{ $t('Issued To') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="num">{{ $t('Balance Qty') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="issue in issues.data" :key="issue.id" class="is-click" @click="openIssue(issue.id)">
              <td :data-label="$t('Issue Number')"><span class="lg-main">{{ issue.issue_number }}</span></td>
              <td :data-label="$t('Date')">{{ $date(issue.issue_date) }}</td>
              <td :data-label="$t('Project(s) / Warehouse(s)')">{{ issue.warehouse?.name }}</td>
              <td :data-label="$t('Issued To')">{{ issue.issued_to }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(statusValue(issue))">{{ prettyStatus(statusValue(issue)) }}</span></td>
              <td :data-label="$t('Balance Qty')" class="num font-bold lg-tip" :data-tip="issueItemsSummary(issue)">{{ formatNumber(balanceValue(issue)) }}</td>
              <td data-label="" @click.stop>
                <div class="lg-actions">
                  <Link v-if="canReturn(issue)" :href="route('return-materials.create', issue.id)" :aria-label="$t('Return items')" class="lg-btn lg-btn-green">
                    <icons name="in" class="w-4 h-4" />{{ $t('Return Items') }}
                  </Link>
                  <span v-else class="lg-chip lg-chip-slate"><icons name="tick" class="w-3 h-3" />{{ $t('Completed') }}</span>
                </div>
              </td>
            </tr>
            <tr v-if="issues.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="issues.meta" :links="issues.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { AdminLayout, PageHeader, SearchFilter, Pagination },
  props: { filters: Object, issues: Object },
  data() {
    return {
      form: { search: this.filters.search },
    };
  },
  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.get(this.route('return-materials.index', Object.keys(query).length ? query : {}), {}, { preserveState: true, replace: true });
      }, 250),
      deep: true,
    },
  },
  methods: {
    openIssue(issueId) {
      if (!issueId) {
        return;
      }

      this.$inertia.get(this.route('issue-materials.show', issueId));
    },
    reset() {
      this.form = { search: null };
      this.$inertia.get(this.route('return-materials.index'), {}, { preserveState: true, replace: true });
    },
    formatNumber(v) {
      const n = Number(v || 0);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    prettyStatus(v) {
      return String(v || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    },
    statusChip(v) {
      const map = { fully_returned: 'lg-chip-green', partially_returned: 'lg-chip-amber', issued: 'lg-chip-blue', open: 'lg-chip-blue' };
      return map[String(v || '')] || 'lg-chip-slate';
    },
    statusValue(issue) {
      return issue.computed_status || issue.status;
    },
    balanceValue(issue) {
      const computed = issue.computed_balance_quantity;
      if (computed === null || computed === undefined || computed === '') {
        return issue.balance_quantity;
      }

      return computed;
    },
    canReturn(issue) {
      return this.statusValue(issue) !== 'fully_returned' && Number(this.balanceValue(issue) || 0) > 0;
    },
    issueItemsSummary(issue) {
      const lines = (issue.items || [])
        .map(line => {
          const name = line.item?.name || line.item?.code || 'Item';
          return `${name}: ${this.formatNumber(line.balance_quantity)}`;
        })
        .filter(Boolean);

      if (!lines.length) {
        return 'No item lines';
      }

      return lines.join(' | ');
    },
  },
};
</script>

<style scoped>
.ri-done { color: var(--lg-mute); }
</style>
