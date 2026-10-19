<template>
  <admin-layout :title="$t('Return Material')">
    <div class="lg-page px-4 md:px-0 rf-form">
      <page-header icon="in" :title="$t('Return Material') + ' - ' + issue.issue_number" :subtitle="$t('Return one or multiple items with full or partial quantities.')">
        <Link :href="route('issue-materials.show', issue.id)" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Issue') }}</Link>
      </page-header>

      <section class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value text-base">{{ issue.warehouse?.name || '-' }}</div><div class="lg-stat-label">{{ $t('Project(s) / Warehouse(s)') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="user" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value text-base">{{ issue.issued_to || '-' }}</div><div class="lg-stat-label">{{ $t('Issued To') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ formatNumber(issue.balance_quantity) }}</div><div class="lg-stat-label">{{ $t('Balance Qty') }}</div></div>
        </div>
      </section>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Return Details') }}</h2></div>
          </header>
          <div class="lg-fields">
            <text-input v-model="form.return_date" type="date" :label="$t('Return Date')" :error="$page.props.errors.return_date" />
            <text-input v-model="form.returned_by" :label="$t('Returned By')" :readonly="true" :error="$page.props.errors.returned_by" />
            <text-input v-model="form.received_by" :label="$t('Received By')" :error="$page.props.errors.received_by" />
            <text-input v-model="form.contact_number" :label="$t('Handed In By')" :error="$page.props.errors.contact_number" />
            <div class="col-span-6 sm:col-span-4 relative">
              <label class="rf-label">{{ $t('Attachment') }}</label>
              <input
                id="return-attachments"
                type="file"
                multiple
                class="sr-only"
                @change="onAttachmentsChange"
                accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip"
              />
              <label for="return-attachments" class="rf-file mt-1">
                <span class="inline-flex items-center gap-2 text-sm">
                  <icons name="clip" class="w-4 h-4 text-blue-600" />
                  <span>{{ $t('Select attachments') }}</span>
                </span>
                <span class="lg-chip lg-chip-blue" style="text-transform: none">
                  {{ form.attachments.length ? `${form.attachments.length} ${$t('file(s)')}` : $t('No files chosen') }}
                </span>
              </label>
              <div class="rf-files" v-if="form.attachments.length">
                <div v-for="(file, fi) in form.attachments" :key="fi" class="truncate">{{ fi + 1 }}. {{ fileLabel(file) }}</div>
              </div>
              <div class="rf-err" v-if="$page.props.errors.attachments">{{ $page.props.errors.attachments }}</div>
              <div class="rf-err" v-if="$page.props.errors['attachments.*']">{{ $page.props.errors['attachments.*'] }}</div>
            </div>
            <div class="lg-span-all">
              <textarea-input v-model="form.remarks" :label="$t('Remarks')" :error="$page.props.errors.remarks" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Items') }}</h2>
              <p class="lg-card-sub">{{ $t('Tick the lines being returned and enter the quantity.') }}</p>
            </div>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Select') }}</th>
                  <th>{{ $t('Item') }}</th>
                  <th class="num">{{ $t('Issued Qty') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('Previously Returned') }}</th>
                  <th class="num">{{ $t('Balance') }}</th>
                  <th>{{ $t('Returning Qty') }}</th>
                  <th>{{ $t('Condition') }}</th>
                  <th>{{ $t('Remarks') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in form.items" :key="line.issue_transaction_item_id">
                  <td :data-label="$t('Select')"><input type="checkbox" class="rf-line-sel" v-model="line.selected" /></td>
                  <td :data-label="$t('Item')"><span class="lg-main">{{ line.item_name }}</span></td>
                  <td :data-label="$t('Issued Qty')" class="num">{{ formatNumber(line.issued_qty) }}</td>
                  <td :data-label="$t('Unit')">{{ line.unit_name }}</td>
                  <td :data-label="$t('Previously Returned')" class="num">{{ formatNumber(line.previously_returned_qty) }}</td>
                  <td :data-label="$t('Balance')" class="num font-bold">{{ formatNumber(line.balance_qty) }}</td>
                  <td :data-label="$t('Returning Qty')" class="rf-qty">
                    <text-input v-model="line.returning_qty" type="number" step="0.01" :error="$page.props.errors[`items.${i}.returning_qty`]" />
                  </td>
                  <td :data-label="$t('Condition')">
                    <select-input v-model="line.condition">
                      <option value="good">{{ $t('Good') }}</option>
                      <option value="damaged">{{ $t('Damaged') }}</option>
                      <option value="lost">{{ $t('Lost') }}</option>
                    </select-input>
                  </td>
                  <td :data-label="$t('Remarks')">
                    <text-input v-model="line.remarks" :error="$page.props.errors[`items.${i}.remarks`]" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="rf-err" v-if="$page.props.errors.items">{{ $page.props.errors.items }}</div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('issue-materials.show', issue.id)" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save Return') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    TextInput,
    SelectInput,
    TextareaInput,
    LoadingButton,
  },
  props: {
    issue: Object,
  },
  data() {
    const currentUserName = this.$page.props.auth?.user?.name || '';

    return {
      form: this.$inertia.form({
        return_date: new Date().toISOString().slice(0, 10),
        returned_by: this.issue.issued_to || '',
        received_by: currentUserName,
        contact_number: this.issue.issued_to || '',
        remarks: '',
        attachments: [],
        items: this.issue.items.map(line => ({
          issue_transaction_item_id: line.id,
          selected: false,
          item_name: `${line.item?.name || ''} (${line.item?.code || ''})`,
          issued_qty: Number(line.issued_quantity || 0),
          unit_name: line.unit?.name || line.unit?.code || '-',
          previously_returned_qty: Number(line.returned_quantity || 0),
          balance_qty: Number(line.balance_quantity || 0),
          returning_qty: '',
          condition: 'good',
          remarks: '',
        })),
      }),
    };
  },
  methods: {
    formatNumber(v) {
      const n = Number(v || 0);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    onAttachmentsChange(e) {
      const files = Array.from(e.target.files || []);
      this.form.attachments = files;
    },
    fileLabel(file) {
      if (!file || typeof file !== 'object') {
        return '';
      }
      const name = file.name || 'Attachment';
      const size = Number(file.size);
      if (!Number.isFinite(size) || size < 0) {
        return name;
      }
      return `${name} (${this.humanSize(size)})`;
    },
    humanSize(size) {
      if (!size) {
        return '0 B';
      }
      const units = ['B', 'kB', 'MB', 'GB', 'TB'];
      const i = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1);
      return `${(size / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
    },
    submit() {
      this.form.post(route('return-materials.store', this.issue.id));
    },
  },
};
</script>

<style scoped>
.rf-form :deep(.lg-fields label:not(:has(input[type='checkbox']))), .rf-label {
  display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute);
}
.rf-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.rf-form :deep(.lg-fields select),
.rf-form :deep(.lg-fields textarea),
.rf-form :deep(.lg-table input:not([type='checkbox'])),
.rf-form :deep(.lg-table select) {
  border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.rf-form :deep(.lg-fields input:focus), .rf-form :deep(.lg-fields select:focus), .rf-form :deep(.lg-fields textarea:focus),
.rf-form :deep(.lg-table input:focus), .rf-form :deep(.lg-table select:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }
.rf-form :deep(.lg-table td) { vertical-align: top; }
.rf-form :deep(.lg-table td > div), .rf-form :deep(.lg-table td .mb-2), .rf-form :deep(.lg-table td .mb-4) { margin-bottom: 0 !important; }
.rf-form :deep(.lg-table label) { display: none; }
.rf-qty { min-width: 110px; }
.rf-line-sel { width: 1.1rem; height: 1.1rem; }
.rf-file { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; min-height: 44px; padding: 0.5rem 0.8rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-card); cursor: pointer; transition: border-color 0.15s ease; }
.rf-file:hover { border-color: rgba(56, 189, 248, 0.7); }
.rf-files { margin-top: 0.5rem; font-size: 0.76rem; color: var(--lg-mute); }
.rf-err { margin-top: 0.3rem; font-size: 0.8rem; color: #be123c; }
html.dark .rf-err { color: #fda4af; }
.rf-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 0.9rem 1.25rem; }
.rf-info-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.rf-info-value { margin-top: 2px; font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
@media (max-width: 767.98px) {
  .rf-form :deep(.lg-table td) { align-items: center; }
  .rf-form :deep(.lg-table td > *) { max-width: 60%; }
}
</style>
