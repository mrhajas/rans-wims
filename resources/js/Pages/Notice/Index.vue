<template>
  <admin-layout :title="$t('Notice Board')">
    <div class="lg-page px-4 md:px-0 nb-page">
      <page-header icon="bell" :title="$t('Notice Board')" :subtitle="$t('View notice board messages and sent history.')">
        <Link v-if="canCreate" :href="route('notices.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />
          <span>{{ $t('Send Notice') }}</span>
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="txn-toolbar mb-4 flex flex-col md:flex-row md:justify-between md:items-center gap-3 print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full min-w-0 md:max-w-md" @reset="reset" />
      </div>

      <div v-if="notices.data.length" class="nb-grid">
        <article
          v-for="notice in notices.data"
          :key="notice.id"
          class="nb-card"
          :class="{ 'is-unread': notice.is_unread }"
          tabindex="0"
          @click="goto(notice.id)"
          @keydown.enter="goto(notice.id)"
        >
          <header class="nb-card-top">
            <span class="nb-card-ico" :class="notice.send_to_all ? 'lg-ico-emerald' : 'lg-ico-indigo'">
              <icons :name="notice.send_to_all ? 'group' : 'users'" class="w-5 h-5" />
            </span>
            <div class="nb-chips">
              <span v-if="notice.is_unread" class="lg-chip lg-chip-amber"><span class="nb-dot"></span>{{ $t('Unread') }}</span>
              <span v-else class="lg-chip lg-chip-slate"><icons name="tick" class="w-3 h-3" />{{ $t('Read') }}</span>
              <span class="lg-chip" :class="notice.send_to_all ? 'lg-chip-green' : 'lg-chip-indigo'">
                {{ notice.send_to_all ? $t('Everyone') : $t('Selected recipients') }}
              </span>
            </div>
          </header>

          <h3 class="nb-title">{{ notice.title }}</h3>
          <p class="nb-message">{{ notice.message }}</p>

          <div v-if="notice.recipient_names && notice.recipient_names.length" class="nb-recipients">
            <span v-for="(name, i) in notice.recipient_names.slice(0, 4)" :key="i" class="lg-chip lg-chip-blue" style="text-transform: none">{{ name }}</span>
            <span v-if="notice.recipient_names.length > 4" class="lg-chip lg-chip-slate" style="text-transform: none">+{{ notice.recipient_names.length - 4 }}</span>
          </div>

          <footer class="nb-card-foot">
            <div class="lg-person">
              <span class="lg-avatar">{{ initials(notice.creator ? notice.creator.name : '') }}</span>
              <div class="min-w-0">
                <div class="lg-main truncate">{{ notice.creator ? notice.creator.name : '-' }}</div>
                <div class="lg-subtext">{{ $datetime(notice.created_at) }}</div>
              </div>
            </div>
            <div class="nb-foot-right" @click.stop>
              <span class="lg-chip lg-chip-blue" style="text-transform: none">
                <icons name="users" class="w-3.5 h-3.5" />{{ notice.histories_count || 0 }} {{ $t('Recipients') }}
              </span>
              <Link :href="route('notices.show', notice.id)" class="lg-btn lg-btn-primary">
                <icons name="email" class="w-4 h-4" />
                <span>{{ $t('Open Notice') }}</span>
              </Link>
            </div>
          </footer>
        </article>
      </div>
      <div v-else class="lg-card lg-empty">{{ $t('There is no data to display.') }}</div>

      <pagination class="mt-6" :meta="notices.meta" :links="notices.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';

export default {
  components: {
    Pagination,
    PageHeader,
    StatTiles,
    AdminLayout,
    SearchFilter,
  },

  props: {
    filters: Object,
    notices: Object,
    canCreate: Boolean,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
      },
    };
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('notices.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
          onFinish: () => {
            const search = document.getElementById('page-search');
            if (search) {
              search.focus();
              const len = search.value ? search.value.length : 0;
              search.setSelectionRange(len, len);
            }
          },
        });
      }, 200),
      deep: true,
    },
  },

  computed: {
    statTiles() {
      const s = this.stats;
      if (!s) return [];
      return [
        { icon: 'bell', tone: 'navy', label: this.$t('Notices'), value: s.total },
        { icon: 'email', tone: 'amber', label: this.$t('Unread by me'), value: s.unread },
        { icon: 'send', tone: 'indigo', label: this.$t('Sent by me'), value: s.mine },
        { icon: 'group', tone: 'emerald', label: this.$t('Sent to everyone'), value: s.everyone },
        { icon: 'calendar', tone: 'sky', label: this.$t('Last 7 days'), value: s.week },
      ];
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
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(this.route('notices.show', id));
    },
  },
};
</script>

<style scoped>
.nb-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 360px), 1fr));
}
.nb-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  padding: 16px 18px;
  border-radius: 22px;
  cursor: pointer;
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  box-shadow: var(--lg-shadow);
  transition: transform 0.15s ease, border-color 0.15s ease;
}
.nb-card:hover,
.nb-card:focus-visible {
  transform: translateY(-2px);
  outline: none;
  border-color: #93c5fd;
}
.nb-card.is-unread {
  border-color: rgba(245, 158, 11, 0.55);
  box-shadow: inset 4px 0 0 #f59e0b, var(--lg-shadow);
}
.nb-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.nb-card-ico {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 13px;
  color: #fff;
}
.nb-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: flex-end;
}
.nb-dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background: #f59e0b;
  animation: nb-pulse 2s ease-in-out infinite;
}
.nb-title {
  font-size: 1.02rem;
  font-weight: 800;
  line-height: 1.3;
  color: var(--lg-ink);
  overflow-wrap: anywhere;
}
.nb-card.is-unread .nb-title {
  color: var(--lg-navy);
}
html.dark .nb-card.is-unread .nb-title {
  color: #bfdbfe;
}
.nb-message {
  font-size: 0.84rem;
  line-height: 1.55;
  color: var(--lg-mute);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}
.nb-recipients {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.nb-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid var(--lg-line);
}
.nb-foot-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
@keyframes nb-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); }
  50% { box-shadow: 0 0 0 5px rgba(245, 158, 11, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .nb-dot { animation: none; }
}
</style>
