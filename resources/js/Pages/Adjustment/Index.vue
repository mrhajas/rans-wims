<template>
  <admin-layout :title="$t('Adjustments')">
    <div class="lg-page px-4 md:px-0 txn-shell">
      <page-header icon="adjustments" :title="$t('Adjustments')" :subtitle="$t('Please review the data in the table below')">
        <offline-lock>
          <Link :href="route('adjustments.create')" class="lg-btn lg-btn-primary">
            <icons name="plus" class="w-4 h-4" />
            <span>{{ $t('create_x', { x: $t('Adjustment') }) }}</span>
          </Link>
        </offline-lock>
      </page-header>

      <stat-tiles v-if="$connection.status !== 'offline'" :items="statItems" @select="pickTile" />

      <div v-if="$connection.status === 'offline'" class="mb-4 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 print:hidden">
        <icons name="wifi" class="w-3.5 h-3.5 flex-shrink-0" />
        <span>{{ $t('Showing cached adjustments from the last 90 days. Search by reference only.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <div class="txn-toolbar mb-6 flex flex-wrap gap-3 justify-between items-center print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full sm:max-w-md sm:mr-4 flex-1 min-w-[220px]" @reset="reset">
          <auto-complete
            id="trashed"
            position="left"
            :label="$t('Trashed')"
            v-model="form.trashed"
            class="mt-1 w-full"
            :suggestions="[
              { label: $t('Not Trashed'), value: null },
              { label: $t('With Trashed'), value: 'with' },
              { label: $t('Only Trashed'), value: 'only' },
            ]"
          >
          </auto-complete>
          <auto-complete
            id="status"
            position="left"
            :label="$t('Status')"
            v-model="form.status"
            class="mt-1 w-full"
            :suggestions="[
              { label: $t('All'), value: null },
              { label: $t('Pending approval'), value: 'Pending' },
              { label: $t('Approved'), value: 'Approved' },
              { label: $t('Rejected'), value: 'Rejected' },
            ]"
          >
          </auto-complete>
        </search-filter>
      </div>
      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th class="px-4 pt-6 pb-4 txn-no-th" data-nolabel>{{ $t('No') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Adjustment') }}</th>
            <th class="px-6 pt-6 pb-4" data-nolabel>{{ $t('Relations') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Details') }}</th>
          </tr>
          <tr :key="adjustment.id" v-for="(adjustment, ci) in displayAdjustments" class="txn-row hover:bg-gray-100 focus-within:bg-gray-100">
            <td class="border-t txn-no" @click="goto(adjustment.id)" :class="{ 'cursor-pointer': $can('read-adjustments') }">
              <div class="txn-no-n">{{ rowNo(ci) }}</div>
            </td>
            <td class="border-t" @click="goto(adjustment.id)" :class="{ 'cursor-pointer': $can('read-adjustments') }">
              <div class="px-6 py-4 flex items-center focus:text-indigo-500">
                <div>
                  <div class="flex items-center gap-2 flex-wrap">
                    <hover-preview category="adjustments" :id="adjustment.id"><span class="lg-ref">{{ adjustment.reference }}</span></hover-preview>
                    <span
                      class="lg-chip"
                      :class="{ 'lg-chip-green': adjustment.type === 'Addition', 'lg-chip-red': adjustment.type === 'Subtraction', 'lg-chip-amber': adjustment.type !== 'Addition' && adjustment.type !== 'Subtraction' }"
                    >
                      <icons v-if="adjustment.type === 'Addition'" name="up" class="w-3 h-3 inline-flex" />
                      <icons v-else-if="adjustment.type === 'Subtraction'" name="down" class="w-3 h-3 inline-flex" />
                      {{ adjustment.type }}
                    </span>
                  </div>
                  <div class="lg-subtext">{{ $t('Transaction Date') }}: {{ $date(adjustment.date) }}</div>
                  <div class="lg-subtext">{{ $t('Date Created') }}: {{ adjustment.created_at ? $datetime(adjustment.created_at) : '-' }}</div>
                  <div v-if="adjustment.extra_attributes?.super_adjustment_reference" class="flex items-center mt-1">
                    <span class="adj-super-badge lg-tip tip-left" :data-tip="$t('Generated from a Super Adjustment')">
                      <icons name="tick" class="w-3 h-3" />
                      {{ $t('Super Adjustment') }}: {{ adjustment.extra_attributes.super_adjustment_reference }}
                    </span>
                  </div>
                </div>

                <icons v-if="adjustment.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
              </div>
            </td>
            <td class="border-t" @click="goto(adjustment.id)" :class="{ 'cursor-pointer': $can('read-adjustments') }">
              <div class="px-6 py-4">
                <div class="lg-meta">
                  <span class="lg-meta-k">{{ $t('Warehouse') }}</span>
                  <span>{{ adjustment.warehouse?.name }}</span>
                </div>
                <div class="lg-meta">
                  <span class="lg-meta-k">{{ $t('User') }}</span>
                  <span>{{ adjustment.user?.name }}</span>
                </div>
                <div v-if="adjustment.status === 'Approved' && adjustment.approver" class="lg-meta">
                  <span class="lg-meta-k">{{ $t('Approved By') }}</span>
                  <span>{{ adjustment.approver.name }}<span v-if="adjustment.approved_by_role" class="adj-role">{{ adjustment.approved_by_role }}</span></span>
                </div>
                <div v-if="adjustment.status === 'Approved' && adjustment.approved_at" class="lg-meta">
                  <span class="lg-meta-k">{{ $t('Approved Date') }}</span>
                  <span>{{ $datetime(adjustment.approved_at) }}</span>
                </div>
                <div v-if="adjustment.status === 'Rejected' && adjustment.rejecter" class="lg-meta">
                  <span class="lg-meta-k">{{ $t('Rejected By') }}</span>
                  <span>{{ adjustment.rejecter.name }}</span>
                </div>
                <div v-if="adjustment.status === 'Rejected' && adjustment.rejected_at" class="lg-meta">
                  <span class="lg-meta-k">{{ $t('Rejected Date') }}</span>
                  <span>{{ $datetime(adjustment.rejected_at) }}</span>
                </div>
              </div>
            </td>
            <td class="border-t max-w-lg min-w-56" @click="goto(adjustment.id)" :class="{ 'cursor-pointer': $can('read-adjustments') }">
              <div class="px-6 py-4 flex items-center">
                <div class="w-full whitespace-normal line-clamp-3">
                  <div class="mb-1">
                    <span class="lg-chip" :class="statusChip(rowStatus(adjustment))">
                      <icons :name="statusIcon(rowStatus(adjustment))" class="w-3 h-3 inline-flex" />
                      {{ $t(statusLabel(rowStatus(adjustment))) }}
                    </span>
                  </div>
                  <div v-if="adjustment.status === 'Rejected' && adjustment.rejection_reason" class="adj-reject-note">{{ adjustment.rejection_reason }}</div>
                  <div class="lg-muted-text">
                    {{ adjustment.details }}
                  </div>
                </div>
              </div>
            </td>
            <td class="border-t w-16">
              <div class="px-4 flex items-center print:hidden">
                <div class="txn-action-toolbar flex items-center gap-1.5" v-if="$can(['create-adjustments', 'import-adjustments', 'export-adjustments']) || adjustment.can_approve || adjustment.can_change_status">
                  <approval-actions :adjustment="adjustment" compact />
                  <change-status :adjustment="adjustment" compact />
                  <Link :href="route('adjustments.show', adjustment.id)" class="txn-icon-btn lg-tip txn-icon-btn-blue" :data-tip="$t('View')" :aria-label="$t('View')">
                    <icons name="doc"></icons>
                  </Link>
                  <a v-if="$can('print-adjustments')" :href="route('adjustments.print', adjustment.id)" target="_blank" class="txn-icon-btn lg-tip txn-icon-btn-teal" :data-tip="$t('Print')" :aria-label="$t('Print')">
                    <icons name="printer"></icons>
                  </a>
                  <a v-if="$can('print-adjustments')" :href="route('adjustments.pdf', adjustment.id)" class="txn-icon-btn lg-tip txn-icon-btn-cyan" :data-tip="$t('Download')" :aria-label="$t('Download')">
                    <icons name="download"></icons>
                  </a>
                  <Link
                    v-if="$can('update-adjustments') && (adjustment.status !== 'Rejected' || $super)"
                    :href="route('adjustments.edit', adjustment.id)"
                    class="txn-icon-btn lg-tip txn-icon-btn-amber"
                    :data-tip="$t('Edit')" :aria-label="$t('Edit')"
                  >
                    <icons name="edit"></icons>
                  </Link>
                  <template v-if="adjustment.deleted_at">
                    <button type="button" @click="restore(adjustment)" class="txn-icon-btn lg-tip txn-icon-btn-blue" :data-tip="$t('Restore')" :aria-label="$t('Restore')">
                      <icons name="refresh"></icons>
                    </button>
                    <button type="button" @click="deletePermanently(adjustment)" class="txn-icon-btn lg-tip txn-icon-btn-red" :data-tip="$t('Delete Permanently')" :aria-label="$t('Delete Permanently')">
                      <icons name="trash"></icons>
                    </button>
                  </template>
                  <template v-else>
                    <button type="button" @click="destroy(adjustment)" class="txn-icon-btn lg-tip txn-icon-btn-red" :data-tip="$t('Delete')" :aria-label="$t('Delete')">
                      <icons name="trash"></icons>
                    </button>
                  </template>
                </div>
              </div>
            </td>
          </tr>
          <tr v-if="displayAdjustments.length === 0">
            <td class="border-t px-6 py-4" colspan="5">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination v-if="$connection.status !== 'offline'" class="mt-6" :meta="adjustments.meta" :links="adjustments.links" />
    </div>

    <!-- Item Details Modal -->
    <modal :show="details" max-width="4xl" :closeable="true" @close="hideDetails">
      <div class="px-6 py-4 print:px-0">
        <div v-if="details && adjustment" class="flex flex-wrap items-center justify-between gap-2 print:hidden">
          <div class="text-lg" :class="{ 'adj-maroon-title': isSuperAdjustment(adjustment) }">
            {{ $t(isSuperAdjustment(adjustment) ? 'Super Adjustment Details' : 'Adjustment Details') }} <span class="hidden sm:inline">({{ adjustment.reference }})</span>
          </div>
          <div class="txn-modal-actions flex flex-wrap items-center justify-end gap-2 min-w-0 max-w-full">
            <approval-actions :adjustment="adjustment" @done="refreshDetails" />
            <change-status :adjustment="adjustment" @done="refreshDetails" />
            <!-- <button
              @click="print()"
              class="flex items-center justify-center mr-2 h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="printer" class="h-5 w-5" />
            </button>
            <Link
              v-if="$can('update-adjustments')"
              :href="route('adjustments.edit', adjustment.id)"
              class="flex items-center justify-center mr-2 h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="edit" class="h-5 w-5" />
            </Link> -->
            <button
              @click="hideDetails()"
              class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="cross" class="h-5 w-5" />
            </button>
          </div>
        </div>

        <div class="mt-4 print-mt-0">
          <adjustment-details v-if="adjustment" :adjustment="adjustment" />
        </div>
      </div>
    </modal>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Adjustment!"
      :close="closePermanentModal"
      action-text="Delete Permanently"
      :action="deleteCategoryPermanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteItem"
      action-type="delete"
      title="Delete Adjustment!"
      action-text="Delete Adjustment"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Adjustment!"
      :close="closeRestoreModal"
      action-text="Restore Adjustment"
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
import Modal from '@/Jetstream/Modal.vue';
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';
import ApprovalActions from '@/Shared/Adjustment/ApprovalActions.vue';
import ChangeStatus from '@/Shared/Adjustment/ChangeStatus.vue';
import { adjustmentStatusMethods } from '@/Shared/Adjustment/status';
import AdjustmentDetails from './Details.vue';
import TecButton from '@/Jetstream/Button.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import { loadIndexData, loadDetail } from '@/Core/offlineDataSource';

export default {
  components: {
    Modal,
    Dialog,
    TecButton,
    Pagination,
    PageHeader,
    StatTiles,
    AdminLayout,
    TecDropdown,
    SelectInput,
    AutoComplete,
    SearchFilter,
    AdjustmentDetails,
    HoverPreview,
    ApprovalActions,
    ChangeStatus,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    filters: Object,
    stats: { type: Object, default: null },
    adjustments: Object,
  },

  data() {
    return {
      edit: null,
      close: false,
      adjustment: null,
      details: false,
      confirm: false,
      loading: false,
      permanent: false,
      restoreConf: false,
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
        status: this.filters.status,
        view: this.filters.view || null,
      },
      offlineAdjustments: null,
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineAdjustments();
    }
  },

  computed: {
    statItems() {
      const s = this.stats;
      if (!s) return [];
      // Clickable (and highlighted when applied) while online; the server does the filtering.
      const online = this.$connection.status !== 'offline';
      const tile = (view, icon, tone, label, value) => ({ icon, tone, label, value, view, ...(online ? { active: (this.form.view || null) === view } : {}) });
      return [
        tile(null, 'collection', 'navy', this.$t('Total adjustments'), s.total),
        tile('today', 'clock', 'sky', this.$t('Created today'), s.today),
        tile('month', 'calendar', 'indigo', this.$t('This month'), s.month),
        tile('pending', 'doc-text', 'amber', this.$t('Pending approval'), s.drafts),
        tile('rejected', 'cross', 'rose', this.$t('Rejected'), s.rejected ?? 0),
        tile('additions', 'up', 'emerald', this.$t('Additions'), s.additions),
        tile('subtractions', 'down', 'rose', this.$t('Subtractions'), s.subtractions),
        tile('damages', 'exclamation', 'violet', this.$t('Damages'), s.damages),
      ];
    },
    displayAdjustments() {
      if (this.$connection.status === 'offline' && this.offlineAdjustments) {
        return this.offlineAdjustments;
      }
      return this.adjustments.data;
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        if (this.$connection.status === 'offline') {
          this.loadOfflineAdjustments();
          return;
        }
        let query = pickBy(this.form);
        this.$inertia.get(
          this.route('adjustments.index', Object.keys(query).length ? query : { remember: 'forget' }),
          {},
          {
            onFinish: () => {
              document.getElementById('page-search').focus();
            },
          }
        );
      }, 150),
      deep: true,
    },
  },

  methods: {
    // Clicking a summary card filters the list to it; clicking the lit card (or the Total card) shows everything again.
    pickTile(tile) {
      this.form.view = tile.view && this.form.view !== tile.view ? tile.view : null;
    },
    ...adjustmentStatusMethods,
    // Row number across pages (page 2 of 20 per page starts at 21); offline cache has no paging, so it just counts from 1.
    rowNo(index) {
      const from = this.$connection.status === 'offline' ? 1 : this.adjustments.meta?.from || 1;
      return from + index;
    },
    // Rows cached offline before approval status existed only carry the draft flag.
    rowStatus(adjustment) {
      return adjustment.status || (adjustment.draft == 1 ? 'Pending' : 'Approved');
    },
    // After approving / rejecting from the details popup: reload the list and re-open the popup on the fresh record.
    refreshDetails() {
      const id = this.adjustment && this.adjustment.id;
      this.details = false;
      this.adjustment = null;
      this.$inertia.reload({ only: ['adjustments', 'stats'], preserveScroll: true, onFinish: () => id && this.goto(id) });
    },
    async loadOfflineAdjustments() {
      const result = await loadIndexData('adjustments', { search: this.form.search });
      this.offlineAdjustments = result.data;
    },
    async goto(id) {
      if (this.adjustment && this.adjustment.id == id) {
        this.details = true;
        return;
      }

      if (this.$connection.status === 'offline') {
        const cached = await loadDetail('adjustments', id);
        if (!cached) {
          return;
        }
        this.adjustment = cached;
        this.details = true;
        return;
      }

      this.loading = true;
      axios.get(route('adjustments.show', id) + '?json=yes').then(res => {
        this.adjustment = res.data;
        this.details = true;
        this.loading = false;
      });
      // if (this.$can('read-adjustments')) {
      //   this.$inertia.visit(route('adjustments.show', id));
      // }
    },
    showDetails() {
      this.details = false;
    },
    hideDetails() {
      this.details = false;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    sendEmail(id) {
      this.$inertia.get(route('notifications.adjustment', id));
    },
    destroy(edit) {
      this.edit = edit;
      this.confirm = true;
    },
    deleteItem() {
      this.$inertia.delete(route('adjustments.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.edit = null;
      this.confirm = false;
    },
    restore(edit) {
      this.edit = edit;
      this.restoreConf = true;
    },
    restoreItem() {
      this.$inertia.put(this.route('adjustments.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.edit = null;
      this.restoreConf = false;
    },
    deletePermanently(edit) {
      this.edit = edit;
      this.permanent = true;
    },
    deleteCategoryPermanently() {
      this.$inertia.delete(route('adjustments.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.edit = null;
      this.permanent = false;
    },
    print() {
      window.print();
    },
    isSuperAdjustment(adjustment) {
      return !!adjustment?.extra_attributes?.super_adjustment_reference;
    },
  },
};
</script>

<style scoped>
.adj-role { margin-left: 6px; padding: 1px 8px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; color: #4338ca; background: rgba(99, 102, 241, 0.14); vertical-align: middle; }
html.dark .adj-role { color: #c7d2fe; }
.adj-reject-note { margin-bottom: 4px; padding: 5px 9px; border-radius: 10px; font-size: 0.74rem; color: #b91c1c; background: rgba(239, 68, 68, 0.09); white-space: normal; }
html.dark .adj-reject-note { color: #fca5a5; }
/* first data row sits right under the table header / scroll edge: open its tooltips downward */
.txn-table-shell tr:nth-child(2) .lg-tip::after {
  bottom: auto;
  top: calc(100% + 8px);
}
.txn-table-shell .lg-tip::after {
  z-index: 40;
}
.lg-ref {
  font-weight: 800;
  color: var(--lg-navy);
}
html.dark .lg-ref {
  color: #bfdbfe;
}
.lg-meta {
  display: flex;
  align-items: baseline;
  gap: 5px;
  font-size: 0.8rem;
  line-height: 1.5;
}
.lg-meta-k {
  flex: none;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.lg-muted-text {
  color: var(--lg-mute);
}
.adj-maroon-title {
  color: #800000;
  font-weight: 700;
}
.adj-type-value {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}
.adj-type-addition {
  color: #059669;
  font-weight: 700;
}
.adj-type-subtraction {
  color: #800000;
  font-weight: 700;
}
.adj-super-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  color: #4338ca;
  background: #eef2ff;
  border-radius: 9999px;
  padding: 0.15rem 0.55rem;
}
.txn-shell {
  position: relative;
}
.txn-bg-decor {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
  border-radius: inherit;
}
.txn-blob {
  position: absolute;
  border-radius: 9999px;
  filter: blur(70px);
  opacity: 0.25;
}
.txn-blob-1 {
  top: -60px;
  left: -40px;
  height: 220px;
  width: 220px;
  background: radial-gradient(circle, #fb923c, transparent 70%);
}
.txn-blob-2 {
  top: 40px;
  right: -60px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #f472b6, transparent 70%);
}

.txn-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(255, 237, 213, 0.65), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(251, 146, 60, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.txn-hero-icon {
  background: linear-gradient(145deg, #fb923c, #ea580c);
  box-shadow: 0 8px 20px -6px rgba(234, 88, 12, 0.5);
}

.txn-create-btn {
  border-radius: 9999px !important;
}

.txn-table-shell {
  border: 1px solid #eef1f6;
  background: #ffffff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 10px 24px rgba(15, 23, 42, 0.05);
}
.txn-table-shell :deep(th) {
  background: rgba(248, 250, 252, 0.88);
  color: #334155;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.txn-row {
  transition: background 0.15s ease;
}

.txn-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.1rem;
  width: 2.1rem;
  min-height: 0;
  padding: 0;
  border-radius: 9999px;
  transition: all 0.15s ease;
}
.txn-icon-btn-blue {
  background: #dbeafe;
  color: #1d4ed8;
}
.txn-icon-btn-blue:hover {
  background: #2563eb;
  color: #fff;
}
.txn-icon-btn-indigo {
  background: #e0e7ff;
  color: #4338ca;
}
.txn-icon-btn-indigo:hover {
  background: #4f46e5;
  color: #fff;
}
.txn-icon-btn-amber {
  background: #fef3c7;
  color: #b45309;
}
.txn-icon-btn-amber:hover {
  background: #d97706;
  color: #fff;
}
.txn-icon-btn-red {
  background: #fee2e2;
  color: #b91c1c;
}
.txn-icon-btn-red:hover {
  background: #dc2626;
  color: #fff;
}
.txn-icon-btn-teal {
  background: #ccfbf1;
  color: #0f766e;
}
.txn-icon-btn-teal:hover {
  background: #0d9488;
  color: #fff;
}
.txn-icon-btn-cyan {
  background: #cffafe;
  color: #0e7490;
}
.txn-icon-btn-cyan:hover {
  background: #0891b2;
  color: #fff;
}

html.dark .txn-hero {
  background: linear-gradient(150deg, rgba(30, 41, 59, 0.75), rgba(15, 23, 42, 0.6));
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .txn-table-shell {
  background: #0f172a;
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .txn-table-shell :deep(th) {
  background: rgba(30, 41, 59, 0.7);
  color: #cbd5e1;
}

@media (max-width: 640px) {
  .txn-icon-btn {
    height: 2.4rem;
    width: 2.4rem;
  }
}
</style>
