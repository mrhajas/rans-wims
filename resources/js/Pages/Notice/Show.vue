<template>
  <admin-layout :title="$t('Notice Details')">
    <div class="lg-page px-4 md:px-0 nb-show">
      <page-header icon="bell" :title="notice.title" :subtitle="$t('Notice message and sent history.')">
        <Link :href="route('notices.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Notice Board') }}
        </Link>
      </page-header>

      <!-- The notice itself -->
      <section class="lg-card nb-notice">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="email" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <span class="nb-eyebrow">{{ $t('You have a notice') }}</span>
            <h2 class="lg-card-title nb-notice-title">{{ notice.title }}</h2>
          </div>
          <span class="lg-chip" :class="notice.send_to_all ? 'lg-chip-green' : 'lg-chip-indigo'">
            {{ notice.send_to_all ? $t('Everyone') : $t('Selected recipients') }}
          </span>
        </header>

        <p class="nb-body">{{ notice.message }}</p>

        <div class="nb-meta">
          <div class="lg-person">
            <span class="lg-avatar">{{ initials(notice.creator ? notice.creator.name : '') }}</span>
            <div class="min-w-0">
              <div class="lg-main truncate">{{ notice.creator ? notice.creator.name : '-' }}</div>
              <div class="lg-subtext">{{ $t('Sent By') }}</div>
            </div>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">
            <icons name="clock" class="w-3.5 h-3.5" />{{ $datetime(notice.created_at) }}
          </span>
        </div>
      </section>

      <!-- Recipient rules -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Recipient Rules') }}</h2>
            <p class="lg-card-sub">{{ $t('Roles') }} / {{ $t('Selected Users') }}</p>
          </div>
        </header>

        <div v-if="notice.send_to_all" class="lg-note lg-note-blue">
          <icons name="group" class="w-4 h-4" />
          <span class="font-semibold">{{ $t('Sent to all users in this account.') }}</span>
        </div>
        <div v-else class="lg-fields">
          <div class="nb-rule">
            <div class="nb-rule-head"><icons name="group" class="w-3.5 h-3.5" />{{ $t('Roles') }}</div>
            <div v-if="notice.target_roles && notice.target_roles.length" class="nb-pills">
              <span v-for="r in notice.target_roles" :key="r.id" class="lg-chip lg-chip-violet" style="text-transform: none">{{ r.name }}</span>
            </div>
            <span v-else class="lg-subtext">-</span>
          </div>
          <div class="nb-rule">
            <div class="nb-rule-head"><icons name="user" class="w-3.5 h-3.5" />{{ $t('Selected Users') }}</div>
            <div v-if="notice.target_users && notice.target_users.length" class="nb-pills">
              <span v-for="u in notice.target_users" :key="u.id" class="lg-chip lg-chip-blue" style="text-transform: none">{{ u.name }}</span>
            </div>
            <span v-else class="lg-subtext">-</span>
          </div>
        </div>
      </section>

      <!-- Sent history -->
      <section v-if="canViewSentHistory" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Sent History') }}</h2>
            <p v-if="notice.histories && notice.histories.length" class="lg-card-sub">
              {{ readCount }} {{ $t('of') }} {{ notice.histories.length }} {{ $t('recipients have read this notice') }}
            </p>
          </div>
          <span v-if="notice.histories && notice.histories.length" class="lg-chip" :class="readPercent === 100 ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">
            <icons name="tick" class="w-3.5 h-3.5" />{{ readPercent }}%
          </span>
        </header>

        <div v-if="notice.histories && notice.histories.length" class="nb-progress" aria-hidden="true">
          <span :style="{ width: readPercent + '%' }"></span>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Recipient') }}</th>
                <th>{{ $t('Sent By') }}</th>
                <th>{{ $t('Sent At') }}</th>
                <th>{{ $t('Read Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="history in notice.histories" :key="history.id">
                <td :data-label="$t('Recipient')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(history.user ? history.user.name : '') }}</span>
                    <span class="lg-main">{{ history.user ? history.user.name : '-' }}</span>
                  </div>
                </td>
                <td :data-label="$t('Sent By')">{{ history.sender ? history.sender.name : '-' }}</td>
                <td :data-label="$t('Sent At')">{{ history.sent_at ? $datetime(history.sent_at) : '-' }}</td>
                <td :data-label="$t('Read Status')">
                  <span v-if="history.read_at" class="lg-chip lg-chip-green" style="text-transform: none">
                    <icons name="tick" class="w-3 h-3" />{{ $t('Read') }} · {{ $datetime(history.read_at) }}
                  </span>
                  <span v-else class="lg-chip lg-chip-amber" style="text-transform: none">
                    <icons name="clock" class="w-3 h-3" />{{ $t('Unread') }}
                  </span>
                </td>
              </tr>
              <tr v-if="!notice.histories || notice.histories.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="4">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
  },

  props: {
    notice: Object,
    canViewSentHistory: Boolean,
  },

  computed: {
    readCount() {
      return (this.notice.histories || []).filter(h => h.read_at).length;
    },
    readPercent() {
      const total = (this.notice.histories || []).length;
      return total ? Math.round((this.readCount / total) * 100) : 0;
    },
  },

  methods: {
    initials(name) {
      return (name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0])
        .join('')
        .toUpperCase();
    },
  },
};
</script>

<style scoped>
.nb-eyebrow {
  display: block;
  font-size: 0.64rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #1d4ed8;
}
html.dark .nb-eyebrow {
  color: #93c5fd;
}
.nb-notice-title {
  font-size: 1.2rem;
  overflow-wrap: anywhere;
}
.nb-body {
  white-space: pre-line;
  font-size: 0.95rem;
  line-height: 1.7;
  color: var(--lg-ink);
  overflow-wrap: anywhere;
  padding: 1rem 1.1rem;
  border-radius: 16px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.nb-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 1rem;
}
.nb-rule {
  padding: 0.85rem 1rem;
  border-radius: 16px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.nb-rule-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.nb-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.nb-progress {
  height: 8px;
  margin-bottom: 1rem;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.nb-progress span {
  display: block;
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #34d399, #047857);
  transition: width 0.4s ease;
}
</style>
