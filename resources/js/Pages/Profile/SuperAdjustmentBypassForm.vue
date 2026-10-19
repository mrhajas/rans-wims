<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('SuperAdjustment ByPass') }}</h2>
        <p class="lg-card-sub">{{ $t('When it is active, your phase 1 approval of a Super Adjustment is followed at once by phase 2 from the Super Admin you choose here. Approvals already given never change: only Super Adjustments you approve after a change follow the new choice.') }}</p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-toggle-row">
        <label class="wims-toggle">
          <input v-model="form.active" type="checkbox" />
          <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
          <span class="wims-toggle-label">{{ form.active ? $t('Phase 2 is given automatically') : $t('Phase 2 needs a different Super Admin') }}</span>
        </label>
        <span class="lg-chip" :class="form.active ? 'lg-chip-green' : 'lg-chip-amber'">{{ form.active ? $t('Active') : $t('Off') }}</span>
      </div>

      <div v-if="form.active" class="pf-field" style="margin-top: 10px; max-width: 360px">
        <label class="lg-label" for="sab-approver">{{ $t('Who approves phase 2?') }}</label>
        <acct-select id="sab-approver" v-model="form.approver_id" :options="data.options" :placeholder="$t('Type to search a Super Admin...')" />
        <p v-if="form.errors.approver_id" class="lg-error">{{ form.errors.approver_id }}</p>
      </div>

      <div class="pf-field" style="margin-top: 10px; max-width: 360px">
        <label class="lg-label" for="sab-pass">{{ $t('Your password') }}</label>
        <input id="sab-pass" v-model="form.password" type="password" autocomplete="current-password" :placeholder="$t('Type your password to save')" />
        <p v-if="form.errors.password" class="lg-error">{{ form.errors.password }}</p>
      </div>

      <div class="pf-foot">
        <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
          <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<script>
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

// Dr Hajas only: the ByPass for the second approval of Super Adjustments (his own password confirms every change).
export default {
  components: { AcctSelect },
  data() {
    const d = this.$page.props.superAdjustmentBypass || { active: false, approver_id: null, options: [] };
    return { data: d, form: this.$inertia.form({ active: !!d.active, approver_id: d.approver_id || '', password: '' }) };
  },
  methods: {
    save() {
      this.form.transform(x => ({ ...x, approver_id: x.active && x.approver_id ? Number(x.approver_id) : null })).put(this.route('super-adjustments.bypass'), { preserveScroll: true, onSuccess: () => this.form.reset('password') });
    },
  },
};
</script>
