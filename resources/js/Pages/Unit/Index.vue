<template>
  <admin-layout :title="$t('Units')">
    <div class="lg-page px-4 md:px-0 txn-shell">
      <page-header icon="calculator" :title="$t('Units')" :subtitle="$t('Units of measure and the formulas that convert between them.')">
        <Link v-if="$can('create-units')" :href="route('units.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Unit') }) }}</Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="txn-toolbar mb-6 flex justify-between items-center print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-md mr-4" @reset="reset">
          <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>
        <tec-dropdown align="right" width="48" v-if="$can(['import-units', 'export-units'])">
          <template #trigger>
            <button class="lg-btn lg-btn-soft"><icons name="menu" class="w-4 h-4" />{{ $t('More') }}</button>
          </template>

          <template #content>
            <a
              v-if="$can('export-units')"
              :href="route('units.export')"
              class="block px-4 py-2 leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
            >
              {{ $t('export_x', { x: $t('Units') }) }}
            </a>
            <tec-dropdown-link v-if="$can('import-units')" :href="route('units.import')">
              {{ $t('import_x', { x: $t('Units') }) }}
            </tec-dropdown-link>
          </template>
        </tec-dropdown>
      </div>
      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th class="px-6 pt-6 pb-4">{{ $t('Name') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Code') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Base Unit') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Formula') }}</th>
          </tr>
          <tr
            :key="unit.id"
            v-for="unit in units.data"
            @click="goto(route('units.edit', unit.id))"
            class="txn-row hover:bg-gray-100 focus-within:bg-gray-100"
            :class="{ 'cursor-pointer': $can('update-units') }"
          >
            <td class="border-t">
              <div class="px-6 py-4">
                <div class="flex items-center lg-main">
                  {{ unit.name }}
                  <icons v-if="unit.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
                </div>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ unit.code }}</span>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                {{ unit.base_unit_id ? unit.base_unit.name : '' }}
                <span class="text-gray-500 ml-1">{{ unit.base_unit_id ? '(' + unit.base_unit.code + ')' : '' }}</span>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span v-if="unit.base_unit_id" class="lg-chip lg-chip-slate lg-mono" style="text-transform: none">{{
                  unit.base_unit.name +
                  ' (' +
                  unit.base_unit.code +
                  ') ' +
                  unit.operator +
                  ' ' +
                  parseFloat(unit.operation_value) +
                  ' = ' +
                  unit.name +
                  ' (' +
                  unit.code +
                  ')'
                }}</span>
              </div>
            </td>
            <td class="border-t w-px">
              <div v-if="$can('update-units')" class="px-4 flex items-center w-full">
                <icons name="chevron-down" class="transform -rotate-90 hidden md:block w-4 h-4" />
                <span class="lg-btn lg-btn-soft row-open md:hidden">{{ $t('Open') }}<icons name="chevron-down" class="transform -rotate-90 block w-4 h-4" /></span>
              </div>
            </td>
          </tr>
          <tr v-if="units.data.length === 0">
            <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination class="mt-4" :meta="units.meta" :links="units.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import TecButton from '@/Jetstream/Button.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';

export default {
  components: {
    PageHeader,
    StatTiles,
    TecButton,
    Pagination,
    AdminLayout,
    TecDropdown,
    SelectInput,
    SearchFilter,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    filters: Object,
    units: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'calculator', tone: 'navy', label: this.$t('Total Units'), value: this.stats.total },
        { icon: 'collection', tone: 'emerald', label: this.$t('Base Units'), value: this.stats.base },
        { icon: 'refresh', tone: 'indigo', label: this.$t('Derived Units'), value: this.stats.derived },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('units.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    goto(link) {
      if (this.$can('update-units')) {
        this.$inertia.visit(link);
      }
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
  },
};
</script>

<style scoped>
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
  opacity: 0.2;
}
.txn-blob-1 {
  top: -60px;
  left: -40px;
  height: 220px;
  width: 220px;
  background: radial-gradient(circle, #6366f1, transparent 70%);
}
.txn-blob-2 {
  top: 40px;
  right: -60px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #a78bfa, transparent 70%);
}

.txn-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(238, 242, 255, 0.65), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(99, 102, 241, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.txn-hero-icon {
  background: linear-gradient(145deg, #6366f1, #4338ca);
  box-shadow: 0 8px 20px -6px rgba(67, 56, 202, 0.5);
}

.txn-pill-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.75rem;
  width: 2.75rem;
  border-radius: 9999px;
  transition: all 0.15s ease;
}
.txn-pill-btn-dark {
  background: linear-gradient(145deg, #1e293b, #0f172a);
  color: #fff;
  box-shadow: 0 6px 16px -6px rgba(15, 23, 42, 0.5);
}
.txn-pill-btn-dark:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 20px -6px rgba(15, 23, 42, 0.55);
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
</style>
