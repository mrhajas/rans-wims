<template>
  <div class="col-span-6 sm:col-span-4 relative" :class="{ 'mb-2': size != 'small' }">
    <tec-label v-if="label" :for="id" :value="label" />
    <css-select
      :id="id"
      ref="input"
      v-bind="$attrs"
      v-model="selected"
      :placeholder="placeholder"
      :class="{ 'is-bad': error, 'py-0 h-8': size == 'small', 'mt-1': label }"
      class="w-full"
    >
      <slot />
    </css-select>
    <!-- <div class="relative mt-1">
      <select
        class="appearance-none w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm"
      >
        <slot />
      </select>
      <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
        <icons name="chevron-down" />
      </div>
    </div> -->
    <tec-input-error v-if="error" :message="error" :class="size === 'small' ? 'absolute mt-0' : 'mt-1'" />
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import CssSelect from '@/Shared/CssSelect.vue';

export default {
  emits: ['update:modelValue'],
  components: { TecLabel, TecInputError, CssSelect },
  props: {
    id: {
      type: String,
      default() {
        return uuidv4();
      },
    },
    size: String,
    label: String,
    error: String,
    placeholder: String,
    modelValue: [String, Number, Boolean],
  },
  data() {
    return {
      selected: this.modelValue,
    };
  },
  watch: {
    // Follow the parent when it changes the value itself (e.g. a summary card sets the status filter).
    modelValue(value) {
      if (value !== this.selected) this.selected = value;
    },
    selected(selected) {
      this.$emit('update:modelValue', selected);
    },
  },
  methods: {
    focus() {
      const el = this.$refs.input && (this.$refs.input.$refs.btn || this.$refs.input.$el);
      el && el.focus && el.focus();
    },
    select() {},
  },
};
</script>
