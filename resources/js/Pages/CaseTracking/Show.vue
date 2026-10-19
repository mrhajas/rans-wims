<template>
  <admin-layout :title="case_.case_number">
    <div class="lg-page px-4 md:px-0 cs-show">
      <page-header icon="exclamation" :title="case_.case_number" :subtitle="case_.subject">
        <span class="lg-chip" :class="statusChip(case_.status)" style="text-transform: none">{{ case_.status }}</span>
        <span v-if="case_.priority" class="lg-chip" :class="priorityChip(case_.priority.color)" style="text-transform: none">{{ case_.priority.name }}</span>
        <Link :href="route('case-tracking.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Case Tracking') }}</Link>
      </page-header>

      <div class="lg-card cs-actions">
        <div class="cs-action-row">
          <Link v-if="can.update" :href="route('case-tracking.edit', case_.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /> {{ $t('Edit') }}</Link>
          <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="act('submit')"><icons name="tick" class="w-4 h-4" /> {{ $t('Submit') }}</button>
          <button v-for="s in allowedStatuses" :key="s" type="button" class="lg-btn lg-btn-soft" @click="openStatusModal(s)">{{ statusActionLabel(s) }}</button>
          <button v-if="can.assign" type="button" class="lg-btn lg-btn-soft" @click="openAssignModal"><icons name="users" class="w-4 h-4" /> {{ $t('Assign') }}</button>
          <a v-if="can.print" :href="route('case-tracking.print', case_.id)" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}</a>
          <a v-if="can.print" :href="route('case-tracking.pdf', case_.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /> {{ $t('Download PDF') }}</a>
          <spr-confirm-password
            v-if="can.delete"
            :title="$t('Delete Case')"
            :content="case_.status === 'Draft' ? $t('This permanently deletes this draft case. Please confirm your password to continue.') : $t('This case has already been submitted, deleting it is permanent and only allowed for Super Admin. Please confirm your password to continue.')"
            @confirmed="act('destroy')"
          >
            <button type="button" class="lg-btn lg-btn-red"><icons name="trash" class="w-4 h-4" /> {{ $t('Delete') }}</button>
          </spr-confirm-password>
        </div>
      </div>

      <div class="cs-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Description') }}</h2>
              <p class="lg-card-sub">{{ case_.subject }}</p>
            </div>
          </header>
          <p class="cs-desc">{{ case_.description }}</p>

          <dl class="cs-facts">
            <div><dt>{{ $t('Category') }}</dt><dd>{{ case_.category?.name || '—' }}</dd></div>
            <div><dt>{{ $t('Project / Site') }}</dt><dd>{{ case_.warehouse?.name || '—' }}</dd></div>
            <div><dt>{{ $t('Module / Reference') }}</dt><dd>{{ case_.module_reference || '—' }}</dd></div>
            <div v-if="case_.external_url"><dt>{{ $t('External Link') }}</dt><dd><a :href="case_.external_url" target="_blank" class="cs-link break-all">{{ case_.external_url }}</a></dd></div>
            <div><dt>{{ $t('Created By') }}</dt><dd>{{ case_.created_by?.name || '—' }}</dd></div>
            <div><dt>{{ $t('Assigned To') }}</dt><dd>{{ case_.assigned_to?.name || $t('Unassigned') }}</dd></div>
          </dl>

          <div v-if="case_.return_reason && case_.status === 'Returned'" class="lg-note lg-note-red mt-4">
            <icons name="exclamation" class="w-4 h-4" /><span><strong>{{ $t('Returned for correction') }}:</strong> {{ case_.return_reason }}</span>
          </div>
          <div v-if="case_.resolution" class="cs-resolution mt-4">
            <icons name="tick" class="w-4 h-4" />
            <span><strong>{{ $t('Resolution') }}</strong> ({{ case_.resolved_by_name }}, {{ formatDateTime(case_.resolved_at) }}):<br>{{ case_.resolution }}</span>
          </div>
          <div v-if="case_.reopened_count > 0" class="lg-note lg-note-blue mt-4">
            <icons name="info" class="w-4 h-4" /><span>{{ $t('This case has been reopened {count} time(s).', { count: case_.reopened_count }) }}</span>
          </div>

          <div v-if="case_.attachments.length" class="cs-attach">
            <div class="cs-label mb-2">{{ $t('Attachments') }}</div>
            <div class="flex flex-wrap gap-2">
              <div v-for="a in case_.attachments" :key="a.id" class="cs-attachment-chip">
                <a :href="a.download_url" class="flex items-center gap-1.5"><icons :name="a.is_image ? 'view' : 'doc-text'" class="w-3.5 h-3.5" /> {{ a.name }}</a>
                <button v-if="can.attach" type="button" class="cs-attachment-remove lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeAttachment(a)"><icons name="cross" class="w-2.5 h-2.5" /></button>
              </div>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Timeline') }}</h2></div>
          </header>
          <div v-if="timeline.length" class="cs-timeline">
            <div v-for="t in timeline" :key="t.id" class="cs-timeline-item">
              <div class="cs-timeline-dot"></div>
              <div class="cs-tl-text">{{ t.description }}</div>
              <div class="cs-tl-meta">{{ t.causer_name }} · {{ formatDateTime(t.created_at) }}</div>
            </div>
          </div>
          <p v-else class="cs-muted">{{ $t('No activity yet.') }}</p>
        </section>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="email" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Comments') }}</h2></div>
        </header>
        <div v-if="case_.comments.length" class="cs-comments">
          <div v-for="c in case_.comments" :key="c.id" class="cs-comment">
            <div class="cs-comment-head">
              <span class="lg-avatar">{{ initials(c.user_name) }}</span>
              <span class="cs-comment-name">{{ c.user_name }}</span>
              <span class="cs-comment-time">{{ formatDateTime(c.created_at) }}</span>
            </div>
            <p class="cs-comment-body">{{ c.body }}</p>
          </div>
        </div>
        <p v-else class="cs-muted mb-4">{{ $t('No comments yet.') }}</p>

        <div v-if="can.comment" class="cs-compose">
          <textarea v-model="commentBody" class="cs-input flex-1" rows="2" :placeholder="$t('Add a comment…')"></textarea>
          <loading-button type="button" :loading="postingComment" :disabled="!commentBody.trim()" class="lg-btn lg-btn-primary self-end" @click="postComment">{{ $t('Post') }}</loading-button>
        </div>
      </section>
    </div>

    <!-- Status change modal — collects a note (Returned) or resolution text (Resolved) before firing. -->
    <teleport to="body">
      <div v-if="statusModal.open" class="cs-modal-overlay" @click.self="statusModal.open = false">
        <div class="cs-modal-panel" role="dialog" aria-modal="true">
          <header class="cs-modal-head">
            <span class="cs-modal-ico lg-ico-indigo"><icons name="refresh" class="w-5 h-5" /></span>
            <h2 class="cs-modal-title">{{ statusActionLabel(statusModal.target) }}</h2>
          </header>
          <div v-if="statusModal.target === 'Resolved'">
            <label class="cs-label">{{ $t('Resolution') }}</label>
            <textarea v-model="statusModal.resolution" class="cs-input w-full" rows="4" :placeholder="$t('How was this resolved…')"></textarea>
          </div>
          <div v-else>
            <label class="cs-label">{{ $t('Note (optional)') }}</label>
            <textarea v-model="statusModal.note" class="cs-input w-full" rows="3" :placeholder="$t('Reason / context for this change…')"></textarea>
          </div>
          <div v-if="statusModal.error" class="cs-error mt-2">{{ statusModal.error }}</div>
          <div class="cs-modal-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="statusModal.open = false">{{ $t('Cancel') }}</button>
            <loading-button type="button" :loading="statusModal.saving" class="lg-btn lg-btn-primary" @click="submitStatusChange">{{ $t('Confirm') }}</loading-button>
          </div>
        </div>
      </div>
    </teleport>

    <!-- Assign modal -->
    <teleport to="body">
      <div v-if="assignModalOpen" class="cs-modal-overlay" @click.self="assignModalOpen = false">
        <div class="cs-modal-panel" role="dialog" aria-modal="true">
          <header class="cs-modal-head">
            <span class="cs-modal-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span>
            <h2 class="cs-modal-title">{{ $t('Assign Case') }}</h2>
          </header>
          <label class="cs-label">{{ $t('Assign To') }}</label>
          <auto-complete v-model="assignUserId" :suggestions="assignableUserOptions" clearable :placeholder="$t('Search a user…')" />
          <div class="cs-modal-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="assignModalOpen = false">{{ $t('Cancel') }}</button>
            <loading-button type="button" :loading="assigning" class="lg-btn lg-btn-primary" @click="submitAssign">{{ $t('Save') }}</loading-button>
          </div>
        </div>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import SprConfirmPassword from '@/Shared/SprConfirmPassword.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, LoadingButton, SprConfirmPassword, PageHeader },

  props: {
    case: Object,
    timeline: { type: Array, default: () => [] },
    allowedStatuses: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    can: Object,
  },

  data() {
    return {
      commentBody: '',
      postingComment: false,
      statusModal: { open: false, target: null, note: '', resolution: '', error: '', saving: false },
      assignModalOpen: false,
      assignUserId: null,
      assigning: false,
    };
  },

  computed: {
    // A computed alias (not a data() copy) so it always reflects the
    // freshest `case` prop after an Inertia partial reload (e.g. a status
    // change) — a one-time data() copy would silently keep showing the
    // status/fields from the moment the page first mounted.
    case_() {
      return this.case;
    },
    assignableUserOptions() {
      return this.assignableUsers.map(u => ({ id: u.id, label: u.name }));
    },
  },

  methods: {
    statusSlug(status) {
      return String(status).toLowerCase().replace(/\s+/g, '_');
    },
    statusChip(status) {
      const map = {
        draft: 'lg-chip-slate',
        submitted: 'lg-chip-indigo',
        in_review: 'lg-chip-amber',
        in_progress: 'lg-chip-blue',
        returned: 'lg-chip-red',
        resolved: 'lg-chip-green',
        closed: 'lg-chip-slate',
      };
      return map[this.statusSlug(status)] || 'lg-chip-slate';
    },
    priorityChip(color) {
      const map = { slate: 'lg-chip-slate', amber: 'lg-chip-amber', rose: 'lg-chip-red', red: 'lg-chip-red' };
      return map[color] || 'lg-chip-slate';
    },
    formatDateTime(v) {
      return v ? this.$datetime(v) : '—';
    },
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
    },
    statusActionLabel(status) {
      // "In Review" means two different things depending on where the case
      // is coming from — the first review after submission, or a reopen out
      // of Resolved/Closed — so the button label is picked by context, not
      // just the target status.
      if (status === 'In Review' && ['Resolved', 'Closed'].includes(this.case_.status)) {
        return this.$t('Reopen');
      }
      const map = {
        'In Review': this.$t('Start Review'),
        'In Progress': this.$t('Start Progress'),
        Returned: this.$t('Return for Correction'),
        Resolved: this.$t('Resolve'),
        Closed: this.$t('Close'),
      };
      return map[status] || (this.$t('Move to') + ' ' + status);
    },
    act(action) {
      if (action === 'destroy') {
        this.$inertia.delete(this.route('case-tracking.destroy', this.case_.id));
        return;
      }
      this.$inertia.patch(this.route('case-tracking.' + action, this.case_.id), {}, { preserveScroll: true });
    },
    openAssignModal() {
      this.assignUserId = this.case_.assigned_to?.id || null;
      this.assignModalOpen = true;
    },
    openStatusModal(target) {
      this.statusModal = { open: true, target, note: '', resolution: '', error: '', saving: false };
    },
    submitStatusChange() {
      if (this.statusModal.target === 'Resolved' && !this.statusModal.resolution.trim()) {
        this.statusModal.error = this.$t('Please describe how this case was resolved.');
        return;
      }
      this.statusModal.saving = true;
      this.$inertia.patch(this.route('case-tracking.status', this.case_.id), {
        status: this.statusModal.target,
        note: this.statusModal.note,
        resolution: this.statusModal.resolution,
      }, {
        preserveScroll: true,
        onError: (errors) => { this.statusModal.error = Object.values(errors)[0]; },
        onSuccess: () => { this.statusModal.open = false; },
        onFinish: () => { this.statusModal.saving = false; },
      });
    },
    submitAssign() {
      this.assigning = true;
      this.$inertia.patch(this.route('case-tracking.assign', this.case_.id), { assigned_to_user_id: this.assignUserId }, {
        preserveScroll: true,
        onSuccess: () => { this.assignModalOpen = false; },
        onFinish: () => { this.assigning = false; },
      });
    },
    postComment() {
      if (!this.commentBody.trim()) return;
      this.postingComment = true;
      this.$inertia.post(this.route('case-tracking.comments.store', this.case_.id), { body: this.commentBody }, {
        preserveScroll: true,
        onSuccess: () => { this.commentBody = ''; },
        onFinish: () => { this.postingComment = false; },
      });
    },
    removeAttachment(a) {
      this.$inertia.delete(a.delete_url, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.cs-actions { padding: 0.8rem 1rem; }
.cs-action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.cs-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); align-items: start; }
@media (max-width: 900px) { .cs-grid { grid-template-columns: minmax(0, 1fr); } }

