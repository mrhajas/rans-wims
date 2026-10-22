<template>
  <travel-modal :show="show" :title="visitor ? $t('Edit Visitor') : $t('Add New Visitor')" :subtitle="visitor ? visitor.reference : $t('Saved in the Visitor Directory, so next time you can just choose them.')" icon="user" :busy="busy" @close="$emit('close')">
    <form id="vl-visitor-form" @submit.prevent="save()">
      <div v-if="dups.length" class="lg-note lg-note-amber vl-dups" role="alert">
        <icons name="exclamation" class="w-4 h-4" />
        <div>
          <b>{{ $t('This looks like someone already in the directory:') }}</b>
          <ul>
            <li v-for="d in dups" :key="d.id"><span><b>{{ d.name }}</b> <small>{{ [d.reference, d.company, d.phone].filter(Boolean).join(' · ') }}</small></span><button type="button" class="lg-btn lg-btn-soft" @click="$emit('saved', d, true)"><icons name="tick" class="w-4 h-4" />{{ $t('Use this visitor') }}</button></li>
          </ul>
          <button v-if="canForce" type="button" class="lg-btn t-slate" :disabled="busy" @click="save(true)">{{ $t('This is a different person: save anyway') }}</button>
          <small v-else class="lg-subtext">{{ $t('Choose the existing visitor. If this really is a different person, ask someone who may save duplicates.') }}</small>
        </div>
      </div>
      <div class="tm-grid">
        <div class="ac-f tm-full"><label>{{ $t('Full name') }} <b class="vl-req">*</b></label><input ref="name" v-model="f.full_name" required maxlength="190" autocomplete="off" :aria-invalid="!!err('full_name')" /><span class="ac-err">{{ err('full_name') }}</span></div>
        <div class="ac-f"><label>{{ $t('Company / organization') }}</label><input v-model="f.company" maxlength="190" autocomplete="off" /><span class="ac-err">{{ err('company') }}</span></div>
        <div class="ac-f"><label>{{ $t('Phone') }} <b v-if="requirePhone" class="vl-req">*</b></label><input v-model="f.phone" type="tel" maxlength="40" inputmode="tel" autocomplete="off" :required="requirePhone" /><span class="ac-err">{{ err('phone') }}</span></div>
        <div class="ac-f"><label>{{ $t('Email') }}</label><input v-model="f.email" type="email" maxlength="190" autocomplete="off" /><span class="ac-err">{{ err('email') }}</span></div>
        <div class="ac-f"><label>{{ $t('Visitor category') }}</label><acct-select v-model="f.category" :options="categories.map(c => ({ value: c, label: c }))" :placeholder="$t('Choose...')" clearable /></div>
        <div v-if="on('vehicle_registration')" class="ac-f"><label>{{ $t('Vehicle registration') }}</label><input v-model="f.vehicle_registration" maxlength="40" autocomplete="off" /></div>
        <template v-if="sensitive">
          <div v-if="on('id_type')" class="ac-f"><label>{{ $t('Identification document') }}</label><input v-model="f.id_type" maxlength="60" :placeholder="$t('Passport, national ID, driving licence...')" autocomplete="off" /></div>
          <div v-if="on('id_number')" class="ac-f"><label>{{ $t('Identification number') }}</label><input v-model="f.id_number" maxlength="80" autocomplete="off" /><small>{{ $t('Encrypted. Only people allowed to see sensitive details can read it.') }}</small></div>
          <div v-if="on('nationality')" class="ac-f"><label>{{ $t('Nationality') }}</label><input v-model="f.nationality" maxlength="100" autocomplete="off" /></div>
          <div v-if="on('address')" class="ac-f tm-full"><label>{{ $t('Address') }}</label><input v-model="f.address" maxlength="500" autocomplete="off" /></div>
          <div v-if="on('emergency_notes')" class="ac-f tm-full"><label>{{ $t('Emergency / contact notes') }}</label><input v-model="f.emergency_notes" maxlength="1000" autocomplete="off" /></div>
        </template>
        <div class="ac-f tm-full"><label>{{ $t('Remarks') }}</label><input v-model="f.remarks" maxlength="2000" autocomplete="off" /></div>
      </div>
    </form>
    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="vl-visitor-form" class="lg-btn lg-btn-primary" :disabled="busy || !f.full_name.trim()"><icons :name="busy ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': busy }" />{{ busy ? $t('Saving...') : $t('Save Visitor') }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

// Add or edit a visitor without leaving the page. Saves with a background request; a likely duplicate is shown (choose the existing visitor or, if allowed, save anyway).
export default {
  components: { TravelModal, AcctSelect },
  props: { show: Boolean, visitor: Object, categories: { type: Array, default: () => [] }, fields: { type: Array, default: () => [] }, sensitive: Boolean, requirePhone: Boolean, prefill: Object },
  emits: ['close', 'saved'],
  data() { return { busy: false, errors: {}, dups: [], canForce: false, f: this.blank() }; },
  watch: {
    show(open) {
      if (!open) return;
      this.f = this.blank(); this.errors = {}; this.dups = []; this.canForce = false;
      if (this.visitor) this.loadDetails();
      this.$nextTick(() => this.$refs.name && this.$refs.name.focus());
    },
  },
  methods: {
    blank() {
      const v = this.visitor || this.prefill || {};
      return { full_name: v.name || v.full_name || '', company: v.company || '', phone: v.phone || '', email: v.email || '', category: v.category || '', vehicle_registration: v.vehicle || v.vehicle_registration || '', id_type: v.id_type || '', id_number: v.id_number || '', nationality: v.nationality || '', address: v.address || '', emergency_notes: v.emergency_notes || '', remarks: v.remarks || '' };
    },
    loadDetails() { /* the page already holds every field the person may see */ },
    on(k) { return this.fields.includes(k); },
    err(k) { return this.errors[k] ? this.errors[k][0] : ''; },
    async save(force = false) {
      this.busy = true; this.errors = {};
      try {
        const body = { ...this.f, ...(force ? { force: 1 } : {}) };
        const r = this.visitor ? await window.axios.put(route('visitor-logs.visitors.update', this.visitor.id), body) : await window.axios.post(route('visitor-logs.visitors.store'), body);
        this.$emit('saved', r.data.visitor, false);
      } catch (e) {
        const res = e.response || {};
        if (res.status === 409) { this.dups = res.data.duplicates || []; this.canForce = !!res.data.can_force; }
        else if (res.status === 422) { this.errors = res.data.errors || {}; }
        else { this.errors = { full_name: [res.data && res.data.message ? res.data.message : this.$t('Something went wrong. Try again.')] }; }
      } finally { this.busy = false; }
    },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-dups { display: flex; gap: 10px; margin-bottom: 16px; }
.vl-dups ul { list-style: none; margin: 8px 0; padding: 0; display: grid; gap: 8px; }
.vl-dups li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.vl-dups li small { display: block; color: var(--lg-mute, #64748b); }
</style>
