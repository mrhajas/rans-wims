<template>
  <admin-layout :title="$t('Quotations')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Quotations')" :subtitle="$t('Supplier quotations gathered against approved purchase requests.')">
        <Link :href="route('supplier-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="home" class="w-4 h-4" />{{ $t('Supplier Records') }}
        </Link>
        <Link :href="route('supplier-records.quotations.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Quotation') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end print:hidden relative z-20">
        <auto-complete id="status" position="left" :label="$t('Status')" v-model="form.status" class="mt-1 w-full max-w-xs" :suggestions="statusOptions" clearable />
      </div>

      <section class="lg-card spr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Valid Until') }}</th>
                <th class="text-right">{{ $t('Total') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="q in quotations.data" :key="q.id" class="is-click" @click="goto(q.id)">
                <td :data-label="$t('Reference')"><span class="lg-main">{{ q.reference }}</span></td>
                <td :data-label="$t('Supplier')">{{ q.supplier_name }}</td>
                <td :data-label="$t('Date')">{{ $date(q.date) }}</td>
                <td :data-label="$t('Valid Until')">{{ q.valid_until ? $date(q.valid_until) : '-' }}</td>
                <td :data-label="$t('Total')" class="text-right font-semibold">{{ $money(q.total) }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusBadgeClass(q.status)" style="text-transform: none">{{ q.status }}</span>
                </td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('supplier-records.quotations.show', q.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                    <a v-if="$can('print-quotations')" :href="route('supplier-records.quotations.print', q.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')">
                      <icons name="printer" class="w-4 h-4" />
                    </a>
                  </div>
                </td>
              </tr>
              <tr v-if="quotations.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination v-if="quotations.data.length" class="mt-6" :meta="quotations.meta" :links="quotations.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { PageHeader, StatTiles, AdminLayout, AutoComplete, Pagination },

  props: {
    filters: Object,
    statuses: Array,
    quotations: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        status: this.filters.status,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'doc-text', tone: 'navy', label: this.$t('All'), value: this.stats.total, status: null, active: !this.form.status },
        { icon: 'doc', tone: 'slate', label: this.$t('Draft'), value: this.stats.draft, status: 'Draft', active: this.form.status === 'Draft' },
        { icon: 'send', tone: 'sky', label: this.$t('Sent'), value: this.stats.sent, status: 'Sent', active: this.form.status === 'Sent' },
        { icon: 'clock', tone: 'amber', label: this.$t('Received'), value: this.stats.received, status: 'Received', active: this.form.status === 'Received' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Selected'), value: this.stats.selected, status: 'Selected', active: this.form.status === 'Selected' },
        { icon: 'x-circle', tone: 'rose', label: this.$t('Rejected'), value: this.stats.rejected, status: 'Rejected', active: this.form.status === 'Rejected' },
      ];
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('supplier-records.quotations.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    pickTile(item) {
      this.form.status = item.status || null;
    },
    goto(id) {
      this.$inertia.visit(route('supplier-records.quotations.show', id));
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        Sent: 'lg-chip-blue',
        Received: 'lg-chip-amber',
        Selected: 'lg-chip-green',
        Rejected: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.spr-card {
  padding: 0.9rem;
}
@media (max-width: 767.98px) {
  .spr-card {
    padding: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
  }
}
</style>
