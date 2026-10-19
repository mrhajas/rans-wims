<template>
  <admin-layout :title="$t('Funds Allocation Link')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Funds Allocation Link')" :subtitle="$t('An allocation is a plan, not money received. Here you see, for each one, what has really come into your accounts and what has really been spent.')">
        <acct-export type="funds_allocation" />
      </page-header>
      <form class="lg-card mt-search" style="display: flex; gap: 8px" @submit.prevent="go"><input v-model="q" type="search" class="ac-input" :placeholder="$t('Search by number or title')" /><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Search') }}</button></form>
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('Allocation') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Status') }}</th><th class="ac-num">{{ $t('Allocated (plan)') }}</th><th>{{ $t('Received / spent') }}</th></tr></thead>
        <tbody>
          <tr v-for="a in rows.data" :key="a.id" class="is-click" @click="$inertia.visit(route('acct.funds.show', a.id))">
            <td :data-label="$t('Allocation')"><span class="lg-main">{{ a.reference }}</span><div class="lg-subtext">{{ a.title }}</div></td><td :data-label="$t('Date')">{{ $date(a.date) }}</td><td :data-label="$t('Status')"><span class="lg-chip lg-chip-slate">{{ a.status }}</span></td>
            <td :data-label="$t('Allocated (plan)')" class="ac-num">{{ a.allocated }}<div class="lg-subtext">{{ a.lines }} {{ $t('lines') }}</div></td>
            <td :data-label="$t('Received / spent')"><div v-for="f in a.flows" :key="f.currency" class="ac-split"><span>{{ f.currency }}</span><span>{{ $t('in') }} <b class="ac-in">{{ m(f.received, f.currency) }}</b></span><span>{{ $t('out') }} <b class="ac-out">{{ m(f.spent, f.currency) }}</b></span><span v-if="f.overspent" class="lg-chip lg-chip-red">{{ $t('Overspent') }}</span></div><span v-if="!a.flows.length" class="lg-subtext">{{ $t('No money recorded yet') }}</span></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td></tr>
        </tbody></table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '../Pager.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AdminLayout, PageHeader, Pager },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object },
  data() { return { q: this.filters.q || '' }; },
  methods: { go() { this.$inertia.get(route('acct.funds.index'), { q: this.q || undefined }, { preserveState: true }); } },
};
</script>
