<template>
  <span v-if="adjustment && adjustment.can_approve" class="aa-actions" :class="{ 'is-icons': compact }" @click.stop>
    <template v-if="compact">
      <button type="button" class="lg-icon-btn lg-tip aa-ico aa-ico-ok" :data-tip="$t('Approve')" :aria-label="$t('Approve')" :disabled="busy" @click.stop="approve">
        <icons name="tick" class="w-4 h-4" />
      </button>
      <button type="button" class="lg-icon-btn lg-tip aa-ico aa-ico-no" :data-tip="$t('Reject')" :aria-label="$t('Reject')" :disabled="busy" @click.stop="rejecting = true">
        <icons name="cross" class="w-4 h-4" />
      </button>
    </template>
    <template v-else>
      <button type="button" class="lg-btn lg-btn-red" :disabled="busy" @click="rejecting = true"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
      <button type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="approve"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
    </template>

    <!-- Super Adjustment: every approval is confirmed with the approver's own password (two different Super Admins when the rule says two) -->
    <tec-dialog-modal :show="approving" max-width="sm" @close="closeApprove">
      <template #title>
        <span class="aa-title">
          <span class="lg-card-ico lg-ico-emerald"><icons name="lock-closed" class="w-5 h-5" /></span>
          <span>{{ $t('Approve :ref', { ref: adjustment.reference }) }}</span>
        </span>
      </template>
      <template #content>
        <p class="aa-hint">{{ approveHint }}</p>
        <label class="aa-label">{{ $t('Your password') }}</label>
        <input ref="pw" v-model="password" type="password" class="aa-input" autocomplete="current-password" :placeholder="$t('Type your password to approve')" @keyup.enter="submitApprove" />
        <p v-if="pwError" class="aa-error">{{ pwError }}</p>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeApprove"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="busy || !password" @click="submitApprove"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="rejecting" max-width="sm" @close="close">
      <template #title>
        <span class="aa-title">
          <span class="lg-card-ico lg-ico-rose"><icons name="x-circle" class="w-5 h-5" /></span>
          <span>{{ $t('Reject :ref?', { ref: adjustment.reference }) }}</span>
        </span>
      </template>
      <template #content>
        <p class="aa-hint">{{ $t('The stock stays untouched and the person who made it is told why.') }}</p>
        <textarea v-model="reason" class="aa-textarea" rows="3" maxlength="1000" :placeholder="$t('Reason for rejecting…')"></textarea>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="close">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="busy || reason.trim().length < 3" @click="reject">
            <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </span>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';

// Approve / Reject for a pending Adjustment. `compact` = icon-only (list rows); otherwise labelled buttons (page headers).
// Rendered only when the server says the current user may act on this record (`can_approve`).
export default {
  components: { TecDialogModal },

  props: {
    adjustment: Object,
    compact: { type: Boolean, default: false },
    // 'adjustments' (default) or 'super-adjustments': which pair of approve / reject routes to call.
    routePrefix: { type: String, default: 'adjustments' },
  },

  emits: ['done'],

  data() {
    return { busy: false, rejecting: false, reason: '', approving: false, password: '' };
  },

  computed: {
    isSuper() { return this.routePrefix === 'super-adjustments'; },
    pwError() { return (this.$page.props.errors || {}).password || ''; },
    approveHint() {
      const need = Number(this.adjustment.required_approvals || 1);
      if (need < 2) return this.$t('Approving creates real Adjustments and changes the stock now. Confirm with your password.');
      return this.adjustment.status === 'Awaiting Phase 2'
        ? this.$t('This is the second approval (phase 2 of 2). When you approve, the stock changes now. Confirm with your password.')
        : this.$t('This is the first approval (phase 1 of 2). The stock does not change yet: a different Super Admin must give the second approval, and the other Super Admins are told by SMS, email and the app bell. Confirm with your password.');
    },
  },

  methods: {
    openApprove() { this.password = ''; this.approving = true; this.$nextTick(() => this.$refs.pw && this.$refs.pw.focus()); },
    closeApprove() { this.approving = false; this.password = ''; },
    submitApprove() {
      if (!this.password) return;
      this.busy = true;
      this.$inertia.put(this.route(this.routePrefix + '.approve', this.adjustment.id), { password: this.password }, {
        preserveScroll: true,
        onSuccess: () => { this.closeApprove(); this.$emit('done'); },
        onFinish: () => { this.busy = false; this.password = ''; },
      });
    },
    async approve() {
      if (this.isSuper) { this.openApprove(); return; }
      const ok = await this.$confirm(
        this.$t('Approve :ref? Its stock changes are applied now and the person who made it is notified.', { ref: this.adjustment.reference }),
        { confirmText: this.$t('Approve') }
      );
      if (!ok) return;
      this.send(this.routePrefix + '.approve', {});
    },
    reject() {
      this.send(this.routePrefix + '.reject', { rejection_reason: this.reason.trim() });
    },
    send(name, data) {
      this.busy = true;
      this.$inertia.put(this.route(name, this.adjustment.id), data, {
        preserveScroll: true,
        onSuccess: () => {
          this.close();
          this.$emit('done');
        },
        onFinish: () => {
          this.busy = false;
        },
      });
    },
    close() {
      this.rejecting = false;
      this.reason = '';
    },
  },
};
</script>

<style scoped>
.aa-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.aa-actions.is-icons { gap: 6px; }
.aa-ico { display: inline-flex; align-items: center; justify-content: center; }
.aa-ico-ok { color: #047857; background: rgba(16, 185, 129, 0.14); }
.aa-ico-ok:hover { background: rgba(16, 185, 129, 0.26); }
.aa-ico-no { color: #b91c1c; background: rgba(239, 68, 68, 0.13); }
.aa-ico-no:hover { background: rgba(239, 68, 68, 0.24); }
html.dark .aa-ico-ok { color: #6ee7b7; }
html.dark .aa-ico-no { color: #fca5a5; }
.aa-title { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; }
.aa-label { display: block; margin: 10px 0 4px; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.aa-input { width: 100%; padding: 10px 12px; border-radius: 12px; font-size: 0.9rem; border: 1px solid #d1d5db; background: transparent; }
.aa-input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.aa-error { margin-top: 6px; font-size: 0.8rem; font-weight: 700; color: #b91c1c; }
html.dark .aa-input { border-color: rgba(255, 255, 255, 0.15); color: #f1f5f9; }
.aa-hint { margin-bottom: 8px; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.aa-textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font-size: 0.86rem; border: 1px solid #d1d5db; background: transparent; }
.aa-textarea:focus { outline: none; border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.18); }
html.dark .aa-textarea { border-color: rgba(255, 255, 255, 0.15); color: #f1f5f9; }
</style>
