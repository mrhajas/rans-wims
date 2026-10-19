<template>
  <admin-layout :title="$t('Issue Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="out" :title="$t('Issue') + ' ' + issue.issue_number" :subtitle="$t('Timeline, returns, and attachments.')">
        <span class="lg-chip" :class="statusChip(issue.status)">{{ prettyStatus(issue.status) }}</span>
        <Link v-if="$can('update-issue-returns') || $admin" :href="route('issue-materials.edit', issue.id)" class="lg-btn lg-btn-soft">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <a :href="route('issue-materials.print', issue.id)" target="_blank" class="lg-btn lg-btn-green">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a :href="route('issue-materials.pdf', issue.id)" target="_blank" class="lg-btn lg-btn-dark">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
        <Link v-if="Number(issue.balance_quantity) > 0" :href="route('return-materials.create', issue.id)" class="lg-btn lg-btn-primary">
          <icons name="in" class="w-4 h-4" />{{ $t('Return Items') }}
        </Link>
      </page-header>

      <section class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="out" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ formatNumber(issue.total_quantity) }}</div><div class="lg-stat-label">{{ $t('Total Qty') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="in" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ formatNumber(issue.returned_quantity) }}</div><div class="lg-stat-label">{{ $t('Returned Qty') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ formatNumber(issue.balance_quantity) }}</div><div class="lg-stat-label">{{ $t('Balance Qty') }}</div></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Issue Details') }}</h2></div>
        </header>
        <div class="is-ref-grid">
          <div><div class="is-field-label">{{ $t('Date') }}</div><div class="is-field-value">{{ $date(issue.issue_date) }}</div></div>
          <div><div class="is-field-label">{{ $t('Project(s) / Warehouse(s)') }}</div><div class="is-field-value">{{ issue.warehouse?.name || '-' }}</div></div>
          <div><div class="is-field-label">{{ $t('Work Activity') }}</div><div class="is-field-value">{{ issue.project || '-' }}</div></div>
          <div><div class="is-field-label">{{ $t('Issued To') }}</div><div class="is-field-value">{{ issue.issued_to || '-' }}</div></div>
          <div><div class="is-field-label">{{ $t('Status') }}</div><div class="is-field-value"><span class="lg-chip" :class="statusChip(issue.status)">{{ prettyStatus(issue.status) }}</span></div></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Items') }}</h2></div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Item') }}</th>
                <th class="num">{{ $t('Issued') }}</th>
                <th class="num">{{ $t('Returned') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
                <th class="num">{{ $t('Damaged') }}</th>
                <th class="num">{{ $t('Lost') }}</th>
                <th>{{ $t('Remarks') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in issue.items" :key="line.id">
                <td :data-label="$t('Item')"><span class="lg-main">{{ line.item?.name }}</span> <span class="lg-subtext">({{ line.item?.code }})</span></td>
                <td :data-label="$t('Issued')" class="num">{{ formatNumber(line.issued_quantity) }}</td>
                <td :data-label="$t('Returned')" class="num">{{ formatNumber(line.returned_quantity) }}</td>
                <td :data-label="$t('Balance')" class="num font-bold">{{ formatNumber(line.balance_quantity) }}</td>
                <td :data-label="$t('Damaged')" class="num" :class="{ 'is-bad': Number(line.damaged_quantity) > 0 }">{{ formatNumber(line.damaged_quantity) }}</td>
                <td :data-label="$t('Lost')" class="num" :class="{ 'is-bad': Number(line.lost_quantity) > 0 }">{{ formatNumber(line.lost_quantity) }}</td>
                <td :data-label="$t('Remarks')">{{ line.remarks }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card" v-if="issue.attachments?.length">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div>
        </header>
        <ul class="is-docs">
          <li v-for="a in issue.attachments" :key="a.id">
            <span class="inline-flex items-center gap-2 min-w-0">
              <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 flex-none" style="color: var(--lg-faint)" />
              <span class="lg-main truncate">{{ a.title }}</span>
              <span v-if="a.filesize" class="lg-subtext flex-none">({{ humanSize(a.filesize) }})</span>
            </span>
            <span class="flex items-center gap-1.5 flex-none">
              <a :href="a.preview_url" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')">
                <icons name="eye-flat" class="w-4 h-4" />
              </a>
              <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
                <icons name="download" class="w-4 h-4" />
              </a>
              <button v-if="$can('update-issue-returns') || $admin" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteAttachment(a)">
                <icons name="trash" class="w-4 h-4" />
              </button>
            </span>
          </li>
        </ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Transaction Timeline') }}</h2></div>
        </header>
        <ul class="is-timeline">
          <li>
            <span class="is-tl-dot"></span>
            <div class="is-tl-title">{{ $t('Issue Created') }}</div>
            <div class="is-tl-meta">{{ $datetime(issue.created_at) }} {{ $t('by') }} {{ issue.user?.name }}</div>
            <div class="is-tl-text">{{ $t('Issued to') }} {{ issue.issued_to }} {{ $t('for') }} {{ issue.project }}.</div>
          </li>
          <li v-for="ret in issue.returns" :key="ret.id">
            <span class="is-tl-dot is-green"></span>
            <div class="is-tl-title">{{ $t('Return') }} - {{ $date(ret.return_date) }}</div>
            <div class="is-tl-meta">{{ $t('Returned by') }} {{ ret.returned_by }}, {{ $t('received by') }} {{ ret.received_by }}</div>
            <div class="is-tl-text" v-if="ret.remarks">{{ ret.remarks }}</div>
            <div class="is-tl-items">
              <span v-for="rLine in ret.items" :key="rLine.id" class="lg-chip lg-chip-slate" style="text-transform: none">
                {{ rLine.item?.name }}: {{ formatNumber(rLine.returning_qty) }} ({{ rLine.condition }})
              </span>
            </div>
          </li>
        </ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('System Logs') }}</h2></div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date/Time') }}</th>
                <th>{{ $t('User') }}</th>
                <th>{{ $t('Action') }}</th>
                <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
                <th>{{ $t('IP Address') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in logs" :key="log.id">
                <td :data-label="$t('Date/Time')">{{ $datetime(log.created_at) }}</td>
                <td :data-label="$t('User')">{{ log.user?.name }}</td>
                <td :data-label="$t('Action')">{{ log.action }}</td>
                <td :data-label="$t('Project(s) / Warehouse(s)')">{{ log.warehouse?.name }}</td>
                <td :data-label="$t('IP Address')" class="lg-mono">{{ log.ip_address }}</td>
              </tr>
              <tr v-if="!logs || !logs.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td></tr>
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
  components: { AdminLayout, PageHeader },
  props: {
    issue: Object,
    logs: Array,
  },
  methods: {
    formatNumber(v) {
      const n = Number(v || 0);
      return Number.isInteger(n) ? n.toString() : n.toFixed(2);
    },
    statusChip(v) {
      const map = { fully_returned: 'lg-chip-green', partially_returned: 'lg-chip-amber', issued: 'lg-chip-blue', open: 'lg-chip-blue' };
      return map[String(v || '')] || 'lg-chip-slate';
    },
    prettyStatus(v) {
      return String(v || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    },
    humanSize(size) {
      const n = Number(size);
      if (!n) return '0 B';
      const units = ['B', 'kB', 'MB', 'GB', 'TB'];
      const i = Math.min(Math.floor(Math.log(n) / Math.log(1024)), units.length - 1);
      return `${(n / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
    },
    async deleteAttachment(attachment) {
      if (!(await this.$confirm('Remove this attachment?', { variant: 'danger' }))) return;
      this.$inertia.delete(attachment.delete_url, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.is-ref-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); gap: 0.9rem 1.25rem; }
.is-field-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.is-field-value { margin-top: 2px; font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.is-bad { color: #be123c; }
html.dark .is-bad { color: #fda4af; }
.is-docs { list-style: none; margin: 0; padding: 0; }
.is-docs li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.6rem 0; }
.is-docs li + li { border-top: 1px solid var(--lg-line); }
.is-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; }
.is-timeline > li { position: relative; padding: 0 0 18px 18px; border-left: 2px solid var(--lg-line); }
.is-timeline > li:last-child { border-left-color: transparent; padding-bottom: 0; }
.is-tl-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.is-tl-dot.is-green { background: #10b981; }
.is-tl-title { font-size: 0.88rem; font-weight: 800; color: var(--lg-ink); }
.is-tl-meta { margin-top: 2px; font-size: 0.76rem; color: var(--lg-mute); }
.is-tl-text { margin-top: 4px; font-size: 0.82rem; color: var(--lg-ink); }
.is-tl-items { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
</style>
