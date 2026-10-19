<template>
  <admin-layout :title="$t('Deleted Records')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="trash" :title="$t('Deleted Records')" :subtitle="$t('Transactions that have been deleted. You can review or restore them here.')">
        <button type="button" class="lg-btn lg-btn-soft" @click="printPage">
          <icons name="printer" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </button>
        <button type="button" class="lg-btn lg-btn-primary" @click="downloadPdf">
          <icons name="download" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </button>
      </page-header>

      <!-- Type tabs -->
      <div class="mb-4 flex flex-wrap gap-2 print:hidden">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          @click="changeType(tab.key)"
          class="del-tab"
          :class="{ 'del-tab-active': type === tab.key }"
        >
          <icons :name="tab.icon" class="w-3.5 h-3.5" />
          {{ $t(tab.label) }}
          <span class="del-tab-count" :class="{ 'del-tab-count-active': type === tab.key }">{{ counts[tab.key] }}</span>
        </button>
      </div>

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <search-filter v-model="form.search" :dropdown="false" class="mb-3" @reset="reset" />
        <div class="lg-grid">
          <auto-complete v-model="form.deleted_by" :suggestions="userOptions" :placeholder="$t('Deleted by') + ': ' + $t('All users')" clearable />
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ type === 'items' ? $t('Item') : $t('Reference') }}</th>
                <th>{{ $t('Details') }}</th>
                <th>{{ $t('Deleted By') }}</th>
                <th>{{ $t('Deleted At') }}</th>
                <th class="text-right print:hidden">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr :key="record.id" v-for="record in records.data">
                <td :data-label="type === 'items' ? $t('Item') : $t('Reference')">
                  <div class="flex items-center justify-end md:justify-start gap-2">
                    <icons name="trash" class="shrink-0 w-3.5 h-3.5 text-rose-500" />
                    <span class="lg-main">{{ record.primary }}</span>
                  </div>
                  <div v-if="record.name" class="lg-subtext">{{ record.name }}</div>
                </td>
                <td :data-label="$t('Details')">
                  <div v-for="rel in record.relations" :key="rel.label" class="text-xs">
                    <span class="lg-subtext">{{ $t(rel.label) }}:</span> {{ rel.value || '—' }}
                  </div>
                  <div v-if="record.created_by" class="text-xs">
                    <span class="lg-subtext">{{ $t('By') }}:</span> {{ record.created_by }}
                  </div>
                </td>
                <td :data-label="$t('Deleted By')">
                  <div v-if="record.deleted_by" class="lg-person">
                    <span class="lg-avatar">{{ initials(record.deleted_by) }}</span>
                    <span class="lg-main">{{ record.deleted_by }}</span>
                  </div>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Deleted At')"><span class="lg-chip lg-chip-red" style="text-transform: none">{{ record.deleted_at ? $datetime(record.deleted_at) : '—' }}</span></td>
                <td :data-label="$t('Actions')" class="print:hidden">
                  <div class="lg-actions">
                    <Link :href="route(type + '.show', record.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')">
                      <icons name="doc" class="w-4 h-4" />
                    </Link>
                    <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Restore')" :aria-label="$t('Restore')" @click="confirmRestore(record)">
                      <icons name="refresh" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="records.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-2 print:hidden" :meta="records.meta" :links="records.links" />
    </div>

    <!-- Restore Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreRecord"
      title="Restore Record!"
      :close="closeRestore"
      action-text="Restore"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <loading v-if="loading" />
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Dialog from '@/Shared/Dialog.vue';
import Loading from '@/Shared/Loading.vue';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: {
    Dialog,
    Loading,
    Pagination,
    AdminLayout,
    SearchFilter,
    AutoComplete,
    PageHeader,
    Link,
  },

  props: {
    type: String,
    counts: Object,
    filters: Object,
    records: Object,
    users: { type: Array, default: () => [] },
  },

  data() {
    return {
      loading: false,
      restoreConf: false,
      selected: null,
      tabs: [
        { key: 'checkins', label: 'Checkins', icon: 'in' },
        { key: 'checkouts', label: 'Checkouts', icon: 'out' },
        { key: 'transfers', label: 'Transfers', icon: 'truck' },
        { key: 'adjustments', label: 'Adjustments', icon: 'adjustments' },
        { key: 'super-adjustments', label: 'Super Adjustments', icon: 'collection' },
        { key: 'items', label: 'Items', icon: 'bag' },
        { key: 'work-packages', label: 'Work Packages', icon: 'clip' },
        { key: 'work-package-issues', label: 'Issues', icon: 'exclamation' },
      ],
      form: {
        search: this.filters.search,
        deleted_by: this.filters.deleted_by ?? null,
      },
    };
  },

  computed: {
    userOptions() {
      return this.users.map(u => ({ id: u.id, label: u.name }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy({ ...this.form, type: this.type });
        this.$inertia.get(this.route('deleted.index', query), {}, { preserveState: true, replace: true });
      }, 200),
      deep: true,
    },
  },

  methods: {
    initials(name) {
      return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },
    changeType(type) {
      if (type === this.type) return;
      this.$inertia.get(
        this.route('deleted.index', pickBy({ type, search: this.form.search, deleted_by: this.form.deleted_by })),
        {},
        { preserveScroll: true }
      );
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    printPage() {
      const query = pickBy({ type: this.type, search: this.form.search, deleted_by: this.form.deleted_by });
      window.open(this.route('deleted.print', query), '_blank');
    },
    downloadPdf() {
      const query = pickBy({ type: this.type, search: this.form.search, deleted_by: this.form.deleted_by });
      window.open(this.route('deleted.pdf', query), '_blank');
    },
    confirmRestore(record) {
      this.selected = record;
      this.restoreConf = true;
    },
    closeRestore() {
      this.restoreConf = false;
      this.selected = null;
    },
    restoreRecord() {
      this.$inertia.put(this.route(this.type + '.restore', this.selected.id), {
        preserveScroll: true,
        onSuccess: () => this.closeRestore(),
      });
    },
  },
};
</script>

<style scoped>
.del-tab {
  display: inline-flex; align-items: center; gap: 7px; padding: 0.5rem 0.95rem; border-radius: 9999px;
  background: var(--lg-card); border: 1px solid var(--lg-line); color: var(--lg-mute); font-size: 0.78rem; font-weight: 800;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.del-tab:hover { transform: translateY(-1px); color: var(--lg-navy); }
.del-tab-active { background: linear-gradient(145deg, #2f6fb3, #0f2540); border-color: transparent; color: #fff; box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.del-tab-active:hover { color: #fff; }
.del-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 1.35rem; height: 1.35rem; padding: 0 0.35rem; border-radius: 9999px; background: rgba(100, 116, 139, 0.16); font-size: 0.68rem; font-weight: 800; }
.del-tab-count-active { background: rgba(255, 255, 255, 0.22); color: #fff; }

@media print {
  .lg-card { border: none; box-shadow: none; }
}
</style>
