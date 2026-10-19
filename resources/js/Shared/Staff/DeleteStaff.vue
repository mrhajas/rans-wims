<template>
  <span>
    <button v-if="compact" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete staff')" :aria-label="$t('Delete staff')" @click.stop="open">
      <icons name="trash" class="w-4 h-4" />
    </button>
    <button v-else type="button" class="dls-trigger" @click="open">
      <icons name="trash" class="w-4 h-4" />{{ $t('Delete Staff') }}
    </button>

    <jetstream-modal v-if="showing" :show="showing" max-width="md" @close="close">
      <form class="dls-panel" @submit.prevent="submit">
        <div class="flex items-center gap-3 mb-4">
          <span class="dls-icon"><icons name="trash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="font-bold text-gray-900 leading-tight">{{ $t('Delete staff profile') }}</h3>
            <p class="text-xs text-gray-500 mt-0.5 truncate">{{ staff.staff_number }} · {{ staff.name }}</p>
          </div>
        </div>

        <p class="dls-warn">
          <icons name="exclamation" class="w-4 h-4 flex-none" />
          {{ $t('This removes the staff profile from the system. A linked login account is kept and simply becomes unlinked. Profiles with salary or payment history cannot be deleted.') }}
        </p>

        <label class="dls-label" for="dls-password">{{ $t('Your admin password') }}</label>
        <input
          id="dls-password"
          ref="password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          class="dls-input"
          :class="{ 'dls-input-error': errorMessage }"
        />
        <p v-if="errorMessage" class="dls-error">{{ errorMessage }}</p>

        <div class="flex justify-end gap-3 mt-5">
          <button type="button" class="dls-btn-plain" @click="close">{{ $t('Cancel') }}</button>
          <button type="submit" class="dls-btn-danger" :disabled="processing || !password">
            <icons name="trash" class="w-4 h-4" />{{ processing ? $t('Deleting…') : $t('Delete permanently') }}
          </button>
        </div>
      </form>
    </jetstream-modal>
  </span>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';

export default {
  components: { JetstreamModal },

  props: { staff: { type: Object, required: true }, compact: { type: Boolean, default: false } },

  data() {
    return { showing: false, password: '', processing: false, serverError: '' };
  },

  computed: {
    errorMessage() {
      return this.serverError || (this.$page.props.errors && this.$page.props.errors.password) || '';
    },
  },

  methods: {
    open() {
      this.showing = true;
      this.password = '';
      this.serverError = '';
      this.$nextTick(() => this.$refs.password && this.$refs.password.focus());
    },
    close() {
      this.showing = false;
      this.password = '';
      this.serverError = '';
    },
    submit() {
      this.processing = true;
      this.serverError = '';
      this.$inertia.delete(route('staffs.destroy', this.staff.id), {
        data: { password: this.password },
        preserveScroll: true,
        onError: errors => {
          this.serverError = errors.password || Object.values(errors)[0] || this.$t('Something went wrong. Please try again.');
          this.password = '';
          this.$nextTick(() => this.$refs.password && this.$refs.password.focus());
        },
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.dls-trigger { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; min-height: 0; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: #b42318; background: #fff; border: 1px solid #f6c9c4; transition: background 0.15s ease, transform 0.15s ease; }
.dls-trigger:hover { background: #fff1f0; transform: translateY(-1px); }
.dls-panel { padding: 1.5rem; background: linear-gradient(165deg, #fff, #fff5f5); }
.dls-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2.5rem; height: 2.5rem; border-radius: 0.8rem; color: #fff; background: linear-gradient(145deg, #f87171, #b91c1c); box-shadow: 0 8px 16px -8px rgba(185, 28, 28, 0.6); }
.dls-warn { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 1rem; padding: 0.65rem 0.85rem; border-radius: 12px; font-size: 0.78rem; line-height: 1.5; color: #7f1d1d; background: #fee2e2; border: 1px solid #fecaca; }
.dls-warn svg { margin-top: 2px; color: #dc2626; }
.dls-label { display: block; margin-bottom: 0.4rem; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em; text-transform: uppercase; color: #64748b; }
.dls-input { width: 100%; padding: 0.55rem 0.75rem; border-radius: 0.6rem; font-size: 0.9rem; background: #fff; border: 1.5px solid #e5e7eb; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.dls-input:focus { outline: none; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14); }
.dls-input-error { border-color: #fca5a5; }
.dls-error { margin-top: 6px; font-size: 0.78rem; font-weight: 700; color: #e11d48; }
.dls-btn-plain { padding: 0.55rem 1.1rem; min-height: 0; border-radius: 0.6rem; border: 1px solid #d1d5db; font-size: 0.85rem; font-weight: 600; color: #374151; }
.dls-btn-plain:hover { background: #f9fafb; }
.dls-btn-danger { display: inline-flex; align-items: center; gap: 6px; padding: 0.55rem 1.1rem; min-height: 0; border-radius: 0.6rem; font-size: 0.85rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #ef4444, #b91c1c); box-shadow: 0 6px 16px -6px rgba(185, 28, 28, 0.55); }
.dls-btn-danger:disabled { opacity: 0.5; cursor: not-allowed; }
html.dark .dls-panel { background: linear-gradient(165deg, #1e293b, #2a1a1f); }
</style>
