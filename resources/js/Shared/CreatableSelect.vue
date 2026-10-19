<template>
  <div class="relative">
    <tec-label v-if="label" :value="label" />
    <input
      type="text"
      ref="input"
      v-model="query"
      @focus="open = true"
      @blur="handleBlur"
      :placeholder="placeholder"
      autocomplete="off"
      class="mt-1 block w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm"
      :class="{ 'border-red-500': error }"
    />
    <div v-if="open" class="absolute z-50 mt-1 w-full rounded-md bg-white shadow-lg border border-gray-200">
      <ul class="max-h-56 overflow-auto py-1 text-sm">
        <li v-for="option in filteredOptions" :key="option">
          <button
            type="button"
            @mousedown.prevent="select(option)"
            class="w-full text-left px-3 py-2 hover:bg-indigo-600 hover:text-white"
          >
            {{ option }}
          </button>
        </li>
        <li v-if="canAddNew">
          <button
            type="button"
            @mousedown.prevent="addNew"
            class="w-full text-left px-3 py-2 text-indigo-700 hover:bg-indigo-600 hover:text-white"
          >
            {{ $t('Add') }} "{{ query.trim() }}"
          </button>
        </li>
        <li v-if="!filteredOptions.length && !canAddNew" class="px-3 py-2 text-gray-400">
          {{ $t('No result suggestions to list.') }}
        </li>
      </ul>
    </div>
    <tec-input-error v-if="error" :message="error" class="mt-0" />
  </div>
</template>

<script>
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';

export default {
  components: { TecLabel, TecInputError },

  props: {
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    label: { type: String, default: null },
    placeholder: { type: String, default: null },
    error: { type: String, default: null },
  },

  emits: ['update:modelValue'],

  data() {
    return {
      query: this.modelValue || '',
      open: false,
    };
  },

  computed: {
    filteredOptions() {
      const q = this.query.trim().toLowerCase();
      if (!q) {
        return this.options;
      }
      return this.options.filter(option => option.toLowerCase().includes(q));
    },

    canAddNew() {
      const q = this.query.trim();
      if (!q) {
        return false;
      }
      return !this.options.some(option => option.toLowerCase() === q.toLowerCase());
    },
  },

  watch: {
    modelValue(value) {
      if (value !== this.query) {
        this.query = value || '';
      }
    },
  },

  methods: {
    select(option) {
      this.query = option;
      this.$emit('update:modelValue', option);
      this.open = false;
    },

    addNew() {
      const value = this.query.trim();
      if (!value) {
        return;
      }
      this.$emit('update:modelValue', value);
      this.open = false;
    },

    handleBlur() {
      setTimeout(() => {
        const value = this.query.trim();
        if (value !== (this.modelValue || '')) {
          this.$emit('update:modelValue', value);
        }
        this.open = false;
      }, 150);
    },
  },
};
</script>
