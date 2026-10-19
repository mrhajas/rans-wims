<template>
  <admin-layout :title="$t('Task Reminders')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clock" :title="$t('My Reminders')" :subtitle="$t('Never miss a task. We\'ll notify you when it\'s due.')">
        <button type="button" class="lg-btn lg-btn-soft" @click="showReport = true">
          <icons name="doc-text" class="w-4 h-4" />
          {{ $t('Report') }}
        </button>
        <button type="button" class="lg-btn lg-btn-primary" @click="openCreate">
          <icons name="plus" class="w-4 h-4" />
          {{ $t('New Reminder') }}
        </button>
      </page-header>

      <stat-tiles :items="statItems" />

      <p v-if="!emailEnabled" class="lg-note lg-note-blue mb-4">
        <icons name="email" class="w-3.5 h-3.5" />
        <span>
          {{ $t('Email reminders are off.') }}
          <Link :href="route('profile.show')" class="font-bold underline">{{ $t('Turn on in your profile') }}</Link>
        </span>
      </p>

      <section class="lg-card lg-filters tr-filters">
        <div class="flex flex-wrap items-center gap-2 flex-1">
          <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            class="tr-tab"
            :class="{ 'tr-tab-active': filter === tab.value }"
            @click="filter = tab.value"
          >
            {{ tab.label }}
            <span class="tr-tab-count">{{ tab.count }}</span>
          </button>
        </div>
        <div class="relative w-full sm:w-64">
          <icons name="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            v-model="search"
            type="text"
            :placeholder="$t('Search reminders...')"
            class="tr-search-input w-full pl-9 pr-3 py-2 text-sm rounded-xl"
          />
        </div>
      </section>

      <div v-if="!filteredReminders.length" class="lg-card tr-empty text-center py-16">
        <div class="inline-flex items-center justify-center h-16 w-16 rounded-2xl lg-ico-navy mb-4">
          <icons name="clock" class="w-8 h-8" />
        </div>
        <p class="lg-card-sub">{{ $t('No reminders here yet.') }}</p>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          :id="`reminder-${reminder.id}`"
          v-for="reminder in filteredReminders"
          :key="reminder.id"
          class="tr-card"
          :class="{ 'tr-card-overdue': isOverdue(reminder), 'tr-card-done': reminder.is_completed, 'tr-card-highlight': highlightedId === reminder.id }"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2.5 min-w-0">
              <span class="tr-priority-badge" :class="`tr-priority-${reminder.priority}`">
                <icons :name="priorityIcon(reminder.priority)" class="w-3.5 h-3.5" />
              </span>
              <div class="min-w-0">
                <div class="font-semibold tr-name truncate" :class="{ 'line-through text-gray-400': reminder.is_completed }">
                  {{ reminder.name }}
                </div>
                <div class="text-xs mt-0.5" :class="isOverdue(reminder) ? 'text-red-600 font-semibold' : 'text-gray-400'">
                  {{ formatDue(reminder.due_at) }}
                </div>
              </div>
            </div>
            <button
              type="button"
              class="tr-check lg-tip flex-none"
              :class="{ 'tr-check-done': reminder.is_completed }"
              :data-tip="reminder.is_completed ? $t('Mark as not done') : $t('Mark as done')"
              :aria-label="reminder.is_completed ? $t('Mark as not done') : $t('Mark as done')"
              @click="toggleComplete(reminder)"
            >
              <icons name="tick" class="w-4 h-4" />
            </button>
          </div>

          <span v-if="reminder.category" class="tr-category-chip mt-3">
            <icons :name="categoryIcon(reminder.category)" class="w-3 h-3" />
            {{ reminder.category }}
          </span>

          <p v-if="reminder.notes" class="text-sm text-gray-500 mt-3 line-clamp-2">{{ reminder.notes }}</p>

          <div v-if="reminder.attachments && reminder.attachments.length" class="mt-3 flex flex-wrap gap-1.5">
            <a
              v-for="att in reminder.attachments"
              :key="att.id"
              :href="route('task-reminders.attachments.download', { task_reminder: reminder.id, attachment: att.id })"
              class="tr-attachment-chip"
            >
              <icons name="clip" class="w-3 h-3" />
              {{ att.filename }}
            </a>
          </div>

          <div v-if="reminder.user && reminder.user.name" class="text-[11px] text-gray-400 mt-3 flex items-center gap-1">
            <icons name="user" class="w-3 h-3" />
            {{ reminder.user.name }}
          </div>

          <div class="tr-card-actions">
            <button type="button" class="lg-btn lg-btn-soft" @click="openEdit(reminder)">
              <icons name="edit" class="w-3.5 h-3.5" />
              {{ $t('Edit') }}
            </button>
            <button type="button" class="lg-btn lg-btn-soft tr-btn-del" @click="askDelete(reminder)">
              <icons name="trash" class="w-3.5 h-3.5" />
              {{ $t('Delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <modal :show="showForm" :max-width="'3xl'" :transparent="true" @close="closeForm">
      <div class="tr-modal-panel">
        <div class="tr-modal-header">
          <span class="tr-modal-header-icon">
            <icons name="clock" class="w-5 h-5" />
          </span>
          <div class="min-w-0 flex-1">
            <div class="tr-modal-title">{{ editing ? $t('Edit Reminder') : $t('New Reminder') }}</div>
            <div class="tr-modal-subtitle">{{ $t("Set a name, date, and priority. We'll take it from there.") }}</div>
          </div>
          <button type="button" class="tr-modal-close-btn" :aria-label="$t('Close')" @click="closeForm">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>

        <div class="tr-modal-body">
          <div class="tr-field-group" style="--tr-stagger: 0">
            <span class="tr-field-label"><icons name="edit" class="w-3.5 h-3.5" />{{ $t('Name') }}</span>
            <text-input
              v-model="form.name"
              :placeholder="$t('What do you need to remember?')"
              :error="form.errors.name"
              class="tr-field-bare"
            />
          </div>

          <div class="tr-field-group" style="--tr-stagger: 1">
            <span class="tr-field-label"><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Schedule') }}</span>
            <div class="grid grid-cols-2 gap-3">
              <div class="relative">
                <date-picker v-model="form.due_date" class="w-full" :placeholder="$t('Due Date')" />
                <tec-input-error v-if="form.errors.due_date" :message="form.errors.due_date" class="mt-1" />
              </div>
              <div class="relative">
                <icons name="clock" class="tr-input-icon" />
                <input v-model="form.due_time" type="time" class="tr-modal-field tr-modal-field-icon w-full rounded-xl" />
                <tec-input-error v-if="form.errors.due_time" :message="form.errors.due_time" class="mt-1" />
              </div>
            </div>
          </div>

          <div class="tr-field-group" style="--tr-stagger: 2">
            <span class="tr-field-label"><icons name="exclamation" class="w-3.5 h-3.5" />{{ $t('Priority') }}</span>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
              <button
                v-for="p in priorities"
                :key="p.value"
                type="button"
                class="tr-priority-chip"
                :class="[`tr-priority-chip-${p.value}`, { 'tr-priority-chip-active': form.priority === p.value }]"
                @click="form.priority = p.value"
              >
                <span class="tr-priority-chip-icon"><icons :name="p.icon" class="w-3.5 h-3.5" /></span>
                {{ p.label }}
              </button>
            </div>
          </div>

          <div class="tr-field-group" style="--tr-stagger: 3">
            <span class="tr-field-label"><icons name="folder" class="w-3.5 h-3.5" />{{ $t('Category') }}</span>
            <creatable-select
              v-model="form.category"
              :options="categories"
              :placeholder="$t('Search or type...')"
              :error="form.errors.category"
            />
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="tr-field-group" style="--tr-stagger: 4">
              <span class="tr-field-label"><icons name="doc-text" class="w-3.5 h-3.5" />{{ $t('Notes') }}</span>
              <textarea-input class="tr-notes-input tr-field-bare" v-model="form.notes" rows="3" :error="form.errors.notes" />
            </div>

            <div class="tr-field-group" style="--tr-stagger: 5">
              <span class="tr-field-label"><icons name="clip" class="w-3.5 h-3.5" />{{ $t('Attachment') }}</span>
              <attachment-picker v-model="form.attachments" :max-files="1" :max-size-kb="5120" :help-text="$t('Up to 5MB, any common file type.')" />
            </div>
          </div>
        </div>

        <div class="tr-modal-footer">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeForm">{{ $t('Cancel') }}</button>
          <loading-button type="button" class="tr-submit-btn" :loading="form.processing" :disabled="form.processing" @click="submitForm">
            <icons name="tick" class="w-4 h-4 mr-1.5 -ml-0.5" />
            {{ editing ? $t('Save') : $t('Create Reminder') }}
          </loading-button>
        </div>
      </div>
    </modal>

    <Dialog
      v-if="deleting"
      :show="!!deleting"
      :title="$t('Delete Reminder?')"
      :content="$t('This reminder will be moved to trash.')"
      :close="() => (deleting = null)"
      :action="confirmDelete"
      action-type="delete"
      :action-text="$t('Delete')"
    />

    <modal :show="showReport" :max-width="'sm'" :transparent="true" @close="showReport = false">
      <div class="tr-modal-panel">
        <div class="tr-modal-header">
          <span class="tr-modal-header-icon">
            <icons name="doc-text" class="w-5 h-5" />
          </span>
          <div class="min-w-0 flex-1">
            <div class="tr-modal-title">{{ $t('Reminders Report') }}</div>
          </div>
          <button type="button" class="tr-modal-close-btn" :aria-label="$t('Close')" @click="showReport = false">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>

        <div class="tr-modal-body">
          <p class="text-sm text-gray-500 mb-4">{{ $t('Choose a date range, then print or download the report. Leave blank for all reminders.') }}</p>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <tec-label :value="$t('From')" />
              <date-picker v-model="reportFrom" class="w-full mt-1" />
            </div>
            <div>
              <tec-label :value="$t('To')" />
              <date-picker v-model="reportTo" class="w-full mt-1" />
            </div>
          </div>
        </div>

        <div class="tr-modal-footer">
          <button type="button" class="lg-btn lg-btn-soft" @click="showReport = false">{{ $t('Cancel') }}</button>
          <div class="flex items-center gap-2">
            <a :href="reportPrintUrl" target="_blank" class="lg-btn lg-btn-dark">
              <icons name="printer" class="w-4 h-4" />
              {{ $t('Print') }}
            </a>
            <a :href="reportPdfUrl" class="lg-btn lg-btn-primary">
              <icons name="download" class="w-4 h-4" />
              {{ $t('Download') }}
            </a>
          </div>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import Modal from '@/Jetstream/Modal.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import CreatableSelect from '@/Shared/CreatableSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import { Link } from '@inertiajs/vue3';

const CATEGORY_ICONS = {
  General: 'list',
  Meeting: 'users',
  'Site Visit': 'truck',
  Procurement: 'cart',
  Maintenance: 'cog',
  Inspection: 'eye-flat',
  Documentation: 'doc-text',
  'Follow-up': 'refresh',
  Deadline: 'clock',
  Personal: 'user',
};

export default {
  props: {
    reminders: { type: Array, default: () => [] },
    emailEnabled: { type: Boolean, default: false },
  },

  components: {
    Dialog,
    Modal,
    TecLabel,
    TextInput,
    AdminLayout,
    LoadingButton,
    TecInputError,
    TextareaInput,
    AttachmentPicker,
    CreatableSelect,
    DatePicker,
    PageHeader,
    StatTiles,
    Link,
  },

  data() {
    return {
      filter: 'upcoming',
      search: '',
      showForm: false,
      showReport: false,
      reportFrom: '',
      reportTo: '',
      editing: null,
      deleting: null,
      highlightedId: null,
      categories: Object.keys(CATEGORY_ICONS),
      priorities: [
        { value: 'low', label: this.$t('Low'), icon: 'down' },
        { value: 'normal', label: this.$t('Normal'), icon: 'toggle' },
        { value: 'high', label: this.$t('High'), icon: 'up' },
        { value: 'urgent', label: this.$t('Urgent'), icon: 'exclamation' },
      ],
      form: this.$inertia.form({
        name: '',
        due_date: '',
        due_time: '',
        priority: 'normal',
        category: '',
        notes: '',
        attachments: [],
      }),
    };
  },

  computed: {
    stats() {
      return {
        total: this.reminders.length,
        upcoming: this.reminders.filter(r => !r.is_completed && !this.isOverdue(r)).length,
        overdue: this.reminders.filter(r => !r.is_completed && this.isOverdue(r)).length,
        completed: this.reminders.filter(r => r.is_completed).length,
      };
    },
    statItems() {
      return [
        { label: this.$t('Total'), value: this.stats.total, icon: 'list', tone: 'slate' },
        { label: this.$t('Upcoming'), value: this.stats.upcoming, icon: 'clock', tone: 'navy' },
        { label: this.$t('Overdue'), value: this.stats.overdue, icon: 'exclamation', tone: 'rose' },
        { label: this.$t('Completed'), value: this.stats.completed, icon: 'tick', tone: 'emerald' },
      ];
    },
    tabs() {
      return [
        { value: 'upcoming', label: this.$t('Upcoming'), count: this.stats.upcoming },
        { value: 'overdue', label: this.$t('Overdue'), count: this.stats.overdue },
        { value: 'completed', label: this.$t('Completed'), count: this.stats.completed },
        { value: 'all', label: this.$t('All'), count: this.stats.total },
      ];
    },
    filteredReminders() {
      let list = this.reminders;

      if (this.filter === 'upcoming') list = list.filter(r => !r.is_completed && !this.isOverdue(r));
      else if (this.filter === 'overdue') list = list.filter(r => !r.is_completed && this.isOverdue(r));
      else if (this.filter === 'completed') list = list.filter(r => r.is_completed);

      const q = this.search.trim().toLowerCase();
      if (q) {
        list = list.filter(
          r =>
            r.name.toLowerCase().includes(q) ||
            (r.notes || '').toLowerCase().includes(q) ||
            (r.category || '').toLowerCase().includes(q) ||
            (r.user?.name || '').toLowerCase().includes(q)
        );
      }

      return list;
    },
    reportPrintUrl() {
      return this.route('task-reminders.print', this.reportParams);
    },
    reportPdfUrl() {
      return this.route('task-reminders.pdf', this.reportParams);
    },
    reportParams() {
      const params = {};
      if (this.reportFrom) params.from = this.reportFrom;
      if (this.reportTo) params.to = this.reportTo;
      return params;
    },
  },

  mounted() {
    const params = new URLSearchParams(window.location.search);
    const highlightId = params.get('highlight');
    if (!highlightId) {
      return;
    }

    const reminder = this.reminders.find(r => String(r.id) === String(highlightId));
    if (!reminder) {
      return;
    }

    this.search = '';
    if (reminder.is_completed) this.filter = 'completed';
    else if (this.isOverdue(reminder)) this.filter = 'overdue';
    else this.filter = 'upcoming';
    this.highlightedId = reminder.id;

    this.$nextTick(() => {
      const el = document.getElementById(`reminder-${reminder.id}`);
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    setTimeout(() => {
      this.highlightedId = null;
    }, 4000);
  },

  methods: {
    isOverdue(reminder) {
      return !reminder.is_completed && new Date(reminder.due_at) < new Date();
    },
    priorityIcon(priority) {
      return this.priorities.find(p => p.value === priority)?.icon || 'toggle';
    },
    categoryIcon(category) {
      return CATEGORY_ICONS[category] || 'hash';
    },
    formatDue(dueAt) {
      const date = new Date(dueAt);
      return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    },
    openCreate() {
      this.editing = null;
      this.form.reset();
      this.form.clearErrors();
      this.form.priority = 'normal';
      this.form.category = '';
      const now = new Date();
      this.form.due_date = now.toISOString().slice(0, 10);
      this.form.due_time = '09:00';
      this.showForm = true;
    },
    openEdit(reminder) {
      this.editing = reminder;
      this.form.clearErrors();
      const due = new Date(reminder.due_at);
      this.form.name = reminder.name;
      this.form.notes = reminder.notes || '';
      this.form.priority = reminder.priority;
      this.form.category = reminder.category || '';
      this.form.due_date = due.toISOString().slice(0, 10);
      this.form.due_time = due.toTimeString().slice(0, 5);
      this.form.attachments = [];
      this.showForm = true;
    },
    closeForm() {
      this.showForm = false;
    },
    submitForm() {
      const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => this.closeForm(),
      };

      if (this.editing) {
        this.form.transform(data => ({ ...data, _method: 'put' })).post(this.route('task-reminders.update', this.editing.id), options);
      } else {
        this.form.post(this.route('task-reminders.store'), options);
      }
    },
    toggleComplete(reminder) {
      this.$inertia.put(this.route('task-reminders.toggle-complete', reminder.id), {}, { preserveScroll: true });
    },
    askDelete(reminder) {
      this.deleting = reminder;
    },
    confirmDelete() {
      this.$inertia.delete(this.route('task-reminders.destroy', this.deleting.id), {
        preserveScroll: true,
        onFinish: () => (this.deleting = null),
      });
    },
  },
};
</script>

<style scoped>
.tr-filters { display: flex; flex-direction: column; gap: 12px; position: relative; z-index: 2; }
@media (min-width: 640px) { .tr-filters { flex-direction: row; align-items: center; } }
.tr-name { font-weight: 800; color: var(--lg-ink); }
.tr-search-input {
  border: 1px solid var(--lg-line);
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.tr-search-input:focus {
  outline: none;
  border-color: #a5b4fc;
  box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.25);
}

.tr-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 800;
  color: var(--lg-mute);
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
  transition: all 0.15s ease;
}
.tr-tab:hover {
  border-color: #c7d2fe;
  color: #4338ca;
  transform: translateY(-1px);
}
.tr-tab-active {
  background: linear-gradient(145deg, #2f6fb3, #0f2540);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9);
}
.tr-tab-active:hover { color: #fff; }
.tr-tab-count {
  font-size: 0.7rem;
  opacity: 0.75;
}

.tr-card {
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  border-radius: 20px;
  padding: 16px;
  box-shadow: var(--lg-shadow);
  animation: tr-card-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) both;
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.tr-card:hover {
  transform: translateY(-2px);
}
.tr-card-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--lg-line); }
.tr-btn-del { color: #b91c1c; }
html.dark .tr-btn-del { color: #fca5a5; }
.tr-card-overdue {
  border-color: #fecaca;
  animation: tr-card-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) both, tr-overdue-pulse 2.4s ease-in-out infinite;
}
.tr-card-done {
  opacity: 0.6;
}
.tr-card-highlight {
  animation: tr-card-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) both, tr-highlight-glow 1.6s ease-in-out 2;
  border-color: #a5b4fc;
}

@keyframes tr-card-in {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
@keyframes tr-overdue-pulse {
  0%,
  100% {
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 0 0 0 rgba(239, 68, 68, 0.12);
  }
  50% {
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 0 0 6px rgba(239, 68, 68, 0.06);
  }
}
@keyframes tr-highlight-glow {
  0%,
  100% {
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 0 0 0 rgba(99, 102, 241, 0.18);
  }
  50% {
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 0 0 8px rgba(99, 102, 241, 0.14);
  }
}

.tr-category-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px;
  border-radius: 8px;
  background: rgba(99, 102, 241, 0.1);
  color: #4338ca;
  font-size: 0.7rem;
  font-weight: 600;
}

.tr-priority-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 30px;
  width: 30px;
  border-radius: 10px;
  flex-none: none;
}
.tr-priority-low {
  background: #f1f5f9;
  color: #64748b;
}
.tr-priority-normal {
  background: #dbeafe;
  color: #2563eb;
}
.tr-priority-high {
  background: #fef3c7;
  color: #d97706;
}
.tr-priority-urgent {
  background: #fee2e2;
  color: #dc2626;
}

.tr-priority-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 10px;
  border-radius: 9999px;
  font-size: 0.76rem;
  font-weight: 600;
  background: rgba(255, 255, 255, 0.55);
  border: 1px solid rgba(255, 255, 255, 0.8);
  color: #6b7280;
  white-space: nowrap;
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  transition: transform 0.15s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.tr-priority-chip:hover {
  transform: translateY(-1px);
}
.tr-priority-chip:active {
  transform: scale(0.96);
}
.tr-priority-chip-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 18px;
  width: 18px;
  border-radius: 9999px;
  background: rgba(100, 116, 139, 0.14);
  flex: none;
  transition: background 0.15s ease, transform 0.15s ease;
}
.tr-priority-chip-active {
  transform: translateY(-1px) scale(1.03);
}
.tr-priority-chip-low.tr-priority-chip-active {
  background: #f1f5f9;
  border-color: #cbd5e1;
  color: #475569;
  box-shadow: 0 6px 14px -4px rgba(100, 116, 139, 0.35);
}
.tr-priority-chip-normal.tr-priority-chip-active {
  background: #dbeafe;
  border-color: #93c5fd;
  color: #1d4ed8;
  box-shadow: 0 6px 14px -4px rgba(37, 99, 235, 0.4);
}
.tr-priority-chip-high.tr-priority-chip-active {
  background: #fef3c7;
  border-color: #fcd34d;
  color: #b45309;
  box-shadow: 0 6px 14px -4px rgba(217, 119, 6, 0.4);
}
.tr-priority-chip-urgent.tr-priority-chip-active {
  background: #fee2e2;
  border-color: #fca5a5;
  color: #b91c1c;
  box-shadow: 0 6px 14px -4px rgba(220, 38, 38, 0.4);
}
.tr-priority-chip-active .tr-priority-chip-icon {
  background: rgba(255, 255, 255, 0.65);
  transform: scale(1.1);
}

.tr-check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  width: 26px;
  border-radius: 9999px;
  border: 1.5px solid #d1d5db;
  background: #fff;
  color: #cbd5e1;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.tr-check:hover {
  border-color: #a5b4fc;
  color: #818cf8;
  background: #eef2ff;
}
.tr-check-done {
  background: #10b981;
  border-color: #10b981;
  color: #fff;
  transform: scale(1.05);
}
.tr-check-done:hover {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.tr-notes-input :deep(textarea) {
  min-height: 84px;
  resize: vertical;
}

.tr-modal-panel {
  background: linear-gradient(160deg, rgba(255, 255, 255, 0.85), rgba(238, 242, 255, 0.74));
  backdrop-filter: blur(32px) saturate(180%);
  -webkit-backdrop-filter: blur(32px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.7);
  border-radius: 22px;
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 30px 70px -16px rgba(79, 70, 229, 0.32), 0 12px 24px -8px rgba(15, 23, 42, 0.16),
    inset 0 1px 0 rgba(255, 255, 255, 0.7);
  overflow: hidden;
  text-align: left;
  animation: tr-modal-pop 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes tr-modal-pop {
  from {
    opacity: 0;
    transform: scale(0.96) translateY(6px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
.tr-modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.5);
}
.tr-modal-header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  width: 42px;
  border-radius: 14px;
  flex: none;
  background: linear-gradient(145deg, #3b82f6, #1c3f66);
  color: #fff;
  box-shadow: 0 8px 18px -6px rgba(79, 70, 229, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.35);
  animation: tr-icon-float 3s ease-in-out infinite;
}
@keyframes tr-icon-float {
  0%,
  100% {
    transform: translateY(0) rotate(0deg);
  }
  50% {
    transform: translateY(-2px) rotate(-4deg);
  }
}
.tr-modal-title {
  font-size: 1.08rem;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.25;
}
.tr-modal-subtitle {
  font-size: 0.76rem;
  color: #6b7280;
  margin-top: 1px;
}
.tr-modal-close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 30px;
  width: 30px;
  min-height: 0;
  padding: 0;
  border-radius: 10px;
  flex: none;
  background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.8);
  color: #64748b;
  transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.tr-modal-close-btn:hover {
  background: #fee2e2;
  color: #dc2626;
  transform: rotate(90deg);
}
.tr-modal-body {
  padding: 18px 22px 20px;
  max-height: 74vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.tr-modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 22px;
  background: rgba(255, 255, 255, 0.35);
  border-top: 1px solid rgba(255, 255, 255, 0.5);
}
.tr-modal-cancel-btn {
  background: rgba(255, 255, 255, 0.6);
  color: #374151;
  border: 1px solid rgba(255, 255, 255, 0.8);
  min-height: 0;
  transition: background 0.15s ease, transform 0.15s ease;
}
.tr-modal-cancel-btn:hover {
  background: rgba(255, 255, 255, 0.9);
}
.tr-modal-cancel-btn:active {
  transform: scale(0.97);
}
.tr-submit-btn {
  background: linear-gradient(145deg, #2f6fb3, #0f2540);
  border: none;
  border-radius: 12px;
  padding: 10px 18px;
  min-height: 0;
  text-transform: none;
  letter-spacing: normal;
  box-shadow: 0 8px 18px -5px rgba(124, 58, 237, 0.55);
  transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.tr-submit-btn:hover:not(:disabled) {
  background: linear-gradient(145deg, #3b82f6, #0f2540);
  transform: translateY(-1px);
  box-shadow: 0 12px 24px -5px rgba(124, 58, 237, 0.65);
}
.tr-submit-btn:active:not(:disabled) {
  transform: translateY(0) scale(0.98);
}

.tr-field-group {
  /* `backwards` (not `both`): holds the "from" keyframe during the stagger
     delay so fields don't flash visible before fading in, but does NOT hold
     the "to" keyframe after the animation ends. A held `transform` value —
     even a no-op `translateY(0)` — creates a new stacking context, which
     would trap the Category dropdown's z-index inside this group and stop
     it floating over later sibling groups (e.g. Notes/Attachment). Reverting
     to the element's real (transform-less) styles after the animation avoids
     that entirely, with no visible difference since the "to" state is the
     same as the element's natural resting position anyway. */
  animation: tr-field-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) backwards;
  animation-delay: calc(var(--tr-stagger, 0) * 45ms);
}
@keyframes tr-field-in {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.tr-field-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.76rem;
  font-weight: 700;
  color: #4f46e5;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  margin-bottom: 6px;
}
.tr-field-bare {
  margin-bottom: 0 !important;
}
.tr-input-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  color: #818cf8;
  pointer-events: none;
  z-index: 1;
}
.tr-modal-field {
  border: 1px solid rgba(15, 23, 42, 0.1);
  background: rgba(255, 255, 255, 0.65);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  padding: 9px 12px;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.tr-modal-field-icon {
  padding-left: 34px;
}
.tr-modal-field:focus {
  border-color: #a5b4fc;
  background: rgba(255, 255, 255, 0.9);
  box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.28);
  outline: none;
}
.tr-modal-body :deep(input:not([type='checkbox']):not([type='radio'])),
.tr-modal-body :deep(textarea) {
  background: rgba(255, 255, 255, 0.65);
  border-color: rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.tr-modal-body :deep(input:not([type='checkbox']):not([type='radio']):focus),
.tr-modal-body :deep(textarea:focus) {
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.28);
}

.tr-attachment-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #eef1f6;
  font-size: 0.7rem;
  color: #64748b;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tr-attachment-chip:hover {
  background: #eef2ff;
  color: #4338ca;
}


/* ---- Clean white modal (New / Edit Reminder, Report) ---- */
.tr-modal-panel {
  background: #fff;
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
  border: 1px solid #e8edf5;
  border-radius: 24px;
  box-shadow: 0 30px 70px -24px rgba(15, 23, 42, 0.35), 0 4px 12px -4px rgba(15, 23, 42, 0.08);
}
.tr-modal-header { padding: 20px 26px 16px; border-bottom: 1px solid #eef2f7; background: #fff; }
.tr-modal-header-icon { height: 40px; width: 40px; border-radius: 12px; background: #3b82f6; box-shadow: none; animation: none; }
.tr-modal-title { font-size: 1.08rem; font-weight: 600; color: #0f172a; }
.tr-modal-subtitle { font-size: 0.78rem; font-weight: 400; color: #94a3b8; }
.tr-modal-close-btn { height: 34px; width: 34px; border-radius: 9999px; background: #f4f6fa; border: 0; color: #64748b; }
.tr-modal-close-btn:hover { background: #fee2e2; color: #dc2626; }
.tr-modal-body { padding: 20px 26px 24px; gap: 18px; background: #fff; }
.tr-field-label { gap: 7px; margin-bottom: 8px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.06em; color: #64748b; }
.tr-field-label :deep(svg) { color: #3b82f6; }
.tr-modal-body :deep(input:not([type='checkbox']):not([type='radio'])),
.tr-modal-body :deep(textarea),
.tr-modal-field {
  background: #fff;
  border: 1px solid #dfe5ef;
  border-radius: 12px;
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
  box-shadow: none;
  color: #0f172a;
}
.tr-modal-body :deep(input:not([type='checkbox']):not([type='radio']):focus),
.tr-modal-body :deep(textarea:focus),
.tr-modal-field:focus { background: #fff; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12); outline: none; }
.tr-input-icon { color: #94a3b8; }
.tr-priority-chip { padding: 9px 10px; background: #fff; border: 1px solid #dfe5ef; color: #64748b; font-weight: 500; backdrop-filter: none; -webkit-backdrop-filter: none; border-radius: 12px; }
.tr-priority-chip:hover { border-color: #c3cde0; background: #f8fafc; }
.tr-priority-chip-icon { background: #f1f5f9; }
.tr-priority-chip-active { transform: none; font-weight: 600; }
.tr-priority-chip-low.tr-priority-chip-active { background: #f1f5f9; border-color: #94a3b8; color: #334155; box-shadow: none; }
.tr-priority-chip-normal.tr-priority-chip-active { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; box-shadow: none; }
.tr-priority-chip-high.tr-priority-chip-active { background: #fffbeb; border-color: #f59e0b; color: #b45309; box-shadow: none; }
.tr-priority-chip-urgent.tr-priority-chip-active { background: #fef2f2; border-color: #ef4444; color: #b91c1c; box-shadow: none; }
.tr-priority-chip-active .tr-priority-chip-icon { background: #fff; transform: none; }
.tr-modal-footer { padding: 16px 26px; background: #fafbfd; border-top: 1px solid #eef2f7; }
.tr-submit-btn { background: #3b82f6; border-radius: 12px; padding: 10px 20px; font-weight: 600; box-shadow: 0 8px 16px -8px rgba(59, 130, 246, 0.8); }
.tr-submit-btn:hover:not(:disabled) { background: #2563eb; box-shadow: 0 10px 20px -8px rgba(37, 99, 235, 0.85); }

html.dark .tr-attachment-chip {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.1);
  color: #94a3b8;
}
html.dark .tr-empty {
  color: #94a3b8;
}
html.dark .tr-category-chip {
  background: rgba(129, 140, 248, 0.18);
  color: #c7d2fe;
}
html.dark .tr-modal-panel {
  background: linear-gradient(160deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.9));
  border-color: rgba(255, 255, 255, 0.1);
  box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.06);
}
html.dark .tr-modal-header {
  color: #f1f5f9;
  border-bottom-color: rgba(255, 255, 255, 0.08);
}
html.dark .tr-modal-title {
  color: #f1f5f9;
}
html.dark .tr-modal-subtitle {
  color: #94a3b8;
}
html.dark .tr-modal-close-btn {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #94a3b8;
}
html.dark .tr-modal-close-btn:hover {
  background: rgba(220, 38, 38, 0.18);
  color: #fca5a5;
}
html.dark .tr-field-label {
  color: #a5b4fc;
}
html.dark .tr-priority-chip {
  background: rgba(255, 255, 255, 0.05);
  border-color: rgba(255, 255, 255, 0.1);
  color: #94a3b8;
}
html.dark .tr-priority-chip-icon {
  background: rgba(255, 255, 255, 0.08);
}
html.dark .tr-priority-chip-low.tr-priority-chip-active {
  background: rgba(100, 116, 139, 0.25);
  border-color: rgba(148, 163, 184, 0.4);
  color: #e2e8f0;
}
html.dark .tr-priority-chip-normal.tr-priority-chip-active {
  background: rgba(37, 99, 235, 0.25);
  border-color: rgba(96, 165, 250, 0.45);
  color: #bfdbfe;
}
html.dark .tr-priority-chip-high.tr-priority-chip-active {
  background: rgba(217, 119, 6, 0.25);
  border-color: rgba(251, 191, 36, 0.4);
  color: #fde68a;
}
html.dark .tr-priority-chip-urgent.tr-priority-chip-active {
  background: rgba(220, 38, 38, 0.25);
  border-color: rgba(252, 165, 165, 0.4);
  color: #fecaca;
}
html.dark .tr-modal-footer {
  background: rgba(255, 255, 255, 0.03);
  border-top-color: rgba(255, 255, 255, 0.08);
}
html.dark .tr-modal-cancel-btn {
  background: rgba(255, 255, 255, 0.06);
  color: #cbd5e1;
  border-color: rgba(255, 255, 255, 0.12);
}
html.dark .tr-modal-cancel-btn:hover {
  background: rgba(255, 255, 255, 0.12);
}
html.dark .tr-modal-field,
html.dark .tr-modal-body :deep(input:not([type='checkbox']):not([type='radio'])),
html.dark .tr-modal-body :deep(textarea) {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #e2e8f0;
}
</style>
