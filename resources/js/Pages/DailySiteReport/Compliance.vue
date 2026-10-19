<template>
  <admin-layout :title="$t('Site Report Compliance')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Compliance')" :subtitle="$t('Track which Site Engineers have submitted their reports, by project and date')">
        <Link :href="route('daily-site-reports.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Site Reports') }}</Link>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a :href="pdfUrl" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
        <a :href="exportUrl" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" />{{ $t('Excel') }}
        </a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card cmp-filter print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Compliance') }}</h2>
            <p class="lg-card-sub" v-if="dates.length">{{ $date(dates[0]) }} &ndash; {{ $date(dates[dates.length - 1]) }}</p>
          </div>
        </header>

        <div class="flex flex-wrap items-end gap-4">
          <div>
            <tec-label :value="$t('View')" class="block mb-2" />
            <div class="cmp-seg">
              <button type="button" :class="{ 'is-on': form.view === 'week' }" @click="setView('week')">{{ $t('Week') }}</button>
              <button type="button" :class="{ 'is-on': form.view === 'month' }" @click="setView('month')">{{ $t('Month') }}</button>
            </div>
          </div>

          <div class="flex items-end gap-2">
            <button type="button" class="lg-icon-btn lg-tip tip-left cmp-nav" @click="shiftAnchor(-1)" :data-tip="$t('Previous')">
              <icons name="down" class="w-4 h-4 rotate-90" />
            </button>
            <date-picker v-model="form.anchor" :label="$t('Anchor Date')" class="mt-1" />
            <button type="button" class="lg-icon-btn lg-tip tip-left cmp-nav" @click="shiftAnchor(1)" :data-tip="$t('Next')">
              <icons name="up" class="w-4 h-4 rotate-90" />
            </button>
          </div>

          <auto-complete id="warehouse" position="left" :label="$t('Warehouses / Projects')" v-model="form.warehouse_id" class="mt-1 w-full sm:w-56" clearable :suggestions="warehouseSuggestions" />
          <auto-complete id="site-engineer" position="left" :label="$t('Site Engineer')" v-model="form.submitted_by" class="mt-1 w-full sm:w-56" clearable :suggestions="siteEngineerSuggestions" />
        </div>
      </section>

      <section class="lg-card cmp-card">
        <div class="lg-table-wrap">
          <table class="lg-table cmp-table">
            <thead>
              <tr>
                <th class="cmp-sticky">{{ $t('Site Engineer') }}</th>
                <th>{{ $t('Project') }}</th>
                <th v-for="date in dates" :key="date" class="text-center">{{ shortDate(date) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.user_id + '-' + row.warehouse_id">
                <td class="cmp-sticky lg-main">{{ row.user_name }}</td>
                <td>{{ row.warehouse_name }}</td>
                <td v-for="date in dates" :key="date" class="text-center">
                  <span v-if="row.cells[date]?.submitted && row.cells[date]?.report_id" class="cell-tooltip">
                    <Link :href="route('daily-site-reports.show', row.cells[date].report_id)">
                      <icons
                        :name="row.cells[date].schedule_status === 'Overdue' ? 'exclamation' : 'tick'"
                        class="w-4 h-4 inline-block"
                        :class="row.cells[date].schedule_status === 'Overdue' ? 'text-amber-500' : 'text-emerald-600'"
                      />
                    </Link>
                    <span class="cell-tooltip-box">
                      <strong>{{ row.cells[date].report_number }}</strong>
                      <span
                        class="cell-tooltip-badge"
                        :class="row.cells[date].schedule_status === 'Overdue' ? 'cell-tooltip-badge--overdue' : 'cell-tooltip-badge--ontime'"
                      >{{ $t(row.cells[date].schedule_status) }}</span>
                      <span>{{ $t('Submitted') }}: {{ row.cells[date].submitted_at }}</span>
                      <span>{{ $t('Report for') }}: {{ date }}</span>
                    </span>
                  </span>
                  <span v-else class="cell-tooltip">
                    <icons name="cross" class="w-4 h-4 text-red-500 inline-block" />
                    <span class="cell-tooltip-box">
                      <span>{{ $t('Report Not Submitted') }}</span>
                    </span>
                  </span>
                </td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" :colspan="dates.length + 2">{{ $t('No Site Engineers with assigned projects found.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TecLabel from '@/Jetstream/Label.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    AdminLayout,
    AutoComplete,
    TecLabel,
    PageHeader,
    StatTiles,
    DatePicker,
  },

  props: {
    view: String,
    anchor: String,
    dates: Array,
    rows: Array,
    warehouses: Array,
    siteEngineers: Array,
    filters: Object,
  },

  data() {
    return {
      form: {
        view: this.view,
        anchor: this.anchor,
        warehouse_id: this.filters.warehouse_id,
        submitted_by: this.filters.submitted_by,
      },
      skipNextWatch: false,
    };
  },

  computed: {
    statTiles() {
      // Derived from the grid currently on screen (future dates are not counted as missing).
      const today = this.formatIsoDate(new Date());
      let onTime = 0;
      let late = 0;
      let missing = 0;

      (this.rows || []).forEach(row => {
        (this.dates || []).forEach(date => {
          const cell = row.cells ? row.cells[date] : null;
          if (cell && cell.submitted && cell.report_id) {
            if (cell.schedule_status === 'Overdue') {
              late++;
            } else {
              onTime++;
            }
          } else if (date <= today) {
            missing++;
          }
        });
      });

      const expected = onTime + late + missing;
      const rate = expected ? Math.round(((onTime + late) / expected) * 100) + '%' : '-';

      return [
        { icon: 'user', tone: 'navy', label: this.$t('Site Engineer'), value: (this.rows || []).length },
        { icon: 'tick', tone: 'emerald', label: this.$t('OnTime'), value: onTime },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Overdue'), value: late },
        { icon: 'cross', tone: 'rose', label: this.$t('Report Not Submitted'), value: missing },
        { icon: 'chart-bar', tone: 'indigo', label: this.$t('Compliance'), value: rate },
      ];
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    siteEngineerSuggestions() {
      return this.siteEngineers.map(u => ({ id: u.id, label: u.name }));
    },
    printUrl() {
      return route('daily-site-reports.compliance.print', this.queryParams());
    },
    pdfUrl() {
      return route('daily-site-reports.compliance.pdf', this.queryParams());
    },
    exportUrl() {
      return route('daily-site-reports.compliance.export', this.queryParams());
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.navigate();
      }, 200),
      deep: true,
    },
  },

  methods: {
    queryParams() {
      const params = {};
      Object.entries(this.form).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
          params[key] = value;
        }
      });
      return params;
    },
    navigate() {
      this.$inertia.get(route('daily-site-reports.compliance.index'), this.queryParams(), {
        preserveState: true,
        replace: true,
      });
    },
    setView(view) {
      this.form.view = view;
    },
    shiftAnchor(direction) {
      const current = new Date(this.form.anchor + 'T00:00:00');
      if (this.form.view === 'month') {
        current.setDate(1);
        current.setMonth(current.getMonth() + direction);
      } else {
        const day = current.getDay();
        const diffToMonday = day === 0 ? -6 : 1 - day;
        current.setDate(current.getDate() + diffToMonday + direction * 7);
      }
      this.form.anchor = this.formatIsoDate(current);
    },
    formatIsoDate(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    },
    shortDate(date) {
      const d = new Date(date + 'T00:00:00');
      const day = d.toLocaleDateString(undefined, { weekday: 'short' });
      const dm = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
      return `${day} ${dm}`;
    },
  },
};
</script>

