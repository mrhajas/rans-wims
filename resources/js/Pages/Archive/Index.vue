<template>
  <admin-layout :title="$t('Archive Records')">
    <div class="lg-page arx ar-page px-4 md:px-0">
      <page-header icon="folder" :title="$t('Archive Records')" :subtitle="$t('Search, filter and open every archived document.')">
        <Link v-if="can.tree" :href="route('archive.tree')" class="lg-btn lg-btn-soft"><icons name="open-folder" class="w-4 h-4" />{{ $t('Archive Tree') }}</Link>
        <Link :href="route('archive.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-pie" class="w-4 h-4" />{{ $t('Dashboard') }}</Link>
        <Link v-if="can.create" :href="route('archive.records.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('New Record') }}</Link>
      </page-header>

      <!-- Search + commonly used filters -->
      <div class="lg-card ar-bar-card relative z-30">
        <div class="ar-top">
          <div class="ar-search">
            <icons name="search" class="w-5 h-5 ar-search-ico" />
            <input v-model="form.q" type="text" class="ar-search-input" :placeholder="$t('Search reference, title, organisation, sender, keywords, tags, filename…')" autocomplete="off" @keydown.enter.prevent="apply" />
            <button v-if="form.q" type="button" class="ar-search-clear lg-tip" :data-tip="$t('Clear search')" :aria-label="$t('Clear search')" @click="form.q = ''"><icons name="x-circle" class="w-4 h-4" /></button>
          </div>
          <button type="button" class="lg-btn" :class="showAdvanced ? 'lg-btn-primary' : 'lg-btn-soft'" :aria-expanded="showAdvanced" @click="showAdvanced = !showAdvanced"><icons name="filter" class="w-4 h-4" />{{ $t('Advanced Filters') }}<span v-if="advancedCount" class="ar-badge">{{ advancedCount }}</span></button>
          <button type="button" class="lg-btn lg-btn-green" @click="apply"><icons name="tick" class="w-4 h-4" />{{ $t('Apply Filters') }}</button>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="!chips.length && !form.q" @click="clear"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear Filters') }}</button>
        </div>

        <div class="lg-fields ar-quick-grid">
          <auto-complete id="f_category" :label="$t('Category')" v-model="form.category" :suggestions="opt(options.categories)" clearable />
          <auto-complete id="f_source" :label="$t('Source')" v-model="form.source_type" :suggestions="opt(options.sources)" clearable />
          <auto-complete id="f_status" :label="$t('Status')" v-model="form.status" :suggestions="opt(options.statuses)" clearable />
          <auto-complete id="f_conf" :label="$t('Confidentiality')" v-model="form.confidentiality" :suggestions="opt(allowedConfidentiality)" clearable />
          <auto-complete id="f_project" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="options.projects" clearable />
        </div>

        <transition name="ar-slide">
          <div v-show="showAdvanced" class="ar-advanced">
            <div class="lg-fields ar-quick-grid">
              <auto-complete id="f_sub" :label="$t('Subcategory')" v-model="form.subcategory" :suggestions="opt(options.subcategories)" clearable />
              <auto-complete id="f_type" :label="$t('Document Type')" v-model="form.document_type" :suggestions="opt(options.documentTypes)" clearable />
              <auto-complete id="f_org" :label="$t('Organization')" v-model="form.organization" :suggestions="opt(options.organizations)" clearable />
              <auto-complete id="f_contact" :label="$t('Client / Bank / Supplier')" v-model="form.contact_id" :suggestions="options.contacts" clearable />
              <auto-complete id="f_dept" :label="$t('Department')" v-model="form.department" :suggestions="opt(options.departments)" clearable />
              <auto-complete id="f_ftype" :label="$t('File Type')" v-model="form.file_type" :suggestions="opt(options.fileTypes)" clearable />
              <auto-complete id="f_by" :label="$t('Created By')" v-model="form.created_by_user_id" :suggestions="options.users" clearable />
              <auto-complete id="f_tag" :label="$t('Tag')" v-model="form.tag" :suggestions="opt(options.tags)" clearable />
              <auto-complete id="f_expiry" :label="$t('Expiry')" v-model="form.expiry" :suggestions="expiryOptions" :searchable="false" clearable />
              <date-range-picker :label="$t('Document Date')" v-model="documentRange" />
              <date-range-picker :label="$t('Received Date')" v-model="receivedRange" />
              <date-range-picker :label="$t('Expiry Date')" v-model="expiryRange" />
            </div>
          </div>
        </transition>

        <div v-if="chips.length" class="ar-active" aria-live="polite">
          <span v-for="c in chips" :key="c.key" class="ar-chip">{{ c.label }}<button type="button" :aria-label="$t('Remove')" @click="removeChip(c.key)">&times;</button></span>
        </div>
      </div>

      <!-- Results -->
      <div class="ar-meta">
        <span class="lg-subtext">{{ records.meta.total.toLocaleString() }} {{ $t('result(s)') }}</span>
        <div class="ar-meta-right">
          <button v-if="can.delete" type="button" class="lg-btn lg-btn-soft ar-small" :class="{ 'is-on': form.trashed === 'only' }" @click="form.trashed = form.trashed === 'only' ? null : 'only'; apply()"><icons name="trash" class="w-4 h-4" />{{ form.trashed === 'only' ? $t('Showing deleted') : $t('Deleted') }}</button>
          <div class="arx-toggle" role="group" :aria-label="$t('View')">
            <button type="button" :class="{ 'is-on': view === 'grid' }" @click="setView('grid')"><icons name="collection" class="w-4 h-4" />{{ $t('Grid') }}</button>
            <button type="button" :class="{ 'is-on': view === 'list' }" @click="setView('list')"><icons name="list" class="w-4 h-4" />{{ $t('List') }}</button>
          </div>
          <label class="ar-pagesize"><span>{{ $t('Sort') }}</span>
            <select :value="form.sort || ''" @change="chooseSort($event.target.value)">
              <option value="">{{ $t('Newest first') }}</option>
              <option v-for="c in sortChoices" :key="c.key" :value="c.key">{{ c.label }}</option>
            </select>
            <button v-if="form.sort" type="button" class="lg-icon-btn lg-tip" :data-tip="form.direction === 'asc' ? $t('Ascending') : $t('Descending')" :aria-label="$t('Toggle sort direction')" @click="form.direction = form.direction === 'asc' ? 'desc' : 'asc'"><icons name="chevron-down" class="w-4 h-4" :class="{ 'rotate-180': form.direction === 'asc' }" /></button>
          </label>
          <label class="ar-pagesize"><span>{{ $t('Per page') }}</span>
            <select v-model.number="form.per_page" @change="apply"><option v-for="n in pageSizes" :key="n" :value="n">{{ n }}</option></select>
          </label>
          <div class="ar-cols">
            <button type="button" class="lg-btn lg-btn-soft ar-small" @click="showCols = !showCols"><icons name="table" class="w-4 h-4" />{{ $t('Columns') }}</button>
            <div v-if="showCols" class="ar-cols-menu">
              <label v-for="c in optionalCols" :key="c.key"><input type="checkbox" v-model="cols[c.key]" @change="saveCols" /> {{ c.label }}</label>
            </div>
          </div>
        </div>
      </div>

      <div v-if="view === 'grid'" class="arx-grid" :class="{ 'ar-busy': busy }">
        <Link v-for="(r, i) in records.data" :key="r.id" :href="route('archive.records.show', r.id)" class="arx-rec" :class="[ 'arx-g' + grad(r.category), { 'is-trashed': r.deleted_at } ]" :style="{ '--i': i }">
          <span class="arx-rec-ico"><icons name="doc-text" class="w-5 h-5" /></span>
          <span class="arx-rec-title">{{ r.title }}</span>
          <span class="arx-rec-ref">{{ r.reference }} · {{ r.category }}<template v-if="r.source_type"> · {{ r.source_type }}</template></span>
          <span class="arx-rec-chips">
            <span v-if="r.confidentiality === 'Confidential' || r.confidentiality === 'Restricted'" class="arx-rec-chip">🔒 {{ r.confidentiality }}</span>
            <span v-if="r.is_expired" class="arx-rec-chip">{{ $t('Expired') }}</span>
            <span v-else-if="r.expires_soon" class="arx-rec-chip">{{ $t('Expiring') }}</span>
            <span v-if="r.status !== 'Active'" class="arx-rec-chip">{{ r.status }}</span>
            <span v-if="r.deleted_at" class="arx-rec-chip">{{ $t('Deleted') }}</span>
          </span>
          <span class="arx-rec-foot"><span>{{ r.document_date ? $date(r.document_date) : '—' }}</span><span>{{ r.files_count }} {{ $t('file(s)') }}<template v-if="r.storage_bytes !== null"> · {{ fmt(r.storage_bytes) }}</template></span></span>
        </Link>
        <div v-if="!records.data.length" class="lg-card ar-empty" style="grid-column: 1 / -1">
          <icons name="folder" class="w-8 h-8" />
          <strong>{{ hasAnyFilter ? $t('No documents match your current filters.') : $t('No archive records found.') }}</strong>
          <button v-if="hasAnyFilter" type="button" class="lg-btn lg-btn-soft" @click="clear"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear Filters') }}</button>
        </div>
      </div>

      <div v-else class="lg-table-wrap -mx-4 md:mx-0" :class="{ 'ar-busy': busy }">
        <table class="lg-table lg-responsive ar-table">
          <thead>
            <tr>
              <th v-for="c in visibleCols" :key="c.key" :class="[{ num: c.num, 'ar-sortable': c.sort, 'is-sorted': form.sort === c.sort }]" :aria-sort="c.sort ? ariaSort(c.sort) : null" @click="c.sort && sortBy(c.sort)">
                <span class="ar-th">{{ c.label }}<span v-if="c.sort" class="ar-arrows"><span :class="{ on: form.sort === c.sort && form.direction === 'asc' }">&#9650;</span><span :class="{ on: form.sort === c.sort && form.direction !== 'asc' }">&#9660;</span></span></span>
              </th>
              <th class="ar-actions-col"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, idx) in records.data" :key="r.id" class="is-click" :class="{ 'is-trashed': r.deleted_at }" @click="open(r)">
              <td :data-label="$t('No')" class="ar-no">{{ rowNumber(idx) }}</td>
              <td :data-label="$t('Reference')"><span class="lg-chip lg-chip-blue ar-ref" style="text-transform: none">{{ r.reference }}</span></td>
              <td :data-label="$t('Title')" class="ar-title-cell">
                <div class="ar-title-wrap">
                  <div class="lg-main ar-title">{{ r.title }}</div>
                  <div class="lg-subtext">{{ r.document_type || '—' }}</div>
                </div>
              </td>
              <td :data-label="$t('Category')">{{ r.category }}</td>
              <td :data-label="$t('Source')">{{ r.source_type || '—' }}</td>
              <td v-if="cols.project" :data-label="$t('Project')">{{ r.project || '—' }}</td>
              <td :data-label="$t('Document Date')" class="ar-nowrap">{{ r.document_date ? $date(r.document_date) : '—' }}</td>
              <td v-if="cols.received" :data-label="$t('Received')" class="ar-nowrap">{{ r.received_date ? $date(r.received_date) : '—' }}</td>
              <td :data-label="$t('Files')" class="num">{{ r.files_count }}</td>
              <td v-if="can.storage" :data-label="$t('Size')" class="num">{{ fmt(r.storage_bytes) }}</td>
              <td :data-label="$t('Confidentiality')"><span class="lg-chip" :class="confChip(r.confidentiality)" style="text-transform: none"><icons v-if="r.confidentiality === 'Confidential' || r.confidentiality === 'Restricted'" name="lock-closed" class="w-3 h-3" />{{ r.confidentiality }}</span></td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(r.status)" style="text-transform: none">{{ r.status }}</span>
                <span v-if="r.is_expired" class="lg-chip lg-chip-red ar-exp" style="text-transform: none">{{ $t('Expired') }}</span>
                <span v-else-if="r.expires_soon" class="lg-chip lg-chip-amber ar-exp" style="text-transform: none">{{ $t('Expiring') }}</span>
              </td>
              <td v-if="cols.created_by" :data-label="$t('Created By')">{{ r.created_by || '—' }}</td>
              <td :data-label="$t('Updated')" class="ar-nowrap">{{ r.updated_at ? $date(r.updated_at) : '—' }}</td>
              <td class="ar-actions-col" data-label="" @click.stop>
                <div class="lg-actions">
                  <template v-if="!r.deleted_at">
                    <Link :href="route('archive.records.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open record')" :aria-label="$t('Open record')"><icons name="eye-flat" class="w-4 h-4" /></Link>
                    <Link v-if="can.update" :href="route('archive.records.edit', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')"><icons name="edit" class="w-4 h-4" /></Link>
                    <Link :href="route('archive.records.show', r.id) + '#history'" class="lg-icon-btn lg-tip" :data-tip="$t('History')" :aria-label="$t('History')"><icons name="clock" class="w-4 h-4" /></Link>
                    <a v-if="can.print" :href="route('archive.records.print', r.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print record sheet')" :aria-label="$t('Print record sheet')"><icons name="printer" class="w-4 h-4" /></a>
                    <button v-if="can.delete" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="remove(r)"><icons name="trash" class="w-4 h-4" /></button>
                  </template>
                  <button v-else-if="can.delete" type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Restore')" :aria-label="$t('Restore')" @click="restore(r)"><icons name="refresh" class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="!records.data.length" class="lg-empty-row">
              <td class="lg-empty" :colspan="visibleCols.length + 1">
                <div class="ar-empty">
                  <icons name="folder" class="w-8 h-8" />
                  <strong>{{ hasAnyFilter ? $t('No documents match your current filters.') : $t('No archive records found.') }}</strong>
                  <button v-if="hasAnyFilter" type="button" class="lg-btn lg-btn-soft" @click="clear"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear Filters') }}</button>
                  <Link v-else-if="can.create" :href="route('archive.records.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('New Record') }}</Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="records.data.length" class="mt-4" :meta="records.meta" :links="records.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';
