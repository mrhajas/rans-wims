<template>
  <admin-layout :title="$t('Dispose Asset')">
    <div class="lg-page px-4 md:px-0 ds-form">
      <page-header icon="trash" :title="$t('Dispose Asset')" :subtitle="asset.asset_number + ' · ' + asset.name">
        <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Book Value') }}: {{ $money(asset.current_book_value) }}</span>
        <Link :href="route('assets.show', asset.id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Cancel') }}</Link>
      </page-header>

      <form @submit.prevent="submit" class="ds-wrap">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="trash" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Dispose Asset') }}</h2></div>
          </header>
          <div class="lg-fields">
            <div>
              <label class="ds-label">{{ $t('Disposal Method') }}</label>
              <css-select v-model="form.disposal_method" class="w-full rounded-md border-gray-300">
                <option v-for="m in disposalMethods" :key="m" :value="m">{{ $t(m) }}</option>
              </css-select>
            </div>
            <text-input type="date" :label="$t('Disposal Date')" v-model="form.disposal_date" />
            <text-input type="number" :label="$t('Disposal Value')" v-model="form.disposal_value" />
            <text-input :label="$t('Buyer / Recipient')" v-model="form.buyer_recipient" />
            <div class="lg-span-all">
              <label class="ds-label">{{ $t('Reason') }}</label>
              <textarea v-model="form.reason" rows="2" class="w-full rounded-md border-gray-300"></textarea>
            </div>
          </div>
        </section>

        <section v-if="['Lost', 'Stolen'].includes(form.disposal_method)" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Circumstances') }}</h2></div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Last Known Location')" v-model="form.last_known_location" />
            <text-input :label="$t('Report Reference')" v-model="form.report_reference" />
            <div class="lg-span-all">
              <label class="ds-label">{{ $t('Circumstances') }}</label>
              <textarea v-model="form.circumstances" rows="2" class="w-full rounded-md border-gray-300"></textarea>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('assets.show', asset.id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-red"><icons name="trash" class="w-4 h-4" />{{ $t('Confirm Disposal') }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, TextInput, PageHeader },

  props: { asset: Object, disposalMethods: Array },

  data() {
    return {
      form: {
        disposal_method: this.disposalMethods[0],
        disposal_date: this.$formatJSDate(new Date()),
        disposal_value: '',
        buyer_recipient: '',
        reason: '',
        last_known_location: '',
        report_reference: '',
        circumstances: '',
      },
    };
  },

  methods: {
    submit() {
      this.$inertia.post(this.route('assets.dispose.store', this.asset.id), this.form);
    },
  },
};
</script>

<style scoped>
.ds-wrap { max-width: 56rem; }
.ds-label, .ds-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute);
}
.ds-label { margin-bottom: 0.25rem; }
.ds-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio'])),
.ds-form :deep(.lg-fields select),
.ds-form :deep(.lg-fields textarea) {
  border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink);
  padding-top: 0.6rem !important; padding-bottom: 0.6rem !important; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ds-form :deep(.lg-fields input:focus),
.ds-form :deep(.lg-fields select:focus),
.ds-form :deep(.lg-fields textarea:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }
</style>
