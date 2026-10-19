<template>
  <section class="slp">
    <header class="slp-head">
      <span class="slp-icon"><icons name="users" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h3 class="slp-title">{{ $t('Staff Profile') }}</h3>
        <p class="slp-sub">{{ $t('Connect this login to an employee record so payroll, vouchers and HR details belong to one person.') }}</p>
      </div>
      <span v-if="link.staff" class="slp-status slp-status-on"><icons name="tick" class="w-3 h-3" />{{ $t('Linked') }}</span>
      <span v-else class="slp-status slp-status-off">{{ $t('Not linked') }}</span>
    </header>

    <!-- Linked -->
    <div v-if="link.staff" class="slp-linked">
      <span v-if="link.staff.photo_url" class="slp-photo" tabindex="0" :aria-label="link.staff.name">
        <img :src="link.staff.photo_url" :alt="link.staff.name" class="slp-avatar slp-avatar-img" />
        <span class="slp-photo-zoom" aria-hidden="true"><img :src="link.staff.photo_url" alt="" /></span>
      </span>
      <span v-else class="slp-avatar">{{ initials(link.staff.name) }}</span>
      <div class="min-w-0 flex-1">
        <div class="slp-name">{{ link.staff.name }}</div>
        <div class="slp-meta">
          <span class="slp-chip">{{ link.staff.staff_number }}</span>
          <span v-if="link.staff.position" class="slp-meta-text">{{ link.staff.position }}</span>
          <span class="slp-chip" :class="link.staff.status === 'Active' ? 'slp-chip-green' : 'slp-chip-amber'">{{ link.staff.status }}</span>
        </div>
      </div>
      <div class="slp-actions">
        <a v-if="link.staff.url" :href="link.staff.url" target="_blank" rel="noopener" class="slp-btn slp-btn-soft"><icons name="view" class="w-4 h-4" />{{ $t('Open profile') }}</a>
        <button v-if="link.can.link" type="button" class="slp-btn slp-btn-danger" :disabled="busy" @click="unlink"><icons name="cross" class="w-4 h-4" />{{ $t('Unlink') }}</button>
      </div>
    </div>

    <!-- Not linked: two ways to connect -->
    <div v-else class="slp-options">
      <div class="slp-card" :class="{ 'slp-card-off': !link.can.link }">
        <div class="slp-card-title"><icons name="link" class="w-4 h-4" />{{ $t('Link to an existing staff profile') }}</div>
        <p class="slp-card-text">{{ $t('Pick the employee record that belongs to this person.') }}</p>
        <template v-if="link.can.link">
          <auto-complete id="slp_staff" :label="$t('Staff profile')" v-model="staffId" :suggestions="link.available" value-key="id" label-key="label" clearable />
          <p v-if="!link.available.length" class="slp-hint">{{ $t('Every staff profile is already linked to a user.') }}</p>
          <label class="slp-check"><input type="checkbox" v-model="fillBlanks" /> <span>{{ $t('Fill the profile\'s empty email / phone / position from this user')}}</span></label>
          <button type="button" class="slp-btn slp-btn-primary" :disabled="!staffId || busy" @click="doLink"><icons name="link" class="w-4 h-4" />{{ $t('Link profile') }}</button>
        </template>
        <p v-else class="slp-hint">{{ $t('You do not have permission to link staff profiles.') }}</p>
      </div>

      <div class="slp-card" :class="{ 'slp-card-off': !link.can.convert }">
        <div class="slp-card-title"><icons name="plus" class="w-4 h-4" />{{ $t('Create a staff profile from this user') }}</div>
        <p class="slp-card-text">{{ $t('A new profile is created and linked, pre-filled from this user:') }}</p>
        <dl v-if="link.defaults" class="slp-defaults">
          <div><dt>{{ $t('Name') }}</dt><dd>{{ link.defaults.name || '—' }}</dd></div>
          <div><dt>{{ $t('Email') }}</dt><dd>{{ link.defaults.personal_email || '—' }}</dd></div>
          <div><dt>{{ $t('Phone') }}</dt><dd>{{ link.defaults.mobile_no || '—' }}</dd></div>
          <div><dt>{{ $t('Position') }}</dt><dd>{{ link.defaults.position || '—' }}</dd></div>
          <div><dt>{{ $t('Status') }}</dt><dd>{{ link.defaults.status }}</dd></div>
        </dl>
        <p class="slp-hint">{{ $t('Join date, salary and bank details are left empty for you to complete.') }}</p>
        <button v-if="link.can.convert" type="button" class="slp-btn slp-btn-primary" :disabled="busy" @click="convert"><icons name="plus" class="w-4 h-4" />{{ $t('Create & open profile') }}</button>
        <p v-else class="slp-hint">{{ $t('You do not have permission to create staff profiles.') }}</p>
      </div>
    </div>

    <p v-if="error" class="slp-error">{{ error }}</p>
  </section>
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { AutoComplete },

  props: {
    link: { type: Object, required: true },
    userId: { type: [Number, String], required: true },
  },

  data() {
    return { staffId: null, fillBlanks: true, busy: false, error: '' };
  },

  methods: {
    initials(name) {
      return (name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },

    run(method, url, data = {}) {
      this.busy = true;
      this.error = '';
      this.$inertia[method](url, data, {
        preserveScroll: true,
        onError: errors => (this.error = Object.values(errors)[0] || this.$t('Something went wrong. Please try again.')),
        onFinish: () => (this.busy = false),
      });
    },

    doLink() {
      this.run('post', route('users.staff.link', this.userId), { staff_id: this.staffId, fill_blanks: this.fillBlanks });
    },

    convert() {
      this.run('post', route('users.staff.convert', this.userId));
    },

    async unlink() {
      if (await this.$confirm(this.$t('Unlink this user from the staff profile? Neither record is deleted.'))) {
        this.run('delete', route('users.staff.unlink', this.userId));
      }
    },
  },
};
</script>

