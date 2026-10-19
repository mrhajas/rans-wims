<template>
  <admin-layout :title="$t('Document Signing Setup')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="doc-text" :title="$t('Document Signing Setup')" :subtitle="$t('Choose which signing roles a document carries and which recorded workflow action qualifies the signer. A signature is only ever shown for an action that really happened.')">
        <Link :href="route('signatories.index')" class="sb-gbtn"><icons name="edit" class="w-4 h-4" />{{ $t('Signatories') }}</Link>
        <Link :href="route('signatories.roles.index')" class="sb-gbtn"><icons name="users" class="w-4 h-4" />{{ $t('Signing roles') }}</Link>
      </sb-hero>

      <section class="sb-card mb-4" style="--i: 0">
        <div class="sb-fields">
          <div class="sb-field sb-wide-2"><label><icons name="doc-text" class="w-3 h-3" />{{ $t('Document') }}</label><auto-complete v-model="type" :suggestions="docOptions" :placeholder="$t('Choose a document..')" /></div>
          <div v-if="doc" class="sb-field sg-go"><button type="button" class="sb-btn" @click="askReset"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore the starting setup') }}</button></div>
        </div>
        <div v-if="resetting" class="sg-confirm">
          <p><b>{{ $t('Replace this document\'s setup with the starting setup?') }}</b></p>
          <div class="sb-field"><label for="mp-pw">{{ $t('Your password') }}</label><input id="mp-pw" v-model="pw" type="password" class="sb-input" autocomplete="current-password" /></div>
          <div class="sg-actions mt-2"><button type="button" class="sb-btn is-primary" :disabled="!pw" @click="doReset"><icons name="tick" class="w-4 h-4" />{{ $t('Restore') }}</button><button type="button" class="sb-btn" @click="resetting = false">{{ $t('Cancel') }}</button></div>
        </div>
      </section>

      <template v-if="doc">
        <section class="sb-card mb-4" style="--i: 1">
          <h3 class="sb-card__title"><span class="sb-ico g-violet"><icons name="list" class="w-4 h-4" /></span>{{ doc.label }}<small>{{ doc.rows.length }} {{ $t('signing lines') }}</small></h3>
          <p v-if="!doc.rows.length" class="sb-none">{{ $t('No signing lines. Add one below or restore the starting setup.') }}</p>
          <div class="sg-maps">
            <div v-for="r in rows" :key="r.id" class="sg-map" :class="{ 'is-off': !r.is_active }">
              <div class="sb-field"><label>{{ $t('Signing role') }}</label><auto-complete v-model="r.signing_role_id" :suggestions="roles" /></div>
              <div class="sb-field"><label>{{ $t('Qualifying action') }}</label><auto-complete v-model="r.workflow_action" :suggestions="doc.actions" /></div>
              <div class="sb-field sg-narrow"><label>{{ $t('Order') }}</label><input v-model.number="r.slot" type="number" min="1" max="12" class="sb-input" /></div>
              <div class="sb-field"><label>{{ $t('Shown') }}</label><auto-complete v-model="r.show_when" :suggestions="whenOptions" /></div>
              <div class="sb-field"><label>{{ $t('If missing') }}</label><auto-complete v-model="r.missing_behaviour" :suggestions="missOptions" /></div>
              <label class="sg-onoff"><input v-model="r.is_required" type="checkbox" />{{ $t('Required') }}</label>
              <label class="sg-onoff" :class="{ 'is-dim': !r.is_required }"><input v-model="r.blocks_final" type="checkbox" :disabled="!r.is_required" />{{ $t('Blocks finalising') }}</label>
              <label class="sg-onoff"><input v-model="r.is_active" type="checkbox" />{{ $t('In use') }}</label>
              <div class="sg-actions"><button type="button" class="sb-btn is-primary" @click="save(r)"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button><button type="button" class="sb-btn" @click="remove(r)"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button></div>
            </div>
          </div>
          <p v-if="$page.props.errors && $page.props.errors.signing_role_id" class="sg-error">{{ $page.props.errors.signing_role_id }}</p>
        </section>

        <section class="sb-card" style="--i: 2">
          <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Add a signing line') }}</h3>
          <form class="sg-map is-new" @submit.prevent="add">
            <div class="sb-field"><label>{{ $t('Signing role') }}</label><auto-complete v-model="nf.signing_role_id" :suggestions="roles" :placeholder="$t('Choose..')" /></div>
            <div class="sb-field"><label>{{ $t('Qualifying action') }}</label><auto-complete v-model="nf.workflow_action" :suggestions="doc.actions" :placeholder="$t('Choose..')" /></div>
            <div class="sb-field sg-narrow"><label>{{ $t('Order') }}</label><input v-model.number="nf.slot" type="number" min="1" max="12" class="sb-input" /></div>
            <div class="sb-field"><label>{{ $t('Shown') }}</label><auto-complete v-model="nf.show_when" :suggestions="whenOptions" /></div>
            <div class="sb-field"><label>{{ $t('If missing') }}</label><auto-complete v-model="nf.missing_behaviour" :suggestions="missOptions" /></div>
            <label class="sg-onoff"><input v-model="nf.is_required" type="checkbox" />{{ $t('Required') }}</label>
            <label class="sg-onoff" :class="{ 'is-dim': !nf.is_required }"><input v-model="nf.blocks_final" type="checkbox" :disabled="!nf.is_required" />{{ $t('Blocks finalising') }}</label>
            <div class="sg-actions"><button type="submit" class="sb-btn is-primary" :disabled="!nf.signing_role_id || !nf.workflow_action"><icons name="plus" class="w-4 h-4" />{{ $t('Add') }}</button></div>
          </form>
          <p class="sg-hint mt-2">{{ $t('Always: the signing line is printed from the start and stays blank until the person acts. After the action: the line only appears once the action has happened. Only when final: the line waits for the final document.') }} {{ $t('"Shown after the action" prints the signature as soon as the action has happened. "Only when final" waits until the document is issued. "Blocks finalising" stops the record being finalised while the signature cannot be shown. Nothing blocks until you switch it on here.') }}</p>
        </section>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, SbHero },
  props: { documents: Array, roles: Array, focus: String },
  data() { return { type: this.focus || (this.documents[0] ? this.documents[0].type : ''), rows: [], resetting: false, pw: '', nf: this.blank() }; },
  computed: {
    docOptions() { return this.documents.map(d => ({ value: d.type, label: d.label + ' (' + d.rows.length + ')' })); },
    doc() { return this.documents.find(d => d.type === this.type) || null; },
    whenOptions() { return [{ value: 'always', label: this.$t('Always') }, { value: 'after', label: this.$t('After the action') }, { value: 'final', label: this.$t('Only when final') }]; },
    missOptions() { return [{ value: 'blank', label: this.$t('Blank line') }, { value: 'flag', label: this.$t('Flag it') }, { value: 'block', label: this.$t('Block') }]; },
  },
  watch: { doc: { immediate: true, handler(d) { this.rows = d ? d.rows.map(r => ({ ...r })) : []; this.nf = this.blank(); } } },
  methods: {
    blank() { return { signing_role_id: '', workflow_action: '', slot: 1, show_when: 'always', missing_behaviour: 'blank', is_required: false, blocks_final: false, is_active: true }; },
    payload(r) { return { signing_role_id: r.signing_role_id, workflow_action: r.workflow_action, slot: r.slot, is_required: !!r.is_required, show_when: r.show_when, blocks_final: !!r.blocks_final && !!r.is_required, missing_behaviour: r.missing_behaviour, permission: r.permission || null, is_active: !!r.is_active }; },
    add() { this.$inertia.post(route('signatories.mappings.store'), { ...this.payload(this.nf), document_type: this.type }, { preserveScroll: true, onSuccess: () => { this.nf = this.blank(); } }); },
    save(r) { this.$inertia.put(route('signatories.mappings.update', r.id), this.payload(r), { preserveScroll: true }); },
    remove(r) { if (window.confirm(this.$t('Remove this signing line?'))) this.$inertia.delete(route('signatories.mappings.destroy', r.id), { preserveScroll: true }); },
    askReset() { this.resetting = true; this.pw = ''; },
    doReset() { this.$inertia.post(route('signatories.mappings.reset', this.type), { password: this.pw }, { preserveScroll: true, onSuccess: () => { this.resetting = false; } }); },
  },
};
</script>
