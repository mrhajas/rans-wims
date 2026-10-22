<template>
  <div class="vl-picker">
    <div class="vl-picker__row">
      <div class="vl-picker__search">
        <auto-complete
          :id="id"
          json
          load-on-focus
          clearable
          :model-value="modelValue || ''"
          :suggestions="route('visitor-logs.lookup.visitors')"
          :placeholder="$t('Search by name, company, phone or reference')"
          :default-text="$t('Type a name, company or phone number')"
          :error="error"
          @update:model-value="pick"
        />
      </div>
      <button v-if="canAdd" type="button" class="lg-btn lg-btn-soft vl-picker__add" @click="open = true"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add New Visitor') }}</span></button>
    </div>
    <div v-if="card" class="vl-card" aria-live="polite">
      <span class="vl-card__ico"><icons name="user" class="w-5 h-5" /></span>
      <div class="vl-card__body">
        <div class="vl-card__head"><b>{{ card.name }}</b><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ card.reference }}</span><span v-if="card.category" class="lg-chip lg-chip-cyan" style="text-transform: none">{{ card.category }}</span></div>
        <div class="vl-card__meta"><span v-if="card.company"><icons name="building" class="w-3 h-3" /> {{ card.company }}</span><span v-if="card.phone"><icons name="mobile" class="w-3 h-3" /> {{ card.phone }}</span><span v-if="card.email"><icons name="email" class="w-3 h-3" /> {{ card.email }}</span><span v-if="card.vehicle"><icons name="car" class="w-3 h-3" /> {{ card.vehicle }}</span></div>
        <div v-if="card.visits !== undefined && card.visits !== null" class="vl-card__meta"><span>{{ card.visits ? $t('Came :n time(s) before', { n: card.visits }) : $t('First visit') }}</span><span v-if="card.last_visit_at">{{ $t('Last visit') }}: {{ $datetime(card.last_visit_at) }}</span><Link v-if="can.history && card.visits && can.visitors" :href="route('visitor-logs.visitors.show', card.id)" target="_blank" class="vl-link">{{ $t('Previous visits') }}</Link></div>
      </div>
      <button v-if="can.update" type="button" class="lg-btn lg-btn-soft" @click="editing = true"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Correct details') }}</span></button>
    </div>
    <visitor-form-modal :show="open" :categories="categories" :fields="fields" :sensitive="can.sensitive" :require-phone="requirePhone" @close="open = false" @saved="saved" />
    <visitor-form-modal v-if="card" :show="editing" :visitor="card" :categories="categories" :fields="fields" :sensitive="false" :require-phone="requirePhone" @close="editing = false" @saved="edited" />
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AutoComplete from '@/Shared/AutoComplete.vue';
import VisitorFormModal from './VisitorFormModal.vue';

// Choose a visitor from the directory (auto-search) or add a new one in a window, without leaving the form: the new visitor is selected at once and nothing already typed is lost.
export default {
  components: { Link, AutoComplete, VisitorFormModal },
  props: { modelValue: { type: [String, Number], default: '' }, initial: Object, categories: { type: Array, default: () => [] }, fields: { type: Array, default: () => [] }, requirePhone: Boolean, error: String,
    can: { type: Object, default: () => ({}) }, canAdd: { type: Boolean, default: true }, id: { type: String, default: () => 'vp-' + Math.random().toString(36).slice(2, 8) } },
  emits: ['update:modelValue', 'picked'],
  data() { return { open: false, editing: false, card: this.initial || null }; },
  watch: { modelValue(v) { if (!v) this.card = null; else if (!this.card || this.card.id !== Number(v)) this.fetchCard(v); } },
  mounted() { if (this.modelValue && !this.card) this.fetchCard(this.modelValue); },
  methods: {
    async fetchCard(id) { try { const r = await window.axios.post(route('visitor-logs.lookup.visitors'), { id }); this.card = r.data[0] || null; this.$emit('picked', this.card); } catch (e) { this.card = null; } },
    // the search field hands over the whole result in json mode: the form keeps only the id, the card below shows the rest
    pick(v) { const id = v && typeof v === 'object' ? v.id : v || ''; if (v && typeof v === 'object') { this.card = v; this.$emit('picked', v); } this.$emit('update:modelValue', id); },
    saved(v) { this.open = false; this.card = v; this.$emit('update:modelValue', v.id); this.$emit('picked', v); },
    edited(v) { this.editing = false; this.card = { ...this.card, ...v }; this.$emit('picked', this.card); },
  },
};
</script>

<style scoped>
.vl-picker__row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
.vl-picker__search { flex: 1 1 260px; min-width: 0; }
.vl-picker__search :deep(.col-span-6) { grid-column: auto; }
.vl-picker__add { flex: 0 0 auto; min-height: 42px; white-space: nowrap; }
.vl-card { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 6px; padding: 12px 14px; border-radius: 16px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e3e9f2); }
.vl-card__ico { flex: none; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 13px; color: #0369a1; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1.5px #0369a1; }
.vl-card__body { flex: 1 1 220px; min-width: 0; display: grid; gap: 3px; }
.vl-card__head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
.vl-card__meta { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.vl-card__meta span { display: inline-flex; align-items: center; gap: 4px; }
.vl-link { font-weight: 800; color: #0369a1; text-decoration: underline; }
@media (max-width: 560px) { .vl-picker__add { width: 100%; justify-content: center; } }
</style>
