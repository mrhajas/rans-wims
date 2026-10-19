<template>
  <admin-layout :title="$t(location ? 'edit_x' : 'create_x', { x: $t('Location') })">
    <div class="lg-page px-4 md:px-0 loc-form">
      <page-header
        icon="map-pin"
        :title="$t(location ? 'edit_x' : 'create_x', { x: $t('Location') })"
        :subtitle="$t('A named destination that can be picked on Transport Routes Records instead of re-typing an address every trip.')"
      >
        <span v-if="location" class="lg-chip lg-chip-blue" style="text-transform: none">{{ location.name }}</span>
        <Link :href="route('transport-locations.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Transport Routes Locations') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="name" :value="$t('Name')" />
              <text-input id="name" class="mt-1 block w-full" v-model="form.name" :placeholder="$t('e.g. Head Office, Dar Port…')" :error="$page.props.errors.name" />
            </div>
            <div>
              <tec-label for="location_type" :value="$t('Type')" />
              <auto-complete
                id="location_type"
                class="mt-1"
                :suggestions="locationTypeOptions"
                v-model="form.location_type"
                :placeholder="$t('Select a type…')"
                :error="$page.props.errors.location_type"
              />
            </div>
            <div class="lg-span-all">
              <tec-label for="address" :value="$t('Address')" />
              <text-input id="address" class="mt-1 block w-full" v-model="form.address" :placeholder="$t('Street, area, city…')" :error="$page.props.errors.address" />
            </div>
            <div class="lg-span-all">
              <tec-label for="notes" :value="$t('Notes')" />
              <textarea id="notes" class="mt-1 block w-full rounded-md border-gray-300" rows="3" v-model="form.notes" :placeholder="$t('Any additional context about this location…')"></textarea>
              <input-error :message="$page.props.errors.notes" class="mt-1" />
            </div>
            <div class="lg-span-all">
              <label class="loc-active-toggle-row">
                <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                <span>
                  <span class="loc-active-toggle-label">{{ $t('Active') }}</span>
                  <span class="loc-active-toggle-help">{{ $t('Inactive locations are hidden from the picker on new Transport Routes Records.') }}</span>
                </span>
              </label>
              <input-error :message="$page.props.errors.is_active" class="mt-1" />
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('transport-locations.index')" class="lg-btn lg-btn-soft">
              <icons name="cross" class="w-4 h-4" />
              {{ $t('Cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-green">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t(location ? 'Save' : 'Create Location') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, PageHeader },

  props: {
    location: Object,
    locationTypes: Array,
  },

  data() {
    return {
      form: this.$inertia.form({
        _method: this.location ? 'PUT' : 'POST',
        name: this.location ? this.location.name : '',
        location_type: this.location ? this.location.location_type : '',
        address: this.location ? this.location.address : '',
        notes: this.location ? this.location.notes : '',
        is_active: this.location ? !!this.location.is_active : true,
      }),
    };
  },

  computed: {
    locationTypeOptions() {
      return this.locationTypes.map(t => ({ value: t, label: this.$t(t) }));
    },
  },

  methods: {
    submit() {
      this.form.post(
        this.location ? this.route('transport-locations.update', this.location.id) : this.route('transport-locations.store'),
        { preserveScroll: true }
      );
    },
  },
};
</script>

<style scoped>
.loc-form :deep(.lg-card label:not(.loc-active-toggle-row)) {
  display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute);
}
.loc-form :deep(.lg-card input:not([type='checkbox'])),
.loc-form :deep(.lg-card select),
.loc-form :deep(.lg-card textarea) {
  border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.loc-form :deep(.lg-card input:not([type='checkbox']):focus),
.loc-form :deep(.lg-card select:focus),
.loc-form :deep(.lg-card textarea:focus) {
  border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
}

.loc-active-toggle-row {
  display: flex; align-items: flex-start; gap: 0.65rem; cursor: pointer;
  padding: 0.75rem 0.9rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line);
}
.loc-active-toggle-row input[type='checkbox'] { margin-top: 0.2rem; flex-shrink: 0; }
.loc-active-toggle-label { display: block; font-weight: 700; font-size: 0.9rem; color: var(--lg-ink); text-transform: none; letter-spacing: 0; }
.loc-active-toggle-help { display: block; font-size: 0.78rem; color: var(--lg-mute); margin-top: 0.1rem; text-transform: none; letter-spacing: 0; font-weight: 500; }
</style>
