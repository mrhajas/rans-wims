<template>
  <jetstream-modal :show="show" max-width="2xl" @close="close">
    <div class="cta-panel">
      <div class="cta-head">
        <span class="cta-ico"><icons name="refresh" class="w-5 h-5" /></span>
        <div class="min-w-0">
          <h3>{{ $t('Convert to Asset') }}</h3>
          <p>{{ $t(':n item(s) selected', { n: ids.length }) }}</p>
        </div>
        <button type="button" class="cta-x" :aria-label="$t('Close')" @click="close"><icons name="x-circle" class="w-5 h-5" /></button>
      </div>

      <!-- the warning, before anything else: the items are deleted -->
      <div class="cta-warn" role="alert">
        <icons name="exclamation" class="w-5 h-5 cta-warn-ico" />
        <div>
          <b>{{ $t('The items will be deleted') }}</b>
          <p>{{ $t('Once an item is converted to an asset, it is deleted from Items, together with its stock rows and stock history. The asset takes the item\'s name, details, unit, price (as the cost of one), photos, and the stock on hand as its quantity, one place for each warehouse that holds it. A deleted item can be found in Deleted Records.') }}</p>
        </div>
      </div>

      <p v-if="loading" class="cta-note">{{ $t('Reading the items...') }}</p>
      <p v-else-if="loadError" class="cta-error" role="alert">{{ loadError }}</p>

      <template v-else>
        <!-- what each item becomes -->
        <div class="cta-list" role="list">
          <div v-for="r in rows" :key="r.id" class="cta-row" :class="{ 'is-blocked': r.blocked }" role="listitem">
            <div class="cta-row-main">
              <div class="cta-name"><item-label :name="r.name" :code="r.code" /></div>
              <div v-if="r.blocked" class="cta-skip"><icons name="x-circle" class="w-4 h-4" /> {{ $t('Will be skipped') }}: {{ r.blocked }}</div>
              <template v-else>
                <div class="cta-becomes">
                  <icons name="forward" class="w-3.5 h-3.5" />
                  <b>{{ fmt(r.quantity) }}</b> {{ r.unit || '' }}
                  <span v-if="r.no_stock" class="cta-dim">({{ $t('no stock on hand, so one asset') }})</span>
                  <span v-if="r.price !== null" class="cta-dim"> · {{ $t('cost of one') }}: {{ fmt(r.price) }} TZS</span>
                  <span v-if="r.photos" class="cta-dim"> · {{ r.photos }} {{ $t('photo(s)') }}</span>
                </div>
                <div v-if="r.places.length" class="cta-places">
                  <span v-for="p in r.places" :key="p.warehouse" class="cta-chip">{{ p.warehouse }}: {{ fmt(p.quantity) }}</span>
                </div>
                <div v-if="r.used_in.length" class="cta-used">
                  <icons name="exclamation" class="w-3.5 h-3.5" />
                  {{ $t('Also named in') }}: {{ r.used_in.map(u => u.count + ' ' + u.label).join(', ') }}. {{ $t('They keep pointing at the deleted item.') }}
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- the choices that apply to every asset made now -->
        <div class="cta-fields">
          <div class="cta-field cta-field-wide">
            <label class="cta-label" for="cta-category">{{ $t('Asset category') }}</label>
            <auto-complete id="cta-category" v-model="form.asset_category_id" :suggestions="categories" clearable :placeholder="$t('Choose a category (optional)')" />
            <p v-if="errors.asset_category_id" class="cta-error">{{ errors.asset_category_id }}</p>
          </div>
          <div class="cta-field">
            <label class="cta-label" for="cta-type">{{ $t('Asset type') }}</label>
            <select id="cta-type" v-model="form.asset_type" class="cta-input">
              <option value="">{{ $t('Choose later') }}</option>
              <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="cta-field">
            <label class="cta-label" for="cta-own">{{ $t('Ownership') }}</label>
            <select id="cta-own" v-model="form.ownership_type" class="cta-input">
              <option v-for="t in ownership" :key="t" :value="t">{{ t }}</option>
            </select>
            <p v-if="errors.ownership_type" class="cta-error">{{ errors.ownership_type }}</p>
          </div>
          <div class="cta-field">
            <label class="cta-label" for="cta-cond">{{ $t('Condition') }}</label>
            <select id="cta-cond" v-model="form.condition" class="cta-input">
              <option v-for="t in conditions" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="cta-field">
            <label class="cta-label" for="cta-date">{{ $t('Acquisition date') }}</label>
            <input id="cta-date" v-model="form.acquisition_date" type="date" class="cta-input" />
            <p v-if="errors.acquisition_date" class="cta-error">{{ errors.acquisition_date }}</p>
          </div>
        </div>

        <label class="cta-ack" :class="{ 'is-error': errors.acknowledge_delete }">
          <input v-model="form.acknowledge_delete" type="checkbox" :disabled="!convertible.length" />
          <span>{{ $t('I understand that :n item(s) will be deleted from Items when they are converted to assets.', { n: convertible.length }) }}</span>
        </label>
        <p v-if="errors.acknowledge_delete" class="cta-error">{{ errors.acknowledge_delete }}</p>
        <p v-if="errors.ids" class="cta-error">{{ errors.ids }}</p>
      </template>

      <div class="cta-actions">
        <button type="button" class="cta-plain" :disabled="busy" @click="close">{{ $t('Cancel') }}</button>
        <button type="button" class="cta-go" :disabled="busy || loading || !!loadError || !convertible.length || !form.acknowledge_delete" @click="submit">
          <icons name="refresh" class="w-4 h-4" />{{ busy ? $t('Converting...') : $t('Convert :n item(s) to Assets', { n: convertible.length }) }}
        </button>
      </div>
    </div>
  </jetstream-modal>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import ItemLabel from '@/Shared/ItemLabel.vue';

