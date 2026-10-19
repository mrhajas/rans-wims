<template>
  <admin-layout :title="record ? $t('Edit Archive Record') : $t('New Archive Record')">
    <div class="lg-page ar-page ar-form px-4 md:px-0">
      <page-header
        icon="folder"
        :title="record ? $t('Edit Archive Record') : $t('New Archive Record')"
        :subtitle="record ? $t('Update the record details or add more attachments.') : $t('Describe the document, then attach its files.')"
      >
        <span v-if="record" class="lg-chip lg-chip-blue" style="text-transform: none">{{ record.reference }}</span>
        <Link :href="record ? route('archive.records.show', record.id) : route('archive.records.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Document') }}</h2></div></header>
          <div class="lg-fields">
            <text-input class="lg-span-all" :label="$t('Title')" v-model="form.title" :error="errors.title" required />
            <auto-complete id="a_category" :label="$t('Category')" v-model="form.category" :suggestions="opt(options.categories)" :searchable="false" :error="errors.category" />
            <text-input :label="$t('Subcategory')" v-model="form.subcategory" :error="errors.subcategory" />
            <auto-complete id="a_type" :label="$t('Document Type')" v-model="form.document_type" :suggestions="opt(options.documentTypes)" clearable />
            <text-input :label="$t('Document RefNo')" v-model="form.reference_number" :error="errors.reference_number" />
            <div class="lg-span-all">
              <label class="ar-label">{{ $t('Description') }}</label>
              <textarea v-model="form.description" rows="3" class="w-full rounded-md border-gray-300"></textarea>
              <p v-if="errors.description" class="ar-err">{{ errors.description }}</p>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="building" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Source & Parties') }}</h2></div></header>
          <div class="lg-fields">
            <auto-complete id="a_source" :label="$t('Source')" v-model="form.source_type" :suggestions="opt(options.sources)" :searchable="false" clearable />
            <text-input :label="$t('Organization')" v-model="form.organization" :error="errors.organization" />
            <text-input :label="$t('Sender')" v-model="form.sender" :error="errors.sender" />
            <text-input :label="$t('Recipient')" v-model="form.recipient" :error="errors.recipient" />
            <auto-complete id="a_project" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="options.projects" clearable />
            <auto-complete id="a_contact" :label="$t('Client / Bank / Supplier')" v-model="form.contact_id" :suggestions="options.contacts" clearable />
            <text-input :label="$t('Department')" v-model="form.department" :error="errors.department" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Dates') }}</h2></div></header>
          <div class="lg-fields">
            <date-picker :label="$t('Document Date')" v-model="form.document_date" :error="errors.document_date" />
            <date-picker :label="$t('Received Date')" v-model="form.received_date" :error="errors.received_date" />
            <date-picker :label="$t('Expiry Date')" v-model="form.expiry_date" :error="errors.expiry_date" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Classification') }}</h2></div></header>
          <div class="lg-fields">
            <auto-complete id="a_conf" :label="$t('Confidentiality')" v-model="form.confidentiality" :suggestions="opt(options.confidentiality)" :searchable="false" :error="errors.confidentiality" />
            <auto-complete id="a_status" :label="$t('Status')" v-model="form.status" :suggestions="opt(options.statuses)" :searchable="false" :error="errors.status" />
            <div class="lg-span-all">
              <label class="ar-label">{{ $t('Tags') }}</label>
              <div class="ar-tags">
                <span v-for="(t, i) in form.tags" :key="t" class="ar-tag">{{ t }}<button type="button" :aria-label="$t('Remove')" @click="form.tags.splice(i, 1)">&times;</button></span>
                <input v-model="tagInput" type="text" list="archive-tag-list" :placeholder="$t('Type a tag, press Enter')" @keydown.enter.prevent="addTag" @keydown.188.prevent="addTag" @blur="addTag" />
                <datalist id="archive-tag-list"><option v-for="t in options.tags" :key="t" :value="t"></option></datalist>
              </div>
            </div>
            <div class="lg-span-all">
              <label class="ar-label">{{ $t('Keywords') }}</label>
              <textarea v-model="form.keywords" rows="2" class="w-full rounded-md border-gray-300" :placeholder="$t('Words people might search for')"></textarea>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="upload" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ record ? $t('Add Attachments') : $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Up to 20 files, each up to') }} {{ maxMb }} MB.</p></div></header>
          <label class="lg-btn lg-btn-soft ar-choose" :class="{ 'is-disabled': uploading }"><icons name="upload" class="w-4 h-4" />{{ files.length ? files.length + ' ' + $t('file(s) selected') + ' - ' + $t('add more') : $t('Choose files') }}
            <input type="file" multiple class="sr-only" :accept="accept" :disabled="uploading" @change="onFiles" />
          </label>
          <p class="lg-subtext ar-hint">{{ extensions.join(', ').toUpperCase() }}</p>
          <p v-if="fileError || errors.files" class="ar-err">{{ fileError || errors.files }}</p>
          <p v-for="(msg, key) in fileErrors" :key="key" class="ar-err">{{ msg }}</p>
          <upload-progress :items="files" :removable="!uploading" @remove="i => (files = files.filter(x => x.uid !== i.uid))" />
          <p v-if="uploadFailed" class="ar-err">{{ $t('The record is saved, but some files did not upload. Press Save again to retry them, or open the record.') }}</p>
          <p v-if="record" class="lg-subtext ar-hint">{{ $t('Existing attachments are managed on the record page.') }}</p>
        </section>

        <div class="ar-submit">
          <Link :href="record ? route('archive.records.show', record.id) : route('archive.records.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
          <Link v-if="uploadFailed && saved" :href="saved.show_url" class="lg-btn lg-btn-soft">{{ $t('Open the record') }}</Link>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="processing"><icons name="tick" class="w-4 h-4" />{{ processing ? (uploading ? $t('Uploading files…') : $t('Saving…')) : record || saved ? $t('Save Changes') : $t('Create Record') }}</button>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { formatBytes } from '@/Core/archiveFormat';
