<template>
  <auto-complete
    :id="id"
    json
    load-on-focus
    clearable
    :model-value="modelValue || ''"
    :suggestions="route('visitor-logs.lookup.people')"
    :placeholder="placeholder"
    :default-text="$t('Type a name to search')"
    :error="error"
    @update:model-value="v => $emit('update:modelValue', v && typeof v === 'object' ? v.id : v || '')"
  />
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';

// Search the users of WIMS (the person a visitor came to see, the recipient of an item). Nothing is copied: the user stays the one record of the person.
export default {
  components: { AutoComplete },
  props: { modelValue: { type: [String, Number], default: '' }, placeholder: String, error: String, id: { type: String, default: () => 'pp-' + Math.random().toString(36).slice(2, 8) } },
  emits: ['update:modelValue'],
};
</script>
