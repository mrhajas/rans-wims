<template>
  <admin-layout :title="edit ? edit.title : $t('Create Work Package')">
    <div class="lg-page px-4 md:px-0">
      <!-- Bulk create -->
      <form v-if="!edit" @submit.prevent="submit">
        <page-header
          icon="clip"
          :title="$t('Create Work Packages')"
          :subtitle="$t('Add one or more activities for the same project at once. Planning fields determine automatic schedule monitoring, so set them carefully.')"
        >
          <Link :href="route('work-packages.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Work Packages') }}</Link>
        </page-header>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Project(s) / Warehouse(s)') }}</h2>
              <p class="lg-card-sub">{{ $t('Create Work Packages') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete
                id="warehouse_id"
                :label="$t('Project(s) / Warehouse(s)')"
                v-model="form.warehouse_id"
                class="mt-1 w-full"
                :suggestions="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              />
              <input-error :message="$page.props.errors.warehouse_id" class="mt-2" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Work Packages') }}</h2>
              <p class="lg-card-sub">{{ $t('Add at least one work package.') }}</p>
            </div>
            <span class="lg-chip lg-chip-violet" style="text-transform: none">{{ form.work_packages.length }} {{ $t('Work Packages') }}</span>
          </header>

          <div class="lg-table-wrap wpf-wrap">
            <table class="lg-table lg-responsive wpf-table">
              <thead>
                <tr>
                  <th class="w-10"></th>
                  <th class="min-w-48">{{ $t('Title') }}</th>
                  <th class="min-w-36">{{ $t('Category') }}</th>
                  <th class="min-w-32">{{ $t('Priority') }}</th>
                  <th class="min-w-48">{{ $t('Engineer') }}</th>
                  <th class="min-w-36">{{ $t('Planned Start') }}</th>
                  <th class="min-w-36">{{ $t('Planned Finish') }}</th>
                  <th class="min-w-28">{{ $t('Weight') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in form.work_packages" :key="index" class="align-top">
                  <td :data-label="$t('Remove')">
                    <button type="button" @click="removeRow(index)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                  <td :data-label="$t('Title')">
                    <tec-input type="text" class="w-full" v-model="row.title" />
                    <div v-if="rowError(index, 'title')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(index, 'title') }}</div>
                  </td>
                  <td :data-label="$t('Category')">
                    <tec-input type="text" class="w-full" v-model="row.category" placeholder="Foundation, Electrical..." />
                  </td>
                  <td :data-label="$t('Priority')">
                    <css-select v-model="row.priority" class="w-full rounded-md border-gray-300 text-sm">
                      <option v-for="p in priorities" :key="p" :value="p">{{ $t(p) }}</option>
                    </css-select>
                  </td>
                  <td :data-label="$t('Engineer')">
                    <css-select v-model="row.engineer_id" class="w-full rounded-md border-gray-300 text-sm">
                      <option :value="null">-</option>
                      <option v-for="e in engineers" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </css-select>
                  </td>
                  <td :data-label="$t('Planned Start')">
                    <date-picker class="w-full" v-model="row.planned_start" />
                    <div v-if="rowError(index, 'planned_start')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(index, 'planned_start') }}</div>
                  </td>
                  <td :data-label="$t('Planned Finish')">
                    <date-picker class="w-full" v-model="row.planned_finish" />
                    <div v-if="rowError(index, 'planned_finish')" class="text-red-600 text-xs mt-1 wims-field-error">{{ rowError(index, 'planned_finish') }}</div>
                  </td>
                  <td :data-label="$t('Weight')">
                    <tec-input type="number" step="0.01" min="0.01" class="w-full" v-model="row.expected_progress_weight" />
                  </td>
                </tr>
                <tr v-if="form.work_packages.length === 0" class="lg-empty-row">
                  <td class="lg-empty" colspan="8">{{ $t('Add at least one work package.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4">
            <button type="button" @click="addRow" class="lg-btn lg-btn-dark">
              <icons name="plus" class="w-4 h-4" />
              {{ $t('Add Work Package') }}
            </button>
            <div class="lg-note lg-note-blue mt-3">
              <icons name="info" class="w-4 h-4" />
              <span>{{ $t('Building, Floor, Zone, Area, Foreman and Subcontractor can be set afterwards from each Work Package\'s edit page.') }}</span>
            </div>
            <div v-if="$page.props.errors.work_packages" class="text-red-600 text-sm mt-2">{{ $page.props.errors.work_packages }}</div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ form.work_packages.length }} {{ $t('Work Packages') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('work-packages.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ form.work_packages.length > 1 ? $t('Create') + ' ' + form.work_packages.length + ' ' + $t('Work Packages') : $t('Create Work Package') }}
            </button>
          </div>
        </div>
      </form>

      <!-- Edit (single) -->
      <form v-else @submit.prevent="submit">
        <page-header icon="clip" :title="edit.reference + ' - ' + edit.title" :subtitle="$t('Planning fields determine automatic schedule monitoring, so set them carefully.')">
          <Link :href="route('work-packages.show', edit.id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Work Package') }}</Link>
        </page-header>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Title') }} / {{ $t('Category') }} / {{ $t('Priority') }} / {{ $t('Status') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-2">
              <tec-label for="title" :value="$t('Title')" />
              <tec-input id="title" type="text" class="mt-1 block w-full" v-model="form.title" required autofocus />
              <input-error :message="form.errors.title" class="mt-2" />
            </div>

            <div>
              <tec-label for="category" :value="$t('Category')" />
              <tec-input id="category" type="text" class="mt-1 block w-full" v-model="form.category" placeholder="Foundation, Electrical, Roofing..." />
              <input-error :message="form.errors.category" class="mt-2" />
            </div>

            <div>
              <auto-complete
                id="warehouse_id"
                :label="$t('Project(s) / Warehouse(s)')"
                v-model="form.warehouse_id"
                class="mt-1 w-full"
                :suggestions="warehouses.map((w) => ({ label: w.name, value: w.id }))"
              />
              <input-error :message="form.errors.warehouse_id" class="mt-2" />
            </div>

            <div>
              <auto-complete
                id="priority"
                :label="$t('Priority')"
                v-model="form.priority"
                class="mt-1 w-full"
                :suggestions="priorities.map((p) => ({ label: $t(p), value: p }))"
              />
              <input-error :message="form.errors.priority" class="mt-2" />
            </div>

            <div>
              <auto-complete
                id="status"
                :label="$t('Status')"
                v-model="form.status"
                class="mt-1 w-full"
                :suggestions="statuses.map((s) => ({ label: $t(s), value: s }))"
              />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Location') }}</h2>
              <p class="lg-card-sub">{{ $t('Building') }} / {{ $t('Floor') }} / {{ $t('Zone') }} / {{ $t('Area') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="building" :value="$t('Building')" />
              <tec-input id="building" type="text" class="mt-1 block w-full" v-model="form.building" />
            </div>
            <div>
              <tec-label for="floor" :value="$t('Floor')" />
              <tec-input id="floor" type="text" class="mt-1 block w-full" v-model="form.floor" />
            </div>
            <div>
              <tec-label for="zone" :value="$t('Zone')" />
              <tec-input id="zone" type="text" class="mt-1 block w-full" v-model="form.zone" />
            </div>
            <div>
              <tec-label for="area" :value="$t('Area')" />
              <tec-input id="area" type="text" class="mt-1 block w-full" v-model="form.area" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Team') }}</h2>
              <p class="lg-card-sub">{{ $t('Engineer') }} / {{ $t('Foreman') }} / {{ $t('Subcontractor') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete
                id="engineer_id"
                :label="$t('Engineer')"
                v-model="form.engineer_id"
                class="mt-1 w-full"
                :suggestions="engineers.map((e) => ({ label: e.name, value: e.id }))"
              />
            </div>
            <div>
              <auto-complete
                id="foreman_id"
                :label="$t('Foreman')"
                v-model="form.foreman_id"
                class="mt-1 w-full"
                :suggestions="foremen.map((f) => ({ label: f.name, value: f.id }))"
              />
            </div>
            <div>
              <auto-complete
                id="subcontractor_id"
                :label="$t('Subcontractor')"
                v-model="form.subcontractor_id"
                class="mt-1 w-full"
                :suggestions="subcontractors.map((s) => ({ label: s.name, value: s.id }))"
              />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="calendar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Schedule') }}</h2>
              <p class="lg-card-sub">{{ $t('Planned Start') }} / {{ $t('Planned Finish') }} / {{ $t('Expected Progress Weight') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="planned_start" :value="$t('Planned Start')" />
              <date-picker id="planned_start" class="mt-1 block w-full" v-model="form.planned_start" required />
              <input-error :message="form.errors.planned_start" class="mt-2" />
            </div>
            <div>
              <tec-label for="planned_finish" :value="$t('Planned Finish')" />
              <date-picker id="planned_finish" class="mt-1 block w-full" v-model="form.planned_finish" required />
              <input-error :message="form.errors.planned_finish" class="mt-2" />
            </div>
            <div>
              <tec-label for="expected_progress_weight" :value="$t('Expected Progress Weight')" />
              <tec-input id="expected_progress_weight" type="number" step="0.01" min="0.01" class="mt-1 block w-full" v-model="form.expected_progress_weight" />
              <p class="lg-subtext">{{ $t('How heavily this activity counts toward overall project progress.') }}</p>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('work-packages.show', edit.id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t('Update Work Package') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import InputError from '@/Jetstream/InputError.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { AdminLayout, TecInput, TecLabel, DatePicker, InputError, PageHeader, AutoComplete },

  props: {
    edit: Object,
    warehouses: Array,
    engineers: Array,
    foremen: Array,
    subcontractors: Array,
    priorities: Array,
    statuses: Array,
  },

  data() {
    if (this.edit) {
      return {
        form: this.$inertia.form({
          title: this.edit.title ?? '',
          category: this.edit.category ?? '',
          warehouse_id: this.edit.warehouse_id ?? null,
          building: this.edit.building ?? '',
          floor: this.edit.floor ?? '',
          zone: this.edit.zone ?? '',
          area: this.edit.area ?? '',
          engineer_id: this.edit.engineer_id ?? null,
          foreman_id: this.edit.foreman_id ?? null,
          subcontractor_id: this.edit.subcontractor_id ?? null,
          priority: this.edit.priority ?? 'Normal',
          planned_start: this.edit.planned_start ?? '',
          planned_finish: this.edit.planned_finish ?? '',
          expected_progress_weight: this.edit.expected_progress_weight ?? 1,
          dependencies: this.edit.dependencies ?? [],
          status: this.edit.status ?? 'Not Started',
        }),
      };
    }

    return {
      form: this.$inertia.form({
        warehouse_id: null,
        work_packages: [this.blankRow()],
      }),
    };
  },

  methods: {
    blankRow() {
      return {
        title: '',
        category: '',
        priority: 'Normal',
        engineer_id: null,
        planned_start: '',
        planned_finish: '',
        expected_progress_weight: 1,
      };
    },
    addRow() {
      this.form.work_packages.push(this.blankRow());
    },
    removeRow(index) {
      if (this.form.work_packages.length === 1) {
        return;
      }
      this.form.work_packages.splice(index, 1);
    },
    rowError(index, field) {
      return this.$page.props.errors?.['work_packages.' + index + '.' + field];
    },
    submit() {
      if (this.edit) {
        this.form.transform((data) => ({ ...data, _method: 'put' })).post(this.route('work-packages.update', this.edit.id));
      } else {
        this.form.post(this.route('work-packages.store'));
      }
    },
  },
};
</script>

<style scoped>
.wpf-table td {
  vertical-align: top;
}
</style>