import UploadProgress from '@/Shared/UploadProgress.vue';
import { makeItem, uploadSequential } from '@/Core/uploadQueue';

export default {
  components: { AdminLayout, PageHeader, TextInput, AutoComplete, DatePicker, Link, UploadProgress },

  props: { record: Object, options: Object, extensions: Array, maxMb: Number },

  data() {
    const r = this.record || {};
    return {
      processing: false,
      uploading: false,
      uploadFailed: false,
      saved: null, // { id, show_url, upload_url } once the record exists, so a retry updates it instead of creating another
      serverErrors: {},
      tagInput: '',
      files: [],
      fileError: null,
      form: {
        title: r.title || '',
        description: r.description || '',
        category: r.category || null,
        subcategory: r.subcategory || '',
        document_type: r.document_type || null,
        source_type: r.source_type || null,
        organization: r.organization || '',
        sender: r.sender || '',
        recipient: r.recipient || '',
        reference_number: r.reference_number || '',
        keywords: r.keywords || '',
        warehouse_id: r.warehouse_id || null,
        contact_id: r.contact_id || null,
        department: r.department || '',
        document_date: r.document_date || null,
        received_date: r.received_date || null,
        expiry_date: r.expiry_date || null,
        confidentiality: r.confidentiality || (this.options.confidentiality.includes('Restricted') ? 'Restricted' : 'Internal'),
        status: r.status || 'Active',
        tags: [...(r.tags || [])],
      },
    };
  },

  computed: {
    errors() {
      return { ...(this.$page.props.errors || {}), ...this.serverErrors };
    },
    fileErrors() {
      return Object.fromEntries(Object.entries(this.errors).filter(([k]) => k.startsWith('files.')));
    },
    accept() {
      return this.extensions.map(e => '.' + e).join(',');
    },
  },

  methods: {
    opt(list) {
      return (list || []).map(v => ({ label: v, value: v }));
    },
    size: formatBytes,
    addTag() {
      const t = this.tagInput.replace(/,/g, '').trim();
      if (t && !this.form.tags.some(x => x.toLowerCase() === t.toLowerCase()) && this.form.tags.length < 30) this.form.tags.push(t.slice(0, 60));
      this.tagInput = '';
    },
    onFiles(e) {
      this.fileError = null;
      const incoming = Array.from(e.target.files || []);
      e.target.value = '';
      for (const f of incoming) {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!this.extensions.includes(ext)) { this.fileError = f.name + ': ' + this.$t('this file type is not allowed.'); continue; }
        if (f.size > this.maxMb * 1048576) { this.fileError = f.name + ': ' + this.$t('is larger than') + ' ' + this.maxMb + ' MB.'; continue; }
        if (this.files.length >= 20) { this.fileError = this.$t('You can attach up to 20 files at a time.'); break; }
        this.files.push(makeItem(f));
      }
    },
    // The record is saved first; each attachment is then sent on its own so it gets a real progress bar.
    async submit() {
      if (this.processing) return;
      this.addTag();
      this.processing = true;
      this.uploadFailed = false;
      this.serverErrors = {};
      try {
        const id = this.record ? this.record.id : this.saved ? this.saved.id : null;
        const url = id ? this.route('archive.records.update', id) : this.route('archive.records.store');
        const res = await window.axios.post(url, { ...this.form, ...(id ? { _method: 'put' } : {}) }, { headers: { Accept: 'application/json' } });
        this.saved = res.data;
        if (this.files.some(f => f.status !== 'uploaded')) {
          this.uploading = true;
          const out = await uploadSequential(this.files, res.data.upload_url);
          this.uploading = false;
          if (out.failed) { this.uploadFailed = true; return; }
        }
        this.$inertia.visit(res.data.show_url);
      } catch (e) {
        const r = e.response;
        if (r && r.status === 422 && r.data && r.data.errors) {
          this.serverErrors = Object.fromEntries(Object.entries(r.data.errors).map(([k, v]) => [k, v[0]]));
        } else if (r && r.status === 423) {
          this.fileError = this.$t('This record is restricted. Open it and confirm your password first.');
        } else {
          this.fileError = (r && r.data && r.data.message) || this.$t('The record could not be saved. Please try again.');
        }
      } finally {
        this.processing = false;
        this.uploading = false;
      }
    },
  },
};
</script>

