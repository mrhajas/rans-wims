<template>
  <admin-layout :title="staff.staff_number">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="staff.staff_number" :subtitle="staff.name + ' · ' + (staff.position || $t('No position set'))">
        <a v-if="canPrint" :href="route('staffs.print', staff.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a v-if="canPrint" :href="route('staffs.pdf', staff.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </a>
        <retire-staff v-if="canRetire" :staff="staff" />
        <Link v-if="$can('update-staffs')" :href="route('staffs.edit', staff.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" /> {{ $t('Edit') }}
        </Link>
        <Link :href="route('staffs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('RANS Staffs') }}</Link>
      </page-header>

      <section class="lg-card sh-id">
        <div v-if="staff.photo_url && !photoFailed" class="sh-photo-wrap flex-none">
          <img :src="staff.photo_url" class="sh-photo" alt="" @error="photoFailed = true" />
        </div>
        <div v-else class="sh-initials flex-none">{{ initials }}</div>
        <div class="min-w-0">
          <div class="sh-name">{{ staff.name }}</div>
          <div class="lg-subtext">{{ staff.position || $t('No position set') }}</div>
          <div class="flex flex-wrap gap-1.5 mt-2">
            <span class="lg-chip lg-chip-blue" style="text-transform: none">{{ staff.staff_number }}</span>
            <span class="lg-chip" :class="statusBadgeClass(staff.status)">{{ staff.status }}</span>
            <span v-if="staff.employment_type" class="lg-chip lg-chip-violet" style="text-transform: none">{{ staff.employment_type }}</span>
            <span v-if="canContracts && current" class="lg-chip lg-tip" :class="contractChip(current.state)" style="text-transform: none" :data-tip="current.contract_number + ' · ' + current.type" @click="activeTab = 'contracts'">
              {{ current.end_date ? $t('Contract ends') + ' ' + $date(current.end_date) : (current.type === 'Permanent' ? $t('Permanent contract') : $t('Contract: open ended')) }}
            </span>
            <span v-else-if="canContracts" class="lg-chip lg-chip-slate" style="text-transform: none; cursor: pointer" @click="activeTab = 'contracts'">{{ $t('No active contract') }}</span>
          </div>
          <p v-if="isRetired" class="sh-retired"><icons name="user" class="w-4 h-4" />
            {{ staff.status === 'Retired Active' ? $t('Retired, still on payroll') : $t('Retired, off payroll') }}<template v-if="staff.retired_at"> · {{ $date(staff.retired_at) }}</template><template v-if="staff.retirement_note"> · {{ staff.retirement_note }}</template>
          </p>
        </div>
      </section>

      <section v-if="ransId" class="sh-rans">
        <header class="sh-rans-head">
          <span class="sh-rans-ico"><icons name="qr-code" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2>{{ $t('RANS Identification') }}</h2><p>{{ $t('The ID card of this staff member. The WIMS staff reference above is a different number.') }}</p></div>
          <a v-if="ransId.card" :href="ransId.card.url" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="eye-flat" class="w-4 h-4" /></span>{{ $t('View ID') }}</a>
          <a v-if="ransId.assign_url" :href="ransId.assign_url" class="lg-btn tone tone-solid" style="--tone: #2563eb"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Assign RANS Staff ID') }}</a>
        </header>
        <div v-if="ransId.card" class="sh-rans-body">
          <id-cutout :front="faces.front" :back="faces.back" :css="faces.css" :color="ransId.card.color" :size="230" :can-print="ransId.card.can_print" :can-download="ransId.card.can_download" :blocked="!ransId.card.active" :blocked-text="$t('Only an active ID can be printed or downloaded.')" @print="makeId('print')" @download="makeId('pdf')" />
          <dl class="sh-rans-dl">
            <div><dt>{{ $t('ID number') }}</dt><dd><a :href="ransId.card.url" class="sh-rans-no">{{ ransId.card.number }}</a></dd></div>
            <div><dt>{{ $t('Status') }}</dt><dd><span class="lg-chip" :class="{ Active: 'lg-chip-green', Expired: 'lg-chip-amber', 'Pending Approval': 'lg-chip-amber', Suspended: 'lg-chip-orange', Revoked: 'lg-chip-red', Replaced: 'lg-chip-slate' }[ransId.card.status] || 'lg-chip-slate'">{{ $t(ransId.card.status) }}</span></dd></div>
            <div><dt>{{ $t('Issued') }}</dt><dd>{{ $date(ransId.card.issued_at) }}</dd></div>
            <div><dt>{{ $t('Expires') }}</dt><dd>{{ ransId.card.expires_at ? $date(ransId.card.expires_at) : $t('No expiry') }}</dd></div>
            <div><dt>{{ $t('Last printed') }}</dt><dd>{{ ransId.card.last_printed_at ? $datetime(ransId.card.last_printed_at) : $t('Never') }}</dd></div>
            <div><dt>{{ $t('Printed') }}</dt><dd>{{ ransId.card.print_count }} {{ $t('times') }}</dd></div>
          </dl>
        </div>
        <p v-else class="lg-sub">{{ $t('No RANS Staff ID has been assigned yet.') }}</p>
        <id-make-overlay />
      </section>

      <stat-tiles :items="statTiles" />

      <div class="sh-tabs mb-4" role="tablist">
        <button type="button" role="tab" :aria-selected="activeTab === 'details'" class="sh-tab" :class="{ 'is-active': activeTab === 'details' }" @click="activeTab = 'details'">
          <icons name="doc-text" class="w-4 h-4" /> {{ $t('Details') }}
        </button>
        <button type="button" role="tab" :aria-selected="activeTab === 'vouchers'" class="sh-tab" :class="{ 'is-active': activeTab === 'vouchers' }" @click="activeTab = 'vouchers'">
          <icons name="bag" class="w-4 h-4" /> {{ $t('Payment Vouchers') }}
          <span class="sh-tab-count">{{ paymentVouchersSummary.count }}</span>
        </button>
        <button v-if="canContracts" type="button" role="tab" :aria-selected="activeTab === 'contracts'" class="sh-tab" :class="{ 'is-active': activeTab === 'contracts' }" @click="activeTab = 'contracts'">
          <icons name="doc-text" class="w-4 h-4" /> {{ $t('Contracts') }}
          <span class="sh-tab-count">{{ contracts.length }}</span>
        </button>
        <button v-if="canContracts" type="button" role="tab" :aria-selected="activeTab === 'history'" class="sh-tab" :class="{ 'is-active': activeTab === 'history' }" @click="activeTab = 'history'">
          <icons name="clock" class="w-4 h-4" /> {{ $t('History') }}
          <span class="sh-tab-count">{{ history.length }}</span>
        </button>
      </div>

      <staff-contracts v-if="activeTab === 'contracts' && canContracts" :staff="staff" :contracts="contracts" :types="contractTypes" :can-add="canContractAdd" :can-update="canContractEdit" :can-delete="canContractDelete" :can-print="canContractPrint" :can-stop="canContractStop" :stop-reasons="stopReasons" />
      <staff-history v-if="activeTab === 'history' && canContracts" :history="history" :stop-reasons="stopReasons" />

      <div v-if="activeTab === 'details'">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Personal Information') }}</h2></div>
          </header>
          <dl class="sh-grid">
            <div><dt>{{ $t('Name') }}</dt><dd>{{ staff.name }}</dd></div>
            <div><dt>{{ $t('Gender') }}</dt><dd>{{ staff.gender || '-' }}</dd></div>
            <div><dt>{{ $t('Qualification') }}</dt><dd>{{ staff.qualification || '-' }}</dd></div>
            <div><dt>{{ $t('Date of Birth') }}</dt><dd>{{ staff.date_of_birth ? $date(staff.date_of_birth) : '-' }}</dd></div>
            <div><dt>{{ $t('Position') }}</dt><dd>{{ staff.position || '-' }}</dd></div>
            <div><dt>{{ $t('Employment') }}</dt><dd>{{ staff.employment_type || '-' }}</dd></div>
            <div><dt>{{ $t('Join Date') }}</dt><dd>{{ staff.join_date ? $date(staff.join_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Status') }}</dt><dd><span class="lg-chip" :class="statusBadgeClass(staff.status)">{{ staff.status }}</span></dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="mobile" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Contact Details') }}</h2></div>
          </header>
          <dl class="sh-grid">
            <div class="lg-span-all"><dt>{{ $t('Address') }}</dt><dd>{{ staff.address || '-' }}</dd></div>
            <div><dt>{{ $t('Personal Email') }}</dt><dd>{{ staff.personal_email || '-' }}</dd></div>
            <div><dt>{{ $t('Mobile No.') }}</dt><dd>{{ staff.mobile_no || '-' }}</dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="heart" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Family Info') }}</h2></div>
          </header>
          <dl class="sh-grid">
            <div><dt>{{ $t('Father Name') }}</dt><dd>{{ staff.father_name || '-' }}</dd></div>
            <div><dt>{{ $t('Father Contact No.') }}</dt><dd>{{ staff.father_contact_no || '-' }}</dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Bank Details') }}</h2></div>
          </header>
          <dl class="sh-grid">
            <div><dt>{{ $t('Bank Name') }}</dt><dd>{{ staff.bank_name || '-' }}</dd></div>
            <div><dt>{{ $t('Account Holder') }}</dt><dd>{{ staff.account_holder_name || '-' }}</dd></div>
            <div><dt>{{ $t('Account No.') }}</dt><dd>{{ staff.account_no || '-' }}</dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Employment & Salary') }}</h2></div>
          </header>
          <dl class="sh-grid">
            <div><dt>{{ $t('Basic Salary') }}</dt><dd>{{ $money(staff.basic_salary) }}</dd></div>
            <div><dt>{{ $t('Allowances') }}</dt><dd>{{ $money(staff.allowances) }}</dd></div>
            <div><dt>{{ $t('Deductions') }}</dt><dd>{{ $money(staff.deductions) }}</dd></div>
            <div><dt>{{ $t('Net Salary') }}</dt><dd><span class="sh-net">{{ $money(staff.net_salary) }}</span></dd></div>
          </dl>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div>
          </header>
          <ul class="sh-files">
            <li v-for="file in staff.attachments" :key="file.id" class="sh-file">
              <button type="button" class="sh-file__name lg-main" @click="preview = file">
                <icons :name="file.is_image ? 'image' : 'doc-text'" class="w-4 h-4 flex-none" /><span class="truncate">{{ file.name }}</span>
              </button>
              <span class="sh-file__btns">
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')" @click="preview = file"><icons name="eye-flat" class="w-4 h-4" /></button>
                <a :href="file.preview_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Open in New Tab')" :aria-label="$t('Open in New Tab')"><icons name="link" class="w-4 h-4" /></a>
                <a :href="file.download_url" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              </span>
            </li>
            <li v-if="!staff.attachments || !staff.attachments.length" class="lg-subtext py-2">{{ $t('No attachments.') }}</li>
          </ul>
        </section>
      </div>

      <div v-else-if="activeTab === 'vouchers'">
        <div class="lg-stats">
          <div class="lg-stat">
            <span class="lg-stat-ico lg-ico-amber"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-stat-value">{{ paymentVouchersSummary.count }}</div>
              <div class="lg-stat-label">{{ $t('Payment Voucher(s)') }}</div>
            </div>
          </div>
          <div class="lg-stat">
            <span class="lg-stat-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-stat-value">{{ $money(paymentVouchersSummary.total) }}</div>
              <div class="lg-stat-label">{{ $t('Total') }}</div>
            </div>
          </div>
        </div>

        <div class="lg-card" style="padding: 1rem">
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('PV Number') }}</th>
                  <th>{{ $t('Type') }}</th>
                  <th>{{ $t('Date') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                  <th>{{ $t('Status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in paymentVouchers" :key="v.id">
                  <td :data-label="$t('PV Number')"><span class="lg-chip lg-chip-blue" style="text-transform: none">{{ v.pv_number }}</span></td>
                  <td :data-label="$t('Type')">{{ v.type }}</td>
                  <td :data-label="$t('Date')">{{ $date(v.voucher_date) }}</td>
                  <td class="num lg-main" :data-label="$t('Amount')">{{ $money(v.total_amount) }}</td>
                  <td :data-label="$t('Status')">
                    <span class="lg-chip" :class="voucherStatusClass(v.status)">{{ v.status }}</span>
                  </td>
                  <td class="num">
                    <Link :href="route('payment-vouchers.show', v.id)" class="lg-btn lg-btn-soft">
                      <icons name="view" class="w-4 h-4" /> {{ $t('View') }}
                    </Link>
                  </td>
                </tr>
                <tr v-if="!paymentVouchers.length" class="lg-empty-row">
                  <td colspan="6" class="lg-empty">{{ $t('No payment vouchers recorded for this staff member yet.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <attachment-viewer :file="preview" :subtitle="staff.name" @close="preview = null" />
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import StaffContracts from '@/Shared/Staff/StaffContracts.vue';
import AttachmentViewer from '@/Shared/AttachmentViewer.vue';
import RetireStaff from '@/Shared/Staff/RetireStaff.vue';
import StaffHistory from '@/Shared/Staff/StaffHistory.vue';
import IdCutout from '@/Pages/IdPasses/Parts/IdCutout.vue';
import IdMakeOverlay from '@/Pages/IdPasses/Parts/IdMakeOverlay.vue';
import { idMakeFile } from '@/Core/idMake';

export default {
  components: { AdminLayout, PageHeader, StatTiles, StaffContracts, RetireStaff, StaffHistory, AttachmentViewer, IdCutout, IdMakeOverlay },

  props: {
    staff: Object,
    paymentVouchers: { type: Array, default: () => [] },
    paymentVouchersSummary: { type: Object, default: () => ({ count: 0, total: 0 }) },
    ransId: { type: Object, default: null },
    contracts: { type: Array, default: () => [] },
    contractTypes: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    stopReasons: { type: Object, default: () => ({}) },
    canContractStop: Boolean,
    canUpdate: Boolean,
    canDelete: Boolean,
    canPrint: Boolean,
    canContracts: Boolean,
    canContractAdd: Boolean,
    canContractEdit: Boolean,
    canContractDelete: Boolean,
    canContractPrint: Boolean,
    canRetire: Boolean,
  },

  data() {
    return {
      faces: { front: null, back: null, css: '' }, preview: null, photoFailed: false, activeTab: ['contracts', 'vouchers', 'history'].includes(new URLSearchParams(window.location.search).get('tab')) ? new URLSearchParams(window.location.search).get('tab') : 'details' };
  },

  computed: {
    statTiles() {
      const st = this.staff;
      return [
        { icon: 'cash', tone: 'indigo', label: this.$t('Basic Salary'), value: this.$money(st.basic_salary) },
        { icon: 'cash', tone: 'emerald', label: this.$t('Net Salary'), value: this.$money(st.net_salary) },
        { icon: 'calendar', tone: 'sky', label: this.$t('Join Date'), value: st.join_date ? this.$date(st.join_date) : '-' },
        { icon: 'bag', tone: 'amber', label: this.$t('Payment Vouchers'), value: this.paymentVouchersSummary.count },
      ];
    },
    current() {
      return this.contracts.find(c => c.status === 'Active') || null;
    },
    isRetired() {
      return ['Retired', 'Retired Active'].includes(this.staff.status);
    },
    initials() {
      return (this.staff.name || '')
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
  },

  mounted() {
    // the card pictures come after the page is shown, so the profile itself is not slowed down
    if (this.ransId && this.ransId.card) window.axios.get(this.ransId.card.faces_url).then(r => { this.faces = r.data; }).catch(() => {});
  },

  methods: {
    // print / download with the "ID press" animation; an ID that was printed before needs a reason, which is asked on its own page
    async makeId(kind) {
      const c = this.ransId.card;
      if (c.print_count > 0) { window.location = c.url; return; }
      const ok = await idMakeFile(kind === 'print' ? c.print_url : c.pdf_url, { mode: kind, color: c.color, title: kind === 'print' ? this.$t('Preparing :n for printing', { n: c.number }) : this.$t('Creating the PDF of :n', { n: c.number }), doneText: c.number, fail: this.$t('The file could not be made.') });
      if (ok) this.$inertia.reload({ only: ['ransId'], preserveScroll: true });
    },
    statusBadgeClass(status) {
      const map = { Active: 'lg-chip-green', 'Retired Active': 'lg-chip-amber', Retired: 'lg-chip-violet', Inactive: 'lg-chip-slate', Terminated: 'lg-chip-red' };
      return map[status] || 'lg-chip-slate';
    },
    contractChip(state) {
      return { open: 'lg-chip-blue', ok: 'lg-chip-green', soon: 'lg-chip-amber', urgent: 'lg-chip-red', expired: 'lg-chip-red' }[state] || 'lg-chip-slate';
    },
    voucherStatusClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        Submitted: 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        Authorised: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.sh-id { display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap; }
.sh-photo-wrap {
  position: relative; width: 88px; height: 88px; border-radius: 1.1rem; overflow: hidden; cursor: zoom-in;
  animation: sh-photo-pulse 2.4s ease-in-out infinite;
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.sh-photo-wrap:hover { transform: scale(1.9); z-index: 20; animation-play-state: paused; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35); }
.sh-photo { width: 100%; height: 100%; object-fit: cover; display: block; }
@keyframes sh-photo-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
  50% { box-shadow: 0 0 0 5px rgba(99, 102, 241, 0); }
}
.sh-initials { display: flex; align-items: center; justify-content: center; width: 72px; height: 72px; border-radius: 1.1rem; color: #fff; font-size: 1.4rem; font-weight: 800; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 8px 20px -6px rgba(67, 56, 202, 0.5); }
.sh-retired { display: flex; align-items: center; gap: 0.4rem; margin: 0.5rem 0 0; font-size: 0.82rem; font-weight: 700; color: #92400e; }
.sh-name { font-size: 1.2rem; font-weight: 800; color: var(--lg-ink); overflow-wrap: anywhere; }

.sh-tabs { display: inline-flex; gap: 6px; padding: 5px; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); max-width: 100%; overflow-x: auto; }
.sh-tab { display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: var(--lg-mute); white-space: nowrap; transition: background 0.15s ease, color 0.15s ease; }
.sh-tab:hover { color: var(--lg-navy); }
.sh-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); }
.sh-tab-count { padding: 0 7px; border-radius: 9999px; font-size: 0.7rem; background: rgba(255, 255, 255, 0.25); }
.sh-tab:not(.is-active) .sh-tab-count { color: #4338ca; background: rgba(99, 102, 241, 0.14); }

.sh-grid { display: grid; gap: 0.9rem 1.25rem; grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr)); margin: 0; }
.sh-grid > div { min-width: 0; padding: 0.6rem 0.8rem; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sh-grid .lg-span-all { grid-column: 1 / -1; }
.sh-grid dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sh-grid dd { margin: 3px 0 0; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.sh-net { font-weight: 800; color: #047857; }
.sh-files { list-style: none; margin: 0; padding: 0; }
.sh-file { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--lg-line); }
.sh-file:last-child { border-bottom: 0; }
.sh-file__name { display: inline-flex; align-items: center; gap: 8px; min-width: 0; padding: 0; border: 0; background: none; cursor: pointer; text-align: left; color: inherit; font-family: inherit; } .sh-file__name:hover span { text-decoration: underline; text-underline-offset: 3px; }
.sh-file__btns { display: inline-flex; gap: 6px; flex: none; }
.sh-details > .lg-card { margin-bottom: 1rem; }
@media (prefers-reduced-motion: reduce) { .sh-photo-wrap { animation: none; } }
.sh-rans { margin-bottom: 18px; }
.sh-rans-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; } .sh-rans-head > div { flex: 1; min-width: 200px; } .sh-rans-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; } .sh-rans-head p { margin: 2px 0 0; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.sh-rans-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 16px -10px rgba(15, 37, 64, 0.8); }
.sh-rans-body { display: flex; align-items: center; justify-content: center; gap: clamp(24px, 6vw, 70px); flex-wrap: wrap; padding: 14px 0 6px; }
.sh-rans-no { font-size: 1.3rem; font-weight: 800; letter-spacing: 0.06em; color: #1e4467; text-decoration: none; }
.sh-rans-dl { display: grid; grid-template-columns: repeat(2, minmax(120px, 1fr)); gap: 16px 30px; margin: 0; } .sh-rans-dl div { display: grid; gap: 2px; } .sh-rans-dl dt { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .sh-rans-dl dd { margin: 0; font-weight: 700; }
</style>
