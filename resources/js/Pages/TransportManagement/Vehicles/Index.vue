<template>
  <admin-layout :title="$t('Vehicles')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="car" :title="$t('Vehicles')" :subtitle="$t('Registered vehicles available for Transport Routes trips.')">
        <a v-if="$can('print-transport-records')" :href="route('transport-vehicles.print-list') + queryString" target="_blank" class="lg-btn lg-btn-green">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-transport-records')" :href="route('transport-vehicles.pdf-list') + queryString" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <Link v-if="canCreate" :href="route('transport-vehicles.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Vehicle') }}
        </Link>
      </page-header>

      <stat-tiles v-if="stats" :items="statTiles" />

      <p class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <span>
          {{ $t('Vehicles registered in the') }}
          <Link v-if="$can('read-assets')" :href="route('assets.index')" class="tv-link">{{ $t('Asset Register') }}</Link>
          <b v-else>{{ $t('Asset Register') }}</b>
          {{ $t('are managed there and are not listed here.') }}
        </span>
      </p>

      <div class="lg-card tv-filters relative z-20">
        <div class="lg-fields tv-filter-grid">
          <div class="sa-search-wrap">
            <icons name="search" class="w-4 h-4 sa-search-icon" />
            <input v-model="form.search" type="text" :placeholder="$t('Search reg. number, name, VIN…')" class="sa-search-input" />
          </div>
          <div>
            <auto-complete id="filter_status" :suggestions="statusOptions" v-model="form.status" :placeholder="$t('All Statuses')" clearable />
          </div>
          <div>
            <auto-complete id="filter_category" :suggestions="categories" v-model="form.asset_category_id" :placeholder="$t('All Categories')" clearable />
          </div>
        </div>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="w-12">{{ $t('No') }}</th>
              <th>{{ $t('Reg. Number') }}</th>
              <th>{{ $t('Name / Make / Model') }}</th>
              <th>{{ $t('Category') }}</th>
              <th>{{ $t('Current Driver') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(vehicle, idx) in vehicles.data"
              :key="vehicle.id"
              class="is-click"
              @click="$inertia.visit(route('transport-vehicles.show', vehicle.id))"
            >
              <td :data-label="$t('No')" class="tm-no">{{ (vehicles.meta?.from ?? 1) + idx }}</td>
              <td :data-label="$t('Reg. Number')">
                <span v-if="vehicle.registration_number" class="lg-chip lg-chip-slate" style="text-transform: none">{{ vehicle.registration_number }}</span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td :data-label="$t('Name / Make / Model')">
                <div class="lg-main inline-flex items-center gap-1.5">
                  {{ vehicle.name }}
                  <span
                    v-if="vehicle.opening_balance !== null"
                    class="tv-ob-indicator lg-tip tip-left"
                    :data-tip="$t('Opening Balance') + ': ' + $money(vehicle.opening_balance) + ' · ' + $date(vehicle.opening_balance_date)"
                  >
                    <icons name="calculator" class="w-3 h-3" />
                  </span>
                </div>
                <div class="lg-subtext" v-if="vehicle.manufacturer || vehicle.model || vehicle.year">
                  {{ [vehicle.manufacturer, vehicle.model, vehicle.year].filter(Boolean).join(' · ') }}
                </div>
              </td>
              <td :data-label="$t('Category')">{{ vehicle.category?.name || '—' }}</td>
              <td :data-label="$t('Current Driver')">
                <span v-if="vehicle.operating_personnel_contact" class="lg-person">
                  <span class="lg-avatar">{{ initials(vehicle.operating_personnel_contact.name) }}</span>
                  <span class="lg-main">{{ vehicle.operating_personnel_contact.name }}</span>
                </span>
                <span v-else class="lg-subtext">{{ $t('Unassigned') }}</span>
              </td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(vehicle.status)" style="text-transform: none">
                  <icons :name="statusIcon(vehicle.status)" class="w-3 h-3" />
                  {{ $t(vehicle.status) }}
                </span>
              </td>
              <td class="lg-sticky-end" data-label="" @click.stop>
                <div class="lg-actions">
                  <Link :href="route('transport-vehicles.show', vehicle.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')">
                    <icons name="eye-flat"></icons>
                  </Link>
                </div>
              </td>
            </tr>
            <tr v-if="!vehicles.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-6" :meta="vehicles.meta" :links="vehicles.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { Link, AdminLayout, AutoComplete, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    vehicles: Object,
    statuses: Array,
    categories: Array,
    canCreate: Boolean,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        status: this.filters.status || null,
        asset_category_id: this.filters.asset_category_id || null,
      },
    };
  },

  computed: {
    // Print / Download carry the filters currently applied, so the paper matches the table.
    queryString() {
      const qs = new URLSearchParams(pickBy(this.form)).toString();
      return qs ? '?' + qs : '';
    },
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'car', tone: 'navy', label: this.$t('Total Vehicles'), value: this.stats.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('In Service'), value: this.stats.in_service },
        { icon: 'cog', tone: 'amber', label: this.$t('Under Maintenance'), value: this.stats.maintenance },
        { icon: 'user', tone: 'slate', label: this.$t('No Driver Assigned'), value: this.stats.unassigned },
      ];
    },
    statusOptions() {
      return this.statuses.map(s => ({ value: s, label: this.$t(s) }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(this.route('transport-vehicles.index'), pickBy(this.form), { preserveState: true, replace: true });
      }, 300),
      deep: true,
    },
  },

  methods: {
    // Normalizes a status label into the CSS-safe suffix used by the
    // .tv-status-* badge modifiers below, e.g. "Under Maintenance" -> "under-maintenance".
    statusSlug(status) {
      return (status || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    },
    statusChip(status) {
      if (['Active', 'Available', 'Sold'].includes(status)) return 'lg-chip-green';
      if (['Assigned', 'In Use'].includes(status)) return 'lg-chip-blue';
      if (['Under Maintenance', 'Under Repair'].includes(status)) return 'lg-chip-amber';
      if (status === 'In Transit') return 'lg-chip-violet';
      if (['Lost', 'Stolen', 'Damaged'].includes(status)) return 'lg-chip-red';
      return 'lg-chip-slate';
    },
    initials(name) {
      return (name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    statusIcon(status) {
      if (['Active', 'Available', 'Assigned', 'In Use'].includes(status)) return 'tick';
      if (['Under Maintenance', 'Under Repair'].includes(status)) return 'cog';
      if (status === 'In Transit') return 'truck';
      if (['Lost', 'Stolen', 'Damaged'].includes(status)) return 'exclamation';
      if (status === 'Idle') return 'clock';
      return 'lock-closed';
    },
  },
};
</script>

<style scoped>
.tv-filters { padding-bottom: 1rem; }
.tv-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); align-items: center; }

.sa-search-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.sa-search-icon {
  position: absolute;
  left: 0.8rem;
  color: var(--lg-faint);
  pointer-events: none;
}
.sa-search-input {
  width: 100%; border: 1px solid var(--lg-line); border-radius: 9999px; color: var(--lg-ink);
  padding: 0.55rem 0.9rem 0.55rem 2.3rem; font-size: 0.82rem; background: var(--lg-soft);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sa-search-input:focus {
  outline: none; border-color: #93c5fd;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.tv-ob-indicator {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.1rem;
  height: 1.1rem;
  border-radius: 9999px;
  flex: none;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  cursor: help;
}
html.dark .tv-ob-indicator { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #fbbf24; }
.tv-link { font-weight: 800; text-decoration: underline; }
</style>