import Pagination from '@/Shared/Pagination.vue';
import { formatBytes, confidentialityChip, statusChip } from '@/Core/archiveFormat';

const FILTER_KEYS = ['q', 'category', 'subcategory', 'document_type', 'source_type', 'organization', 'warehouse_id', 'contact_id', 'department', 'status', 'confidentiality', 'file_type', 'created_by_user_id', 'tag', 'expiry'];

export default {
  components: { AdminLayout, PageHeader, AutoComplete, DateRangePicker, Pagination, Link },

  props: { records: Object, filters: Object, options: Object, pageSizes: Array, can: Object },

  data() {
    const f = this.filters || {};
    const form = { sort: f.sort || null, direction: f.direction || null, per_page: f.per_page || 25, trashed: f.trashed || null };
    FILTER_KEYS.forEach(k => { form[k] = f[k] !== undefined && f[k] !== null ? f[k] : null; });
    form.q = f.q || '';

    let cols = { project: false, received: false, created_by: false };
    try { cols = { ...cols, ...JSON.parse(localStorage.getItem('archive_cols') || '{}') }; } catch (e) { /* storage unavailable */ }

    return {
      form,
      busy: false,
      showAdvanced: this.advancedHasValue(f),
      view: (() => { try { return localStorage.getItem('archive_view') || 'grid'; } catch (e) { return 'grid'; } })(),
      showCols: false,
      cols,
      documentRange: { start: f.document_from || null, end: f.document_to || null },
      receivedRange: { start: f.received_from || null, end: f.received_to || null },
      expiryRange: { start: f.expiry_from || null, end: f.expiry_to || null },
    };
  },

  computed: {
    allowedConfidentiality() {
      return this.can.confidential ? this.options.confidentiality : this.options.confidentiality.filter(c => c === 'Public' || c === 'Internal');
    },
    expiryOptions() {
      return [{ label: this.$t('Expiring in 30 days'), value: 'expiring' }, { label: this.$t('Already expired'), value: 'expired' }];
    },
    sortChoices() {
      const c = [
        { key: 'reference', label: this.$t('Reference') }, { key: 'title', label: this.$t('Title') }, { key: 'category', label: this.$t('Category') },
        { key: 'source_type', label: this.$t('Source') }, { key: 'document_date', label: this.$t('Document Date') }, { key: 'received_date', label: this.$t('Received Date') },
        { key: 'files_count', label: this.$t('Files') }, { key: 'created_at', label: this.$t('Created') }, { key: 'updated_at', label: this.$t('Updated') },
      ];
      if (this.can.storage) c.splice(7, 0, { key: 'storage_bytes', label: this.$t('Size') });
      return c;
    },
    optionalCols() {
      return [{ key: 'project', label: this.$t('Project') }, { key: 'received', label: this.$t('Received Date') }, { key: 'created_by', label: this.$t('Created By') }];
    },
    visibleCols() {
      const all = [
        { key: 'no', label: this.$t('No') },
        { key: 'reference', label: this.$t('Reference'), sort: 'reference' },
        { key: 'title', label: this.$t('Title'), sort: 'title' },
        { key: 'category', label: this.$t('Category'), sort: 'category' },
        { key: 'source', label: this.$t('Source'), sort: 'source_type' },
        this.cols.project && { key: 'project', label: this.$t('Project') },
        { key: 'document_date', label: this.$t('Document Date'), sort: 'document_date' },
        this.cols.received && { key: 'received', label: this.$t('Received'), sort: 'received_date' },
        { key: 'files', label: this.$t('Files'), sort: 'files_count', num: true },
        this.can.storage && { key: 'size', label: this.$t('Size'), sort: 'storage_bytes', num: true },
        { key: 'conf', label: this.$t('Confidentiality') },
        { key: 'status', label: this.$t('Status') },
        this.cols.created_by && { key: 'created_by', label: this.$t('Created By') },
        { key: 'updated', label: this.$t('Updated'), sort: 'updated_at' },
      ];
      return all.filter(Boolean);
    },
    chips() {
      const chips = [];
      const named = (list, v) => (list.find(o => o.value == v) || {}).label || v;
      const labels = { category: 'Category', subcategory: 'Subcategory', document_type: 'Type', source_type: 'Source', organization: 'Organization', department: 'Department', status: 'Status', confidentiality: 'Confidentiality', file_type: 'File', tag: 'Tag' };
      Object.keys(labels).forEach(k => { if (this.form[k]) chips.push({ key: k, label: this.form[k] }); });
      if (this.form.warehouse_id) chips.push({ key: 'warehouse_id', label: named(this.options.projects, this.form.warehouse_id) });
      if (this.form.contact_id) chips.push({ key: 'contact_id', label: named(this.options.contacts, this.form.contact_id) });
      if (this.form.created_by_user_id) chips.push({ key: 'created_by_user_id', label: this.$t('By') + ' ' + named(this.options.users, this.form.created_by_user_id) });
      if (this.form.expiry) chips.push({ key: 'expiry', label: named(this.expiryOptions, this.form.expiry) });
      if (this.documentRange.start || this.documentRange.end) chips.push({ key: 'document', label: this.$t('Document') + ': ' + (this.documentRange.start || '…') + ' – ' + (this.documentRange.end || '…') });
      if (this.receivedRange.start || this.receivedRange.end) chips.push({ key: 'received', label: this.$t('Received') + ': ' + (this.receivedRange.start || '…') + ' – ' + (this.receivedRange.end || '…') });
      if (this.expiryRange.start || this.expiryRange.end) chips.push({ key: 'expiryRange', label: this.$t('Expiry') + ': ' + (this.expiryRange.start || '…') + ' – ' + (this.expiryRange.end || '…') });
      return chips;
    },
    advancedCount() {
      const adv = ['subcategory', 'document_type', 'organization', 'contact_id', 'department', 'file_type', 'created_by_user_id', 'tag', 'expiry'];
      return adv.filter(k => this.form[k]).length + [this.documentRange, this.receivedRange, this.expiryRange].filter(r => r.start || r.end).length;
    },
    hasAnyFilter() {
      return this.chips.length > 0 || !!this.form.q;
    },
  },

  watch: {
    // Search and the filters apply on their own after a short pause (server-side); the buttons just apply instantly.
    form: { handler() { this.applyDebounced(); }, deep: true },
    documentRange: { handler() { this.applyDebounced(); }, deep: true },
    receivedRange: { handler() { this.applyDebounced(); }, deep: true },
    expiryRange: { handler() { this.applyDebounced(); }, deep: true },
  },

  created() {
    this.applyDebounced = debounce(() => this.apply(), 450);
  },

  methods: {
    advancedHasValue(f) {
      return ['subcategory', 'document_type', 'organization', 'contact_id', 'department', 'file_type', 'created_by_user_id', 'tag', 'expiry', 'document_from', 'document_to', 'received_from', 'received_to', 'expiry_from', 'expiry_to'].some(k => f[k]);
    },
    opt(list) {
      return (list || []).map(v => ({ label: v, value: v }));
    },
    fmt: formatBytes,
    confChip: confidentialityChip,
    statusChip,
    query() {
      const q = { ...this.form,
        document_from: this.documentRange.start, document_to: this.documentRange.end,
        received_from: this.receivedRange.start, received_to: this.receivedRange.end,
        expiry_from: this.expiryRange.start, expiry_to: this.expiryRange.end };
      if (q.per_page === 25) delete q.per_page;
      if (!q.sort) { delete q.sort; delete q.direction; }

      return pickBy(q, v => v !== null && v !== '' && v !== undefined);
    },
    apply() {
      this.applyDebounced.cancel();
      this.busy = true;
      this.$inertia.get(this.route('archive.records.index'), this.query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => { this.busy = false; },
      });
    },
    clear() {
      FILTER_KEYS.forEach(k => { this.form[k] = null; });
      this.form.q = '';
      this.form.trashed = null;
      this.documentRange = { start: null, end: null };
      this.receivedRange = { start: null, end: null };
      this.expiryRange = { start: null, end: null };
    },
    removeChip(key) {
      if (key === 'document') this.documentRange = { start: null, end: null };
      else if (key === 'received') this.receivedRange = { start: null, end: null };
      else if (key === 'expiryRange') this.expiryRange = { start: null, end: null };
      else this.form[key] = null;
    },
    sortBy(col) {
      if (this.form.sort !== col) { this.form.sort = col; this.form.direction = 'asc'; }
      else if (this.form.direction === 'asc') this.form.direction = 'desc';
      else { this.form.sort = null; this.form.direction = null; }
    },
    ariaSort(col) {
      if (this.form.sort !== col) return 'none';
      return this.form.direction === 'asc' ? 'ascending' : 'descending';
    },
    // Running number across pages (page 2 carries on after page 1).
    rowNumber(index) {
      return (this.records.meta && this.records.meta.from ? this.records.meta.from : 1) + index;
    },
    chooseSort(key) {
      this.form.sort = key || null;
      this.form.direction = key ? 'asc' : null;
    },
    setView(v) {
      this.view = v;
      try { localStorage.setItem('archive_view', v); } catch (e) { /* ignore */ }
    },
    grad(category) {
      let h = 0;
      for (const ch of String(category)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
      return h % 6;
    },
    saveCols() {
      try { localStorage.setItem('archive_cols', JSON.stringify(this.cols)); } catch (e) { /* ignore */ }
    },
    open(r) {
      if (!r.deleted_at) this.$inertia.visit(this.route('archive.records.show', r.id));
    },
    async remove(r) {
      if (await this.$confirm(this.$t('Delete archive record') + ' ' + r.reference + '? ' + this.$t('Its files are kept and it can be restored.'), { variant: 'danger', confirmText: this.$t('Delete') })) {
        this.$inertia.delete(this.route('archive.records.destroy', r.id), { preserveScroll: true });
      }
    },
    restore(r) {
      this.$inertia.put(this.route('archive.records.restore', r.id), {}, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.ar-bar-card { padding: 0.9rem 1rem 1rem; }
.ar-top { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 0.8rem; }
.ar-search { position: relative; flex: 1 1 320px; min-width: 0; display: flex; align-items: center; }
.ar-search-ico { position: absolute; left: 0.95rem; color: var(--lg-faint); pointer-events: none; }
.ar-search-input { width: 100%; height: 2.8rem; padding: 0 2.5rem 0 2.8rem; border-radius: 1rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.ar-search-input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.18); }
.ar-search-clear { position: absolute; right: 0.8rem; color: var(--lg-faint); }
.ar-badge { margin-left: 2px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #7f1d1d; color: #fff; font-size: 0.65rem; font-weight: 800; line-height: 18px; text-align: center; }
.ar-quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); gap: 0.75rem; align-items: end; }
.ar-advanced { margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px dashed var(--lg-line); }
.ar-slide-enter-active, .ar-slide-leave-active { transition: opacity 0.18s ease; }
.ar-slide-enter-from, .ar-slide-leave-to { opacity: 0; }
.ar-active { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 0.8rem; }
.ar-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 6px 3px 11px; border-radius: 9999px; background: rgba(79, 70, 229, 0.12); color: #4338ca; font-size: 0.74rem; font-weight: 700; }
.ar-chip button { width: 18px; height: 18px; border-radius: 9999px; line-height: 1; font-size: 0.95rem; color: inherit; background: rgba(79, 70, 229, 0.14); }
html.dark .ar-chip { color: #c7d2fe; }

.ar-meta { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.6rem; }
.ar-meta-right { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.ar-small { padding: 0.35rem 0.75rem; font-size: 0.74rem; }
.ar-small.is-on { box-shadow: inset 0 0 0 2px #7f1d1d; }
.ar-pagesize { display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 700; color: var(--lg-mute); }
.ar-pagesize select { height: 2rem; padding: 0 1.6rem 0 0.6rem; border-radius: 0.7rem; border: 1px solid var(--lg-line); background-color: var(--lg-card); color: var(--lg-ink); font-size: 0.78rem; }
.ar-cols { position: relative; }
.ar-cols-menu { position: absolute; right: 0; top: calc(100% + 6px); z-index: 30; display: flex; flex-direction: column; gap: 8px; min-width: 180px; padding: 10px 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -16px rgba(15, 23, 42, 0.45); font-size: 0.8rem; color: var(--lg-ink); }
.ar-cols-menu label { display: flex; align-items: center; gap: 8px; cursor: pointer; }

.ar-busy { opacity: 0.55; transition: opacity 0.15s ease; pointer-events: none; }
.ar-table tbody tr.is-trashed { opacity: 0.6; }
.ar-ref { font-variant-numeric: tabular-nums; }
.ar-title-cell { min-width: 180px; max-width: 300px; }
.ar-nowrap { white-space: nowrap; }
.ar-no { width: 1%; font-weight: 800; color: var(--lg-mute); font-variant-numeric: tabular-nums; }
/* a soft shadow line under every record in the list */
.ar-table tbody tr:not(.lg-empty-row) { box-shadow: 0 1px 0 var(--lg-line), 0 7px 10px -9px rgba(15, 23, 42, 0.35); position: relative; }
.ar-table tbody tr:not(.lg-empty-row):hover { box-shadow: 0 1px 0 var(--lg-line), 0 10px 14px -9px rgba(15, 23, 42, 0.45); }
.ar-title-wrap { min-width: 0; }
.ar-table :deep(th), .ar-table :deep(td) { padding-left: 0.65rem; padding-right: 0.65rem; }
.ar-title { overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: normal; }
.ar-exp { margin-left: 4px; }
.ar-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.ar-th { display: inline-flex; align-items: center; gap: 0.35rem; }
.ar-arrows { display: inline-flex; flex-direction: column; font-size: 0.5rem; line-height: 0.7; opacity: 0.4; }
.ar-arrows .on { color: #7f1d1d; opacity: 1; } html.dark .ar-arrows .on { color: #fda4af; }
.is-sorted .ar-arrows { opacity: 1; }
.ar-actions-col { width: 1%; white-space: nowrap; }
.ar-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--lg-faint); }
.ar-empty strong { color: var(--lg-mute); font-size: 0.9rem; }
@media (max-width: 639.98px) {
  .ar-top > .lg-btn { flex: 1 1 calc(50% - 0.3rem); justify-content: center; }
  .ar-search { flex-basis: 100%; }
  .ar-meta-right { width: 100%; justify-content: space-between; }
}
</style>