<style scoped>
/* backdrop-filter creates a new stacking context, so without an explicit
   z-index here the AutoComplete dropdown lists inside this card would be
   painted underneath the table card that follows it. */
.cmp-filter {
  position: relative;
  z-index: 30;
}
.cmp-card { padding: 0.9rem; }

.cmp-seg { display: inline-flex; padding: 3px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.cmp-seg button { padding: 6px 16px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.cmp-seg button.is-on { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); }
.cmp-nav { margin-bottom: 4px; }

.cmp-table td, .cmp-table th { padding: 10px 14px; }
.cmp-table .cmp-sticky { position: sticky; left: 0; z-index: 2; background: var(--lg-card); }
.cmp-table thead .cmp-sticky { background: var(--lg-soft); }
.cmp-table tbody tr:hover td.cmp-sticky { background: var(--lg-soft); }

.cell-tooltip {
  position: relative;
  display: inline-block;
}

.cell-tooltip-box {
  visibility: hidden;
  opacity: 0;
  position: absolute;
  bottom: 130%;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: #111827;
  color: #f9fafb;
  padding: 6px 10px;
  border-radius: 10px;
  font-size: 0.7rem;
  line-height: 1.3;
  white-space: nowrap;
  z-index: 40;
  pointer-events: none;
  transition: opacity 0.12s ease;
}

.cell-tooltip-box::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #111827;
}

/* the first body row sits right under the scroll container's top edge, so open its tooltips downward */
.cmp-table tbody tr:first-child .cell-tooltip-box {
  bottom: auto;
  top: 130%;
}
.cmp-table tbody tr:first-child .cell-tooltip-box::after {
  top: auto;
  bottom: 100%;
  border-top-color: transparent;
  border-bottom-color: #111827;
}

.cell-tooltip:hover .cell-tooltip-box {
  visibility: visible;
  opacity: 1;
}

.cell-tooltip-badge {
  display: inline-block;
  align-self: flex-start;
  border-radius: 9999px;
  padding: 0 6px;
  font-weight: 700;
  font-size: 0.65rem;
}

.cell-tooltip-badge--overdue {
  background: #f59e0b;
  color: #1f2937;
}

.cell-tooltip-badge--ontime {
  background: #10b981;
  color: #f9fafb;
}

@media (max-width: 767.98px) {
  .cmp-card { padding: 0; background: transparent; border: 0; box-shadow: none; }
  .cmp-card .lg-table-wrap { border: 1px solid var(--lg-line); background: var(--lg-card); overflow-x: auto; border-radius: 16px; }
}
</style>
