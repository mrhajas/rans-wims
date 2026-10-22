<template>
  <travel-modal :show="show" :title="$t('Add Contact')" :subtitle="$t('The sender is saved in Contacts, in the category Visitors.')" icon="user" :busy="busy" @close="$emit('close')">
    <form id="vl-contact-form" @submit.prevent="save(false)">
      <div v-if="existing" class="lg-note lg-note-amber vl-dup" role="alert">
        <icons name="exclamation" class="w-4 h-4" />
        <div><b>{{ $t('This looks like a contact that already exists:') }}</b><div class="vl-dup__row"><span><b>{{ existing.name }}</b> <small>{{ [existing.phone, existing.category].filter(Boolean).join(' · ') }}</small></span><button type="button" class="lg-btn lg-btn-soft" @click="$emit('saved', existing)"><icons name="tick" class="w-4 h-4" />{{ $t('Use this contact') }}</button></div></div>
      </div>
      <div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('Name') }} <b class="vl-req">*</b></label><input ref="name" v-model="f.name" required maxlength="50" autocomplete="off" /><span class="ac-err">{{ err('name') }}</span></div>
        <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="f.phone" type="tel" maxlength="40" inputmode="tel" autocomplete="off" /><span class="ac-err">{{ err('phone') }}</span></div>
        <div class="ac-f"><label>{{ $t('Email') }}</label><input v-model="f.email" type="email" maxlength="190" autocomplete="off" /><span class="ac-err">{{ err('email') }}</span></div>
        <div class="ac-f tm-full"><label>{{ $t('Company / details') }}</label><input v-model="f.details" maxlength="300" autocomplete="off" /></div>
        <div class="ac-f tm-full"><label>{{ $t('Category') }}</label><div><span class="lg-chip lg-chip-cyan" style="text-transform: none">{{ $t('Visitors') }}</span></div><small>{{ $t('Give a phone number or an email address.') }}</small></div>
      </div>
    </form>
    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="vl-contact-form" class="lg-btn lg-btn-primary" :disabled="busy || !f.name.trim()"><icons :name="busy ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': busy }" />{{ busy ? $t('Saving...') : $t('Save Contact') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '@/Pages/Travels/TravelModal.vue';

// "+ Add Contact" for the incoming-item form: saves the sender as a Contact (category Visitors) without leaving the page.
export default {
  components: { TravelModal },
  props: { show: Boolean },
  emits: ['close', 'saved'],
  data() { return { busy: false, errors: {}, existing: null, f: { name: '', phone: '', email: '', details: '' } }; },
  watch: { show(open) { if (!open) return; this.f = { name: '', phone: '', email: '', details: '' }; this.errors = {}; this.existing = null; this.$nextTick(() => this.$refs.name && this.$refs.name.focus()); } },
  methods: {
    err(k) { return this.errors[k] ? this.errors[k][0] : ''; },
    async save() {
      this.busy = true; this.errors = {}; this.existing = null;
      try {
        const r = await window.axios.post(route('visitor-logs.lookup.contacts.store'), this.f);
        this.$emit('saved', r.data.contact);
      } catch (e) {
        const res = e.response || {};
        if (res.status === 409) this.existing = res.data.existing;
        else if (res.status === 422) this.errors = res.data.errors || {};
        else this.errors = { name: [this.$t('Something went wrong. Try again.')] };
      } finally { this.busy = false; }
    },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-dup { display: flex; gap: 10px; margin-bottom: 16px; }
.vl-dup__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px; }
.vl-dup small { display: block; color: var(--lg-mute, #64748b); }
</style>
