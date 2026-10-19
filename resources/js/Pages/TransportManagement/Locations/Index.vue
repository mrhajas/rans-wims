<template>
  <admin-layout :title="$t('Transport Routes Locations')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="map-pin" :title="$t('Transport Routes Locations')" :subtitle="$t('Named destinations used on Transport Routes Records so trips don\'t need re-typed addresses.')">
        <Link v-if="canCreate" :href="route('transport-locations.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Location') }}
        </Link>
      </page-header>

      <div class="lg-card tm-filters relative z-20">
        <div class="lg-fields tm-filter-grid">
          <div class="sa-search-wrap">
            <icons name="search" class="w-4 h-4 sa-search-icon" />
            <input v-model="form.search" type="text" :placeholder="$t('Search name, address…')" class="sa-search-input" />
          </div>
          <div>
            <auto-complete id="filter_location_type" :suggestions="locationTypeOptions" v-model="form.location_type" :placeholder="$t('All Types')" clearable />
          </div>
        </div>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="w-12">{{ $t('No') }}</th>
              <th>{{ $t('Name') }}</th>
              <th>{{ $t('Type') }}</th>
              <th>{{ $t('Address') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(loc, idx) in locations.data"
              :key="loc.id"
              :class="{ 'is-click': loc.can_edit }"
              @click="loc.can_edit && $inertia.visit(route('transport-locations.edit', loc.id))"
            >
              <td :data-label="$t('No')" class="tm-no">{{ (locations.meta?.from ?? 1) + idx }}</td>
              <td :data-label="$t('Name')">
                <div class="lg-main">{{ loc.name }}</div>
                <div v-if="loc.creator" class="lg-subtext">
                  {{ $t('Added by') }} {{ loc.creator?.name ?? $t('System') }} · {{ $date(loc.created_at) }}
                </div>
              </td>
              <td :data-label="$t('Type')">
                <span class="lg-chip" :class="typeBadgeClass(loc.location_type)" style="text-transform: none">
                  <icons :name="typeIcon(loc.location_type)" class="w-3 h-3 inline-flex" />
                  {{ $t(loc.location_type) }}
                </span>
              </td>
              <td :data-label="$t('Address')"><span class="lg-clip tm-addr">{{ loc.address || '—' }}</span></td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="loc.is_active ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">
                  <icons :name="loc.is_active ? 'tick' : 'cross'" class="w-3 h-3 inline-flex" />
                  {{ loc.is_active ? $t('Active') : $t('Inactive') }}
                </span>
              </td>
              <td class="lg-sticky-end" data-label="" @click.stop>
                <div class="lg-actions">
                  <Link v-if="loc.can_edit" :href="route('transport-locations.edit', loc.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')">
                    <icons name="edit"></icons>
                  </Link>
                  <button v-if="loc.can_delete" type="button" @click="destroy(loc)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')">
                    <icons name="trash"></icons>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!locations.data.length" class="lg-empty-row">
              <td class="lg-empty" colspan="6">
                <icons name="map-pin" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                {{ $t('There is no data to display.') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-6" :meta="locations.meta" :links="locations.links" />
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

export default {
  components: { Link, AdminLayout, AutoComplete, Pagination, PageHeader },

  props: {
    filters: Object,
    locations: Object,
    locationTypes: Array,
    canCreate: Boolean,
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        location_type: this.filters.location_type || null,
      },
    };
  },

  computed: {
    locationTypeOptions() {
      return this.locationTypes.map(t => ({ value: t, label: this.$t(t) }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(this.route('transport-locations.index'), pickBy(this.form), { preserveState: true, replace: true });
      }, 300),
      deep: true,
    },
  },

  methods: {
    typeBadgeClass(type) {
      const map = {
        Office: 'lg-chip-blue',
        Site: 'lg-chip-green',
        Workshop: 'lg-chip-amber',
        Port: 'lg-chip-cyan',
        Airport: 'lg-chip-violet',
        'Government Office': 'lg-chip-red',
        'Other Location': 'lg-chip-slate',
      };
      return map[type] || 'lg-chip-slate';
    },
    typeIcon(type) {
      const map = {
        Office: 'building',
        Site: 'collection',
        Workshop: 'cog',
        Port: 'map-pin',
        Airport: 'plane',
        'Government Office': 'building',
        'Other Location': 'folder',
      };
      return map[type] || 'map-pin';
    },
    async destroy(loc) {
      if (!(await this.$confirm(this.$t('Delete this location? This cannot be undone.'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('transport-locations.destroy', loc.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.tm-filters { padding-bottom: 1.15rem; }
.tm-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); align-items: center; }
.tm-addr { display: block; max-width: 320px; white-space: nowrap; color: var(--lg-mute); }

.sa-search-wrap { position: relative; display: flex; align-items: center; }
.sa-search-icon { position: absolute; left: 0.8rem; color: var(--lg-faint); pointer-events: none; }
.sa-search-input {
  width: 100%; border: 1px solid var(--lg-line); border-radius: 9999px; color: var(--lg-ink);
  padding: 0.55rem 0.9rem 0.55rem 2.3rem; font-size: 0.82rem; background: var(--lg-soft);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sa-search-input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }

@media (max-width: 767.98px) {
  .tm-addr { max-width: 100%; white-space: normal; text-align: right; }
}
</style>
