<template>
  <div class="vl-time" role="group" :aria-label="$t('Time')">
    <select :value="hh" :disabled="disabled" :aria-label="$t('Hour')" @change="set($event.target.value, mm)"><option v-for="h in hours" :key="h" :value="h">{{ h }}</option></select>
    <span aria-hidden="true">:</span>
    <select :value="mm" :disabled="disabled" :aria-label="$t('Minute')" @change="set(hh, $event.target.value)"><option v-for="m in minutes" :key="m" :value="m">{{ m }}</option></select>
  </div>
</template>

<script>
// A time of day (24 hours) as two selects: the WIMS date picker has no time part, and a typed time is easy to get wrong. Value: "HH:mm".
const pad = n => String(n).padStart(2, '0');
export default {
  props: { modelValue: { type: String, default: '' }, disabled: Boolean },
  emits: ['update:modelValue'],
  computed: {
    hh() { return (this.modelValue || '').split(':')[0] || '00'; },
    mm() { return (this.modelValue || '').split(':')[1] || '00'; },
    hours() { return Array.from({ length: 24 }, (_, i) => pad(i)); },
    minutes() { return Array.from({ length: 60 }, (_, i) => pad(i)); },
  },
  methods: { set(h, m) { this.$emit('update:modelValue', h + ':' + m); } },
};
</script>

<style scoped>
.vl-time { display: flex; align-items: center; gap: 8px; }
.vl-time select { flex: 1 1 0 !important; min-width: 0 !important; width: auto !important; max-width: 110px; }
.vl-time > span { font-weight: 800; color: var(--lg-mute, #64748b); }
</style>
