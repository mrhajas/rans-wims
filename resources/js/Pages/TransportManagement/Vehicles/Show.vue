<template>
  <admin-layout :title="vehicle.name">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="car" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <nav class="tm-crumbs">
            <Link :href="route('transport-vehicles.index')">{{ $t('Vehicles') }}</Link>
            <span>/</span>
            <span>{{ vehicle.name }}</span>
          </nav>
          <h1 class="lg-title tm-title">
            {{ vehicle.asset_number }} · {{ vehicle.name }}
            <span class="lg-chip" :class="statusChip(vehicle.status)" style="text-transform: none">
              <icons :name="statusIcon(vehicle.status)" class="w-3 h-3 inline-flex" />
              {{ $t(vehicle.status) }}
            </span>
          </h1>
          <div class="tm-meta">
            <span class="lg-chip lg-chip-blue" v-if="vehicle.registration_number" style="text-transform: none">
              <icons name="doc" class="w-3.5 h-3.5 inline-flex" />
              {{ vehicle.registration_number }}
            </span>
            <span class="lg-chip lg-chip-violet" v-if="vehicle.category" style="text-transform: none">
              <icons name="collection" class="w-3.5 h-3.5 inline-flex" />
              {{ vehicle.category.name }}
            </span>
            <span class="lg-chip lg-chip-amber" v-if="vehicle.operating_personnel_contact" style="text-transform: none">
              <icons name="user" class="w-3.5 h-3.5 inline-flex" />
              {{ $t('Driver') }}: {{ vehicle.operating_personnel_contact.name }}
            </span>
          </div>
        </div>
        <div class="lg-hero-actions tm-actions">
          <a v-if="$can('print-transport-records')" :href="route('transport-vehicles.print', vehicle.id)" target="_blank" class="lg-btn tm-act lg-btn-primary print:hidden">
            <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
          </a>
          <a v-if="$can('print-transport-records')" :href="route('transport-vehicles.pdf', vehicle.id)" class="lg-btn tm-act lg-btn-dark print:hidden">
            <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
          </a>
          <Link v-if="vehicle.can_edit" :href="route('transport-vehicles.edit', vehicle.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')">
            <icons name="edit" />
          </Link>
          <button v-if="vehicle.can_delete" type="button" @click="destroyVehicle" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')">
            <icons name="trash" />
          </button>
        </div>
      </header>

      <stat-tiles
        v-if="vehicle.trip_stats"
        :items="[
          { icon: 'truck', tone: 'sky', label: $t('Total Trips'), value: vehicle.trip_stats.total_trips },
          { icon: 'cash', tone: 'indigo', label: $t('Total Amount'), value: $money(vehicle.trip_stats.total_amount) },
          { icon: 'tick', tone: 'emerald', label: $t('Total Paid'), value: $money(vehicle.trip_stats.total_paid) },
          { icon: 'exclamation', tone: 'rose', label: $t('Total Outstanding'), value: $money(vehicle.trip_stats.total_outstanding) },
        ]"
      />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Vehicle Details') }}</h2>
          </div>
        </header>
        <div class="tm-kv">
          <div><p class="tm-k">{{ $t('Make / Manufacturer') }}</p><p class="tm-v">{{ vehicle.manufacturer || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Model') }}</p><p class="tm-v">{{ vehicle.model || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Year') }}</p><p class="tm-v">{{ vehicle.year || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Color') }}</p><p class="tm-v">{{ vehicle.color || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('VIN / Chassis Number') }}</p><p class="tm-v">{{ vehicle.vin_chassis_number || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Engine Number') }}</p><p class="tm-v">{{ vehicle.engine_number || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Ownership Type') }}</p><p class="tm-v">{{ vehicle.ownership_type ? $t(vehicle.ownership_type) : '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Owner / Company Name') }}</p><p class="tm-v">{{ vehicle.owner_name || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Capacity') }}</p><p class="tm-v">{{ vehicle.capacity || '—' }}</p></div>
          <div><p class="tm-k">{{ $t('Fuel Type') }}</p><p class="tm-v">{{ vehicle.fuel_type || '—' }}</p></div>
          <div>
            <p class="tm-k">{{ $t('Current Driver') }}</p>
            <p class="tm-v" v-if="vehicle.operating_personnel_contact">
              {{ vehicle.operating_personnel_contact.name }}
              <span class="tm-v-sub" v-if="vehicle.operating_personnel_contact.phone">· {{ vehicle.operating_personnel_contact.phone }}</span>
            </p>
            <p class="tm-v tm-v-sub" v-else>{{ $t('Unassigned') }}</p>
          </div>
        </div>
        <div v-if="vehicle.notes" class="tm-block"><p class="tm-k">{{ $t('Notes') }}</p><p class="tm-text">{{ vehicle.notes }}</p></div>
      </section>

      <section class="lg-card" v-if="vehicle.opening_balance !== null || canManageOpeningBalance">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="calculator" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Opening Balance') }}</h2>
          </div>
        </header>

        <template v-if="vehicle.opening_balance !== null">
          <div v-if="!correctingOpeningBalance" class="tm-ob-row">
            <div class="tm-kv tm-kv-4">
              <div><p class="tm-k">{{ $t('Amount') }}</p><p class="tm-v tm-num">{{ $money(vehicle.opening_balance) }}</p></div>
              <div><p class="tm-k">{{ $t('Effective Date') }}</p><p class="tm-v">{{ vehicle.opening_balance_date ? $date(vehicle.opening_balance_date) : '—' }}</p></div>
              <div><p class="tm-k">{{ $t('Set By') }}</p><p class="tm-v">{{ vehicle.opening_balance_set_by?.name || '—' }}</p></div>
              <div><p class="tm-k">{{ $t('Set At') }}</p><p class="tm-v">{{ vehicle.opening_balance_set_at ? $datetime(vehicle.opening_balance_set_at) : '—' }}</p></div>
            </div>
            <spr-confirm-password
              v-if="canManageOpeningBalance"
              :title="$t('Correct Opening Balance')"
              :content="$t('Correcting the opening balance changes this vehicle\'s cost and outstanding totals. Please confirm your password to continue.')"
              @confirmed="startCorrectingOpeningBalance"
            >
              <button type="button" class="lg-btn tv-ob-correct-btn flex-none">
                <icons name="edit" class="w-4 h-4 inline-flex" /> {{ $t('Correct Opening Balance') }}
              </button>
            </spr-confirm-password>
          </div>
          <div v-if="!correctingOpeningBalance && vehicle.opening_balance_notes" class="tm-block">
            <p class="tm-k">{{ $t('Notes') }}</p>
            <p class="tm-text">{{ vehicle.opening_balance_notes }}</p>
          </div>

          <form v-if="correctingOpeningBalance" @submit.prevent="submitOpeningBalanceCorrection" class="tm-form space-y-4 mt-2">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <tec-label :value="$t('Opening Balance Amount')" />
                <text-input type="number" min="0" step="0.01" class="mt-1 block w-full" v-model="obForm.opening_balance" required />
                <input-error :message="$page.props.errors.opening_balance" class="mt-1" />
              </div>
              <div>
                <tec-label :value="$t('Effective Date')" />
                <text-input type="date" class="mt-1 block w-full" v-model="obForm.opening_balance_date" />
                <input-error :message="$page.props.errors.opening_balance_date" class="mt-1" />
              </div>
            </div>
            <div>
              <tec-label :value="$t('Notes')" />
              <textarea class="block w-full rounded-md border-gray-300 mt-1" rows="2" v-model="obForm.opening_balance_notes"></textarea>
              <input-error :message="$page.props.errors.opening_balance_notes" class="mt-1" />
            </div>
            <div class="tm-form-foot">
              <button type="button" class="lg-btn lg-btn-soft" @click="correctingOpeningBalance = false">
                <icons name="cross" class="w-4 h-4 inline-flex" /> {{ $t('Cancel') }}
              </button>
              <loading-button type="submit" :loading="obForm.processing" :disabled="obForm.processing">
                <icons v-if="!obForm.processing" name="tick" class="w-4 h-4 inline-flex mr-2" /> {{ $t('Save Correction') }}
              </loading-button>
            </div>
          </form>
        </template>
        <div v-else class="lg-note lg-note-blue">
          <icons name="info" class="w-4 h-4" />
          <div>
            {{ $t('No opening balance set for this vehicle.') }}
            <Link :href="route('transport-vehicles.edit', vehicle.id)" class="tm-inline-link">{{ $t('Set it from Edit') }}</Link>
          </div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Recent Trips') }}</h2>
          </div>
        </header>

        <div v-if="vehicle.recent_trips?.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-12">{{ $t('No') }}</th>
                <th>{{ $t('Trip Number') }}</th>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Driver') }}</th>
                <th>{{ $t('Destination') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Payment Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(trip, idx) in vehicle.recent_trips"
                :key="trip.id"
                class="is-click"
                @click="$inertia.visit(route('transport-records.show', trip.id))"
              >
                <td :data-label="$t('No')" class="tm-no">{{ idx + 1 }}</td>
                <td :data-label="$t('Trip Number')"><span class="lg-main">{{ trip.trip_number }}</span></td>
                <td :data-label="$t('Date')" class="tm-num">{{ $date(trip.trip_date) }}</td>
                <td :data-label="$t('Driver')">{{ trip.driver?.name || '—' }}</td>
                <td :data-label="$t('Destination')">{{ trip.destination_label }}</td>
                <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(trip.amount) }}</span></td>
                <td :data-label="$t('Payment Status')">
                  <span class="lg-chip" :class="payChip(trip.payment_status)" style="text-transform: none">{{ $t(trip.payment_status) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="lg-empty tm-empty">{{ $t('No trips recorded for this vehicle yet.') }}</div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Payments') }}</h2>
          </div>
        </header>

        <div v-if="vehicle.payment_vouchers?.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-12">{{ $t('No') }}</th>
                <th>{{ $t('PV Number') }}</th>
                <th>{{ $t('Date') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(pv, idx) in vehicle.payment_vouchers"
                :key="pv.id"
                class="is-click"
                @click="$inertia.visit(route('payment-vouchers.show', pv.id))"
              >
                <td :data-label="$t('No')" class="tm-no">{{ idx + 1 }}</td>
                <td :data-label="$t('PV Number')"><span class="lg-main">{{ pv.pv_number }}</span></td>
                <td :data-label="$t('Date')" class="tm-num">{{ $date(pv.voucher_date) }}</td>
                <td :data-label="$t('Amount')" class="num"><span class="lg-main">{{ $money(pv.total_amount) }}</span></td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="pvChip(pv.status)" style="text-transform: none">{{ $t(pv.status) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="lg-empty tm-empty">{{ $t('No Payment Vouchers have been created from this vehicle’s trips yet.') }}</div>
      </section>

      <section class="lg-card" v-if="vehicle.attachments?.length">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
        </header>
        <ul class="tm-att-list">
          <li v-for="a in vehicle.attachments" :key="a.id" class="tm-att-item">
            <span class="tm-att-name">
              <icons name="doc" class="w-4 h-4 flex-none" />
              <span class="truncate">{{ a.title }}</span>
            </span>
            <span class="lg-actions">
              <a :href="downloadUrl(a, true)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" /></a>
              <a :href="downloadUrl(a)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" /></a>
              <button v-if="vehicle.can_edit" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteAttachment(a)">
                <icons name="trash" />
              </button>
            </span>
          </li>
        </ul>
      </section>

      <section class="lg-card" v-if="vehicle.activity?.length">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Activity History') }}</h2>
          </div>
        </header>
        <div class="tm-timeline">
          <div v-for="(entry, index) in vehicle.activity" :key="index" class="tm-tl-row">
            <span class="tm-tl-dot"></span>
            <div class="min-w-0 flex-1">
              <div class="tm-tl-top">
                <span class="tm-tl-desc">{{ entry.description }}</span>
                <span class="tm-tl-time">{{ $datetime(entry.created_at) }}</span>
              </div>
              <div class="lg-subtext" v-if="entry.causer">{{ entry.causer.name }}</div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import SprConfirmPassword from '@/Shared/SprConfirmPassword.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, LoadingButton, SprConfirmPassword, StatTiles },

  props: {
    vehicle: Object,
    canManageOpeningBalance: Boolean,
  },

  data() {
    return {
      correctingOpeningBalance: false,
      obForm: this.$inertia.form({
        opening_balance: this.vehicle.opening_balance,
        opening_balance_date: this.vehicle.opening_balance_date,
        opening_balance_notes: this.vehicle.opening_balance_notes,
      }),
    };
  },

  methods: {
    startCorrectingOpeningBalance() {
      this.obForm.opening_balance = this.vehicle.opening_balance;
      this.obForm.opening_balance_date = this.vehicle.opening_balance_date;
      this.obForm.opening_balance_notes = this.vehicle.opening_balance_notes;
      this.correctingOpeningBalance = true;
    },
    submitOpeningBalanceCorrection() {
      this.obForm.patch(this.route('transport-vehicles.opening-balance.update', this.vehicle.id), {
        preserveScroll: true,
        onSuccess: () => { this.correctingOpeningBalance = false; },
      });
    },
    // Normalizes a status/payment-status label into the CSS-safe suffix used
    // by the .tv-status-* / .tv-pay-* badge modifiers below, e.g.
    // "Under Maintenance" -> "under-maintenance".
    statusSlug(status) {
      return (status || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    },
    statusChip(status) {
      if (['Active', 'Available', 'Sold'].includes(status)) return 'lg-chip-green';
      if (['Assigned', 'In Use'].includes(status)) return 'lg-chip-blue';
      if (['Under Maintenance', 'Under Repair'].includes(status)) return 'lg-chip-amber';
      if (status === 'In Transit') return 'lg-chip-violet';
      if (['Lost', 'Stolen', 'Damaged'].includes(status)) return 'lg-chip-red';
      return 'lg-chip-slate';
    },
    payChip(status) {
      switch (this.statusSlug(status)) {
        case 'paid': return 'lg-chip-green';
        case 'partially-paid': return 'lg-chip-amber';
        case 'unpaid': return 'lg-chip-red';
        case 'pending-payment': return 'lg-chip-blue';
        default: return 'lg-chip-slate';
      }
    },
    pvChip(status) {
      switch (this.statusSlug(status)) {
        case 'submitted': return 'lg-chip-amber';
        case 'approved': return 'lg-chip-blue';
        case 'authorised': return 'lg-chip-green';
        case 'rejected':
        case 'cancelled': return 'lg-chip-red';
        default: return 'lg-chip-slate';
      }
    },
    statusIcon(status) {
      if (['Active', 'Available', 'Assigned', 'In Use'].includes(status)) return 'tick';
      if (['Under Maintenance', 'Under Repair'].includes(status)) return 'cog';
      if (status === 'In Transit') return 'truck';
      if (['Lost', 'Stolen', 'Damaged'].includes(status)) return 'exclamation';
      if (status === 'Idle') return 'clock';
      return 'lock-closed';
    },
    downloadUrl(attachment, inline = false) {
      const url = this.route('transport-vehicles.attachments.download', [this.vehicle.id, attachment.id]);
      return inline ? url + '?disposition=inline' : url;
    },
    async deleteAttachment(attachment) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('transport-vehicles.attachments.destroy', [this.vehicle.id, attachment.id]), { preserveScroll: true });
    },
    async destroyVehicle() {
      if (!(await this.$confirm(this.$t('Delete this vehicle? This cannot be undone.'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('transport-vehicles.destroy', this.vehicle.id));
    },
  },
};
</script>

<style scoped>
.tm-crumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 2px; font-size: 0.72rem; color: var(--lg-faint); }
.tm-crumbs a { color: var(--lg-mute); }
.tm-crumbs a:hover { color: #2563eb; }
.tm-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.tm-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tm-num { font-variant-numeric: tabular-nums; }

.tm-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: 16px 20px; }
.tm-kv-4 { flex: 1; }
.tm-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 3px; }
.tm-v { font-size: 0.88rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.tm-v-sub { font-weight: 500; color: var(--lg-faint); }
.tm-text { font-size: 0.86rem; color: var(--lg-ink); line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
.tm-block { margin-top: 14px; }
.tm-empty { padding: 28px 16px !important; font-size: 0.84rem; }
.tm-inline-link { margin-left: 4px; font-weight: 700; color: #2563eb; }
.tm-inline-link:hover { text-decoration: underline; }
html.dark .tm-inline-link { color: #93c5fd; }

.tm-ob-row { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
.tv-ob-correct-btn { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; }
.tv-ob-correct-btn:hover { background: #fef3c7; color: #92400e; }
html.dark .tv-ob-correct-btn { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border-color: rgba(245, 158, 11, 0.25); }

.tm-form :deep(label) { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.tm-form :deep(input:not([type='checkbox']):not([type='radio'])), .tm-form :deep(textarea) {
  border: 1px solid var(--lg-line); border-radius: 0.85rem; background: var(--lg-card); color: var(--lg-ink);
}
.tm-form :deep(input:focus), .tm-form :deep(textarea:focus) { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
.tm-form-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }

.tm-att-list { border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); overflow: hidden; }
.tm-att-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; }
.tm-att-item + .tm-att-item { border-top: 1px solid var(--lg-line); }
.tm-att-name { display: inline-flex; align-items: center; gap: 10px; min-width: 0; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); }

.tm-timeline { position: relative; display: flex; flex-direction: column; gap: 14px; padding-left: 4px; }
.tm-timeline::before { content: ''; position: absolute; left: 8px; top: 8px; bottom: 8px; width: 2px; background: var(--lg-line); }
.tm-tl-row { position: relative; display: flex; gap: 14px; }
.tm-tl-dot { position: relative; flex: none; width: 10px; height: 10px; margin-top: 5px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.tm-tl-top { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px 12px; }
.tm-tl-desc { font-size: 0.85rem; font-weight: 700; color: var(--lg-ink); }
.tm-tl-time { font-size: 0.72rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }

/* header buttons wrap and never push the page wider than the screen; on phones they share the width */
.tm-actions { flex-wrap: wrap; max-width: 100%; }
.tm-actions > * { min-width: 0; max-width: 100%; }
@media (max-width: 639.98px) {
  .tm-actions { width: 100%; justify-content: flex-start; }
  .tm-actions > .tm-act, .tm-actions > .lg-btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
}
</style>
