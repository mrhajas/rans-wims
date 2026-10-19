<template>
  <admin-layout :title="$t('Issue & Return Dashboard')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-pie" :title="$t('Issue & Return Dashboard')" :subtitle="$t('Summary of issued and returned reusable materials.')" />

      <stat-tiles :items="statItems" @select="onTileSelect" />

      <section v-if="activeFilter" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="adjustments" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="idb-filter-label">{{ $t('Filtered Transactions') }}</div>
            <h2 class="idb-filter-name">{{ $t(activeFilter.label) }}</h2>
          </div>
          <div class="flex items-center gap-2 ml-auto print:hidden">
            <button type="button" @click="printFiltered" class="lg-btn lg-btn-green"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
            <button type="button" @click="clearFilter" class="lg-btn lg-btn-soft"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button>
          </div>
        </header>

        <div v-if="activeFilter.type === 'issue'">
          <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Issue Number') }}</th>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                <th>{{ $t('Issued To') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="issue in filteredIssues" :key="`filtered-issue-${issue.id}`" class="is-click" @click="openIssue(issue.id)">
                <td :data-label="$t('Issue Number')"><span class="idb-ref">{{ issue.issue_number }}</span></td>
                <td :data-label="$t('Date')">{{ $date(issue.issue_date) }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ issue.warehouse?.name || 'N/A' }}</td>
                <td :data-label="$t('Issued To')">{{ issue.issued_to }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusBadgeClass(issue.computed_status || issue.status)">{{ prettyStatus(issue.computed_status || issue.status) }}</span></td>
              </tr>
              <tr v-if="!filteredIssues.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No matching issues found.') }}</td></tr>
            </tbody>
          </table>
        </div>
        </div>

        <div v-else>
          <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Issue Number') }}</th>
                <th>{{ $t('Return Date') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                <th>{{ $t('Returned By') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ret in filteredReturns" :key="`filtered-return-${ret.id}`" class="is-click" @click="openIssue(ret.issue_transaction_id)">
                <td :data-label="$t('Issue Number')"><span class="idb-ref">{{ ret.issue_transaction?.issue_number || 'N/A' }}</span></td>
                <td :data-label="$t('Return Date')">{{ $date(ret.return_date) }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ ret.issue_transaction?.warehouse?.name || 'N/A' }}</td>
                <td :data-label="$t('Returned By')">{{ ret.returned_by }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip lg-chip-green">{{ $t(activeFilter.statusLabel) }}</span></td>
              </tr>
              <tr v-if="!filteredReturns.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No matching returns found.') }}</td></tr>
            </tbody>
          </table>
        </div>
        </div>
      </section>

      <div class="idb-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="out" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent Issues') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Issue Number') }}</th>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                <th>{{ $t('Issued To') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="issue in latestIssues" :key="issue.id" class="is-click" @click="openIssue(issue.id)">
                <td :data-label="$t('Issue Number')"><span class="idb-ref">{{ issue.issue_number }}</span></td>
                <td :data-label="$t('Date')">{{ $date(issue.issue_date) }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ issue.warehouse?.name }}</td>
                <td :data-label="$t('Issued To')">{{ issue.issued_to }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusBadgeClass(issue.computed_status || issue.status)">{{ prettyStatus(issue.computed_status || issue.status) }}</span></td>
              </tr>
              <tr v-if="!latestIssues?.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No recent issues found.') }}</td></tr>
            </tbody>
          </table>
        </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="in" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent Returns') }}</h2></div>
          </header>
          <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Issue Number') }}</th>
                <th>{{ $t('Return Date') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                <th>{{ $t('Returned By') }}</th>
                <th>{{ $t('Received By') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ret in latestReturns" :key="ret.id" class="is-click" @click="openIssue(ret.issue_transaction_id)">
                <td :data-label="$t('Issue Number')"><span class="idb-ref">{{ ret.issue_transaction?.issue_number || 'N/A' }}</span></td>
                <td :data-label="$t('Return Date')">{{ $date(ret.return_date) }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ ret.issue_transaction?.warehouse?.name || 'N/A' }}</td>
                <td :data-label="$t('Returned By')">{{ ret.returned_by }}</td>
                <td :data-label="$t('Received By')">{{ ret.received_by }}</td>
              </tr>
              <tr v-if="!latestReturns?.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No recent returns found.') }}</td></tr>
            </tbody>
          </table>
        </div>
        </section>
      </div>
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
    today: String,
    cards: Object,
    latestIssues: Array,
    latestReturns: Array,
    allIssues: {
      type: Array,
      default: () => [],
    },
    allReturns: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      activeFilterKey: null,
    };
  },
  computed: {
    activeFilter() {
      return this.cardRows.find(card => card.key === this.activeFilterKey) || null;
    },
    cardRows() {
      return [
        {
          key: 'total_issued_items',
          label: 'Total Issued Items',
          value: this.cards.total_issued_items,
          icon: 'collection',
          clickable: false,
          textClass: 'text-slate-500',
          valueClass: 'text-slate-800',
          iconBgClass: 'bg-slate-100',
          iconClass: 'text-slate-700',
        },
        {
          key: 'outstanding_issues',
          label: 'Outstanding Issues',
          value: this.cards.outstanding_issues,
          icon: 'info',
          clickable: true,
          type: 'issue',
          statusLabel: 'Outstanding',
          textClass: 'text-amber-600',
          valueClass: 'text-amber-800',
          iconBgClass: 'bg-amber-100',
          iconClass: 'text-amber-700',
        },
        {
          key: 'fully_returned',
          label: 'Fully Returned',
          value: this.cards.fully_returned,
          icon: 'tick',
          clickable: true,
          type: 'issue',
          statusLabel: 'Fully Returned',
          textClass: 'text-emerald-600',
          valueClass: 'text-emerald-800',
          iconBgClass: 'bg-emerald-100',
          iconClass: 'text-emerald-700',
        },
        {
          key: 'partially_returned',
          label: 'Partially Returned',
          value: this.cards.partially_returned,
          icon: 'refresh',
          clickable: true,
          type: 'issue',
          statusLabel: 'Partially Returned',
          textClass: 'text-indigo-600',
          valueClass: 'text-indigo-800',
          iconBgClass: 'bg-indigo-100',
          iconClass: 'text-indigo-700',
        },
        {
          key: 'overdue_returns',
          label: 'Overdue Returns',
          value: this.cards.overdue_returns,
          icon: 'bell',
          clickable: false,
          textClass: 'text-rose-600',
          valueClass: 'text-rose-800',
          iconBgClass: 'bg-rose-100',
          iconClass: 'text-rose-700',
        },
        {
          key: 'damaged_returns',
          label: 'Damaged Returns',
          value: this.cards.damaged_returns,
          icon: 'cross',
          clickable: false,
          textClass: 'text-red-600',
          valueClass: 'text-red-800',
          iconBgClass: 'bg-red-100',
          iconClass: 'text-red-700',
        },
        {
          key: 'todays_issues',
          label: "Today's Issues",
          value: this.cards.todays_issues,
          icon: 'doc-text',
          clickable: true,
          type: 'issue',
          statusLabel: "Today's Issues",
          textClass: 'text-blue-600',
          valueClass: 'text-blue-800',
          iconBgClass: 'bg-blue-100',
          iconClass: 'text-blue-700',
        },
        {
          key: 'todays_returns',
          label: "Today's Returns",
          value: this.cards.todays_returns,
          icon: 'in',
          clickable: true,
          type: 'return',
          statusLabel: "Today's Returns",
          textClass: 'text-teal-600',
          valueClass: 'text-teal-800',
          iconBgClass: 'bg-teal-100',
          iconClass: 'text-teal-700',
        },
      ];
    },
    statItems() {
      const tones = {
        total_issued_items: 'slate',
        outstanding_issues: 'amber',
        fully_returned: 'emerald',
        partially_returned: 'indigo',
        overdue_returns: 'rose',
        damaged_returns: 'pink',
        todays_issues: 'sky',
        todays_returns: 'teal',
      };
      return this.cardRows.map(card => ({
        icon: card.icon,
        tone: tones[card.key] || 'navy',
        label: this.$t(card.label),
        value: this.formatNumber(card.value),
        active: card.clickable ? this.activeFilterKey === card.key : undefined,
        card,
      }));
    },
    filteredIssues() {
      if (!this.activeFilter || this.activeFilter.type !== 'issue') {
        return [];
      }

      const rows = this.allIssues || [];
      switch (this.activeFilter.key) {
        case 'outstanding_issues':
          return rows.filter(issue => Number(issue.balance_quantity || 0) > 0);
        case 'fully_returned':
          return rows.filter(issue => String(issue.computed_status || issue.status) === 'fully_returned');
        case 'partially_returned':
          return rows.filter(issue => String(issue.computed_status || issue.status) === 'partially_returned');
        case 'todays_issues':
          return rows.filter(issue => String(issue.issue_date || '').slice(0, 10) === this.today);
        default:
          return [];
      }
    },
    filteredReturns() {
      if (!this.activeFilter || this.activeFilter.type !== 'return') {
        return [];
      }
      if (this.activeFilter.key === 'todays_returns') {
        return (this.allReturns || []).filter(ret => String(ret.return_date || '').slice(0, 10) === this.today);
      }
      return [];
    },
  },
  methods: {
    onTileSelect(item) {
      this.onCardClick(item.card);
    },
    onCardClick(card) {
      if (!card.clickable) {
        return;
      }
      this.activeFilterKey = this.activeFilterKey === card.key ? null : card.key;
    },
    clearFilter() {
      this.activeFilterKey = null;
    },
    cardClass(card) {
      if (!card.clickable) {
        return 'bg-white border-gray-100 cursor-default';
      }

      if (this.activeFilterKey === card.key) {
        return 'bg-indigo-50 border-indigo-300 ring-2 ring-indigo-100 cursor-pointer';
      }

      return 'bg-white border-gray-100 hover:border-indigo-200 hover:shadow-md cursor-pointer';
    },
    openIssue(issueId) {
      if (!issueId) {
        return;
      }
      this.$inertia.get(this.route('issue-materials.show', issueId));
    },
    printFiltered() {
      window.print();
    },
    formatNumber(v) {
      const n = Number(v || 0);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    prettyStatus(v) {
      return String(v || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    },
    statusBadgeClass(status) {
      const key = String(status || 'open');
      if (key === 'fully_returned') {
        return 'lg-chip-green';
      }
      if (key === 'partially_returned') {
        return 'lg-chip-indigo';
      }
      if (key === 'overdue') {
        return 'lg-chip-red';
      }
      return 'lg-chip-amber';
    },
  },
};
</script>

<style scoped>
.idb-ref { font-weight: 800; color: #4338ca; }
html.dark .idb-ref { color: #a5b4fc; }
.idb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); gap: 1rem; }
.idb-grid > .lg-card { margin-bottom: 0; min-width: 0; }
.idb-filter-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.idb-filter-name { font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); }
</style>