<style scoped>
.ar-label { display: block; margin-bottom: 0.25rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.ar-err { margin-top: 0.3rem; font-size: 0.78rem; font-weight: 600; color: #be123c; }
.ar-form :deep(.lg-fields textarea) { border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); }
.ar-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 6px 8px; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-card); }
.ar-tags input { flex: 1 1 140px; min-width: 120px; border: 0 !important; box-shadow: none !important; background: transparent !important; color: var(--lg-ink); padding: 4px; }
.ar-tag { display: inline-flex; align-items: center; gap: 6px; padding: 3px 6px 3px 10px; border-radius: 9999px; background: rgba(79, 70, 229, 0.12); color: #4338ca; font-size: 0.76rem; font-weight: 700; }
.ar-tag button { width: 18px; height: 18px; border-radius: 9999px; line-height: 1; color: inherit; background: rgba(79, 70, 229, 0.14); }
html.dark .ar-tag { color: #c7d2fe; }
.ar-choose { cursor: pointer; }
.ar-choose.is-disabled { opacity: 0.5; pointer-events: none; }
.ar-hint { margin-top: 0.5rem; overflow-wrap: anywhere; }
.ar-picked { margin: 0.75rem 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.ar-picked li { display: flex; align-items: center; gap: 8px; padding: 6px 8px 6px 10px; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); min-width: 0; }
.ar-picked-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; font-size: 0.82rem; color: var(--lg-ink); }
.ar-submit { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; margin: 0.5rem 0 1.5rem; }
@media (max-width: 575.98px) { .ar-submit > * { flex: 1 1 100%; justify-content: center; } }
</style>
