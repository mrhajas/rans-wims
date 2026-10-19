<template>
  <admin-layout :title="$t('Salary Settings')">
    <salary-page>
      <page-header icon="cog" :title="$t('Salary Settings')" :subtitle="$t('Configure components, approval workflow, statutory rates, and notifications for the Salary module.')" />

      <div class="sl-split sl-split-even sset-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Salary Components') }}</h2></div>
            <span class="lg-chip lg-chip-slate">{{ components.length }}</span>
          </header>
          <div class="lg-table-wrap sset-mb">
            <table class="lg-table">
              <thead>
                <tr><th>{{ $t('Label') }}</th><th>{{ $t('Type') }}</th><th>{{ $t('System') }}</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="c in components" :key="c.id">
                  <td class="lg-main">{{ c.label }}</td>
                  <td><span class="lg-chip" :class="c.type === 'deduction' ? 'lg-chip-red' : 'lg-chip-green'">{{ c.type }}</span></td>
                  <td>{{ c.is_system ? $t('Yes') : $t('No') }}</td>
                  <td class="num">
                    <button v-if="!c.is_system" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteComponent(c.id)"><icons name="trash" class="w-4 h-4" /></button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <form @submit.prevent="addComponent" class="lg-fields sset-add">
            <text-input :label="$t('New Component Label')" v-model="newComponent.label" />
            <text-input :label="$t('Key')" v-model="newComponent.key" placeholder="e.g. overtime" />
            <auto-complete id="new_component_type" :label="$t('Type')" v-model="newComponent.type" :suggestions="[{label:'Earning',value:'earning'},{label:'Deduction',value:'deduction'}]" />
            <div class="sset-add-btn"><button type="submit" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /> {{ $t('Add') }}</button></div>
          </form>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Notifications') }}</h2></div>
          </header>
          <p class="sl-hint">{{ $t('Who is told about salary periods and payments (app, SMS, email, WhatsApp, Meta) is set on the Salary Notification page. Who may prepare, submit, approve and pay is given on the Roles screen.') }}</p>
          <Link v-if="$page.props.auth.user" :href="route('salary.workflow')" class="lg-btn lg-btn-primary mt-3"><icons name="adjustments" class="w-4 h-4" />{{ $t('Open Salary Notification') }}</Link>
        </section>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Statutory Deductions') }}</h2></div>
          <label class="sl-check sl-check-sm">
            <input type="checkbox" v-model="statutory.is_enabled" @change="saveStatutory" /> {{ $t('Enabled') }}
          </label>
        </header>
        <div class="lg-note lg-note-amber sset-mb">
          <icons name="exclamation" class="w-4 h-4" />
          <span>{{ $t('Verify these against the current Finance Act / ZSSF Act before relying on them for real payroll, tax law changes independently of this app.') }}</span>
        </div>
        <div class="sset-stat">
          <div>
            <div class="sset-sub-title">{{ $t('PAYE Bands (monthly taxable pay)') }}</div>
            <div class="lg-table-wrap sset-mb">
              <table class="lg-table">
                <thead>
                  <tr><th>{{ $t('Up To (TZS)') }}</th><th>{{ $t('Rate %') }}</th><th></th></tr>
                </thead>
                <tbody>
                  <tr v-for="(band, i) in statutory.paye_bands" :key="i">
                    <td>
                      <input type="number" class="sl-input sset-rate" :value="band.up_to" :placeholder="$t('No limit')" @input="band.up_to = $event.target.value === '' ? null : +$event.target.value" />
                    </td>
                    <td><input type="number" step="0.01" class="sl-input sset-rate" :value="pct(band.rate)" @input="band.rate = (+$event.target.value || 0) / 100" /></td>
                    <td class="num"><button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeBand(i)"><icons name="trash" class="w-4 h-4" /></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button type="button" class="lg-btn lg-btn-soft" @click="addBand"><icons name="plus" class="w-4 h-4" /> {{ $t('Add Band') }}</button>
          </div>
          <div>
            <div class="sset-rates">
              <div>
                <label class="sset-label">{{ $t('ZSSF Employee %') }}</label>
                <input type="number" step="0.01" class="sl-input w-full" :value="pct(statutory.nssf_employee_rate)" @input="statutory.nssf_employee_rate = (+$event.target.value || 0) / 100" />
              </div>
              <div>
                <label class="sset-label">{{ $t('ZSSF Employer %') }}</label>
                <input type="number" step="0.01" class="sl-input w-full" :value="pct(statutory.nssf_employer_rate)" @input="statutory.nssf_employer_rate = (+$event.target.value || 0) / 100" />
              </div>
              <div>
                <label class="sset-label">{{ $t('WCF %') }}</label>
                <input type="number" step="0.01" class="sl-input w-full" :value="pct(statutory.wcf_rate)" @input="statutory.wcf_rate = (+$event.target.value || 0) / 100" />
              </div>
              <div>
                <label class="sset-label">{{ $t('SDL %') }}</label>
                <input type="number" step="0.01" class="sl-input w-full" :value="pct(statutory.sdl_rate)" @input="statutory.sdl_rate = (+$event.target.value || 0) / 100" />
              </div>
            </div>
            <p class="sl-hint sset-note">{{ $t('ZSSF Employer, WCF, and SDL are employer-only costs, tracked for reporting, never deducted from the employee.') }}</p>
            <div class="sset-save">
              <button type="button" class="lg-btn lg-btn-primary" @click="saveStatutory"><icons name="tick" class="w-4 h-4" /> {{ $t('Save Statutory Settings') }}</button>
              <span v-if="statutorySaved" class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" /> {{ $t('Saved!') }}</span>
            </div>
          </div>
        </div>
      </section>


    </salary-page>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import CreatableSelect from '@/Shared/CreatableSelect.vue';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { Link, AdminLayout, TextInput, AutoComplete, CreatableSelect, SalaryPage, PageHeader },
  props: { components: Array, roles: Array, statutorySettings: Object },
  data() {
    return {
      newComponent: { label: '', key: '', type: 'earning' },
      statutory: {
        is_enabled: this.statutorySettings.is_enabled,
        paye_bands: this.statutorySettings.paye_bands.map(b => ({ ...b })),
        nssf_employee_rate: this.statutorySettings.nssf_employee_rate,
        nssf_employer_rate: this.statutorySettings.nssf_employer_rate,
        wcf_rate: this.statutorySettings.wcf_rate,
        sdl_rate: this.statutorySettings.sdl_rate,
      },
      statutorySaved: false,
    };
  },
  methods: {
    addComponent() {
      if (!this.newComponent.label || !this.newComponent.key) return;
      this.$inertia.post(route('salary.settings.components.store'), this.newComponent, {
        onSuccess: () => { this.newComponent = { label: '', key: '', type: 'earning' }; },
      });
    },
    deleteComponent(id) {
      this.$inertia.delete(route('salary.settings.components.destroy', id));
    },
    pct(rate) {
      return Math.round(rate * 10000) / 100;
    },
    addBand() {
      this.statutory.paye_bands.push({ up_to: null, rate: 0 });
    },
    removeBand(i) {
      this.statutory.paye_bands.splice(i, 1);
    },
    saveStatutory() {
      this.$inertia.put(route('salary.settings.statutory.update'), this.statutory, {
        preserveScroll: true,
        onSuccess: () => {
          this.statutorySaved = true;
          setTimeout(() => { this.statutorySaved = false; }, 3000);
        },
      });
    },
  },
};
</script>

