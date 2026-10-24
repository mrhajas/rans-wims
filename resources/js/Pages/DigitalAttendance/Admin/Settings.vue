<template>
  <admin-layout :title="$t('Digital Attendance Settings')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="cog" :title="$t('Digital Attendance Settings')" :subtitle="$t('Control how staff clock in and out, what is captured and what counts as late.')" />

      <form @submit.prevent="submit" class="set-form">
        <div v-if="errorList.length" class="lg-note lg-note-red mb-4">
          <icons name="exclamation" class="w-4 h-4" />
          <div class="min-w-0">
            <div v-for="(e, i) in errorList" :key="i">{{ e }}</div>
          </div>
        </div>

        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="toggle" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Attendance Rules') }}</div>
              <div class="lg-card-sub">{{ $t('Switches that decide how clocking in and out behaves.') }}</div>
            </div>
          </div>
          <div class="set-list">
          <div class="set-row">
            <div class="min-w-0">
              <div class="set-label">{{ $t('Digital Attendance') }}</div>
              <div class="set-help">{{ $t('Master switch: when off, clock in/out is disabled for everyone.') }}</div>
            </div>
            <button type="button" class="set-toggle" :class="{ 'set-toggle-on': form.digital_attendance_enabled }" :aria-pressed="!!form.digital_attendance_enabled" @click="form.digital_attendance_enabled = !form.digital_attendance_enabled">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          <div class="set-row">
            <div class="min-w-0">
              <div class="set-label">{{ $t('Location Capture') }}</div>
              <div class="set-help">{{ $t('Ask the browser for GPS coordinates on clock in/out.') }}</div>
            </div>
            <button type="button" class="set-toggle" :class="{ 'set-toggle-on': form.digital_attendance_location_capture }" :aria-pressed="!!form.digital_attendance_location_capture" @click="form.digital_attendance_location_capture = !form.digital_attendance_location_capture">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          <div class="set-row">
            <div class="min-w-0">
              <div class="set-label">{{ $t('Require Location') }}</div>
              <div class="set-help">{{ $t('Block clock-in if location permission was denied.') }}</div>
            </div>
            <button type="button" class="set-toggle" :class="{ 'set-toggle-on': form.digital_attendance_require_location }" :aria-pressed="!!form.digital_attendance_require_location" @click="form.digital_attendance_require_location = !form.digital_attendance_require_location">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          <div class="set-row">
            <div class="min-w-0">
              <div class="set-label">{{ $t('Require Clock-Out') }}</div>
              <div class="set-help">{{ $t('Flag sessions left open past the working day as Missing Clock-Out.') }}</div>
            </div>
            <button type="button" class="set-toggle" :class="{ 'set-toggle-on': form.digital_attendance_require_clock_out }" :aria-pressed="!!form.digital_attendance_require_clock_out" @click="form.digital_attendance_require_clock_out = !form.digital_attendance_require_clock_out">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          <div class="set-row">
            <div class="min-w-0">
              <div class="set-label">{{ $t('Allowed Remote Attendance') }}</div>
              <div class="set-help">{{ $t('Allow clocking in from outside the office (no geofence check).') }}</div>
            </div>
            <button type="button" class="set-toggle" :class="{ 'set-toggle-on': form.digital_attendance_allow_remote }" :aria-pressed="!!form.digital_attendance_allow_remote" @click="form.digital_attendance_allow_remote = !form.digital_attendance_allow_remote">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          </div>
        </section>

        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Geofencing') }}</div>
              <div class="lg-card-sub">{{ $t('Only meaningful if Location Capture is on. Off by default: never enforced unless you turn this on.') }}</div>
            </div>
            <button type="button" class="set-toggle ml-auto" :class="{ 'set-toggle-on': form.digital_attendance_geofencing }" :aria-pressed="!!form.digital_attendance_geofencing" @click="form.digital_attendance_geofencing = !form.digital_attendance_geofencing">
              <span class="set-toggle-knob"></span>
            </button>
          </div>
          <div v-if="form.digital_attendance_geofencing" class="lg-fields">
            <div>
              <label class="set-field-label">{{ $t('Office Latitude') }}</label>
              <input v-model="form.digital_attendance_geofence_latitude" type="number" step="any" class="set-input" />
            </div>
            <div>
              <label class="set-field-label">{{ $t('Office Longitude') }}</label>
              <input v-model="form.digital_attendance_geofence_longitude" type="number" step="any" class="set-input" />
            </div>
            <div>
              <label class="set-field-label">{{ $t('Radius (meters)') }}</label>
              <input v-model="form.digital_attendance_geofence_radius" type="number" class="set-input" />
            </div>
            <div>
              <label class="set-field-label">{{ $t('Mode') }}</label>
              <css-select v-model="form.digital_attendance_geofence_mode" class="set-input">
                <option value="warning">{{ $t('Warning only') }}</option>
                <option value="block">{{ $t('Block attendance') }}</option>
              </css-select>
            </div>
          </div>
          <div v-else class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <div>{{ $t('Geofencing is off. Turn it on to set the office position, radius and mode.') }}</div>
          </div>
        </section>

        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Working Hours') }}</div>
              <div class="lg-card-sub">{{ $t('Used to decide when someone counts as late.') }}</div>
            </div>
          </div>
          <div class="lg-fields">
            <div>
              <label class="set-field-label">{{ $t('Working Start') }}</label>
              <input v-model="form.digital_attendance_working_start" type="time" class="set-input" />
            </div>
            <div>
              <label class="set-field-label">{{ $t('Working End') }}</label>
              <input v-model="form.digital_attendance_working_end" type="time" class="set-input" />
            </div>
            <div>
              <label class="set-field-label">{{ $t('Late Threshold (minutes)') }}</label>
              <input v-model="form.digital_attendance_late_threshold_minutes" type="number" min="0" class="set-input" />
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span v-if="saved" class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Saved.') }}</span>
          <div class="lg-actionbar-btns">
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="processing"><icons name="tick" class="w-4 h-4" />{{ processing ? $t('Saving…') : $t('Save Settings') }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader },
  props: { attendance_settings: Object },
  data() {
    return { form: { ...this.attendance_settings }, processing: false, saved: false };
  },
  computed: {
    errorList() {
      const errors = (this.$page && this.$page.props && this.$page.props.errors) || {};
      return Object.values(errors);
    },
  },
  methods: {
    submit() {
      this.processing = true;
      this.saved = false;
      this.$inertia.put(this.route('digital-attendance-settings.update'), this.form, {
        preserveScroll: true,
        onSuccess: () => (this.saved = true),
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.set-form { max-width: 56rem; }
.set-list { display: flex; flex-direction: column; }
.set-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.85rem 0; border-bottom: 1px solid var(--lg-line); }
.set-row:first-child { padding-top: 0; }
.set-row:last-child { padding-bottom: 0; border-bottom: 0; }
.set-label { font-weight: 800; font-size: 0.9rem; color: var(--lg-ink); }
.set-help { margin-top: 0.1rem; max-width: 34rem; font-size: 0.78rem; color: var(--lg-mute); }
.set-field-label { display: block; margin-bottom: 0.3rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.set-input { width: 100%; padding: 0.55rem 0.8rem; font-size: 0.85rem; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 12px; }
.set-input:focus { outline: none; border-color: #34d399; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }

.set-toggle { position: relative; flex: none; width: 2.8rem; height: 1.6rem; padding: 0; border: 0; border-radius: 9999px; background: #cbd5e1; transition: background 0.25s ease, box-shadow 0.2s ease; }
.set-toggle:hover { box-shadow: 0 0 0 4px rgba(148, 163, 184, 0.2); }
.set-toggle-on { background: #059669; }
.set-toggle-on:hover { box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.2); }
.set-toggle-knob { position: absolute; top: 0.2rem; left: 0.2rem; width: 1.2rem; height: 1.2rem; border-radius: 9999px; background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.set-toggle-on .set-toggle-knob { transform: translateX(1.2rem); }
html.dark .set-toggle { background: #475569; }
html.dark .set-toggle-on { background: #059669; }
</style>
