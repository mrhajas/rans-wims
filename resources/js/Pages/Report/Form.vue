<template>
  <form class="ra-form" @submit.prevent="submit" @keyup.enter="submit">
    <section class="ra-form-card">
      <header class="ra-form-head">
        <span class="ra-form-ico"><icons name="filter" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="ra-form-title">{{ $t('Filters') }}</h2>
        </div>
      </header>

      <div class="ra-form-fields">
        <date-picker v-model="form.start_date" :label="$t('Start Date')" />
        <date-picker v-model="form.end_date" :label="$t('End Date')" />
        <text-input type="datetime-local" v-model="form.start_created_at" :label="$t('Start Created At')" />
        <text-input type="datetime-local" v-model="form.end_created_at" :label="$t('End Created At')" />
        <text-input v-model="form.reference" :label="$t('Reference')" />
        <div v-if="type == 'checkin' || type == 'checkout'">
          <!-- <auto-complete json :label="$t('Contact')" v-model="form.contact_id" :suggestions="route('contacts.search')" class="mr-4" /> -->
          <auto-complete clearable :label="$t('Contact')" v-model="form.contact_id" :suggestions="contacts" />
        </div>
        <div v-if="type != 'transfer'">
          <auto-complete clearable :label="$t('Warehouse')" v-model="form.warehouse_id" :suggestions="warehouses" />
        </div>
        <template v-if="type == 'transfer'">
          <div>
            <auto-complete
              clearable
              :suggestions="warehouses"
              :label="$t('From Warehouse')"
              v-model="form.from_warehouse_id"
            />
          </div>
          <div>
            <auto-complete clearable :label="$t('To Warehouse')" v-model="form.to_warehouse_id" :suggestions="warehouses" />
          </div>
        </template>
        <div>
          <auto-complete clearable :label="$t('User')" v-model="form.user_id" :suggestions="users" />
        </div>
        <div>
          <auto-complete clearable :label="$t('Category')" v-model="form.category_id" :suggestions="categories" />
        </div>
        <div>
          <auto-complete clearable :label="$t('Item')" v-model="form.item_id" :suggestions="warehouseItems" />
        </div>
      </div>

      <div class="ra-form-foot">
        <button type="button" @click="reset" class="lg-btn lg-btn-soft">
          <icons name="refresh" class="w-4 h-4" />
          {{ $t('Reset') }}
        </button>
        <div class="ra-form-right">
          <check-box id="trashed" v-model:checked="form.trashed" :label="$t('Include trashed records')" />
          <check-box id="draft" v-model:checked="form.draft" :label="$t('Only draft records')" />
          <loading-button type="submit" :loading="form.processing" :disabled="form.processing">
            <icons name="search" class="w-4 h-4 mr-2" />
            {{ $t('Submit') }}
          </loading-button>
        </div>
      </div>
    </section>
  </form>
</template>

<script>
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';

