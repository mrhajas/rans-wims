<template>
  <admin-layout :title="$t('Super Adjustments')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="adjustments" :title="$t('Super Adjustments')" :subtitle="$t('Physical stock counts pending or already reconciled against system stock.')">
        <Link v-if="canSessions" :href="route('adjustment-sessions.index')" class="lg-btn lg-btn-soft">
          <icons name="clock" class="w-4 h-4" />
          <span>{{ $t('Adjustment Sessions') }}</span>
        </Link>
        <Link v-if="canCreate" :href="route('super-adjustments.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />
          <span>{{ $t('New Super Adjustment') }}</span>
        </Link>
      </page-header>

      <section class="lg-card sa-rule">
        <span class="lg-card-ico lg-ico-emerald"><icons name="lock-closed" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="lg-card-title">{{ rule.approvals === 2 ? $t('Two Super Admin approvals') : $t('One Super Admin approval') }}</h2>
          <p class="lg-card-sub">{{ rule.approvals === 2 ? $t('A Super Adjustment changes the stock only after two different Super Admins approve it, each with their own password. After the first, the other Super Admins are told by SMS, email and the app bell.') : $t('A Super Adjustment changes the stock after one Super Admin approves it with their password.') }}</p>
        </div>
        <button v-if="rule.can_change" type="button" class="lg-btn lg-btn-soft" @click="openRule"><icons name="cog" class="w-4 h-4" />{{ $t('Change rule') }}</button>
      </section>
      <tec-dialog-modal :show="ruleOpen" max-width="sm" @close="ruleOpen = false">
        <template #title><span class="sa-rule-title"><icons name="lock-closed" class="w-5 h-5" />{{ $t('How many Super Admin approvals?') }}</span></template>
        <template #content>
          <label class="sa-opt" :class="{ 'is-on': ruleForm.approvals === 2 }"><input v-model.number="ruleForm.approvals" type="radio" :value="2" /><span><b>{{ $t('Two Super Admins') }}</b><small>{{ $t('Two different Super Admins approve, each with their password. Recommended.') }}</small></span></label>
          <label class="sa-opt" :class="{ 'is-on': ruleForm.approvals === 1 }"><input v-model.number="ruleForm.approvals" type="radio" :value="1" /><span><b>{{ $t('One Super Admin') }}</b><small>{{ $t('One Super Admin approves with their password (a Super Admin\'s own count is approved when saved).') }}</small></span></label>
          <p v-if="ruleForm.approvals === 2 && rule.super_admins < 2" class="sa-rule-warn">{{ $t('Two approvals need at least two active Super Admins. There is only :n.', { n: rule.super_admins }) }}</p>
          <label class="sa-pw-l">{{ $t('Your password') }}</label>
          <input v-model="ruleForm.password" type="password" class="sa-pw" autocomplete="current-password" :placeholder="$t('Type your password to save')" @keyup.enter="saveRule" />
          <p v-if="ruleError" class="sa-rule-warn">{{ ruleError }}</p>
          <p class="lg-sub mt-2">{{ $t('Records already waiting keep the approvals they have; the new rule applies from their next approval.') }}</p>
        </template>
        <template #footer>
          <div class="flex items-center justify-between gap-2 w-full">
            <button type="button" class="lg-btn lg-btn-soft" @click="ruleOpen = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="ruleForm.processing || !ruleForm.password" @click="saveRule"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
          </div>
        </template>
      </tec-dialog-modal>

      <div v-if="filters.session_id" class="lg-note lg-note-amber" style="margin-bottom: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Showing only the Super Adjustments of one Adjustment Session.') }} <Link :href="route('super-adjustments.index')" style="font-weight: 800; text-decoration: underline">{{ $t('Show all') }}</Link></span></div>
      <stat-tiles :items="statItems" @select="pickTile" />

      <section class="lg-card lg-filters">
        <div class="lg-fields sa-filters">
          <text-input v-model="form.search" :label="$t('Search')" :placeholder="$t('Search reference, warehouse, user…')" />
          <select-input v-model="form.status" :label="$t('Status')">
            <option :value="null">{{ $t('All Statuses') }}</option>
            <option v-for="s in statuses" :key="s" :value="s">{{ $t(statusName(s)) }}</option>
          </select-input>
          <div class="sa-filter-reset">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="!hasFilters" @click="reset">
              <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
            </button>
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Reference') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Project / Warehouse') }}</th>
              <th>{{ $t('Submitted By') }}</th>
              <th>{{ $t('Difference') }}</th>
              <th>{{ $t('Date') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sa in superAdjustments.data" :key="sa.id" class="is-click" @click="$inertia.visit(route('super-adjustments.show', sa.id))">
              <td :data-label="$t('Reference')"><span class="lg-main lg-mono">{{ sa.reference }}</span><Link v-if="sa.session && canSessions" :href="route('adjustment-sessions.show', sa.session.id)" class="sa-sesschip" :title="$t('Adjustment Session') + ' ' + sa.session.reference + ' (' + $t(sa.session.status) + ')'" @click.stop><icons name="clock" class="w-3 h-3" />{{ sa.session.reference }}</Link></td>
              <td :data-label="$t('Status')" class="sa-status">
                <approval-track :status="sa.status" :phases="sa.phases || []" :approver="sa.approver" :approver-role="sa.approved_by_role || ''" :rejecter="sa.rejecter" />
              </td>
              <td :data-label="$t('Project / Warehouse')" class="sa-wrap">{{ sa.warehouse?.name }}</td>
              <td :data-label="$t('Submitted By')" class="sa-wrap">{{ sa.user?.name }}</td>
              <td :data-label="$t('Difference')" class="sa-diff">
                <template v-if="sa.items_count">
                  <span class="sa-diff-n">{{ sa.items_count }} {{ $t('items') }}</span>
                  <span v-if="sa.raised_total" class="sa-diff-up" :title="$t('Counted above the system stock')">+{{ $number(sa.raised_total) }}</span>
                  <span v-if="sa.lowered_total" class="sa-diff-down" :title="$t('Counted below the system stock')">-{{ $number(sa.lowered_total) }}</span>
                  <span v-if="!sa.raised_total && !sa.lowered_total" class="sa-diff-same">{{ $t('Match') }}</span>
                </template>
                <span v-else class="sa-muted">—</span>
              </td>
              <td :data-label="$t('Date')" class="tabular-nums">{{ $date(sa.date) }}</td>
              <td class="lg-sticky-end" :data-label="$t('Actions')" @click.stop>
                <div class="lg-actions">
                  <approval-actions :adjustment="sa" route-prefix="super-adjustments" compact />
                  <change-status :adjustment="sa" route-prefix="super-adjustments" compact />
                  <Link :href="route('super-adjustments.show', sa.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')">
                    <icons name="doc" />
                  </Link>
                  <a v-if="sa.can_print" :href="route('super-adjustments.print', sa.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')">
                    <icons name="printer" />
                  </a>
                  <a v-if="sa.can_print" :href="route('super-adjustments.pdf', sa.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
                    <icons name="download" />
                  </a>
                </div>
              </td>
            </tr>
            <tr v-if="!superAdjustments.data.length" class="lg-empty-row">
              <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-4" :meta="superAdjustments.meta" :links="superAdjustments.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import ApprovalActions from '@/Shared/Adjustment/ApprovalActions.vue';
import ChangeStatus from '@/Shared/Adjustment/ChangeStatus.vue';
import ApprovalTrack from '@/Shared/Adjustment/ApprovalTrack.vue';

export default {
  components: { TecDialogModal, AdminLayout, Pagination, PageHeader, StatTiles, TextInput, SelectInput, ApprovalActions, ChangeStatus, ApprovalTrack },

  props: {
    filters: Object,
    superAdjustments: Object,
    statuses: Array,
    stats: { type: Object, default: () => ({}) },
    canCreate: Boolean,
    canSessions: Boolean,
    rule: { type: Object, default: () => ({ approvals: 2, can_change: false, super_admins: 0 }) },
  },

  data() {
    return {
      ruleOpen: false,
      ruleForm: this.$inertia.form({ approvals: 2, password: '' }),
      form: {
        search: this.filters.search,
        status: this.filters.status || null,
      },
    };
  },

  computed: {
    ruleError() { return (this.$page.props.errors || {}).password || (this.$page.props.errors || {}).approvals || ''; },
    hasFilters() {
      return !!(this.form.search || this.form.status);
    },
    // Whole-list figures from the server; each card filters the list to that status and lights up while applied.
    statItems() {
      const s = this.stats || {};
      const tile = (status, icon, tone, label, value) => ({ icon, tone, label, value: Number(value || 0), status, active: (this.form.status || null) === status });
      return [
        tile(null, 'collection', 'navy', this.$t('Total Super Adjustments'), s.total),
        tile('Draft', 'clock', 'amber', this.$t('Pending approval'), s.Draft),
        tile('Awaiting Phase 2', 'lock-closed', 'sky', this.$t('Awaiting 2nd approval'), s.Awaiting),
        tile('Approved', 'tick', 'emerald', this.$t('Approved and Completed'), s.Approved),
        tile('Rejected', 'cross', 'rose', this.$t('Rejected'), s.Rejected),
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(this.route('super-adjustments.index'), pickBy(this.form), { preserveState: true, replace: true });
      }, 300),
      deep: true,
    },
  },

  methods: {
    // Clicking a card filters to that status; clicking the lit card (or the Total card) shows everything again.
    pickTile(tile) {
      this.form.status = tile.status && this.form.status !== tile.status ? tile.status : null;
    },
    statusName(s) {
      return s === 'Draft' ? 'Pending approval' : s === 'Awaiting Phase 2' ? 'Awaiting 2nd approval' : s === 'Approved' ? 'Approved and Completed' : s;
    },
    openRule() {
      this.ruleForm.approvals = this.rule.approvals;
      this.ruleForm.password = '';
      this.ruleOpen = true;
    },
    saveRule() {
      this.ruleForm.put(this.route('super-adjustments.approval-rule'), { preserveScroll: true, onSuccess: () => { this.ruleOpen = false; this.ruleForm.password = ''; } });
    },
    statusIcon(status) {
      if (status === 'Awaiting Phase 2') return 'lock-closed';
      if (status === 'Approved') return 'tick';
      if (status === 'Rejected') return 'cross';
      return 'clock';
    },
    statusChip(status) {
      if (status === 'Awaiting Phase 2') return 'lg-chip-sky';
      if (status === 'Approved') return 'sa-final';
      if (status === 'Rejected') return 'lg-chip-red';
      return 'lg-chip-amber';
    },
    reset() {
      this.form.search = '';
      this.form.status = null;
    },
  },
};
</script>

<style scoped>
.sa-sesschip { display: inline-flex; align-items: center; gap: 4px; margin-left: 8px; padding: 1px 8px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, #f97316, #c2410c); text-decoration: none; vertical-align: middle; }
.sa-rule { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
/* on a phone the rule card is stacked: icon and title on one line, the explanation across the full width, the button full width underneath */
@media (max-width: 720px) {
  .sa-rule { display: grid; grid-template-columns: 44px minmax(0, 1fr); align-items: center; gap: 10px 12px; padding: 14px; }
  .sa-rule > .lg-card-ico { grid-column: 1; grid-row: 1; margin: 0; }
  .sa-rule > div { display: contents; }
  .sa-rule .lg-card-title { grid-column: 2; grid-row: 1; margin: 0; font-size: 1.02rem; line-height: 1.25; }
  .sa-rule .lg-card-sub { grid-column: 1 / -1; margin: 0; font-size: 0.86rem; line-height: 1.5; }
  .sa-rule > .lg-btn { grid-column: 1 / -1; width: 100%; justify-content: center; }
}
.sa-rule-title { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; }
.sa-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 14px; margin-bottom: 8px; cursor: pointer; box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.4); }
.sa-opt.is-on { box-shadow: inset 0 0 0 2px #10b981; background: rgba(16, 185, 129, 0.08); }
.sa-opt span { display: grid; gap: 2px; } .sa-opt small { color: var(--lg-mute, #64748b); }
.sa-pw-l { display: block; margin: 12px 0 4px; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.sa-pw { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid #d1d5db; background: transparent; }
.sa-rule-warn { margin-top: 8px; font-size: 0.8rem; font-weight: 700; color: #b91c1c; }
.sa-filters {
  grid-template-columns: minmax(220px, 2fr) minmax(180px, 1fr) auto;
  align-items: end;
}
.sa-wrap { white-space: normal; min-width: 130px; max-width: 240px; }
/* the status cell is drawn by ApprovalTrack */
.sa-status { min-width: 250px; vertical-align: top; }
@media (max-width: 720px) { .sa-status { min-width: 0; } }
.sa-filter-reset {
  display: flex;
  align-items: flex-end;
}
@media (max-width: 720px) {
  .sa-filters {
    grid-template-columns: 1fr;
  }
}
.sa-diff { white-space: nowrap; } .sa-diff-n { display: block; font-size: 0.72rem; font-weight: 700; color: #64748b; } .sa-diff-up, .sa-diff-down, .sa-diff-same { display: inline-block; margin: 2px 4px 0 0; padding: 1px 9px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; font-variant-numeric: tabular-nums; } .sa-diff-up { color: #166534; background: #dcfce7; } .sa-diff-down { color: #991b1b; background: #fee2e2; } .sa-diff-same { color: #475569; background: #e2e8f0; }
</style>