<style scoped>
.slp { margin-top: 1.5rem; padding: 1.15rem 1.3rem 1.3rem; border-radius: 22px; background: rgba(255, 255, 255, 0.92); border: 1px solid #e6ecf5; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 18px 32px -22px rgba(15, 23, 42, 0.3); }
.slp-head { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
.slp-icon { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; flex: none; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5); box-shadow: 0 10px 18px -8px rgba(63, 99, 240, 0.9); }
.slp-title { font-size: 1.02rem; font-weight: 800; color: #0f172a; }
.slp-sub { font-size: 0.78rem; color: #64748b; margin-top: 1px; }
.slp-status { margin-left: auto; flex: none; display: inline-flex; align-items: center; gap: 5px; padding: 3px 11px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; }
.slp-status-on { background: rgba(5, 150, 105, 0.12); color: #047857; }
.slp-status-off { background: rgba(71, 85, 105, 0.1); color: #475569; }

.slp-linked { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 14px 16px; border-radius: 16px; background: #f6f8ff; border: 1px solid #e3e9fb; }
.slp-avatar { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; flex: none; border-radius: 9999px; font-size: 0.9rem; font-weight: 800; color: #4338ca; background: linear-gradient(135deg, #e0f2fe, #e0e7ff); box-shadow: 0 0 0 2px #fff, 0 6px 12px -6px rgba(15, 23, 42, 0.4); }
.slp-avatar-img { object-fit: cover; }
/* Hover / focus magnifier: the photo opens up as a larger circle */
.slp-photo { position: relative; display: inline-flex; flex: none; border-radius: 9999px; cursor: zoom-in; outline: none; }
.slp-photo-zoom { position: absolute; left: -6px; top: -6px; z-index: 40; width: 46px; height: 46px; overflow: hidden; border-radius: 9999px; border: 4px solid #fff; background: #fff; box-shadow: 0 22px 40px -12px rgba(15, 23, 42, 0.55), 0 0 0 1px rgba(99, 102, 241, 0.25); opacity: 0; pointer-events: none; transform-origin: 50% 50%; transition: opacity 0.18s ease, width 0.22s cubic-bezier(0.3, 1.3, 0.5, 1), height 0.22s cubic-bezier(0.3, 1.3, 0.5, 1), left 0.22s cubic-bezier(0.3, 1.3, 0.5, 1), top 0.22s cubic-bezier(0.3, 1.3, 0.5, 1); }
.slp-photo-zoom img { display: block; width: 100%; height: 100%; object-fit: cover; }
.slp-photo:hover .slp-photo-zoom, .slp-photo:focus-visible .slp-photo-zoom { opacity: 1; width: 190px; height: 190px; left: -72px; top: -72px; }
@media (prefers-reduced-motion: reduce) { .slp-photo-zoom { transition: opacity 0.1s ease; } }
.slp-name { font-weight: 800; color: #0f172a; }
.slp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 3px; }
.slp-meta-text { font-size: 0.78rem; color: #64748b; }
.slp-chip { padding: 1px 9px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; background: #e0e7ff; color: #4338ca; }
.slp-chip-green { background: rgba(5, 150, 105, 0.12); color: #047857; }
.slp-chip-amber { background: rgba(217, 119, 6, 0.14); color: #92400e; }
.slp-actions { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }

.slp-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 14px; }
.slp-card { display: flex; flex-direction: column; gap: 10px; min-width: 0; padding: 16px; border-radius: 18px; background: #fbfcff; border: 1px solid #e6ebf8; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03); }
.slp-card-off { opacity: 0.7; }
.slp-card-title { display: flex; align-items: center; gap: 8px; font-size: 0.86rem; font-weight: 800; color: #1e3a8a; }
.slp-card-text { font-size: 0.78rem; color: #64748b; }
.slp-defaults { display: grid; grid-template-columns: 1fr; gap: 4px; padding: 10px 12px; border-radius: 12px; background: #fff; border: 1px dashed #cfd8f3; }
.slp-defaults > div { display: flex; justify-content: space-between; gap: 12px; font-size: 0.78rem; }
.slp-defaults dt { color: #94a3b8; font-weight: 700; flex: none; }
.slp-defaults dd { color: #0f172a; font-weight: 700; text-align: right; overflow-wrap: anywhere; }
.slp-hint { font-size: 0.74rem; color: #94a3b8; }
.slp-check { display: flex; align-items: flex-start; gap: 8px; font-size: 0.78rem; color: #475569; cursor: pointer; }
.slp-check input { margin-top: 2px; accent-color: #4f6df5; }
.slp-error { margin-top: 12px; padding: 9px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: #b42318; background: #fff5f5; border: 1px solid #fbd0d0; }

.slp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 16px; min-height: 0; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease; }
.slp-btn:hover:not(:disabled) { transform: translateY(-1px); }
.slp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.slp-btn-primary { align-self: flex-start; color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5); box-shadow: 0 10px 18px -10px rgba(63, 99, 240, 0.95); }
.slp-btn-soft { color: #3b52c4; background: #fff; border: 1px solid #d9e1fb; }
.slp-btn-danger { color: #b42318; background: #fff; border: 1px solid #f6c9c4; }

html.dark .slp, html.dark .slp-card { background: rgba(15, 23, 42, 0.8); border-color: rgba(255, 255, 255, 0.08); }
html.dark .slp-title, html.dark .slp-name { color: #f1f5f9; }
</style>
