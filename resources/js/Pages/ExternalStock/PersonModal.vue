<template>
  <travel-modal :show="show" :title="$t('Add External Personnel')" :subtitle="$t('Saved in Contacts, in the category External Personnel, and chosen for this movement.')" icon="truck" tone="amber" :busy="busy" @close="$emit('close')">
    <form id="ex-person-form" @submit.prevent="save">
      <div v-if="existing" class="lg-note lg-note-amber ex-dup" role="alert">
        <icons name="exclamation" class="w-4 h-4" />
        <div><b>{{ $t('This person is already in the External Personnel list:') }}</b>
          <div class="ex-dup__row"><span><b>{{ existing.name }}</b> <small>{{ existing.phone }}</small></span><button type="button" class="lg-btn lg-btn-soft" @click="$emit('saved', existing)"><icons name="tick" class="w-4 h-4" />{{ $t('Use this person') }}</button></div></div>
      </div>
      <div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('Full name') }} <b class="ex-req">*</b></label><input ref="name" v-model="f.name" required maxlength="50" autocomplete="off" :aria-invalid="!!err('name')" /><span class="ac-err">{{ err('name') }}</span></div>
        <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="f.phone" type="tel" maxlength="40" inputmode="tel" autocomplete="off" /><span class="ac-err">{{ err('phone') }}</span></div>
        <div class="ac-f"><label>{{ $t('Email') }}</label><input v-model="f.email" type="email" maxlength="190" autocomplete="off" /><span class="ac-err">{{ err('email') }}</span></div>
        <div class="ac-f tm-full"><label>{{ $t('Location') }}</label><input v-model="f.location" maxlength="150" autocomplete="off" :placeholder="$t('Where they are based, for example Mwanakwerekwe')" /><small>{{ $t('It shows on the movement and in the Stock Out documents.') }}</small></div>
        <div class="ac-f tm-full"><label>{{ $t('Company / details') }}</label><input v-model="f.details" maxlength="500" autocomplete="off" /></div>
        <div class="ac-f tm-full"><label>{{ $t('Category') }}</label><div><span class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('External Personnel') }}</span></div><small>{{ $t('Give a phone number or an email address.') }}</small></div>
      </div>
    </form>
    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="ex-person-form" class="lg-btn lg-btn-primary" :disabled="busy || !f.name.trim()"><icons :name="busy ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': busy }" />{{ busy ? $t('Saving...') : $t('Save Person') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '@/Pages/Travels/TravelModal.vue';

// "+ Add External Personnel" on the External Stock form: a window that saves the person as a Contact of the category External Personnel without leaving the movement.
export default {
  components: { TravelModal },
  props: { show: Boolean },
  emits: ['close', 'saved'],
  data() { return { busy: false, errors: {}, existing: null, f: { name: '', phone: '', email: '', location: '', details: '' } }; },
  watch: { show(open) { if (!open) return; this.f = { name: '', phone: '', email: '', location: '', details: '' }; this.errors = {}; this.existing = null; this.$nextTick(() => this.$refs.name && this.$refs.name.focus()); } },
  methods: {
    err(k) { return this.errors[k] ? this.errors[k][0] : ''; },
    async save() {
      this.busy = true; this.errors = {}; this.existing = null;
      try {
        const r = await window.axios.post(route('external-stock.people.store'), this.f);
        this.$emit('saved', r.data.person);
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
.ex-req { color: #dc2626; }
.ex-dup { display: flex; gap: 10px; margin-bottom: 16px; }
.ex-dup__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px; }
.ex-dup small { display: block; color: var(--lg-mute, #64748b); }
</style>
