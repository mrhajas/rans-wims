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
          :suggestions="route('visitor-logs.lookup.contacts')"
          :placeholder="$t('Search Contacts by name, phone or email')"
          :default-text="$t('Type a name, phone number or email')"
          :error="error"
          @update:model-value="pick"
        />
      </div>
      <button type="button" class="lg-btn lg-btn-soft vl-picker__add" @click="open = true"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add Contact') }}</span></button>
    </div>
    <div v-if="card" class="vl-card" aria-live="polite">
      <span class="vl-card__ico"><icons name="user" class="w-5 h-5" /></span>
      <div class="vl-card__body">
        <div class="vl-card__head"><b>{{ card.name }}</b><span v-if="card.category" class="lg-chip lg-chip-cyan" style="text-transform: none">{{ card.category }}</span></div>
        <div class="vl-card__meta"><span v-if="card.phone"><icons name="mobile" class="w-3 h-3" /> {{ card.phone }}</span><span v-if="card.email"><icons name="email" class="w-3 h-3" /> {{ card.email }}</span></div>
      </div>
    </div>
    <contact-form-modal :show="open" @close="open = false" @saved="saved" />
  </div>
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';
import ContactFormModal from './ContactFormModal.vue';

// The sender of an item: chosen from Contacts, or added as a new Contact (category Visitors) in a window without leaving the form.
export default {
  components: { AutoComplete, ContactFormModal },
  props: { modelValue: { type: [String, Number], default: '' }, initial: Object, error: String, id: { type: String, default: () => 'cp-' + Math.random().toString(36).slice(2, 8) } },
  emits: ['update:modelValue', 'picked'],
  data() { return { open: false, card: this.initial || null }; },
  watch: { modelValue(v) { if (!v) this.card = null; else if (!this.card || this.card.id !== Number(v)) this.fetchCard(v); } },
  mounted() { if (this.modelValue && !this.card) this.fetchCard(this.modelValue); },
  methods: {
    async fetchCard(id) { try { const r = await window.axios.post(route('visitor-logs.lookup.contacts'), { id }); this.card = r.data[0] || null; this.$emit('picked', this.card); } catch (e) { this.card = null; } },
    // the search field hands over the whole result in json mode: the form keeps only the id
    pick(v) { const id = v && typeof v === 'object' ? v.id : v || ''; if (v && typeof v === 'object') { this.card = v; this.$emit('picked', v); } this.$emit('update:modelValue', id); },
    saved(c) { this.open = false; this.card = c; this.$emit('update:modelValue', c.id); this.$emit('picked', c); },
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
@media (max-width: 560px) { .vl-picker__add { width: 100%; justify-content: center; } }
</style>
