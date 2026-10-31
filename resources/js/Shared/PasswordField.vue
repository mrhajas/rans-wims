<template>
  <div class="pwf" :class="{ 'is-error': !!error }">
    <label v-if="label" class="pwf-label" :for="id">{{ label }}</label>
    <div class="pwf-box">
      <input
        :id="id"
        ref="input"
        class="pwf-input"
        :type="shown ? 'text' : 'password'"
        :value="modelValue"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :aria-invalid="error ? 'true' : null"
        :aria-describedby="error ? id + '-err' : null"
        spellcheck="false"
        autocapitalize="off"
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <button type="button" class="pwf-eye" :aria-label="shown ? $t('Hide password') : $t('Show password')" :aria-pressed="shown" tabindex="-1" @click="shown = !shown">
        <icons :name="shown ? 'eye-off' : 'eye'" class="w-4 h-4" />
      </button>
    </div>
    <p v-if="error" :id="id + '-err'" class="pwf-error" role="alert">{{ error }}</p>
  </div>
</template>

<script>
// A password box with a show / hide eye. `autocomplete="new-password"` keeps the browser from filling in the signed-in person's own saved password.
export default {
  props: { modelValue: { type: String, default: '' }, label: String, id: { type: String, required: true }, error: String, placeholder: String, autocomplete: { type: String, default: 'new-password' } },
  emits: ['update:modelValue'],
  data() { return { shown: false }; },
  methods: { focus() { this.$refs.input.focus(); } },
};
</script>

<style>
.pwf { min-width: 0; }
.pwf-label { display: block; margin-bottom: 4px; font-size: 0.8rem; font-weight: 700; color: #475569; }
.pwf-box { position: relative; }
.pwf-input { width: 100%; height: 42px; padding: 0 44px 0 14px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.92rem; color: #0f172a; transition: border-color 0.2s, box-shadow 0.2s; }
.pwf-input::placeholder { color: #94a3b8; }
.pwf-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.pwf.is-error .pwf-input { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12); }
.pwf-eye { position: absolute; top: 50%; right: 6px; width: 32px; height: 32px; margin-top: -16px; display: grid; place-items: center; border: 0; border-radius: 9px; background: transparent; color: #64748b; cursor: pointer; transition: background 0.2s, color 0.2s; }
.pwf-eye:hover { background: rgba(99, 102, 241, 0.1); color: #4f46e5; }
.pwf-error { margin-top: 5px; font-size: 0.78rem; font-weight: 600; color: #dc2626; line-height: 1.35; }
html.dark .pwf-label { color: #cbd5e1; }
html.dark .pwf-input { background: #0f172a; border-color: #334155; color: #f1f5f9; }
html.dark .pwf-eye { color: #94a3b8; }
</style>
