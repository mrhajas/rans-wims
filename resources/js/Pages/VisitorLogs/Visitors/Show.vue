<template>
  <admin-layout :title="visitor.name">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="user" :title="visitor.name" :subtitle="[visitor.reference, visitor.company].filter(Boolean).join(' · ')">
        <Link :href="route('visitor-logs.visitors.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <Link v-if="can.newVisit && visitor.is_active" :href="route('visitor-logs.visits.create', { visitor_id: visitor.id })" class="lg-btn lg-btn-primary"><icons name="in" class="w-4 h-4" /><span>{{ $t('New Visit') }}</span></Link>
        <button v-if="can.update" type="button" class="lg-btn lg-btn-soft" @click="editOpen = true"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></button>
        <button v-if="can.deactivate" type="button" class="lg-btn" :class="visitor.is_active ? 't-red' : 'lg-btn-soft'" :disabled="busy" @click="toggle"><icons :name="visitor.is_active ? 'x-circle' : 'tick'" class="w-4 h-4" /><span>{{ visitor.is_active ? $t('Deactivate') : $t('Reactivate') }}</span></button>
      </page-header>

      <div v-if="!visitor.is_active" class="lg-note lg-note-amber" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('This visitor is inactive: they stay in the history but cannot be chosen for new visits.') }}</span></div>

      <div class="vl-two">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
          <dl class="ac-kv">
            <div><dt>{{ $t('Company') }}</dt><dd>{{ visitor.company || '-' }}</dd></div>
            <div><dt>{{ $t('Phone') }}</dt><dd>{{ visitor.phone || '-' }}</dd></div>
            <div><dt>{{ $t('Email') }}</dt><dd>{{ visitor.email || '-' }}</dd></div>
            <div><dt>{{ $t('Category') }}</dt><dd>{{ visitor.category || '-' }}</dd></div>
            <div v-if="fields.includes('vehicle_registration')"><dt>{{ $t('Vehicle') }}</dt><dd>{{ visitor.vehicle || '-' }}</dd></div>
            <div><dt>{{ $t('Added by') }}</dt><dd>{{ visitor.created_by || '-' }} <small class="lg-subtext">{{ visitor.created_at ? $datetime(visitor.created_at) : '' }}</small></dd></div>
          </dl>
          <p v-if="visitor.remarks" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Remarks') }}:</b> {{ visitor.remarks }}</p>
        </section>
        <section v-if="can.sensitive" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Sensitive Details') }}</h2><p class="lg-card-sub">{{ $t('Only people allowed to see sensitive details can read these. Each time they are opened it is logged.') }}</p></div></header>
          <dl class="ac-kv">
            <div v-if="fields.includes('id_type')"><dt>{{ $t('Identification document') }}</dt><dd>{{ visitor.id_type || '-' }}</dd></div>
            <div v-if="fields.includes('id_number')"><dt>{{ $t('Identification number') }}</dt><dd>{{ visitor.id_number || '-' }}</dd></div>
            <div v-if="fields.includes('nationality')"><dt>{{ $t('Nationality') }}</dt><dd>{{ visitor.nationality || '-' }}</dd></div>
            <div v-if="fields.includes('address')"><dt>{{ $t('Address') }}</dt><dd>{{ visitor.address || '-' }}</dd></div>
            <div v-if="fields.includes('emergency_notes')"><dt>{{ $t('Emergency / contact notes') }}</dt><dd>{{ visitor.emergency_notes || '-' }}</dd></div>
          </dl>
          <p v-if="!fields.some(f => ['id_type', 'id_number', 'nationality', 'address', 'emergency_notes'].includes(f))" class="lg-sub">{{ $t('No sensitive field is switched on in the Visitor Logs settings.') }}</p>
        </section>
      </div>

      <section v-if="can.history" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Previous Visits') }}</h2><p class="lg-card-sub">{{ $t('The latest 30 visits you may see.') }}</p></div></header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive tr-table">
            <thead><tr><th>{{ $t('Visit') }}</th><th>{{ $t('Person visited') }}</th><th>{{ $t('Purpose') }}</th><th class="tr-nw">{{ $t('Check-in') }}</th><th class="tr-nw">{{ $t('Check-out') }}</th><th>{{ $t('Duration') }}</th><th>{{ $t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="v in visits" :key="v.id" :class="{ 'is-click': v.can_open }" @click="v.can_open && $inertia.visit(route('visitor-logs.visits.show', v.id))">
                <td :data-label="$t('Visit')"><b class="lg-main lg-mono">{{ v.reference }}</b></td>
                <td :data-label="$t('Person visited')" class="tr-wrap">{{ v.host || '-' }}</td>
                <td :data-label="$t('Purpose')" class="tr-wrap">{{ v.purpose || '-' }}</td>
                <td :data-label="$t('Check-in')" class="tr-nw">{{ $datetime(v.check_in_at) }}</td>
                <td :data-label="$t('Check-out')" class="tr-nw">{{ v.check_out_at ? $datetime(v.check_out_at) : '-' }}</td>
                <td :data-label="$t('Duration')" class="tr-nw">{{ v.duration || '-' }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(v.status)" style="text-transform: none">{{ $t(v.status) }}</span></td>
              </tr>
              <tr v-if="!visits.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No visits yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
    <visitor-form-modal :show="editOpen" :visitor="editing" :categories="categories" :fields="fields" :sensitive="can.sensitive" :require-phone="requirePhone" @close="editOpen = false" @saved="edited" />
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import VisitorFormModal from '../Parts/VisitorFormModal.vue';
import { visitChip } from '../Parts/vl.js';

export default {
  components: { Link, AdminLayout, PageHeader, VisitorFormModal },
  props: { visitor: Object, visits: Array, fields: Array, categories: Array, can: Object, requirePhone: Boolean },
  data() { return { editOpen: false, busy: false }; },
  computed: { editing() { return { ...this.visitor, full_name: this.visitor.name, vehicle_registration: this.visitor.vehicle }; } },
  methods: {
    chip: visitChip,
    edited() { this.editOpen = false; this.$inertia.reload({ preserveScroll: true }); },
    toggle() { this.busy = true; this.$inertia.post(route('visitor-logs.visitors.toggle', this.visitor.id), {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
  },
};
</script>

<style scoped>
.vl-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
.vl-two > .lg-card { margin-bottom: 0; }
@media (max-width: 900px) { .vl-two { grid-template-columns: 1fr; } .vl-two > .lg-card { margin-bottom: 16px; } }
</style>
