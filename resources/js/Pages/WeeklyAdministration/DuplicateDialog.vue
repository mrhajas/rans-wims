<template>
  <travel-modal :show="show" :title="$t('Duplicate this Document')" :subtitle="doc ? doc.reference : ''" icon="duplicate" tone="amber" :busy="busy" @close="$emit('close')">
    <form id="wa-dup" class="wa-ui" @submit.prevent="go">
      <p class="lg-sub" style="margin: 0 0 12px">{{ $t('A new document is made with the same lines and charity section. Its date moves to the next week by itself. You can change anything before it is approved.') }}</p>
      <div class="ac-f">
        <label>{{ $t('Your password') }} *</label>
        <input v-model="password" type="password" autocomplete="current-password" :placeholder="$t('Type your own password to confirm')" required />
        <span class="ac-err">{{ err('password') }}</span>
      </div>
    </form>
    <template #footer>
      <button type="button" class="lg-btn t-slate" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="wa-dup" class="lg-btn lg-btn-primary" :disabled="busy || !password"><icons name="duplicate" class="w-4 h-4" />{{ busy ? $t('Working...') : $t('Duplicate') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '../Travels/TravelModal.vue';

export default {
  components: { TravelModal },
  props: { show: Boolean, doc: Object },
  emits: ['close'],
  data() { return { busy: false, password: '' }; },
  watch: { show(v) { if (v) this.password = ''; } },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    go() { this.busy = true; this.$inertia.post(route('weekly-administration.duplicate', this.doc.id), { password: this.password }, { onSuccess: () => this.$emit('close'), onFinish: () => { this.busy = false; this.password = ''; } }); },
  },
};
</script>
