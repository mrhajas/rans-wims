<template>
  <admin-layout :title="$t('Labour List') + ' ' + labourList.reference">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="labourList.reference" :subtitle="labourList.warehouse_name + ' · ' + labourList.period_start + ' – ' + labourList.period_end">
        <span class="lg-chip" :class="statusChip(labourList.status)">{{ labourList.status }}</span>
        <span v-if="labourList.payment_status" class="lg-chip" :class="payChip(labourList.payment_status)">{{ labourList.payment_status }}</span>
        <Link :href="route('labour.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Labour') }}</Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="lg-actionbar lbr-actions">
        <span class="lg-actionbar-note">{{ labourList.reference }}</span>
        <div class="lg-actionbar-btns flex-wrap">
          <Link v-if="can.update" :href="route('labour.edit', labourList.id)" class="lg-btn lg-btn-soft">
            <icons name="edit" class="w-4 h-4" /> {{ $t('Edit') }}
          </Link>
          <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="submit"><icons name="send" class="w-4 h-4" />{{ $t('Submit for Review') }}</button>
          <button v-if="can.createPaymentVoucher" type="button" class="lg-btn lg-btn-green" @click="createVoucher">
            <icons name="doc-text" class="w-4 h-4" /> {{ $t('Create Payment Voucher') }}
          </button>
          <a v-if="can.print" :href="route('labour.print', labourList.id)" target="_blank" class="lg-btn lg-btn-soft">
            <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
          </a>
          <a v-if="can.print" :href="route('labour.pdf', labourList.id)" class="lg-btn lg-btn-soft">
            <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
          </a>
          <button v-if="can.delete" type="button" class="lg-btn lg-btn-red" @click="destroy"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
        </div>
      </div>

      <section v-if="labourList.review_remarks && labourList.status === 'Returned'" class="lg-card lbr-return">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Return Reason') }}</h2></div>
        </header>
        <div class="text-sm lbr-body">{{ labourList.review_remarks }}</div>
      </section>

      <section v-if="can.clear || can.approve" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ can.clear ? $t('Project Manager Review') : $t('Super Admin Review') }}</h2></div>
        </header>

        <div v-if="can.approve && agreementItems.length" class="lbr-revision-block mb-4">
          <div class="lbr-revision-title">{{ $t('Review Requested Amounts') }}</div>
          <div class="lg-table-wrap lbr-inner-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Labour Personnel') }}</th>
                  <th class="num">{{ $t('Requested') }}</th>
                  <th class="num">{{ $t('Outstanding Balance') }}</th>
                  <th>{{ $t('Approved Amount') }}</th>
                  <th>{{ $t('Reason (if changed)') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in agreementItems" :key="item.id">
                  <td :data-label="$t('Labour Personnel')">
                    <div class="lg-main">{{ item.payee_name }}</div>
                    <div class="lg-subtext">{{ item.agreement_reference }}</div>
                  </td>
                  <td class="num" :data-label="$t('Requested')">{{ money(item.amount) }}</td>
                  <td class="num" :data-label="$t('Outstanding Balance')">{{ money(item.agreement_outstanding_balance) }}</td>
                  <td :data-label="$t('Approved Amount')">
                    <input
                      type="text"
                      inputmode="decimal"
                      class="lbr-revision-input"
                      :value="formatMoneyInput(revisions[item.id].approved_amount)"
                      @input="revisions[item.id].approved_amount = $event.target.value.replace(/,/g, '')"
                    />
                  </td>
                  <td :data-label="$t('Reason')">
                    <input type="text" class="lbr-revision-input" v-model="revisions[item.id].revision_reason" :placeholder="$t('Required if amount changed')" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <textarea-input v-model="reviewForm.remarks" :label="$t('Remarks (required to return)')" />
        <div class="flex items-center gap-2 mt-3 flex-wrap">
          <button v-if="can.clear" type="button" class="lg-btn lg-btn-primary" @click="changeStatus('Cleared')"><icons name="tick" class="w-4 h-4" />{{ $t('Clear') }}</button>
          <button
            v-if="can.approve"
            type="button"
            class="lg-btn lg-btn-green"
            :class="{ 'lg-tip tip-left': labourList.status !== 'Cleared' }"
            :data-tip="labourList.status !== 'Cleared' ? $t('Already approved. Return it for correction to approve again.') : null"
            :disabled="labourList.status !== 'Cleared'"
            @click="changeStatus('Approved')"
          ><icons name="tick" class="w-4 h-4" />{{ $t('Approve for Payment') }}</button>
          <button type="button" class="lg-btn lg-btn-red" @click="changeStatus('Returned')"><icons name="refresh" class="w-4 h-4" />{{ $t('Return for Correction') }}</button>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Approval Chain') }}</h2></div>
        </header>
        <div class="lbr-chain">
          <div class="lbr-step" :class="{ 'is-done': labourList.submitted_by_name }">
            <div class="lbr-chain-label">{{ $t('Submitted') }}</div>
            <div class="lbr-chain-who">{{ labourList.submitted_by_name || '—' }}</div>
            <div class="lbr-chain-when">{{ labourList.submitted_at || '' }}</div>
          </div>
          <div class="lbr-step" :class="{ 'is-done': labourList.cleared_by_name }">
            <div class="lbr-chain-label">{{ $t('Cleared (PM)') }}</div>
            <div class="lbr-chain-who">{{ labourList.cleared_by_name || '—' }}</div>
            <div class="lbr-chain-when">{{ labourList.cleared_at || '' }}</div>
          </div>
          <div class="lbr-step" :class="{ 'is-done': labourList.approved_by_name }">
            <div class="lbr-chain-label">{{ $t('Approved (Super Admin)') }}</div>
            <div class="lbr-chain-who">{{ labourList.approved_by_name || '—' }}</div>
            <div class="lbr-chain-when">{{ labourList.approved_at || '' }}</div>
          </div>
          <div class="lbr-step" :class="{ 'is-done': labourList.batch_number }">
            <div class="lbr-chain-label">{{ $t('Payment Voucher') }}</div>
            <div class="lbr-chain-who">{{ labourList.batch_number || '—' }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Labour Personnel') }}</h2></div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Labour Personnel') }}</th>
                <th>{{ $t('Priority') }}</th>
                <th>{{ $t('Work Performed') }}</th>
                <th class="num">{{ $t('Days/Hrs') }}</th>
                <th class="num">{{ $t('Rate') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Voucher') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in labourList.items" :key="item.id">
                <td :data-label="$t('Labour Personnel')">
                  <div class="lg-person"><span class="lg-avatar">{{ initials(item.payee_name) }}</span><span class="lg-main">{{ item.payee_name }}</span></div>
                </td>
                <td :data-label="$t('Priority')">
                  <span class="lg-chip" :class="priorityChip(item.priority)">{{ item.priority }}</span>
                </td>
                <td :data-label="$t('Work Performed')"><span class="lbr-work">{{ item.work_description || '—' }}</span></td>
                <td class="num" :data-label="$t('Days/Hrs')">{{ item.rate_type === 'day' ? item.days : (item.rate_type === 'hour' ? item.hours : '—') }}</td>
                <td class="num" :data-label="$t('Rate')">{{ money(item.rate) }}</td>
                <td class="num lg-main" :data-label="$t('Amount')">
                  {{ money(item.final_amount) }}
                  <div v-if="item.approved_amount !== null && item.approved_amount !== item.amount" class="lg-subtext line-through">{{ money(item.amount) }}</div>
                </td>
                <td :data-label="$t('Voucher')">
                  <Link v-if="item.payment_voucher" :href="route('payment-vouchers.show', item.payment_voucher.id)" class="lg-chip" :class="item.payment_voucher.paid ? 'lg-chip-green' : 'lg-chip-blue'">
                    {{ item.payment_voucher.pv_number }}
                  </Link>
                  <span v-else class="lg-subtext">—</span>
                </td>
              </tr>
              <tr class="lbr-total-row">
                <td colspan="5" class="num lbr-total-label">{{ $t('Total') }}</td>
                <td class="num lg-main">{{ money(finalTotalAmount) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="labourList.description" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Description') }}</h2></div>
        </header>
        <div class="text-sm lbr-body">{{ labourList.description }}</div>
      </section>

      <section v-if="(labourList.attachments || []).length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div>
        </header>
        <div class="flex flex-wrap gap-2">
          <a v-for="a in labourList.attachments" :key="a.id" :href="a.download_url" class="lg-btn lg-btn-soft">
            <icons name="download" class="w-3.5 h-3.5" /> {{ a.name }}
          </a>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, TextareaInput, PageHeader, StatTiles },

  props: {
    labourList: Object,
    can: Object,
  },

  data() {
    const revisions = {};
    this.labourList.items.forEach(item => {
      if (item.workforce_agreement_id) {
        revisions[item.id] = { approved_amount: item.approved_amount ?? item.amount, revision_reason: item.revision_reason || '' };
      }
    });

    return {
      reviewForm: this.$inertia.form({ remarks: '' }),
      revisions,
    };
  },

  computed: {
    statTiles() {
      return [
        { icon: 'cash', tone: 'indigo', label: this.$t('Total Labour Amount'), value: this.money(this.labourList.total_amount) },
        { icon: 'users', tone: 'sky', label: this.$t('Labour Personnel'), value: this.labourList.items.length },
        { icon: 'doc-text', tone: this.labourList.batch_id ? 'emerald' : 'slate', label: this.$t('Payment Voucher'), value: this.labourList.batch_id ? this.labourList.batch_number : this.$t('Not yet created') },
      ];
    },
    agreementItems() {
      return this.labourList.items.filter(item => item.workforce_agreement_id);
    },
    finalTotalAmount() {
      return this.labourList.items.reduce((sum, item) => sum + Number(item.final_amount ?? item.amount), 0);
    },
  },

  methods: {
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatMoneyInput(v) {
      if (v === null || v === undefined || v === '') return '';
      const [intPart, decimalPart] = String(v).split('.');
      const groupedInt = intPart === '' || intPart === '-' ? intPart : Number(intPart).toLocaleString('en-US');
      return decimalPart !== undefined ? `${groupedInt}.${decimalPart}` : groupedInt;
    },
    statusChip(s) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', returned: 'lg-chip-red', cleared: 'lg-chip-amber', approved: 'lg-chip-blue', payment_voucher_created: 'lg-chip-green' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    payChip(s) {
      return { unpaid: 'lg-chip-red', processing: 'lg-chip-amber', paid: 'lg-chip-green', issue: 'lbr-chip-dark' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    priorityChip(s) {
      return { low: 'lg-chip-slate', normal: 'lg-chip-blue', high: 'lg-chip-amber', extreme: 'lg-chip-red' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    initials(name) {
      return (name || '')
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
    statusSlug(s) {
      return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    },
    submit() {
      this.$inertia.patch(this.route('labour.submit', this.labourList.id));
    },
    async changeStatus(status) {
      if (status === 'Returned' && (!this.reviewForm.remarks || !this.reviewForm.remarks.trim())) {
        await this.$alert(this.$t('Please enter remarks explaining why this is being returned.'));
        return;
      }

      let items;
      if (status === 'Approved' && this.agreementItems.length) {
        const changedItems = [];
        for (const item of this.agreementItems) {
          const revision = this.revisions[item.id];
          const changed = Number(revision.approved_amount) !== Number(item.amount);
          if (changed) {
            if (!revision.revision_reason.trim()) {
              await this.$alert(this.$t('Please enter a reason for revising the approved amount for {name}.', { name: item.payee_name }));
              return;
            }
            changedItems.push({ id: item.id, approved_amount: revision.approved_amount, revision_reason: revision.revision_reason });
          }
        }
        if (changedItems.length) items = changedItems;
      }

      this.reviewForm.transform(data => ({ status, remarks: data.remarks, ...(items ? { items } : {}) })).patch(this.route('labour.status', this.labourList.id), {
        onSuccess: () => { this.reviewForm.reset(); },
      });
    },
    createVoucher() {
      this.$inertia.post(this.route('labour.create-payment-voucher', this.labourList.id));
    },
    async destroy() {
      if (!(await this.$confirm(this.$t('Delete this Labour List? This cannot be undone.')))) return;
      this.$inertia.delete(this.route('labour.destroy', this.labourList.id));
    },
  },
};
</script>

<style scoped>
.lbr-actions { position: static; margin-bottom: 1rem; border-radius: 22px; }
.lbr-body { color: var(--lg-ink); white-space: pre-line; }
.lbr-return { border-color: rgba(244, 63, 94, 0.3); }
.lbr-work { display: inline-block; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lg-mute); }
.lbr-chip-dark { background: #450a0a; color: #fecaca; }
.lbr-total-row td { background: var(--lg-soft); border-bottom: 0; }
.lbr-total-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }

.lbr-revision-block { border: 1px solid rgba(245, 158, 11, 0.35); background: rgba(254, 243, 199, 0.5); border-radius: 16px; padding: 0.9rem; }
.lbr-revision-title { font-size: 0.72rem; font-weight: 800; color: #92400e; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.6rem; }
.lbr-inner-wrap { background: var(--lg-card); }
.lbr-revision-input { display: block; width: 100%; min-width: 110px; border: 1px solid var(--lg-line); border-radius: 10px; padding: 0.4rem 0.6rem; font-size: 0.82rem; color: var(--lg-ink); background: var(--lg-card); }
.lbr-revision-input:focus { outline: none; border-color: #6f8dff; box-shadow: 0 0 0 3px rgba(111, 141, 255, 0.2); }

.lbr-chain { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); gap: 12px; }
.lbr-step { position: relative; padding: 0.75rem 0.9rem 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.lbr-step::before { content: ''; position: absolute; left: 0; top: 10px; bottom: 10px; width: 4px; border-radius: 9999px; background: var(--lg-line); }
.lbr-step.is-done::before { background: linear-gradient(180deg, #34d399, #047857); }
.lbr-chain-label { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lg-faint); margin-bottom: 0.2rem; }
.lbr-chain-who { font-size: 0.88rem; font-weight: 700; color: var(--lg-ink); }
.lbr-chain-when { font-size: 0.7rem; color: var(--lg-faint); }
html.dark .lbr-revision-title { color: #fcd34d; }
html.dark .lbr-revision-block { background: rgba(245, 158, 11, 0.08); }
</style>