// Converts the ticked items into assets. It first asks the server what each item would become (the stock that becomes the quantity, the places, the
// photos, what else names the item, and which items cannot be converted), tells the person plainly that the items are DELETED, and sends the choices
// only after they tick that they understand. The server checks everything again: permissions, the tick, and each item's own rules.
export default {
  components: { JetstreamModal, AutoComplete, ItemLabel },
  props: { show: Boolean, ids: { type: Array, default: () => [] } },
  emits: ['close', 'done'],
  data() {
    return {
      loading: false, loadError: '', busy: false, errors: {},
      rows: [], categories: [], types: [], ownership: [], conditions: [],
      form: { asset_category_id: null, asset_type: '', ownership_type: '', condition: 'New', acquisition_date: '', acknowledge_delete: false },
    };
  },
  computed: {
    convertible() { return this.rows.filter(r => !r.blocked); },
  },
  watch: {
    show(v) { if (v) this.load(); },
  },
  methods: {
    fmt(n) { return Number(n).toLocaleString(undefined, { maximumFractionDigits: 3 }); },
    close() { if (!this.busy) this.$emit('close'); },
    load() {
      this.loading = true;
      this.loadError = '';
      this.errors = {};
      this.rows = [];
      this.form.acknowledge_delete = false;
      window.axios.post(route('assets.convert-from-items.preview'), { ids: this.ids })
        .then(({ data }) => {
          this.rows = data.rows;
          this.categories = data.categories;
          this.types = data.types;
          this.ownership = data.ownership_types;
          this.conditions = data.conditions;
          this.form.ownership_type = data.defaults.ownership_type;
          this.form.condition = data.defaults.condition;
          this.form.acquisition_date = data.defaults.acquisition_date;
          if (!this.rows.length) this.loadError = this.$t('The selected items could not be found.');
        })
        .catch(e => {
          this.loadError = e.response && e.response.status === 403 ? this.$t('You are not allowed to convert items to assets.') : this.$t('The items could not be read. Please try again.');
        })
        .finally(() => (this.loading = false));
    },
    submit() {
      this.busy = true;
      this.errors = {};
      this.$inertia.post(route('assets.convert-from-items'), {
        ids: this.convertible.map(r => r.id),
        asset_category_id: this.form.asset_category_id || null,
        asset_type: this.form.asset_type || null,
        ownership_type: this.form.ownership_type,
        condition: this.form.condition || null,
        acquisition_date: this.form.acquisition_date || null,
        acknowledge_delete: this.form.acknowledge_delete ? 1 : 0,
      }, {
        onSuccess: () => this.$emit('done'),
        onError: e => { this.errors = e; },
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

<style scoped>
.cta-panel { padding: 1.25rem 1.4rem 1.2rem; background: linear-gradient(170deg, #fff, #f8fafc); }
html.dark .cta-panel { background: linear-gradient(170deg, #111a33, #0c1226); color: #e2e8f0; }
.cta-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; }
.cta-head h3 { margin: 0; font-size: 1.1rem; font-weight: 800; color: #0f172a; } html.dark .cta-head h3 { color: #f1f5f9; }
.cta-head p { margin: 0.1rem 0 0; font-size: 0.8rem; color: #64748b; }
.cta-ico { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.6rem; height: 2.6rem; border-radius: 0.85rem; color: #fff; background: linear-gradient(145deg, #6366f1, #4338ca); box-shadow: 0 10px 18px -10px rgba(67, 56, 202, 0.9); }
.cta-x { margin-left: auto; flex: none; color: #94a3b8; border-radius: 999px; } .cta-x:hover { color: #475569; }

.cta-warn { display: flex; gap: 0.7rem; padding: 0.8rem 0.95rem; border-radius: 0.9rem; border: 1.5px solid #fca5a5; background: linear-gradient(135deg, #fff1f2, #ffe4e6); color: #7f1d1d; margin-bottom: 0.9rem; }
.cta-warn b { display: block; font-size: 0.92rem; font-weight: 800; margin-bottom: 0.15rem; } .cta-warn p { margin: 0; font-size: 0.8rem; line-height: 1.45; }
.cta-warn-ico { flex: none; margin-top: 0.1rem; color: #dc2626; }
html.dark .cta-warn { background: linear-gradient(135deg, rgba(127, 29, 29, 0.45), rgba(76, 5, 25, 0.5)); color: #fecaca; border-color: rgba(248, 113, 113, 0.5); }

.cta-note { font-size: 0.85rem; color: #64748b; padding: 0.6rem 0; }
.cta-error { font-size: 0.78rem; font-weight: 700; color: #dc2626; margin: 0.3rem 0 0; }
.cta-list { max-height: 15rem; overflow-y: auto; display: grid; gap: 0.5rem; padding: 0.1rem 0.15rem 0.1rem 0; margin-bottom: 0.9rem; overscroll-behavior: contain; }
.cta-row { padding: 0.6rem 0.8rem; border-radius: 0.8rem; border: 1px solid #e2e8f0; background: #fff; }
html.dark .cta-row { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
.cta-row.is-blocked { border-color: #fecaca; background: #fff7f7; } html.dark .cta-row.is-blocked { background: rgba(127, 29, 29, 0.25); border-color: rgba(248, 113, 113, 0.4); }
.cta-name { font-size: 0.88rem; font-weight: 800; color: #0f172a; overflow-wrap: anywhere; } html.dark .cta-name { color: #f1f5f9; }
.cta-becomes { display: flex; flex-wrap: wrap; align-items: center; gap: 0.3rem; margin-top: 0.25rem; font-size: 0.8rem; color: #334155; } html.dark .cta-becomes { color: #cbd5e1; }
.cta-dim { color: #64748b; font-size: 0.76rem; }
.cta-places { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.35rem; }
.cta-chip { font-size: 0.7rem; font-weight: 700; padding: 0.12rem 0.5rem; border-radius: 999px; background: #eef2ff; color: #4338ca; } html.dark .cta-chip { background: rgba(99, 102, 241, 0.22); color: #c7d2fe; }
.cta-used { display: flex; align-items: flex-start; gap: 0.35rem; margin-top: 0.4rem; font-size: 0.74rem; color: #b45309; } html.dark .cta-used { color: #fcd34d; }
.cta-skip { display: flex; align-items: flex-start; gap: 0.35rem; margin-top: 0.25rem; font-size: 0.78rem; font-weight: 700; color: #b91c1c; } html.dark .cta-skip { color: #fca5a5; }

.cta-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.7rem 0.9rem; margin-bottom: 0.8rem; }
.cta-field-wide { grid-column: 1 / -1; }
.cta-label { display: block; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; margin-bottom: 0.3rem; }
.cta-input { width: 100%; border-radius: 0.65rem; border: 1.5px solid #e2e8f0; padding: 0.5rem 0.7rem; font-size: 0.86rem; background: #fff; color: #0f172a; }
html.dark .cta-input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #f1f5f9; }
.cta-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }

.cta-ack { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.7rem 0.85rem; border-radius: 0.8rem; border: 1.5px solid #fcd34d; background: #fffbeb; font-size: 0.84rem; font-weight: 700; color: #78350f; cursor: pointer; }
.cta-ack input { margin-top: 0.15rem; width: 1.05rem; height: 1.05rem; flex: none; accent-color: #dc2626; }
.cta-ack.is-error { border-color: #f87171; }
html.dark .cta-ack { background: rgba(120, 53, 15, 0.3); border-color: rgba(252, 211, 77, 0.45); color: #fde68a; }

.cta-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; }
.cta-plain { padding: 0.6rem 1.1rem; border-radius: 0.7rem; border: 1px solid #cbd5e1; color: #334155; font-size: 0.86rem; font-weight: 700; background: #fff; } html.dark .cta-plain { background: transparent; color: #e2e8f0; border-color: rgba(255, 255, 255, 0.2); }
.cta-go { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.6rem 1.2rem; border-radius: 0.7rem; border: 0; color: #fff; font-size: 0.86rem; font-weight: 800; background: linear-gradient(145deg, #ef4444, #b91c1c); box-shadow: 0 12px 20px -12px rgba(185, 28, 28, 0.95); transition: transform 0.15s ease, opacity 0.15s ease; }
.cta-go:hover:not(:disabled) { transform: translateY(-1px); } .cta-go:disabled, .cta-plain:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 560px) { .cta-fields { grid-template-columns: 1fr; } .cta-panel { padding: 1rem; } .cta-actions > * { flex: 1 1 100%; justify-content: center; } }
</style>