export default {
  props: ['type', 'warehouses', 'users', 'categories', 'contacts', 'action', 'listing'],

  components: { CheckBox, TextInput, DatePicker, SelectInput, AutoComplete, LoadingButton },

  computed: {
    warehouseItems() {
      const selectedWarehouseId = this.form.from_warehouse_id || this.form.warehouse_id || this.form.to_warehouse_id;

      if (!selectedWarehouseId) {
        return [];
      }

      // the page brings the stock list of the warehouses already chosen; any other is fetched when it is picked (fetchedItems)
      const warehouse = (this.listing || []).find(l => String(l.id) === String(selectedWarehouseId)) || { items: this.fetchedItems[selectedWarehouseId] || [] };
      if (!warehouse?.items?.length) {
        return [];
      }

      const categoryId = this.form.category_id;
      const items = categoryId
        ? warehouse.items.filter(item => String(item.category_id) === String(categoryId))
        : warehouse.items;

      return items.map(item => ({
        ...item,
        id: item.id,
        value: item.id,
        label: item.code ? `${item.code} - ${item.name}` : item.name,
        name: item.name,
      }));
    }
  },

  watch: {
    'form.warehouse_id'() {
      this.loadWarehouseItems();
      this.syncItemSelection();
    },
    'form.from_warehouse_id'() {
      this.loadWarehouseItems();
      this.syncItemSelection();
    },
    'form.to_warehouse_id'() {
      this.loadWarehouseItems();
      this.syncItemSelection();
    },
    'form.category_id'() {
      this.syncItemSelection();
    },
  },

  data() {
    const urlParams = new URLSearchParams(window.location.search);
    return {
      fetchedItems: {},
      form: this.$inertia.form({
        start_date: urlParams.get('start_date'),
        end_date: urlParams.get('end_date'),
        start_created_at: urlParams.get('start_created_at'),
        end_created_at: urlParams.get('end_created_at'),
        reference: urlParams.get('reference'),
        contact_id: urlParams.get('contact_id'),
        user_id: urlParams.get('user_id'),
        category_id: urlParams.get('category_id'),
        warehouse_id: urlParams.get('warehouse_id'),
        to_warehouse_id: urlParams.get('to_warehouse_id'),
        from_warehouse_id: urlParams.get('from_warehouse_id'),
        draft: urlParams.get('draft'),
        trashed: urlParams.get('trashed'),
        item_id: urlParams.get('item_id'), 
      }),
    };
  },

  methods: {
    // the warehouse's stocked items, when the page did not bring them (a warehouse picked after the page loaded)
    async loadWarehouseItems() {
      const id = this.form.from_warehouse_id || this.form.warehouse_id || this.form.to_warehouse_id;
      if (!id || (this.listing || []).some(l => String(l.id) === String(id)) || this.fetchedItems[id] !== undefined) {
        return;
      }
      this.fetchedItems = { ...this.fetchedItems, [id]: [] };
      try {
        const r = await window.axios.get(route('reports.warehouse-items', id));
        this.fetchedItems = { ...this.fetchedItems, [id]: r.data || [] };
      } catch (e) {
        this.fetchedItems = { ...this.fetchedItems, [id]: [] };
      }
      this.syncItemSelection();
    },
    syncItemSelection() {
      if (!this.form.item_id) {
        return;
      }
      const picked = this.form.from_warehouse_id || this.form.warehouse_id || this.form.to_warehouse_id;
      if (picked && !(this.listing || []).some(l => String(l.id) === String(picked)) && !this.fetchedItems[picked]?.length) {
        return; // that warehouse's items are still being fetched: keep the item until they arrive
      }

      const allowedIds = this.warehouseItems.map(item => String(item.id));
      if (!allowedIds.includes(String(this.form.item_id))) {
        this.form.item_id = null;
      }
    },
    submit() {
      if (this.action) {
        this.form
          .transform(data => {
            let d = {};
            Object.keys(data).map(k => {
              if (data[k] !== null && data[k] !== "") {
                d[k] = data[k];
              }
            });
            return d;
          })
          .get(this.action, {
            replace: true,
            preserveState: true,
            preserveScroll: true,
          });
      }
    },
    reset() {
      this.form = this.$inertia.form({
        start_date: null,
        end_date: null,
        start_created_at: null,
        end_created_at: null,
        reference: null,
        contact_id: null,
        user_id: null,
        category_id: null,
        warehouse_id: null,
        to_warehouse_id: null,
        from_warehouse_id: null,
        draft: null,
        trashed: null,
        item_id: null,
      });
      this.$inertia.visit(this.action, { replace: true, preserveState: true });
    },
  },
};
</script>

<style scoped>
.ra-form {
  --f-card: var(--lg-card, #ffffff);
  --f-line: var(--lg-line, #e6ebf3);
  --f-ink: var(--lg-ink, #0f172a);
  --f-mute: var(--lg-mute, #64748b);
  --f-shadow: var(--lg-shadow, 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 30px -18px rgba(15, 23, 42, 0.24));
  margin-bottom: 1rem;
  position: relative;
  z-index: 20;
}
html.dark .ra-form {
  --f-card: var(--lg-card, rgba(15, 23, 42, 0.82));
  --f-line: var(--lg-line, rgba(255, 255, 255, 0.09));
  --f-ink: var(--lg-ink, #f1f5f9);
  --f-mute: var(--lg-mute, #94a3b8);
}
.ra-form-card {
  padding: 1.15rem 1.3rem 1.3rem;
  border-radius: 22px;
  background: var(--f-card);
  border: 1px solid var(--f-line);
  box-shadow: var(--f-shadow);
}
.ra-form-head { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
.ra-form-ico {
  display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff;
  background: linear-gradient(145deg, #38bdf8, #0369a1); box-shadow: 0 10px 18px -8px rgba(3, 105, 161, 0.8);
}
.ra-form-title { font-size: 1rem; font-weight: 800; color: var(--f-ink); }
.ra-form-fields { display: grid; gap: 1rem 1.25rem; grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr)); }
.ra-form-fields > * { min-width: 0; }
.ra-form-fields :deep(.col-span-6) { grid-column: auto !important; }
.ra-form-fields :deep(.mb-2), .ra-form-fields :deep(.mb-4), .ra-form-fields :deep(.mb-6) { margin-bottom: 0 !important; }
.ra-form-fields :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--f-mute);
}
.ra-form-fields :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important; border-color: var(--f-line) !important; background: var(--f-card); color: var(--f-ink);
}
.ra-form-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 1.1rem; padding-top: 1rem; border-top: 1px dashed var(--f-line); }

.ra-form-right :deep(span.text-gray-600) { color: var(--f-mute); font-weight: 600; font-size: 0.82rem; }
.ra-form-right { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 1rem 1.5rem; }
</style>