.cs-desc { font-size: 0.9rem; line-height: 1.6; color: var(--lg-ink); white-space: pre-wrap; overflow-wrap: anywhere; }
.cs-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: 0; margin-top: 1rem; border: 1px solid var(--lg-line); border-radius: 16px; overflow: hidden; }
.cs-facts > div { padding: 10px 14px; border-bottom: 1px solid var(--lg-line); border-right: 1px solid var(--lg-line); margin: -0.5px; min-width: 0; background: var(--lg-card); }
.cs-facts dt, .cs-label { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.cs-facts dd { margin-top: 2px; font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.cs-label { display: block; margin-bottom: 0.35rem; }
.cs-link { color: #1d4ed8; font-weight: 700; }
.cs-link:hover { text-decoration: underline; }
html.dark .cs-link { color: #93c5fd; }

.cs-resolution { display: flex; align-items: flex-start; gap: 8px; padding: 0.7rem 0.9rem; border-radius: 14px; font-size: 0.82rem; line-height: 1.5; color: #166534; background: rgba(220, 252, 231, 0.7); border: 1px solid rgba(34, 197, 94, 0.35); }
.cs-resolution svg { flex: none; margin-top: 2px; }
html.dark .cs-resolution { color: #86efac; background: rgba(34, 197, 94, 0.1); }

.cs-attach { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.cs-attachment-chip { display: inline-flex; align-items: center; gap: 6px; padding: 0.3rem 0.4rem 0.3rem 0.8rem; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-ink); font-size: 0.74rem; font-weight: 700; }
.cs-attachment-chip:hover { border-color: #93c5fd; }
.cs-attachment-remove { display: inline-flex; align-items: center; justify-content: center; width: 1.3rem; height: 1.3rem; min-width: 1.3rem; min-height: 1.3rem; padding: 0; margin: 0; border: none; border-radius: 9999px; background: rgba(239, 68, 68, 0.12); color: #b91c1c; }
.cs-attachment-remove:hover { background: rgba(239, 68, 68, 0.25); }
.cs-attachment-remove :deep(div), .cs-attachment-remove :deep(svg) { width: 9px; height: 9px; flex: none; display: flex; align-items: center; justify-content: center; }

.cs-timeline { position: relative; margin-left: 5px; padding-left: 1.1rem; border-left: 2px solid var(--lg-line); }
.cs-timeline-item { position: relative; padding-bottom: 1rem; }
.cs-timeline-item:last-child { padding-bottom: 0; }
.cs-timeline-dot { position: absolute; left: calc(-1.1rem - 6px); top: 0.25rem; width: 10px; height: 10px; border-radius: 9999px; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 0 0 3px var(--lg-card); }
.cs-tl-text { font-size: 0.82rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.cs-tl-meta { margin-top: 1px; font-size: 0.7rem; color: var(--lg-faint); }
.cs-muted { font-size: 0.8rem; color: var(--lg-faint); }

.cs-comments { display: flex; flex-direction: column; gap: 10px; margin-bottom: 1rem; }
.cs-comment { padding: 0.7rem 0.9rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.cs-comment-head { display: flex; align-items: center; gap: 10px; }
.cs-comment-name { font-size: 0.84rem; font-weight: 800; color: var(--lg-ink); }
.cs-comment-time { margin-left: auto; font-size: 0.7rem; color: var(--lg-faint); }
.cs-comment-body { margin-top: 6px; padding-left: 42px; font-size: 0.86rem; color: var(--lg-ink); white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 640px) { .cs-comment-body { padding-left: 0; } .cs-comment-head { flex-wrap: wrap; } }
.cs-compose { display: flex; gap: 8px; }
@media (max-width: 640px) { .cs-compose { flex-direction: column; } .cs-compose .lg-btn { align-self: stretch; } }

.cs-input { border: 1px solid var(--lg-line); border-radius: 14px; padding: 0.6rem 0.8rem; font-size: 0.88rem; color: var(--lg-ink); background: var(--lg-card); }
.cs-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.cs-error { font-size: 0.74rem; color: #dc2626; font-weight: 700; }

.cs-modal-overlay { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(3px); }
.cs-modal-panel { width: 100%; max-width: 32rem; max-height: 92vh; overflow-y: auto; padding: 1.4rem; border-radius: 22px; background: #fff; box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.5); --lg-card: #fff; --lg-line: #e6ebf3; --lg-ink: #0f172a; --lg-faint: #94a3b8; --lg-mute: #64748b; }
.cs-modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
.cs-modal-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; }
.cs-modal-title { font-size: 1.02rem; font-weight: 800; color: #0f172a; }
.cs-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.1rem; }
html.dark .cs-modal-panel { background: #1e293b; --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-ink: #f1f5f9; --lg-faint: #64748b; --lg-mute: #94a3b8; }
html.dark .cs-modal-title { color: #f1f5f9; }
html.dark .cs-attachment-remove { color: #fca5a5; }
</style>
