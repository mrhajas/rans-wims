<template>
  <modal :show="show" max-width="lg" :closeable="!busy" @close="close">
    <div class="ssm">
      <header class="ssm-head">
        <span class="ssm-ico"><icons name="refresh" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h3 class="ssm-title">{{ $t('Sync email and phone') }}</h3>
          <p class="ssm-sub">{{ staff ? staff.name + ' (' + staff.staff_number + ')' : $t('Staff profile') }}</p>
        </div>
        <button type="button" class="ssm-x" :disabled="busy" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-4 h-4" /></button>
      </header>

      <div class="ssm-body">
        <div class="ssm-dir" role="radiogroup" :aria-label="$t('Direction')">
          <button v-for="d in directions" :key="d.key" type="button" role="radio" :aria-checked="direction === d.key" class="ssm-dir-btn" :class="{ 'is-on': direction === d.key }" :disabled="busy" @click="setDirection(d.key)">
            <b>{{ d.label }}</b><small>{{ d.hint }}</small>
          </button>
        </div>

        <p v-if="loading" class="ssm-empty">{{ $t('Checking both records...') }}</p>
        <table v-else-if="rows.length" class="ssm-table" data-no-number>
          <thead><tr><th>{{ $t('Detail') }}</th><th>{{ $t('User') }}</th><th>{{ $t('Staff profile') }}</th><th>{{ $t('What happens') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.key">
              <td data-l="Detail"><b>{{ r.label }}</b></td>
              <td :data-l="$t('User')" class="ssm-val">{{ r.user || '-' }}</td>
              <td :data-l="$t('Staff profile')" class="ssm-val">{{ r.staff || '-' }}</td>
              <td :data-l="$t('What happens')">
                <span class="ssm-chip" :class="'is-' + r.action">{{ actionText(r) }}</span>
                <small v-if="r.reason" class="ssm-reason">{{ r.reason }}</small>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="error" class="ssm-msg is-error"><icons name="exclamation" class="w-4 h-4 flex-none" />{{ error }}</p>
        <p v-if="!loading && rows.length && !anyChange" class="ssm-msg is-note"><icons name="info" class="w-4 h-4 flex-none" />{{ $t('Nothing would change with this direction.') }}</p>
      </div>

      <footer class="ssm-foot">
        <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="close">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="busy || loading || !anyChange" @click="apply"><icons name="refresh" class="w-4 h-4" />{{ busy ? $t('Syncing...') : $t('Sync now') }}</button>
      </footer>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

// The Sync button of the user form: shows, for the email and the phone, what the user and the linked staff profile hold and what a sync would do,
// before anything is changed. Default direction: User -> Staff; a detail the user does not have is brought in from the staff profile.
export default {
  components: { Modal },
  props: { show: Boolean, userId: { type: [Number, String], required: true } },
  emits: ['close', 'done'],
  data() {
    return { direction: 'auto', rows: [], staff: null, loading: false, busy: false, error: '' };
  },
  computed: {
    anyChange() {
      return this.rows.some(r => r.action === 'push' || r.action === 'pull');
    },
    directions() {
      return [
        { key: 'auto', label: this.$t('User to Staff'), hint: this.$t('Recommended. Empty user details are filled from the staff profile.') },
        { key: 'user_to_staff', label: this.$t('User to Staff only'), hint: this.$t('The user is never changed.') },
        { key: 'staff_to_user', label: this.$t('Staff to User'), hint: this.$t('The staff profile wins.') },
      ];
    },
  },
  watch: {
    show(v) {
      if (v) {
        this.direction = 'auto';
        this.load();
      }
    },
  },
  methods: {
    close() {
      if (!this.busy) this.$emit('close');
    },
    setDirection(k) {
      this.direction = k;
      this.load();
    },
    load() {
      this.loading = true;
      this.error = '';
      axios
        .get(this.route('users.staff.sync.preview', this.userId), { params: { direction: this.direction } })
        .then(res => {
          this.rows = res.data.rows;
          this.staff = res.data.staff;
        })
        .catch(err => (this.error = (err.response && err.response.data && err.response.data.message) || this.$t('Something went wrong. Please try again.')))
        .finally(() => (this.loading = false));
    },
    actionText(r) {
      return {
        push: this.$t('User to Staff: ') + r.result,
        pull: this.$t('Staff to User: ') + r.result,
        same: this.$t('Already the same'),
        none: this.$t('Nothing to copy'),
        skip: this.$t('Skipped'),
      }[r.action];
    },
    apply() {
      this.busy = true;
      this.$inertia.post(this.route('users.staff.sync', this.userId), { direction: this.direction }, {
        preserveScroll: true,
        onSuccess: () => this.$emit('done'),
        onError: errors => (this.error = Object.values(errors)[0] || this.$t('Something went wrong. Please try again.')),
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

<style scoped>
.ssm { overflow: hidden; border-radius: 24px; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e2e8f0); }
.ssm-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--lg-line, #e2e8f0); }
.ssm-ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5); box-shadow: 0 10px 18px -10px #4f6df5; }
.ssm-title { margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--lg-ink, #0f172a); }
.ssm-sub { margin: 1px 0 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.ssm-x { display: grid; place-items: center; flex: none; width: 32px; height: 32px; min-height: 0 !important; padding: 0 !important; border-radius: 10px; color: var(--lg-mute, #64748b); background: var(--lg-soft, #f1f5f9); }
.ssm-x:hover { color: #dc2626; background: rgba(239, 68, 68, 0.12); }
.ssm-body { display: grid; gap: 14px; padding: 16px 20px; }
.ssm-dir { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
.ssm-dir-btn { display: grid; gap: 2px; padding: 9px 12px; text-align: left; border-radius: 14px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.15s ease, background 0.15s ease; }
.ssm-dir-btn b { font-size: 0.84rem; color: var(--lg-ink, #0f172a); }
.ssm-dir-btn small { font-size: 0.7rem; line-height: 1.35; color: var(--lg-mute, #64748b); }
.ssm-dir-btn.is-on { background: rgba(79, 109, 245, 0.09); box-shadow: inset 0 0 0 2px rgba(79, 109, 245, 0.6); }
.ssm-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.ssm-table th { padding: 6px 8px; text-align: left; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
.ssm-table td { padding: 9px 8px; vertical-align: top; border-top: 1px solid var(--lg-line, #e2e8f0); color: var(--lg-ink, #0f172a); }
.ssm-val { overflow-wrap: anywhere; color: var(--lg-mute, #475569); }
.ssm-chip { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; overflow-wrap: anywhere; color: #475569; background: rgba(100, 116, 139, 0.14); }
.ssm-chip.is-push, .ssm-chip.is-pull { color: #1d4ed8; background: rgba(59, 130, 246, 0.14); }
.ssm-chip.is-same { color: #047857; background: rgba(16, 185, 129, 0.14); }
.ssm-chip.is-skip { color: #b45309; background: rgba(245, 158, 11, 0.16); }
.ssm-reason { display: block; margin-top: 3px; font-size: 0.72rem; color: #b45309; }
.ssm-empty { margin: 0; font-size: 0.85rem; color: var(--lg-mute, #64748b); }
.ssm-msg { display: flex; align-items: center; gap: 8px; margin: 0; padding: 8px 12px; border-radius: 12px; font-size: 0.82rem; }
.ssm-msg.is-error { color: #b91c1c; background: rgba(239, 68, 68, 0.1); }
.ssm-msg.is-note { color: var(--lg-mute, #475569); background: var(--lg-soft, #f1f5f9); }
.ssm-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--lg-line, #e2e8f0); background: var(--lg-soft, rgba(248, 250, 252, 0.6)); }
html.dark .ssm-chip.is-push, html.dark .ssm-chip.is-pull { color: #93c5fd; }
html.dark .ssm-chip.is-same { color: #6ee7b7; }
@media (max-width: 560px) {
  .ssm-table thead { display: none; }
  .ssm-table tr { display: block; padding: 8px 0; border-top: 1px solid var(--lg-line, #e2e8f0); }
  .ssm-table td { display: flex; gap: 8px; border: 0; padding: 3px 0; }
  .ssm-table td::before { content: attr(data-l); flex: none; width: 6.5rem; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
}
</style>
