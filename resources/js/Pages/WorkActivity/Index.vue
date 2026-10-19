<template>
  <admin-layout :title="$t('Activities')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clock" :title="$t('Activities')" :subtitle="$t('Activities that can be assigned to check-outs.')">
        <Link :href="route('activities.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Activity') }) }}</Link>
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
      </div>

      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th class="px-6 pt-6 pb-4">{{ $t('Name') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Status') }}</th>
          </tr>
          <tr
            :key="activity.id"
            v-for="activity in activities.data"
            @click="goto(route('activities.edit', activity.id))"
            class="txn-row hover:bg-gray-100 focus-within:bg-gray-100 cursor-pointer"
          >
            <td class="border-t">
              <div class="px-6 py-4 flex items-center lg-main">
                {{ activity.name }}
                <icons v-if="activity.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span class="lg-chip" :class="activity.deleted_at ? 'lg-chip-red' : 'lg-chip-green'" style="text-transform: none">{{ activity.deleted_at ? $t('Trashed') : $t('Active') }}</span>
              </div>
            </td>
            <td class="border-t w-px">
              <div class="px-4 flex items-center w-full">
                <icons name="chevron-down" class="transform -rotate-90 hidden md:block w-4 h-4" />
                <span class="lg-btn lg-btn-soft row-open md:hidden">{{ $t('Open') }}<icons name="chevron-down" class="transform -rotate-90 block w-4 h-4" /></span>
              </div>
            </td>
          </tr>
          <tr v-if="activities.data.length === 0">
            <td class="lg-empty" colspan="3">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination class="mt-6" :meta="activities.meta" :links="activities.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';

export default {
  components: { PageHeader, StatTiles, Pagination, SelectInput, AdminLayout, SearchFilter, TecSectionTitle },

  props: {
    filters: Object,
    activities: Object,
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
        { icon: 'clock', tone: 'navy', label: this.$t('Total Activities'), value: this.stats.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('In Use'), value: this.stats.in_use },
        { icon: 'collection', tone: 'slate', label: this.$t('Unused'), value: this.stats.unused },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('activities.index', Object.keys(query).length ? query : { remember: 'forget' }), {
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
      this.$inertia.visit(link);
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
  },
};
</script>
