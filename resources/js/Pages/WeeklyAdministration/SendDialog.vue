<template>
  <travel-modal :show="show" :title="$t('Send by Email')" :subtitle="doc ? doc.reference + ' - ' + $date(doc.doc_date) : ''" icon="send" :busy="busy" @close="$emit('close')">
    <form id="wa-send" class="wa-ui" @submit.prevent="send">
      <div class="ac-f">
        <label>{{ $t('Email addresses') }}</label>
        <textarea v-model="form.emails" rows="3" :placeholder="$t('One or more email addresses, separated by commas')" style="width: 100%"></textarea>
        <span class="ac-err">{{ err('emails') }}</span>
      </div>
      <label class="wa-toggle" style="margin-top: 12px">
        <input v-model="form.to_super_admins" type="checkbox" />
        <span><b>{{ $t('Send to the Super Admins') }}</b><small>{{ $t('Every active Super Admin who has an email address receives the document.') }}</small></span>
      </label>
      <p class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('The document is attached as a PDF with its QR code. Every sending is written to the activity log and shown on the document.') }}</p>
    </form>
    <template #footer>
      <button type="button" class="lg-btn t-slate" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="wa-send" class="lg-btn lg-btn-primary" :disabled="busy || (!form.emails.trim() && !form.to_super_admins)"><icons name="send" class="w-4 h-4" />{{ busy ? $t('Sending...') : $t('Send') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '../Travels/TravelModal.vue';

export default {
  components: { TravelModal },
  props: { show: Boolean, doc: Object },
  emits: ['close'],
  data() { return { busy: false, form: { emails: '', to_super_admins: false } }; },
  watch: { show(v) { if (v) { this.form = { emails: '', to_super_admins: false }; } } },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    send() { this.busy = true; this.$inertia.post(route('weekly-administration.send', this.doc.id), { emails: this.form.emails, to_super_admins: this.form.to_super_admins ? 1 : 0 }, { preserveScroll: true, onSuccess: () => this.$emit('close'), onFinish: () => (this.busy = false) }); },
  },
};
</script>
