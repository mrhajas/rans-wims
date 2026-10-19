<template>
  <div class="lg-span-all ap-box">
    <div class="ap-grid">
      <auto-complete class="ap-from" :id="id + '_from'" :label="$t('Items are now at')" v-model="form.from_placement_id" :suggestions="options" :error="errors.from_placement_id" @update:modelValue="onFrom" />
      <div class="ap-qty">
        <text-input type="number" step="any" min="0" :label="$t('Quantity')" v-model="form.quantity" :error="errors.quantity" />
      </div>
      <div class="ap-unit">
        <text-input :label="$t('Unit')" :model-value="unit || '-'" disabled />
      </div>
    </div>
    <p class="ap-hint">
      <icons name="info" class="w-3.5 h-3.5" />
      <span v-if="selected">{{ $t('Available at this place') }}: <b>{{ fmt(selected.quantity) + (unit ? ' ' + unit : '') }}</b>
        <button v-if="Number(form.quantity) !== selected.quantity" type="button" class="ap-all" @click="form.quantity = selected.quantity">{{ $t('Use all') }}</button></span>
      <span v-else>{{ $t('Choose where the items are now.') }}</span>
    </p>
  </div>
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';

export default {
  components: { AutoComplete, TextInput },
  props: { form: Object, placements: { type: Array, default: () => [] }, unit: { type: String, default: '' }, id: { type: String, default: 'ap' }, errors: { type: Object, default: () => ({}) } },
  computed: {
    options() {
      return this.placements.map(p => ({ value: p.id, label: p.place + (p.responsible_name ? ' · ' + p.responsible_name : '') + ' · ' + this.fmt(p.quantity) + (this.unit ? ' ' + this.unit : '') }));
    },
    selected() { return this.placements.find(p => p.id === this.form.from_placement_id) || null; },
  },
  methods: {
    fmt(n) { return Number(n).toLocaleString(undefined, { maximumFractionDigits: 3 }); },
    onFrom(id) { const p = this.placements.find(x => x.id === id); if (p && !(Number(this.form.quantity) > 0 && Number(this.form.quantity) <= p.quantity)) this.form.quantity = p.quantity; else if (p && Number(this.form.quantity) > p.quantity) this.form.quantity = p.quantity; },
  },
};
</script>

<style scoped>
.ap-box { padding: 0.8rem 0.9rem; border-radius: 14px; background: rgba(59, 130, 246, 0.06); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.2); }
.ap-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.8rem; align-items: start; }
.ap-grid :deep(.relative), .ap-grid :deep(.mb-2), .ap-grid :deep(.mb-4) { margin-bottom: 0 !important; }
.ap-hint { display: flex; align-items: center; gap: 0.4rem; margin: 0.55rem 0 0; font-size: 0.78rem; color: var(--lg-mute); } .ap-hint b { color: var(--lg-text); }
.ap-all { margin-left: 0.5rem; padding: 0 0.6rem; border: 0; border-radius: 99px; font-size: 0.72rem; font-weight: 800; color: #1d4ed8; background: #dbeafe; cursor: pointer; min-height: 0 !important; }
.ap-from { grid-column: 1 / -1; }
</style>
