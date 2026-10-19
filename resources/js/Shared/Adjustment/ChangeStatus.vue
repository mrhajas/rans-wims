<template>
  <span v-if="adjustment && adjustment.can_change_status" class="cs-wrap" @click.stop>
    <button v-if="compact" type="button" class="lg-icon-btn lg-tip cs-ico" :data-tip="$t('Change status')" :aria-label="$t('Change status')" @click.stop="open = true">
      <icons name="refresh" class="w-4 h-4" />
    </button>
    <button v-else type="button" class="lg-btn lg-btn-soft" @click="open = true"><icons name="refresh" class="w-4 h-4" />{{ $t('Change status') }}</button>

    <tec-dialog-modal :show="open" max-width="md" @close="close">
      <template #title>
        <span class="cs-title">
          <span class="lg-card-ico lg-ico-violet"><icons name="refresh" class="w-5 h-5" /></span>
          <span>{{ $t('Change status of :ref', { ref: adjustment.reference }) }}</span>
        </span>
      </template>
      <template #content>
        <p class="cs-now">{{ $t('Current status') }}: <span class="lg-chip" :class="chipFor(adjustment.status)">{{ $t(labelFor(adjustment.status)) }}</span></p>
        <div v-if="adjustment.approval_locked" class="cs-locked" role="alert">
          <span class="cs-locked-ico"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div>
            <b>{{ $t('This approval is locked') }}</b>
            <p>{{ adjustment.approval_locked_message }}</p>
            <Link :href="route('adjustments.create')" class="lg-btn lg-btn-primary cs-new"><icons name="plus" class="w-4 h-4" />{{ $t('Create a new Adjustment') }}</Link>
          </div>
        </div>
        <div v-else class="cs-options" role="radiogroup" :aria-label="$t('New status')">
          <label v-for="s in options" :key="s" class="cs-opt" :class="[{ 'is-on': target === s }, 'is-' + s.toLowerCase()]">
            <input v-model="target" type="radio" :value="s" class="cs-radio" />
            <span class="cs-opt-ico"><icons :name="iconFor(s)" class="w-4 h-4" /></span>
            <span class="cs-opt-t">{{ $t(labelFor(s)) }}</span>
          </label>
        </div>
 <textarea v-if="!adjustment.approval_locked && target === 'Rejected'" v-model="reason" class="cs-textarea" rows="3" maxlength="1000" :placeholder="$t('Reason for rejecting…')"></textarea>
        <p v-if="$page.props.errors && $page.props.errors.status" class="cs-warn" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.status }}</span></p>
        <p v-if="warning && !adjustment.approval_locked" class="cs-warn"><icons name="exclamation" class="w-4 h-4" /><span>{{ warning }}</span></p>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="close">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busy || adjustment.approval_locked || !target || (target === 'Rejected' && reason.trim().length < 3)" @click="save">
            <icons name="tick" class="w-4 h-4" />{{ $t('Change status') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </span>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';

// Super Admin only (the server sends `can_change_status` and re-checks): move a record to any other status. Leaving Approved
// reverses the stock it applied; arriving at Approved applies it. `kind` picks the wording and routes.
export default {
  components: { TecDialogModal },

  props: {
    adjustment: Object,
    compact: { type: Boolean, default: false },
    routePrefix: { type: String, default: 'adjustments' },
  },

  emits: ['done'],

  data() {
    return { open: false, busy: false, target: null, reason: '' };
  },

  computed: {
    all() {
      // with the two-approval rule a Super Adjustment is approved only through the Approve button (two different Super Admins), never by choosing a status
      if (this.routePrefix === 'super-adjustments') return Number(this.adjustment.required_approvals) === 2 ? ['Draft', 'Rejected'] : ['Draft', 'Approved', 'Rejected'];
      return ['Pending', 'Approved', 'Rejected'];
    },
    options() {
      return this.all.filter(s => s !== this.adjustment.status);
    },
    warning() {
      const from = this.adjustment.status;
      if (!this.target) return '';
      if (from === 'Approved' && this.adjustment.approved_at && !this.adjustment.approval_locked) return this.$t('The stock changes this record applied will be reversed. This is only possible within :n hours of approval.', { n: 8 });
      if (from === 'Approved') return this.$t('The stock changes this record applied will be reversed.');
      if (this.target === 'Approved') return this.$t('The stock changes will be applied now and the person who made it is notified.');
      return '';
    },
  },

  watch: {
    open(v) {
      if (v) {
        this.target = this.options[0] || null;
        this.reason = '';
      }
    },
  },

  methods: {
    labelFor: s => ({ Pending: 'Pending approval', Draft: 'Pending approval', 'Awaiting Phase 2': 'Awaiting 2nd approval', Approved: 'Approved', Rejected: 'Rejected' }[s] || s),
    iconFor: s => ({ Pending: 'clock', Draft: 'clock', Approved: 'tick', Rejected: 'cross' }[s] || 'info'),
    chipFor: s => ({ Pending: 'lg-chip-amber', Draft: 'lg-chip-amber', 'Awaiting Phase 2': 'lg-chip-sky', Approved: 'lg-chip-green', Rejected: 'lg-chip-red' }[s] || 'lg-chip-slate'),
    close() {
      this.open = false;
    },
    save() {
      this.busy = true;
      this.$inertia.put(
        this.route(this.routePrefix + '.status', this.adjustment.id),
        { status: this.target, rejection_reason: this.target === 'Rejected' ? this.reason.trim() : null },
        {
          preserveScroll: true,
          onSuccess: () => {
            this.close();
            this.$emit('done');
          },
          onFinish: () => {
            this.busy = false;
          },
        }
      );
    },
  },
};
</script>

<style scoped>
.cs-wrap { display: inline-flex; }
.cs-ico { display: inline-flex; align-items: center; justify-content: center; color: #6d28d9; background: rgba(139, 92, 246, 0.13); }
.cs-ico:hover { background: rgba(139, 92, 246, 0.24); }
html.dark .cs-ico { color: #c4b5fd; }
.cs-title { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; }
.cs-now { margin-bottom: 10px; font-size: 0.85rem; color: var(--lg-mute, #64748b); }
.cs-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
.cs-opt { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--lg-line, #e2e8f0); cursor: pointer; font-weight: 700; font-size: 0.85rem; }
.cs-radio { position: absolute; opacity: 0; pointer-events: none; }
.cs-opt-ico { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 10px; background: var(--lg-soft, #f1f5f9); }
.cs-opt.is-on { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22); }
.cs-opt.is-approved.is-on .cs-opt-ico { background: rgba(16, 185, 129, 0.2); color: #047857; }
.cs-opt.is-rejected.is-on .cs-opt-ico { background: rgba(239, 68, 68, 0.18); color: #b91c1c; }
.cs-opt.is-pending.is-on .cs-opt-ico, .cs-opt.is-draft.is-on .cs-opt-ico { background: rgba(245, 158, 11, 0.22); color: #b45309; }
.cs-textarea { width: 100%; margin-top: 10px; padding: 10px 12px; border-radius: 12px; font-size: 0.86rem; border: 1px solid #d1d5db; background: transparent; }
.cs-textarea:focus { outline: none; border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.18); }
html.dark .cs-textarea { border-color: rgba(255, 255, 255, 0.15); color: #f1f5f9; }
.cs-locked { display: flex; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 1px solid rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.07); font-size: 0.82rem; line-height: 1.5; }
.cs-locked p { margin: 4px 0 10px; color: var(--lg-mute, #64748b); }
.cs-locked b { color: var(--lg-ink, #0f172a); }
.cs-locked-ico { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 12px; background: rgba(239, 68, 68, 0.16); color: #b91c1c; }
.cs-new { display: inline-flex; }
.cs-warn { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 8px 10px; border-radius: 12px; font-size: 0.78rem; color: #92400e; background: rgba(245, 158, 11, 0.12); }
html.dark .cs-warn { color: #fcd34d; }
</style>
