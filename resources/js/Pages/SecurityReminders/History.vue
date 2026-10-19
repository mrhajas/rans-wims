<template>
  <admin-layout :title="$t('Security Reminders / History')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clock" :title="$t('Reminder History')" :subtitle="$t('Every Security Reminder ever sent, and who it went to.')">
        <Link :href="route('security-reminders.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('Security Reminders') }}
        </Link>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-dark">
          <icons name="printer" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </a>
        <a :href="downloadUrl" target="_blank" class="lg-btn lg-btn-primary">
          <icons name="download" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </a>
      </page-header>

      <section class="lg-card lg-filters sr-filters relative z-20 print:hidden">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sr-search-wrap sm:col-span-1">
            <icons name="search" class="sr-search-icon" />
            <input v-model="form.search" type="text" class="sr-search-input" :placeholder="$t('Search sender or message…')" />
          </div>
          <div class="grid grid-cols-2 gap-2 sm:col-span-2">
            <date-picker v-model="form.date_from" :placeholder="$t('From')" clearable />
            <date-picker v-model="form.date_to" :placeholder="$t('To')" clearable />
          </div>
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Sent By') }}</th>
                <th>{{ $t('Mode') }}</th>
                <th>{{ $t('Recipients') }}</th>
                <th>{{ $t('Message') }}</th>
                <th>{{ $t('Sent At') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in campaigns.data" :key="c.id">
                <td :data-label="$t('Sent By')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(c.sent_by) }}</span>
                    <span class="lg-main">{{ c.sent_by }}</span>
                  </div>
                </td>
                <td :data-label="$t('Mode')">
                  <span class="lg-chip" :class="c.recipient_mode === 'all' ? 'lg-chip-indigo' : 'lg-chip-amber'">
                    {{ c.recipient_mode === 'all' ? $t('All Users') : $t('Selected') }}
                  </span>
                </td>
                <td :data-label="$t('Recipients')">
                  <span class="lg-chip lg-chip-blue lg-tip tip-left sr-tip" :data-tip="recipientTip(c)">{{ c.recipient_count }}</span>
                </td>
                <td :data-label="$t('Message')">
                  <div class="lg-tip tip-left sr-tip" :data-tip="c.message || $t('(default message)')">
                    <div class="lg-clip truncate">{{ c.message || $t('(default message)') }}</div>
                  </div>
                </td>
                <td :data-label="$t('Sent At')"><span class="lg-subtext">{{ c.created_at ? $datetime(c.created_at) : '—' }}</span></td>
              </tr>
              <tr v-if="!campaigns.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="5">{{ $t('No reminders match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="campaigns.meta" :links="campaigns.links" />
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, Pagination, DatePicker, PageHeader },

  props: {
    campaigns: Object,
    filters: Object,
  },

  data() {
    return {
      form: {
        search: this.filters?.search || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
      },
    };
  },

  computed: {
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });
      return out;
    },
    printUrl() {
      return this.route('security-reminders.history.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('security-reminders.history.download', this.activeFilters);
    },
  },

  watch: {
    'form.search': debounce(function () {
      this.applyFilters();
    }, 400),
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    initials(name) {
      return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },
    recipientTip(c) {
      const names = (c.recipient_names || []).join(', ');
      return c.recipient_names_more ? `${names} +${c.recipient_names_more} ${this.$t('more')}` : names;
    },
    applyFilters() {
      this.$inertia.get(this.route('security-reminders.history'), this.form, { preserveState: true, replace: true });
    },
  },
};
</script>

<style scoped>
/* DatePicker.vue's own root div hardcodes col-span-6 sm:col-span-4 (sized for a 6-column form grid elsewhere);
   inside the 2-column From/To grid that would force each date field onto its own row. */
.sr-filters :deep(.col-span-6.sm\:col-span-4) { grid-column: span 1 / span 1 !important; }
.sr-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0 0.8rem; background: #fff; }
.sr-search-wrap:focus-within { border-color: #e11d48; box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12); }
.sr-search-icon { width: 14px; height: 14px; color: #94a3b8; flex: none; }
.sr-search-input { flex: 1; min-width: 0; border: none !important; outline: none; box-shadow: none !important; background: transparent; padding: 0.55rem 0 !important; font-size: 0.82rem; color: var(--lg-ink); }
.sr-tip::after { white-space: pre-line; min-width: 200px; max-width: 320px; }
html.dark .sr-search-wrap { background: #1e293b; border-color: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
</style>
