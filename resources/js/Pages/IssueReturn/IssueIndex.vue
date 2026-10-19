<template>
  <admin-layout :title="$t('Issue Material')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="out" :title="$t('Issue Material')" :subtitle="$t('Manage issue transactions and return progress.')">
        <Link v-if="$can('create-issue-returns') || $admin || isSiteEngineer" :href="route('issue-materials.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Issue') }}
        </Link>
      </page-header>

      <div class="txn-toolbar flex flex-wrap gap-3 items-center relative z-20 print:hidden">
        <search-filter v-model="form.search" class="w-full sm:max-w-md flex-1 min-w-[220px]" @reset="reset" />
        <span class="lg-subtext ml-auto">{{ issues.meta ? issues.meta.total : issues.data.length }} {{ $t('result(s)') }}</span>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Issue Number') }}</th>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('Project(s) / Warehouse(s)') }}</th>
              <th>{{ $t('Work Activity') }}</th>
              <th>{{ $t('Issued To') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Created By') }}</th>
              <th class="print:hidden"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="issue in issues.data" :key="issue.id" class="is-click" @click="goto(issue.id)">
              <td :data-label="$t('Issue Number')"><span class="lg-main">{{ issue.issue_number }}</span></td>
              <td :data-label="$t('Date')">{{ $date(issue.issue_date) }}</td>
              <td :data-label="$t('Project(s) / Warehouse(s)')">{{ issue.warehouse?.name }}</td>
              <td :data-label="$t('Work Activity')">{{ issue.project }}</td>
              <td :data-label="$t('Issued To')">{{ issue.issued_to }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(issue.status)">{{ prettyStatus(issue.status) }}</span></td>
              <td :data-label="$t('Created By')" class="iri-by">{{ issue.user?.name || '-' }}</td>
              <td data-label="" class="print:hidden" @click.stop>
                <div class="lg-actions">
                  <Link
                    :href="route('issue-materials.show', issue.id)"
                    :aria-label="$t('View issue')"
                    class="lg-icon-btn"
                    @mouseenter="showTooltip($event, issueItemsSummary(issue))"
                    @mouseleave="hideTooltip"
                  >
                    <icons name="view" class="w-4 h-4" />
                  </Link>
                  <Link
                    v-if="canEdit(issue)"
                    :href="route('issue-materials.edit', issue.id)"
                    :data-tip="$t('Edit issue')"
                    :aria-label="$t('Edit issue')"
                    class="lg-icon-btn lg-tip"
                  >
                    <icons name="edit" class="w-4 h-4" />
                  </Link>
                  <Link
                    v-if="canReturn(issue)"
                    :href="route('return-materials.create', issue.id)"
                    :data-tip="$t('Return items')"
                    :aria-label="$t('Return items')"
                    class="lg-icon-btn is-green lg-tip"
                  >
                    <icons name="in" class="w-4 h-4" />
                  </Link>
                  <a
                    :href="route('issue-materials.print', issue.id)"
                    target="_blank"
                    :data-tip="$t('Print issue')"
                    :aria-label="$t('Print issue')"
                    class="lg-icon-btn lg-tip"
                  >
                    <icons name="printer" class="w-4 h-4" />
                  </a>
                  <button
                    v-if="$admin"
                    type="button"
                    @click="destroy(issue.id)"
                    :data-tip="$t('Delete issue')"
                    :aria-label="$t('Delete issue')"
                    class="lg-icon-btn is-red lg-tip"
                  >
                    <icons name="trash" class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="issues.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="issues.meta" :links="issues.links" />
    </div>

    <!-- Teleported to <body> and positioned via getBoundingClientRect() on
         hover so the item summary is never clipped by the table wrapper. -->
    <Teleport to="body">
      <div v-if="tooltip.visible" class="iri-floating-tooltip" :style="{ left: tooltip.x + 'px', top: tooltip.y + 'px' }">{{ tooltip.text }}</div>
    </Teleport>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import Pagination from '@/Shared/Pagination.vue';

export default {
  components: { AdminLayout, PageHeader, SearchFilter, Pagination },
  props: {
    filters: Object,
    issues: Object,
  },
  data() {
    return {
      form: {
        search: this.filters.search,
      },
      tooltip: { visible: false, text: '', x: 0, y: 0 },
    };
  },
  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.get(this.route('issue-materials.index', Object.keys(query).length ? query : {}), {}, { preserveState: true, replace: true });
      }, 250),
      deep: true,
    },
  },
  methods: {
    hasRole(roleName) {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => role.name === roleName);
    },
    reset() {
      this.form = { search: null };
      this.$inertia.get(this.route('issue-materials.index'), {}, { preserveState: true, replace: true });
    },
    canEdit(issue) {
      return (
        (this.$can('update-issue-returns') || this.$admin) &&
        Number(issue.returned_quantity || 0) === 0 &&
        Number(issue.balance_quantity || 0) > 0 &&
        String(issue.status || '') !== 'fully_returned'
      );
    },
    canReturn(issue) {
      return Number(issue.balance_quantity || 0) > 0 && String(issue.status || '') !== 'fully_returned';
    },
    async destroy(id) {
      if (!(await this.$confirm('Delete this issue transaction?', { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('issue-materials.destroy', id));
    },
    prettyStatus(v) {
      return String(v || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    },
    // Shown as a CSS tooltip (see .iri-tooltip below) on the View eye icon —
    // a quick-glance summary so a user doesn't have to open the record just
    // to see what's in it.
    issueItemsSummary(issue) {
      const itemLines = (issue.items || [])
        .map(line => {
          const name = line.item?.name || line.item?.code || 'Item';
          return `${name}: ${Number(line.issued_quantity || 0)}`;
        })
        .filter(Boolean);

      const summary = [
        `${this.$date(issue.issue_date)} · ${this.prettyStatus(issue.status)}`,
        issue.warehouse?.name ? `Project: ${issue.warehouse.name}` : null,
        issue.project ? `Activity: ${issue.project}` : null,
        issue.issued_to ? `Issued To: ${issue.issued_to}` : null,
        itemLines.length ? itemLines.join(', ') : 'No item lines',
      ].filter(Boolean);

      return summary.join('\n');
    },
    statusChip(v) {
      const map = { fully_returned: 'lg-chip-green', partially_returned: 'lg-chip-amber', issued: 'lg-chip-blue', open: 'lg-chip-blue' };
      return map[String(v || '')] || 'lg-chip-slate';
    },
    goto(id) {
      this.$inertia.visit(this.route('issue-materials.show', id));
    },
    showTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.tooltip = { visible: true, text, x: rect.left + rect.width / 2, y: rect.top };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
  },
  computed: {
    isSiteEngineer() {
      return SITE_ENGINEER_ROLES.some(role => this.hasRole(role));
    },
  },
};
</script>

<style scoped>
.iri-floating-tooltip {
  position: fixed; transform: translate(-50%, calc(-100% - 10px));
  width: max-content; max-width: 18rem; background: #0f172a; color: #fff; padding: 0.55rem 0.8rem; border-radius: 12px;
  font-size: 0.72rem; font-weight: 600; line-height: 1.5; white-space: pre-line; text-align: left;
  pointer-events: none; z-index: 9999;
  box-shadow: 0 12px 24px -10px rgba(15, 23, 42, 0.6);
}
.iri-by { color: var(--lg-mute); font-weight: 600; }
</style>
