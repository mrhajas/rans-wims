<template>
  <admin-layout :title="$t('Signing Roles')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="users" :title="$t('Signing Roles')" :subtitle="$t('Prepared By, Approved By and the other roles a document can carry. A person can hold different roles on different documents.')">
        <Link :href="route('signatories.index')" class="sb-gbtn"><icons name="edit" class="w-4 h-4" />{{ $t('Signatories') }}</Link>
      </sb-hero>
      <section class="sb-card mb-4" style="--i: 0">
        <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Add a signing role') }}</h3>
        <form class="sb-fields" @submit.prevent="add">
          <div class="sb-field"><label for="r-l">{{ $t('Name') }}</label><input id="r-l" v-model="nf.label" type="text" class="sb-input" maxlength="60" /></div>
          <div class="sb-field sb-wide-2"><label for="r-d">{{ $t('What it means') }}</label><input id="r-d" v-model="nf.description" type="text" class="sb-input" maxlength="255" /></div>
          <div class="sb-field sg-go"><button type="submit" class="sb-btn is-primary" :disabled="!nf.label || nf.processing"><icons name="plus" class="w-4 h-4" />{{ $t('Add') }}</button></div>
        </form>
        <p v-if="$page.props.errors && $page.props.errors.label" class="sg-error">{{ $page.props.errors.label }}</p>
      </section>
      <section class="sb-card" style="--i: 1">
        <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Roles') }}<small>{{ list.length }}</small></h3>
        <div class="sg-roles">
          <form v-for="r in list" :key="r.id" class="sg-role" :class="{ 'is-off': !r.is_active }" @submit.prevent="save(r)">
            <div class="sb-field"><label>{{ $t('Name') }}</label><input v-model="r.label" type="text" class="sb-input" maxlength="60" /></div>
            <div class="sb-field sb-wide-2"><label>{{ $t('What it means') }}</label><input v-model="r.description" type="text" class="sb-input" maxlength="255" /></div>
            <div class="sb-field sg-order"><label>{{ $t('Order') }}</label><input v-model.number="r.sort_order" type="number" min="0" max="9999" class="sb-input" /></div>
            <label class="sg-onoff"><input v-model="r.is_active" type="checkbox" />{{ $t('In use') }}</label>
            <div class="sg-actions"><button type="submit" class="sb-btn is-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
              <button type="button" class="sb-btn" :disabled="r.used > 0" :title="r.used > 0 ? $t('Used by :n document setup(s). Switch it off instead.', { n: r.used }) : ''" @click="remove(r)"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button></div>
            <small class="sg-key">{{ r.key }}<template v-if="r.used"> · {{ $t('used by') }} {{ r.used }}</template></small>
          </form>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

export default {
  components: { AdminLayout, Link, SbHero },
  props: { roles: Array },
  data() { return { nf: this.$inertia.form({ label: '', description: '' }), list: this.roles.map(r => ({ ...r })) }; },
  watch: { roles(v) { this.list = v.map(r => ({ ...r })); } },
  methods: {
    add() { this.nf.post(route('signatories.roles.store'), { preserveScroll: true, onSuccess: () => this.nf.reset() }); },
    save(r) { this.$inertia.put(route('signatories.roles.update', r.id), { label: r.label, description: r.description, sort_order: r.sort_order, is_active: r.is_active }, { preserveScroll: true }); },
    remove(r) { if (window.confirm(this.$t('Delete the signing role :n?', { n: r.label }))) this.$inertia.delete(route('signatories.roles.destroy', r.id), { preserveScroll: true }); },
  },
};
</script>
