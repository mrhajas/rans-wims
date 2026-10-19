<template>
  <admin-layout :title="$t('Categories')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="collection" :title="$t('Categories')" :subtitle="$t('Group your items into categories and sub-categories.')">
        <offline-lock v-if="$can('create-categories')">
          <Link :href="route('categories.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Category') }) }}</Link>
        </offline-lock>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div v-if="$connection.status === 'offline'" class="lg-note lg-note-amber mb-4 print:hidden">
        <icons name="wifi" class="w-4 h-4" />
        <span>{{ $t('Showing cached categories.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <div class="txn-toolbar mb-6 flex justify-between items-center print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-md mr-4" @reset="reset">
          <label class="mt-4 block text-gray-700">{{ $t('Child of') }}:</label>
          <select-input v-model="form.parent" class="mt-1 w-full">
            <option :value="null">{{ $t('All') }}</option>
            <template v-for="c in parents" :key="c.id">
              <option :value="c.id">{{ c.name }}</option>
            </template>
          </select-input>
          <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>
        <tec-dropdown align="right" width="48" v-if="$can(['import-categories', 'export-categories'])">
          <template #trigger>
            <button class="lg-btn lg-btn-soft"><icons name="menu" class="w-4 h-4" />{{ $t('More') }}</button>
          </template>

          <template #content>
            <a
              v-if="$can('export-categories')"
              :href="route('categories.export')"
              class="block px-4 py-2 leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
            >
              {{ $t('export_x', { x: $t('Categories') }) }}
            </a>
            <tec-dropdown-link v-if="$can('import-categories')" :href="route('categories.import')">
              {{ $t('import_x', { x: $t('Categories') }) }}
            </tec-dropdown-link>
          </template>
        </tec-dropdown>
      </div>
      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th class="px-6 pt-6 pb-4">{{ $t('Name') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Code') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Type') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Child of') }}</th>
          </tr>
          <tr
            :key="category.id"
            v-for="category in displayCategories"
            @click="$connection.status !== 'offline' && goto(route('categories.edit', category.id))"
            class="txn-row hover:bg-gray-100 focus-within:bg-gray-100"
            :class="{ 'cursor-pointer': $can('update-categories') && $connection.status !== 'offline' }"
          >
            <td class="border-t">
              <div class="px-6 py-4">
                <div class="flex items-center lg-main">
                  <hover-preview category="categories" :id="category.id">{{ category.name }}</hover-preview>
                  <icons v-if="category.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
                </div>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ category.code }}</span>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span class="lg-chip" :class="category.parent_id ? 'lg-chip-violet' : 'lg-chip-green'" style="text-transform: none">{{ category.parent_id ? $t('Sub-category') : $t('Parent category') }}</span>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <template v-if="category.parent_id && category.parent">
                  {{ category.parent.name }}
                  <span class="text-gray-500 ml-1">({{ category.parent.code }})</span>
                </template>
                <span v-else class="lg-subtext">-</span>
              </div>
            </td>
            <td class="border-t w-px">
              <div v-if="$can('update-categories')" class="px-4 flex items-center w-full">
                <icons name="chevron-down" class="transform -rotate-90 hidden md:block w-4 h-4" />
                <span class="lg-btn lg-btn-soft row-open md:hidden">{{ $t('Open') }}<icons name="chevron-down" class="transform -rotate-90 block w-4 h-4" /></span>
              </div>
            </td>
          </tr>
          <tr v-if="displayCategories.length === 0">
            <td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination v-if="$connection.status !== 'offline'" class="mt-6" :meta="categories.meta" :links="categories.links" />
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
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';
import { loadIndexData } from '@/Core/offlineDataSource';

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
    HoverPreview,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    parents: Array,
    filters: Object,
    categories: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        parent: this.filters.parent,
        trashed: this.filters.trashed,
      },
      offlineCategories: null,
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineCategories();
    }
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Total Categories'), value: this.stats.total },
        { icon: 'folder', tone: 'emerald', label: this.$t('Parent Categories'), value: this.stats.parent },
        { icon: 'list', tone: 'violet', label: this.$t('Sub-categories'), value: this.stats.child },
        { icon: 'tick', tone: 'amber', label: this.$t('In Use'), value: this.stats.in_use },
      ];
    },
    displayCategories() {
      if (this.$connection.status === 'offline' && this.offlineCategories) {
        return this.offlineCategories;
      }
      return this.categories.data;
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        if (this.$connection.status === 'offline') {
          this.loadOfflineCategories();
          return;
        }
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('categories.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    async loadOfflineCategories() {
      const result = await loadIndexData('categories', { search: this.form.search });
      this.offlineCategories = result.data;
    },
    goto(link) {
      if (this.$can('update-categories')) {
        this.$inertia.visit(link);
      }
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
  },
};
</script>