<style scoped>
.sset-grid { align-items: start; }
.sset-grid > .lg-card { margin-bottom: 0; }
.sset-grid { margin-bottom: 1rem; }
.sset-mb { margin-bottom: 1rem; }
.sset-add { position: relative; z-index: 10; grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); align-items: end; }
.sset-add-btn { display: flex; align-items: flex-end; padding-bottom: 2px; }
.sset-step { margin-bottom: 10px; }
.sset-step-name { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); }
.sset-step-desc { margin: 2px 0 10px; }
.sset-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sset-chip { display: inline-flex; align-items: center; gap: 6px; padding: 0.2rem 0.4rem 0.2rem 0.75rem; border-radius: 9999px; background: rgba(99, 102, 241, 0.14); color: #4338ca; font-size: 0.76rem; font-weight: 700; }
html.dark .sset-chip { color: #c7d2fe; }
.sset-chip button { display: inline-flex; align-items: center; justify-content: center; width: 1.2rem; height: 1.2rem; border-radius: 9999px; background: rgba(99, 102, 241, 0.2); font-weight: 800; }
.sset-chip button:hover { background: rgba(99, 102, 241, 0.38); }
.sset-stat { display: grid; gap: 1.5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 900px) { .sset-stat { grid-template-columns: minmax(0, 1fr); } }
.sset-sub-title, .sset-label { display: block; margin-bottom: 8px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sset-label { margin-bottom: 4px; }
.sset-rate { width: 100%; min-width: 6rem; }
.sset-rates { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 480px) { .sset-rates { grid-template-columns: minmax(0, 1fr); } }
.sset-note { margin-top: 12px; }
.sset-save { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.sset-rules { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); }
@media (max-width: 480px) { .sset-rules { grid-template-columns: minmax(0, 1fr); } }
.sset-rule { margin-top: 0 !important; }
.sset-toggles { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 10px; }
</style>
