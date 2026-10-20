<template>
  <modal :show="show" :max-width="maxWidth" :closeable="!busy" :transparent="true" @close="close">
    <div class="tm-card ac sc-ui tr-ui" role="document">
      <header class="tm-head">
        <span class="tm-ico" :class="{ 'is-amber': tone === 'amber' }"><icons :name="icon" class="w-6 h-6" /></span>
        <div class="tm-titles"><h2 :id="'tm-' + _uid">{{ title }}</h2><p v-if="subtitle">{{ subtitle }}</p></div>
        <button type="button" class="tm-x" :aria-label="$t('Close')" :disabled="busy" @click="close"><icons name="cross" class="w-4 h-4" /></button>
      </header>
      <div class="tm-body"><slot /></div>
      <footer v-if="$slots.footer" class="tm-foot"><slot name="footer" /></footer>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

// The one dialog of the Travels module: an icon header, a roomy body and a quiet footer (full width buttons on a phone).
export default {
  components: { Modal },
  props: { show: Boolean, title: String, subtitle: String, icon: { type: String, default: 'info' }, tone: String, maxWidth: { type: String, default: 'lg' }, busy: Boolean },
  emits: ['close'],
  computed: { _uid() { return Math.random().toString(36).slice(2, 8); } },
  methods: { close() { if (!this.busy) this.$emit('close'); } },
};
</script>
