<template>
  <admin-layout :title="staff ? $t('Edit Staff') : $t('Add Staff')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="users"
        :title="staff ? $t('Edit Staff') : $t('Add Staff')"
        :subtitle="staff ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="staff" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ staff.staff_number }}</span>
        <Link :href="route('staffs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('RANS Staffs') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Personal Information') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Date of Birth') }} / {{ $t('Status') }}</p>
            </div>
          </header>
          <div class="stf-identity">
            <div class="stf-photo-picker flex-none" @click="$refs.photoInput.click()">
              <img v-if="photoPreviewUrl" :src="photoPreviewUrl" class="stf-photo-img" alt="" />
              <div v-else class="stf-photo-placeholder">{{ $t('PHOTO') }}</div>
              <a
                v-if="photoPreviewUrl"
                :href="photoPreviewUrl"
                :download="staff ? staff.staff_number + '-photo' : 'photo'"
                class="stf-photo-download lg-tip"
                :data-tip="$t('Download Photo')"
                @click.stop
               :aria-label="$t('Download Photo')">
                <icons name="download" class="w-3 h-3" />
              </a>
              <button v-if="photoPreviewUrl" type="button" class="stf-photo-remove lg-tip" @click.stop="removePhoto" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
                <icons name="cross" class="w-3 h-3" />
              </button>
            </div>
            <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/svg+xml" class="hidden" @change="onPhotoChosen" />

            <div class="lg-fields stf-identity-fields">
              <div><text-input :label="$t('Name')" v-model="form.name" :error="$page.props.errors.name" required /></div>
              <div><text-input :label="$t('Qualification')" v-model="form.qualification" :error="$page.props.errors.qualification" /></div>
              <div><date-picker :label="$t('Date of Birth')" v-model="form.date_of_birth" :error="$page.props.errors.date_of_birth" /></div>
              <div><auto-complete id="gender" :label="$t('Gender')" v-model="form.gender" :suggestions="genderOptions" clearable /></div>
              <div><auto-complete id="employment_type" :label="$t('Employment')" v-model="form.employment_type" :suggestions="employmentOptions" clearable /></div>
              <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" :suggestions="statusOptions" :error="$page.props.errors.status" /></div>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="mobile" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Contact Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Address') }} / {{ $t('Personal Email') }} / {{ $t('Mobile No.') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all"><textarea-input :label="$t('Address')" v-model="form.address" :error="$page.props.errors.address" /></div>
            <div><text-input :label="$t('Personal Email')" v-model="form.personal_email" :error="$page.props.errors.personal_email" /></div>
            <div><text-input :label="$t('Mobile No.')" placeholder="0674020223 or +255674020223" v-model="form.mobile_no" :error="$page.props.errors.mobile_no" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="heart" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Family Info') }}</h2>
              <p class="lg-card-sub">{{ $t('Father Name') }} / {{ $t('Father Contact No.') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div><text-input :label="$t('Father Name')" v-model="form.father_name" :error="$page.props.errors.father_name" /></div>
            <div><text-input :label="$t('Father Contact No.')" v-model="form.father_contact_no" :error="$page.props.errors.father_contact_no" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Bank Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Bank Name') }} / {{ $t('Account No.') }} / {{ $t('TIN Number') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div><text-input :label="$t('Bank Name')" v-model="form.bank_name" :error="$page.props.errors.bank_name" /></div>
            <div><text-input :label="$t('Account Holder Name')" v-model="form.account_holder_name" :error="$page.props.errors.account_holder_name" /></div>
            <div><text-input :label="$t('Account No.')" v-model="form.account_no" :error="$page.props.errors.account_no" /></div>
            <div><text-input :label="$t('TIN Number')" v-model="form.tin_number" :error="$page.props.errors.tin_number" /></div>
            <div><text-input :label="$t('ZSSF Number')" v-model="form.nssf_number" :error="$page.props.errors.nssf_number" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Employment & Salary') }}</h2>
              <p class="lg-card-sub">{{ $t('Position') }} / {{ $t('Join Date') }} / {{ $t('Basic Salary') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all"><auto-complete id="user_id" :label="$t('Linked user account (optional)')" v-model="form.user_id" :suggestions="users" value-key="id" label-key="label" :error="$page.props.errors.user_id" clearable /></div>
            <div><auto-complete id="position" :label="$t('Position')" v-model="form.position" :suggestions="positionOptions" :error="$page.props.errors.position" freeform clearable /></div>
            <div><date-picker :label="$t('Join Date')" v-model="form.join_date" :error="$page.props.errors.join_date" /></div>
            <div><text-input type="number" step="0.01" :label="$t('Basic Salary')" v-model="form.basic_salary" :error="$page.props.errors.basic_salary" /></div>
            <div><text-input type="number" step="0.01" :label="$t('Allowances')" v-model="form.allowances" :error="$page.props.errors.allowances" /></div>
            <div><text-input type="number" step="0.01" :label="$t('Deductions')" v-model="form.deductions" :error="$page.props.errors.deductions" /></div>
            <div class="stf-net-salary-box flex flex-col justify-center">
              <span class="stf-net-label">{{ $t('Net Salary') }}</span>
              <span class="stf-net-value">{{ $money(netSalaryPreview) }}</span>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Contracts, ID scans, certificates: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <attachment-picker v-model="selectedFiles" :max-files="5" :help-text="$t('Contracts, ID scans, certificates: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.')" />
          <ul v-if="staff && staff.attachments && staff.attachments.length" class="stf-files">
            <li v-for="file in staff.attachments" :key="file.id" class="stf-file">
              <span class="stf-file-name">{{ file.name }}</span>
              <div class="flex items-center gap-1 shrink-0">
                <a
                  :href="file.preview_url"
                  target="_blank"
                  class="stf-att-icon-btn lg-tip"
                  :data-tip="$t('Preview')"
                  @mouseenter="showAttachmentZoom($event, file)"
                  @mouseleave="hideAttachmentZoom"
                >
                  <icons name="eye-flat" class="w-4 h-4 flex-none" />
                </a>
                <a :href="file.download_url" class="stf-att-icon-btn lg-tip" :data-tip="$t('Download')">
                  <icons name="download" class="w-4 h-4 flex-none" />
                </a>
                <button type="button" class="stf-att-icon-btn stf-att-icon-btn-danger lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(file)">
                  <icons name="trash" class="w-4 h-4 flex-none" />
                </button>
              </div>
            </li>
          </ul>
        </section>

        <teleport to="body">
          <div class="stf-att-zoom" :class="{ 'stf-att-zoom-show': attachmentZoom.show }" :style="{ left: attachmentZoom.x + 'px', top: attachmentZoom.y + 'px' }">
            <img v-if="attachmentZoom.isImage" :src="attachmentZoom.src" alt="" />
            <div v-else class="stf-att-zoom-file">
              <icons name="doc" class="w-8 h-8" />
              <span>{{ $t('No preview available') }}</span>
            </div>
            <div class="stf-att-zoom-name">{{ attachmentZoom.name }}</div>
          </div>
        </teleport>

        <div class="lg-actionbar">
          <delete-staff v-if="staff && $can('delete-staffs')" :staff="staff" />
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('staffs.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /> {{ staff ? $t('Save Changes') : $t('Add Staff') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import DeleteStaff from '@/Shared/Staff/DeleteStaff.vue';

export default {
  components: { AdminLayout, TextInput, DatePicker, TextareaInput, AutoComplete, PageHeader, AttachmentPicker, DeleteStaff },

  props: {
    staff: Object,
    users: { type: Array, default: () => [] },
  },

  data() {
    const s = this.staff || {};
    return {
      selectedFiles: [],
      photoFile: null,
      photoRemoved: false,
      // Teleported to <body> and positioned via getBoundingClientRect() on
      // hover, same pattern as Staff/Index.vue's photo zoom — keeps the
      // enlarged preview from being clipped by this form's own layout.
      attachmentZoom: { show: false, src: null, name: '', isImage: false, x: 0, y: 0 },
      form: this.$inertia.form({
        name: s.name || '',
        user_id: s.user_id || null,
        qualification: s.qualification || '',
        date_of_birth: s.date_of_birth || '',
        gender: s.gender || null,
        employment_type: s.employment_type || null,
        status: s.status || 'Active',
        address: s.address || '',
        personal_email: s.personal_email || '',
        mobile_no: s.mobile_no || '',
        father_name: s.father_name || '',
        father_contact_no: s.father_contact_no || '',
        bank_name: s.bank_name || '',
        account_holder_name: s.account_holder_name || '',
        account_no: s.account_no || '',
        tin_number: s.tin_number || '',
        nssf_number: s.nssf_number || '',
        position: s.position || 'RANS Staff',
        join_date: s.join_date || '',
        basic_salary: s.basic_salary || 0,
        allowances: s.allowances || 0,
        deductions: s.deductions || 0,
      }),
    };
  },

  computed: {
    genderOptions() {
      return ['Male', 'Female'].map(g => ({ label: this.$t(g), value: g }));
    },
    employmentOptions() {
      return ['Full time', 'Part time', 'Freelancing', 'Internship'].map(e => ({ label: this.$t(e), value: e }));
    },
    statusOptions() {
      return ['Active', 'Retired Active', 'Retired', 'Inactive', 'Terminated'].map(s => ({ label: this.$t(s), value: s }));
    },
    // Common RANS construction-org job titles — freeform (see the
    // auto-complete's `freeform` prop) so a title outside this list can
    // still be typed in and saved as-is, rather than forcing a pick from
    // a fixed set.
    positionOptions() {
      return [
        'RANS Staff',
        'Managing Director', 'General Manager', 'Operations Manager', 'Construction Manager',
        'Project Director', 'Project Manager', 'Deputy Project Manager', 'Project Coordinator',
        'Site Manager', 'Site Engineer', 'Civil Engineer', 'Structural Engineer', 'Electrical Engineer',
        'Mechanical Engineer', 'Quantity Surveyor', 'Assistant Quantity Surveyor', 'Architect',
        'Site Architect', 'Land Surveyor', 'Surveyor', 'Planning Engineer', 'Project Planner',
        'Contracts Manager', 'Contracts Administrator', 'Procurement Manager', 'Procurement Officer',
        'Commercial Manager', 'Cost Engineer', 'Estimator', 'Accountant', 'Finance Manager',
        'Store Manager', 'Storekeeper', 'Logistics Officer', 'Document Controller',
        'Administrative Officer', 'Human Resources Manager', 'HR Officer', 'Health & Safety Manager',
        'HSE Officer', 'Safety Officer', 'Quality Manager', 'QA/QC Engineer', 'QA/QC Inspector',
        'Environmental Officer', 'Foreman', 'General Foreman', 'Civil Foreman', 'Electrical Foreman',
        'Mechanical Foreman', 'Site Supervisor', 'Clerk of Works', 'Materials Engineer',
        'Materials Technician', 'Laboratory Technician', 'CAD Technician', 'BIM Coordinator',
        'BIM Manager', 'Equipment Manager', 'Fleet Manager', 'Plant Operator', 'Equipment Operator',
        'Driver', 'Mason', 'Carpenter', 'Steel Fixer', 'Welder', 'Electrician', 'Plumber', 'Painter',
        'Tiler', 'Roofer', 'Gypsum Installer', 'Glazier', 'HVAC Technician', 'Machine Operator',
        'General Labourer', 'Skilled Worker', 'Semi-Skilled Worker', 'Apprentice', 'Security Officer',
        'Cleaner', 'Messenger', 'Other',
      ].map(p => ({ label: p, value: p }));
    },
    netSalaryPreview() {
      const basic = parseFloat(this.form.basic_salary) || 0;
      const allowances = parseFloat(this.form.allowances) || 0;
      const deductions = parseFloat(this.form.deductions) || 0;
      return basic + allowances - deductions;
    },
    photoPreviewUrl() {
      if (this.photoRemoved) return null;
      if (this.photoFile) return URL.createObjectURL(this.photoFile);
      return this.staff && this.staff.photo_url ? this.staff.photo_url : null;
    },
  },

  methods: {
    async removeAttachment(file) {
      if (!(await this.$confirm(this.$t('Remove this attachment? This cannot be undone.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(file.delete_url, {
        preserveScroll: true,
        onSuccess: () => {
          this.staff.attachments = this.staff.attachments.filter(f => f.id !== file.id);
        },
      });
    },
    showAttachmentZoom(event, file) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.attachmentZoom = {
        show: true,
        src: file.preview_url,
        name: file.name,
        isImage: file.is_image,
        x: rect.right + 14,
        y: rect.top + rect.height / 2,
      };
    },
    hideAttachmentZoom() {
      this.attachmentZoom.show = false;
    },
    onPhotoChosen(e) {
      const file = e.target.files && e.target.files[0];
      if (file) {
        this.photoFile = file;
        this.photoRemoved = false;
      }
    },
    async removePhoto() {
      if (!this.photoFile && this.staff && this.staff.photo_url) {
        if (!(await this.$confirm(this.$t('Remove the current photo?'), { variant: 'danger' }))) {
          return;
        }
        this.$inertia.delete(route('staffs.photo.destroy', this.staff.id), {
          preserveScroll: true,
          onSuccess: () => {
            this.staff.photo_url = null;
          },
        });
        return;
      }
      this.photoFile = null;
      this.photoRemoved = true;
      this.$refs.photoInput.value = '';
    },
    submit() {
      this.form.transform(data => ({
        ...data,
        photo: this.photoFile,
        attachments: this.selectedFiles,
        ...(this.staff ? { _method: 'put' } : {}),
      }));

      if (this.staff) {
        this.form.post(route('staffs.update', this.staff.id));
      } else {
        this.form.post(route('staffs.store'));
      }
    },
  },
};
</script>

<style scoped>
.fx-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.fx-form :deep(.lg-fields select),
.fx-form :deep(.lg-fields textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.fx-form :deep(.lg-fields select:hover) {
  border-color: rgba(99, 102, 241, 0.6) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #6366f1 !important;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18) !important;
}

.stf-identity { display: flex; flex-direction: column; gap: 1.25rem; align-items: flex-start; }
.stf-identity-fields { flex: 1 1 auto; width: 100%; min-width: 0; }
@media (min-width: 768px) {
  .stf-identity { flex-direction: row; }
}

.stf-photo-picker {
  position: relative; width: 168px; height: 196px; border-radius: 1rem; border: 2px dashed #c7d2fe; background: #f5f7ff;
  display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; transition: border-color 0.15s ease;
  animation: stf-photo-outline-pulse 2.4s ease-in-out infinite;
}
.stf-photo-picker:hover { border-color: #818cf8; animation-play-state: paused; }
@keyframes stf-photo-outline-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.35); border-color: #c7d2fe; }
  50% { box-shadow: 0 0 0 6px rgba(99, 102, 241, 0); border-color: #818cf8; }
}
.stf-photo-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
.stf-photo-picker:hover .stf-photo-img { transform: scale(1.45); }
.stf-photo-placeholder { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; color: #818cf8; }
.stf-photo-remove, .stf-photo-download {
  position: absolute; display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; min-width: 26px; min-height: 26px; padding: 0; margin: 0; border: none;
  border-radius: 9999px; color: #fff; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.25); line-height: 1;
}
.stf-photo-remove :deep(div), .stf-photo-download :deep(div),
.stf-photo-remove :deep(svg), .stf-photo-download :deep(svg) {
  width: 13px; height: 13px; flex: none; display: flex; align-items: center; justify-content: center;
}
.stf-photo-remove { top: 6px; right: 6px; background: rgba(190, 18, 60, 0.92); }
.stf-photo-remove:hover { background: rgba(190, 18, 60, 1); }
.stf-photo-download { top: 6px; left: 6px; background: rgba(30, 64, 175, 0.92); }
.stf-photo-download:hover { background: rgba(30, 64, 175, 1); }

.stf-net-salary-box {
  padding: 0.55rem 0.9rem; border-radius: 14px;
  background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.28);
}
.stf-net-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.stf-net-value { font-size: 1.15rem; font-weight: 800; color: #047857; font-variant-numeric: tabular-nums; }

.stf-files { margin-top: 0.9rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.stf-file { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.55rem 0.8rem; font-size: 0.84rem; color: var(--lg-ink); }
.stf-file + .stf-file { border-top: 1px solid var(--lg-line); }
.stf-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lg-mute); }

.stf-att-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.85rem; height: 1.85rem; flex-shrink: 0; padding: 0;
  color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 0.5rem; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.stf-att-icon-btn:hover { background: var(--lg-soft); color: var(--lg-ink); transform: translateY(-1px); }
.stf-att-icon-btn-danger:hover { background: #ffe4e6; color: #be123c; border-color: #fecdd3; }

.stf-att-zoom {
  position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999;
  background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; max-width: 220px;
}
.stf-att-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.stf-att-zoom img { display: block; width: 200px; max-height: 260px; object-fit: contain; border-radius: 0.7rem; background: #f1f5f9; }
.stf-att-zoom-file {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.4rem; width: 200px; height: 140px;
  background: #f1f5f9; color: #94a3b8; border-radius: 0.7rem; font-size: 0.72rem; text-align: center; padding: 0.5rem;
}
.stf-att-zoom-name { text-align: center; font-size: 0.75rem; font-weight: 700; color: #374151; margin-top: 0.5rem; word-break: break-word; }

html.dark .stf-photo-picker { background: rgba(255, 255, 255, 0.04); border-color: rgba(129, 140, 248, 0.35); }
html.dark .stf-net-salary-box { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.3); }
html.dark .stf-net-value { color: #6ee7b7; }
html.dark .stf-att-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .stf-att-zoom-name { color: #e2e8f0; }
</style>
