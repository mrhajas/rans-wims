<template>
  <admin-layout :title="$t('Archive Notifications')">
    <div class="lg-page arx px-4 md:px-0">
      <page-header icon="bell" :title="$t('Archive Notifications')" :subtitle="$t('When the daily summary of expiring documents is sent.')">
        <Link :href="route('archive.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-pie" class="w-4 h-4" />{{ $t('Dashboard') }}</Link>
      </page-header>

      <p class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <span>
          {{ $t('Who is told about each Archive event, on which channels (bell, email, SMS, WhatsApp) and with which words is set on the Archive Workflow page. People are never told about a confidential record they are not allowed to open.') }}
          <Link v-if="canOpenWorkflow" :href="route('archive.workflow')" class="an-link">{{ $t('Open the Archive Workflow') }}</Link>
        </span>
      </p>

      <section class="lg-card an-card">
        <header class="an-head">
          <span class="an-ico"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="an-title">{{ $t('Expiring / expired documents') }}</h3>
            <p class="lg-subtext">{{ $t('A daily summary of documents that are about to expire or have expired.') }}</p>
          </div>
          <span class="lg-chip ml-auto" :class="schedule.enabled ? 'lg-chip-green' : 'lg-chip-slate'" style="text-transform: none">{{ schedule.enabled ? $t('On') : $t('Off') }}</span>
        </header>

        <div class="an-when">
          <span class="lg-chip lg-chip-indigo" style="text-transform: none"><icons name="clock" class="w-3 h-3" />{{ $t('Once a day') }}</span>
          <label class="an-field"><span>{{ $t('Send at') }}</span><input type="time" v-model="send_time" /></label>
          <label class="an-field"><span>{{ $t('Expiring within (days)') }}</span><input type="number" min="1" max="365" v-model.number="lead_days" /></label>
          <span v-if="schedule.last_run_at" class="lg-subtext">{{ $t('Last sent') }} {{ $datetime(schedule.last_run_at) }}</span>
        </div>
        <p class="lg-subtext">{{ $t('Switch it on or off, and choose who receives it, on the Archive Workflow page.') }}</p>
      </section>

      <!-- Save -->
      <div class="an-save lg-card">
        <label class="an-pw">
          <span>{{ $t('Confirm with your password to save') }}</span>
          <input v-model="password" type="password" autocomplete="current-password" :placeholder="$t('Your password')" @keydown.enter.prevent="save" />
        </label>
        <p v-if="error" class="an-err">{{ error }}</p>
        <p v-if="saved" class="an-ok"><icons name="tick" class="w-4 h-4" />{{ $t('Schedule saved.') }}</p>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="saving || !password" @click="save"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving…') : $t('Save schedule') }}</button>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader, Link },

  props: { schedule: Object, canOpenWorkflow: Boolean },

  data() {
    return {
      send_time: this.schedule.send_time,
      lead_days: this.schedule.lead_days,
      password: '',
      error: null,
      saving: false,
      saved: false,
    };
  },

  methods: {
    async save() {
      this.saving = true;
      this.error = null;
      try {
        await window.axios.put(this.route('archive.notifications.update'), { password: this.password, send_time: this.send_time, lead_days: this.lead_days });
        this.password = '';
        this.saved = true;
        setTimeout(() => { this.saved = false; }, 3500);
      } catch (e) {
        const errors = e.response && e.response.data && e.response.data.errors;
        this.error = (errors && (errors.password || Object.values(errors)[0]) && (errors.password || Object.values(errors)[0])[0]) || this.$t('Unable to save the schedule.');
      } finally {
        this.saving = false;
      }
    },
  },
};
</script>

<style scoped>
.an-card { margin-bottom: 0; display: flex; flex-direction: column; gap: 0.85rem; animation: arx-rise 0.45s ease backwards; animation-delay: calc(var(--i, 0) * 45ms); }
.an-card.is-off { opacity: 0.7; }
.an-head { display: flex; align-items: flex-start; gap: 12px; }
.an-ico { display: inline-grid; place-items: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); flex: none; }
.an-title { margin: 0; font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); }
.an-switch { position: relative; margin-left: auto; flex: none; width: 44px; height: 24px; cursor: pointer; }
.an-switch input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; cursor: pointer; }
.an-switch span { position: absolute; inset: 0; border-radius: 9999px; background: var(--lg-line); transition: background 0.2s ease; }
.an-switch span::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.an-switch input:checked + span { background: #059669; }
.an-switch input:checked + span::after { transform: translateX(20px); }
.an-switch input:focus-visible + span { outline: 2px solid #4f46e5; outline-offset: 2px; }
.an-when { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px 14px; }
.an-field { display: flex; flex-direction: column; gap: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.an-field input { height: 2.1rem; min-width: 7rem; padding: 0 0.6rem; border-radius: 0.7rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.82rem; font-weight: 700; text-transform: none; letter-spacing: 0; }
.an-label { margin-bottom: 6px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.an-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.an-role { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-mute); font-size: 0.74rem; font-weight: 700; transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease; }
.an-role:hover { border-color: var(--arx-accent); }
.an-role.is-on { background: rgba(79, 70, 229, 0.14); border-color: rgba(79, 70, 229, 0.5); color: #4338ca; }
html.dark .an-role.is-on { color: #c7d2fe; }
.an-x { margin-left: 4px; display: inline-grid; place-items: center; }
.an-add { height: 2rem; padding: 0 1.6rem 0 0.7rem; border-radius: 9999px; border: 1px dashed var(--lg-line); background-color: transparent; color: var(--lg-mute); font-size: 0.74rem; font-weight: 700; max-width: 100%; }
.an-foot { display: flex; flex-wrap: wrap; gap: 6px 18px; padding-top: 0.7rem; margin-top: auto; border-top: 1px dashed var(--lg-line); }
.an-check { display: inline-flex; align-items: center; gap: 7px; font-size: 0.78rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; }
.an-save { position: sticky; bottom: 12px; z-index: 20; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.8rem; box-shadow: 0 -10px 30px -14px rgba(15, 23, 42, 0.4); }
.an-pw { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.an-pw input { height: 2.6rem; padding: 0 0.9rem; border-radius: 0.9rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; text-transform: none; letter-spacing: 0; }
.an-ok { flex-basis: 100%; display: flex; align-items: center; gap: 6px; margin: 0; font-size: 0.82rem; font-weight: 700; color: #047857; }
.an-err { flex-basis: 100%; margin: 0; font-size: 0.8rem; font-weight: 600; color: #be123c; }
@media (prefers-reduced-motion: reduce) { .an-card { animation: none; } }
.an-link { margin-left: 8px; font-weight: 800; text-decoration: underline; }
</style>
