<template>
  <admin-layout :title="$t('Contacts')">
    <div class="lg-page px-4 md:px-0 txn-shell">
      <page-header icon="user" :title="$t('Contacts')" :subtitle="$t('Suppliers, clients, drivers, foremen and everyone else you work with.')">
        <a v-if="$can('print-contacts')" :href="route('contacts.print-list') + queryString" target="_blank" class="lg-btn lg-btn-green"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="$can('print-contacts')" :href="route('contacts.pdf-list') + queryString" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
        <Link v-if="$can('create-contacts')" :href="route('contacts.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Contact') }) }}</Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div class="txn-toolbar mb-6 flex justify-between items-center print:hidden relative z-20">
        <div class="flex items-end gap-3 flex-wrap w-full mr-4 cx-filters">
        <search-filter v-model="form.search" class="w-full max-w-md" @reset="reset">
          <label class="mt-4 block text-gray-700">{{ $t('Category') }}:</label>
          <select-input v-model="form.category" class="mt-1 w-full">
            <option :value="null">{{ $t('All') }}</option>
            <option v-for="category in categories" :key="category" :value="category">{{ $t(category) }}</option>
          </select-input>
          <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>
        <label class="cx-cat"><span>{{ $t('Category') }}</span>
          <select-input v-model="form.category" class="mt-1" :aria-label="$t('Category')">
            <option :value="null">{{ $t('All categories') }}</option>
            <option v-for="category in categories" :key="category" :value="category">{{ $t(category) }}{{ stats && stats.categories && stats.categories[category] ? ' (' + stats.categories[category] + ')' : '' }}</option>
          </select-input>
        </label>
        <button v-if="form.category" type="button" class="lg-btn t-slate" @click="form.category = null"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button>
        </div>
        <tec-dropdown align="right" width="48" v-if="$can(['import-contacts', 'export-contacts'])">
          <template #trigger>
            <button class="lg-btn lg-btn-soft"><icons name="menu" class="w-4 h-4" />{{ $t('More') }}</button>
          </template>

          <template #content>
            <a
              v-if="$can('export-contacts')"
              :href="route('contacts.export')"
              class="block px-4 py-2 leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
            >
              {{ $t('export_x', { x: $t('Contacts') }) }}
            </a>
            <tec-dropdown-link v-if="$can('import-contacts')" :href="route('contacts.import')">
              {{ $t('import_x', { x: $t('Contacts') }) }}
            </tec-dropdown-link>
          </template>
        </tec-dropdown>
      </div>
      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th class="px-4 pt-6 pb-4 txn-no-th" data-nolabel>{{ $t('No') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Name') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Category') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Email') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Phone') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Location') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Details') }}</th>
          </tr>
          <tr
            :key="contact.id"
            v-for="(contact, ci) in contacts.data"
            @click="goto(contact.category === 'Suppliers' ? route('supplier-records.suppliers.show', contact.id) : route('contacts.edit', contact.id))"
            class="txn-row hover:bg-gray-100 focus-within:bg-gray-100"
            :class="{ 'cursor-pointer': $can('update-contacts') }"
          >
            <td class="border-t txn-no">
              <div class="txn-no-n">{{ rowNo(ci) }}</div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center focus:text-indigo-500">
                <span class="lg-person"><span class="lg-avatar">{{ initials(contact.name) }}</span><hover-preview category="contacts" :id="contact.id"><span class="lg-main">{{ contact.name }}</span></hover-preview></span>
                <icons v-if="contact.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                <span v-if="contact.category" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ $t(contact.category) }}</span>
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                {{ contact.email }}
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                {{ contact.phone }}
              </div>
            </td>
            <td class="border-t">
              <div class="px-6 py-4 flex items-center">
                {{ contact.location }}
              </div>
            </td>
            <td class="border-t max-w-sm">
              <div class="px-6 py-4 flex items-center truncate">
                {{ contact.details }}
              </div>
            </td>
            <td class="border-t w-px">
              <div v-if="$can('update-contacts')" class="px-4 flex items-center w-full">
                <icons name="chevron-down" class="transform -rotate-90 hidden md:block w-4 h-4" />
                <span class="lg-btn lg-btn-soft row-open md:hidden">{{ $t('Open') }}<icons name="chevron-down" class="transform -rotate-90 block w-4 h-4" /></span>
              </div>
            </td>
          </tr>
          <tr v-if="contacts.data.length === 0">
            <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination class="mt-4" :meta="contacts.meta" :links="contacts.links" />
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
    filters: Object,
    contacts: Object,
    categories: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
        category: this.filters.category,
      },
    };
  },

  computed: {
    // Print / Download carry the search, category and trashed filter currently applied, so the paper matches the table.
    queryString() {
      const qs = new URLSearchParams(pickBy(this.form)).toString();
      return qs ? '?' + qs : '';
    },
    statTiles() {
      if (!this.stats) {
        return [];
      }
      const tones = ['indigo', 'emerald', 'amber', 'sky', 'violet', 'pink'];
      const top = Object.entries(this.stats.categories || {})
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5)
        .map(([category, total], i) => ({ icon: 'users', tone: tones[i % tones.length], label: this.$t(category), value: total, category, active: this.form.category === category }));

      return [{ icon: 'user', tone: 'navy', label: this.$t('All Contacts'), value: this.stats.total, category: null, active: !this.form.category }, ...top];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('contacts.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    // Row number across pages (page 2 of 20 per page starts at 21).
    rowNo(index) {
      return (this.contacts.meta?.from || 1) + index;
    },
    pickTile(item) {
      this.form.category = item.category || null;
    },
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },
    goto(link) {
      if (this.$can('update-contacts')) {
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
.cx-cat { display: grid; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; min-width: 220px; }
.cx-cat span { margin-bottom: 2px; }
@media (max-width: 560px) { .cx-cat { min-width: 100%; } }
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
  background: radial-gradient(circle, #14b8a6, transparent 70%);
}
.txn-blob-2 {
  top: 40px;
  right: -60px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #38bdf8, transparent 70%);
}

.txn-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(240, 253, 250, 0.65), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(20, 184, 166, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.txn-hero-icon {
  background: linear-gradient(145deg, #14b8a6, #0f766e);
  box-shadow: 0 8px 20px -6px rgba(15, 118, 110, 0.5);
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
