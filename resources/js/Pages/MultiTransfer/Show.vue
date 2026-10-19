<template>
  <admin-layout :title="multi.reference">
    <div class="lg-page mt px-4 md:px-0">
      <page-header icon="collection" :title="multi.reference" :subtitle="$t('To') + ' ' + multi.to + ' · ' + $date(multi.date)">
        <Link :href="route('multi-transfers.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <template v-if="can.print">
          <a :href="route('multi-transfers.print', multi.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
          <a :href="route('multi-transfers.pdf', multi.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        </template>
      </page-header>

      <section class="lg-card mt-status">
        <span class="lg-chip" :class="chip(multi.status)">{{ $t(multi.status_label) }}</span>
        <span class="mt-meta">{{ multi.approved }} / {{ multi.transfers_count }} {{ $t('transfers approved') }}</span>
        <span class="mt-meta">{{ $t('Created by') }} {{ multi.user }}</span>
        <p v-if="multi.details" class="mt-details">{{ multi.details }}</p>
        <div v-if="can.approve || can.cancel" class="mt-actions">
          <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="approveAll"><icons name="tick" class="w-4 h-4" />{{ $t('Approve all') }} ({{ can.pending }})</button>
          <button v-if="can.cancel" type="button" class="lg-btn lg-btn-red" :disabled="busy" @click="askCancel = !askCancel"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel the waiting transfers') }}</button>
        </div>
        <div v-if="askCancel" class="mt-cancel">
          <input v-model="reason" type="text" maxlength="500" :placeholder="$t('Reason (optional)')" />
          <button type="button" class="lg-btn lg-btn-red" :disabled="busy" @click="cancelAll">{{ $t('Confirm cancel') }}</button>
        </div>
        <p v-if="can.approve" class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('Approving moves stock for every source that still has enough. A source that does not is named and stays waiting.') }}</span></p>
      </section>

      <section v-for="t in multi.transfers" :key="t.id" class="lg-card mt-src-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('From') }} {{ t.from }}</h2><p class="lg-card-sub">{{ t.reference }}<template v-if="t.approved_by"> · {{ $t('approved by') }} {{ t.approved_by }}</template><template v-if="t.cancelled_by"> · {{ $t('cancelled by') }} {{ t.cancelled_by }}<template v-if="t.cancel_reason"> ({{ t.cancel_reason }})</template></template></p></div>
          <span class="lg-chip" :class="chip(t.status)">{{ $t(childLabel(t.status)) }}</span>
          <Link v-if="t.can_open" :href="route('transfers.edit', t.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open the transfer')" :aria-label="$t('Open the transfer')"><icons name="eye-flat" class="w-4 h-4" /></Link>
        </header>
        <ul class="mt-lines">
          <li v-for="(l, i) in t.lines" :key="i"><span class="mt-no">{{ i + 1 }}</span><span class="mt-item"><b>{{ l.item }}</b><small>{{ l.code }}</small></span><span class="mt-qty">{{ l.quantity }} {{ l.unit }}</span></li>
        </ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader, Link },
  props: { multi: Object, can: Object },
  data() { return { busy: false, askCancel: false, reason: '' }; },
  methods: {
    chip(s) { return { pending: 'lg-chip-amber', partial: 'lg-chip-violet', approved: 'lg-chip-green', received: 'lg-chip-green', cancelled: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    childLabel(s) { return { pending: 'Waiting for approval', approved: 'Approved', received: 'Received', cancelled: 'Cancelled' }[s] || s; },
    approveAll() { this.busy = true; this.$inertia.post(route('multi-transfers.approve', this.multi.id), {}, { preserveScroll: true, onFinish: () => { this.busy = false; } }); },
    cancelAll() { this.busy = true; this.$inertia.post(route('multi-transfers.cancel', this.multi.id), { reason: this.reason }, { preserveScroll: true, onFinish: () => { this.busy = false; this.askCancel = false; } }); },
  },
};
</script>
