<template>
  <admin-layout :title="session.reference">
    <div class="lg-page px-4 md:px-0 asn-ui">
      <page-header icon="adjustments" :title="session.reference" :subtitle="session.warehouse.name + (session.name ? ' · ' + session.name : '')">
        <Link :href="route('adjustment-sessions.index')" class="lg-btn lg-btn-soft"><icons name="collection" class="w-4 h-4" />{{ $t('Sessions') }}</Link>
        <a v-if="can.print" :href="route('adjustment-sessions.print', session.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="can.print" :href="route('adjustment-sessions.pdf', session.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
        <Link v-if="isOpen && can.count" :href="route('super-adjustments.create', { warehouse_id: session.warehouse.id })" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('New Super Adjustment') }}</Link>
        <button v-if="isOpen && can.end" type="button" class="lg-btn lg-btn-dark" @click="openEnd"><icons name="lock-closed" class="w-4 h-4" />{{ $t('End Session') }}</button>
      </page-header>

      <section class="lg-card asn-head">
        <div class="asn-head__main">
          <span class="asn-pill" :class="isOpen ? 'is-open' : 'is-ended'"><icons :name="isOpen ? 'clock' : 'tick'" class="asn-pill__ico" />{{ $t(session.status) }}</span>
          <dl class="asn-facts">
            <div><dt>{{ $t('Started') }}</dt><dd>{{ $datetime(session.started_at) }} · {{ session.started_by }}</dd></div>
            <div v-if="!isOpen"><dt>{{ $t('Ended') }}</dt><dd>{{ $datetime(session.ended_at) }} · {{ session.ended_by }}</dd></div>
            <div v-if="session.note"><dt>{{ $t('Note') }}</dt><dd>{{ session.note }}</dd></div>
            <div v-if="session.end_note"><dt>{{ $t('End note') }}</dt><dd>{{ session.end_note }}</dd></div>
            <div v-if="session.clearing"><dt>{{ $t('Clearing count') }}</dt><dd><a :href="route('super-adjustments.show', session.clearing.id)" target="_blank" rel="noopener" class="asn-link">{{ session.clearing.reference }}</a> ({{ session.clearing.status }})</dd></div>
          </dl>
        </div>
        <div class="asn-prog" :title="stats.percent + '%'">
          <div class="asn-prog__bar"><span :style="{ width: Math.min(100, stats.percent) + '%' }"></span></div>
          <small>{{ stats.counted }} {{ $t('of') }} {{ stats.counted + stats.not_counted }} {{ $t('items with stock are counted') }} ({{ stats.percent }}%)</small>
        </div>
      </section>

      <stat-tiles :items="statItems" />

      <div v-if="isOpen" class="lg-note lg-note-amber asn-note"><icons name="clock" class="w-4 h-4" /><span>{{ $t('This session is open. Every Super Adjustment of :w is added to it, and the question "items not in this count" is not asked on them. When the counting is finished, end the session: the items below that were never counted can then be cleared in one Super Adjustment.', { w: session.warehouse.name }) }}</span></div>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Super Adjustments of this session') }}</h2></div></header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th class="asn-no">{{ $t('No') }}</th><th>{{ $t('Reference') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('By') }}</th><th>{{ $t('Status') }}</th><th class="num">{{ $t('Items') }}</th></tr></thead>
            <tbody>
              <tr v-for="(d, i) in documents" :key="d.id" class="is-click" @click="openDoc(d)">
                <td :data-label="$t('No')" class="asn-no">{{ i + 1 }}</td>
                <td :data-label="$t('Reference')"><a :href="route('super-adjustments.show', d.id)" target="_blank" rel="noopener" class="asn-link lg-mono" @click.stop>{{ d.reference }}</a><span v-if="d.is_clearing" class="lg-chip lg-chip-violet" style="margin-left: 6px; text-transform: none">{{ $t('clearing count') }}</span></td>
                <td :data-label="$t('Date')" class="tabular-nums">{{ $date(d.date) }}</td>
                <td :data-label="$t('By')">{{ d.by }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="docChip(d.status)" style="text-transform: none">{{ $t(d.status === 'Draft' ? 'Pending approval' : d.status) }}</span></td>
                <td :data-label="$t('Items')" class="num">{{ d.items }}</td>
              </tr>
              <tr v-if="!documents.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('No Super Adjustment was made in this session yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico" :class="stats.not_counted ? 'lg-ico-rose' : 'lg-ico-emerald'"><icons :name="stats.not_counted ? 'exclamation' : 'check-double'" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ isOpen ? $t('Not counted yet') : $t('Items that were not counted') }} ({{ stats.not_counted }})</h2><p class="lg-card-sub">{{ isOpen ? $t('Items that still have stock in this Project / Warehouse and are on no Super Adjustment of the session.') : $t('What was left uncounted when the session ended, and what was done with it.') }}</p></div>
          <input v-model="search" class="asn-search" type="search" :placeholder="$t('Search item...')" />
        </header>
        <div v-if="stats.moved && isOpen" class="lg-note lg-note-amber asn-note"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t(':n of these items had stock movements since the session started (received, issued or moved). They were probably handled, so check them before clearing.', { n: stats.moved }) }}</span></div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th class="asn-no">{{ $t('No') }}</th><th>{{ $t('Code') }}</th><th>{{ $t('Item') }}</th><th class="num">{{ $t('System quantity') }}</th><th>{{ $t('Unit') }}</th><th>{{ isOpen ? $t('Moved since start') : $t('What was done') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in shown" :key="r.item_id">
                <td :data-label="$t('No')" class="asn-no">{{ i + 1 }}</td>
                <td :data-label="$t('Code')" class="lg-mono">{{ r.code }}</td>
                <td :data-label="$t('Item')" class="asn-wrap"><b>{{ r.name }}</b></td>
                <td :data-label="$t('System quantity')" class="num tabular-nums">{{ qty(r.quantity) }}</td>
                <td :data-label="$t('Unit')">{{ r.unit || '-' }}</td>
                <td v-if="isOpen" :data-label="$t('Moved since start')"><span v-if="r.moved" class="lg-chip lg-chip-amber" style="text-transform: none"><icons name="refresh" class="w-3 h-3" /> {{ $t('moved') }} · {{ $datetime(r.last_moved_at) }}</span><span v-else class="lg-subtext">-</span></td>
                <td v-else :data-label="$t('What was done')"><span class="lg-chip" :class="r.cleared ? 'lg-chip-red' : 'lg-chip-slate'" style="text-transform: none">{{ r.cleared ? $t('Cleared (counted at zero)') : $t('Kept as it is') }}</span></td>
              </tr>
              <tr v-if="!rows.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ isOpen ? $t('Every item with stock is counted so far.') : $t('Every item with stock was counted.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <p v-if="filtered.length > limit" class="asn-more"><button type="button" class="lg-btn lg-btn-soft" @click="limit += 200">{{ $t('Show more') }} ({{ filtered.length - limit }})</button></p>
      </section>
    </div>

    <!-- end the session: choose what to clear -->
    <modal :show="endOpen" max-width="2xl" @close="endOpen = false">
      <div class="asn-dlg">
        <header class="asn-dlg__head"><icons name="lock-closed" class="w-5 h-5" /><div><h3>{{ $t('End Adjustment Session') }} {{ session.reference }}</h3><p>{{ $t('Decide what to do with the items nobody counted.') }}</p></div></header>
        <div class="asn-dlg__body">
          <div v-if="pending.length" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('These Super Adjustments of the session are still waiting for approval: :r. Approve or reject them first, then end the session.', { r: pending.join(', ') }) }}</span></div>
          <template v-if="rows.length">
            <div class="asn-pick__bar">
              <label class="asn-chk"><input type="checkbox" :checked="allTicked" :disabled="!can.clear" @change="tickAll($event.target.checked)" /> {{ $t('Clear all') }}</label>
              <span class="asn-hint">{{ ticked.length }} {{ $t('of') }} {{ rows.length }} {{ $t('items will be cleared') }}</span>
              <input v-model="pickSearch" class="asn-search" type="search" :placeholder="$t('Search item...')" />
            </div>
            <div v-if="!can.clear" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('You can end the session, but clearing items needs the permission to create Super Adjustments.') }}</span></div>
            <ul class="asn-pick">
              <li v-for="r in pickShown" :key="r.item_id" :class="{ 'is-on': picked[r.item_id] }">
                <label><input type="checkbox" :checked="!!picked[r.item_id]" :disabled="!can.clear" @change="picked[r.item_id] = $event.target.checked" /><span class="asn-pick__name"><b>{{ r.name }}</b><small>{{ r.code }}</small></span><span class="asn-pick__qty">{{ qty(r.quantity) }} {{ r.unit }}</span><span v-if="r.moved" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('moved') }}</span></label>
              </li>
            </ul>
            <p v-if="pickFiltered.length > pickLimit" class="asn-hint"><button type="button" class="lg-btn lg-btn-soft" @click="pickLimit += 200">{{ $t('Show more') }} ({{ pickFiltered.length - pickLimit }})</button></p>
            <p class="asn-hint">{{ $t('Items that moved since the session started are not ticked: check them first. Clearing makes ONE normal Super Adjustment that counts the ticked items at zero. It follows the usual approvals, so the stock changes only when it is approved.') }}</p>
          </template>
          <p v-else class="asn-hint">{{ $t('Every item with stock was counted. Nothing needs to be cleared.') }}</p>
          <div class="ac-f"><label>{{ $t('Note (optional)') }}</label><textarea v-model="endNote" rows="2" maxlength="1000"></textarea></div>
          <p v-if="endError" class="asn-err">{{ endError }}</p>
        </div>
        <footer class="asn-dlg__foot">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="ending" @click="endOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn" :class="ticked.length ? 'lg-btn-red' : 'lg-btn-dark'" :disabled="ending || pending.length > 0" @click="doEnd">
            <icons name="lock-closed" class="w-4 h-4" />{{ ending ? $t('Ending...') : ticked.length ? $t('End session and clear :n item(s)', { n: ticked.length }) : $t('End session without clearing') }}
          </button>
        </footer>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Modal },
  props: { session: Object, documents: Array, pending: Array, uncounted: Array, stats: Object, can: Object },
  data() { return { search: '', limit: 200, endOpen: false, picked: {}, pickSearch: '', pickLimit: 200, endNote: '', ending: false, endError: '' }; },
  computed: {
    isOpen() { return this.session.status === 'Open'; },
    rows() { return this.uncounted || []; },
    filtered() { const q = this.search.trim().toLowerCase(); return q ? this.rows.filter(r => (r.name + ' ' + (r.code || '')).toLowerCase().includes(q)) : this.rows; },
    shown() { return this.filtered.slice(0, this.limit); },
    pickFiltered() { const q = this.pickSearch.trim().toLowerCase(); return q ? this.rows.filter(r => (r.name + ' ' + (r.code || '')).toLowerCase().includes(q)) : this.rows; },
    pickShown() { return this.pickFiltered.slice(0, this.pickLimit); },
    ticked() { return this.rows.filter(r => this.picked[r.item_id]).map(r => r.item_id); },
    allTicked() { return this.rows.length > 0 && this.ticked.length === this.rows.length; },
    statItems() {
      const s = this.stats;
      return [
        { icon: 'doc-text', tone: 'sky', label: this.$t('Super Adjustments'), value: s.documents },
        { icon: 'check-double', tone: 'forest', label: this.$t('Items counted'), value: s.counted },
        { icon: 'exclamation', tone: s.not_counted ? 'maroon' : 'forest', label: this.isOpen ? this.$t('Not counted yet') : this.$t('Not counted'), value: s.not_counted },
        this.isOpen ? { icon: 'refresh', tone: 'orange', label: this.$t('Moved since start'), value: s.moved } : { icon: 'trash', tone: 'maroon', label: this.$t('Cleared'), value: s.cleared },
      ];
    },
  },
  methods: {
    qty(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    docChip(s) { return { Draft: 'lg-chip-amber', 'Awaiting Phase 2': 'lg-chip-sky', Approved: 'lg-chip-green', Rejected: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    openDoc(d) { window.open(this.route('super-adjustments.show', d.id), '_blank', 'noopener'); },
    openEnd() {
      this.picked = {}; this.rows.forEach(r => { if (!r.moved) this.picked[r.item_id] = true; }); // what moved since the start is not ticked
      this.pickSearch = ''; this.pickLimit = 200; this.endNote = ''; this.endError = ''; this.endOpen = true;
    },
    tickAll(on) { const p = {}; if (on) this.rows.forEach(r => { p[r.item_id] = true; }); this.picked = p; },
    async doEnd() {
      if (this.ending || this.pending.length) return;
      if (this.ticked.length && !await this.$confirm(this.$t('Clear :n item(s)? One Super Adjustment will be made that counts them at zero. It follows the usual approvals before the stock changes.', { n: this.ticked.length }), { confirmText: this.$t('End and clear') })) return;
      this.ending = true; this.endError = '';
      this.$inertia.post(this.route('adjustment-sessions.end', this.session.id), { clear_item_ids: this.ticked, note: this.endNote }, {
        preserveScroll: true,
        onSuccess: () => { this.endOpen = false; },
        onError: e => { this.endError = Object.values(e)[0] || this.$t('Could not end the session.'); },
        onFinish: () => { this.ending = false; },
      });
    },
  },
};
</script>

<style scoped>
.asn-head { display: grid; gap: 12px; }
.asn-head__main { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 14px 22px; }
.asn-facts { display: grid; gap: 4px 28px; margin: 0; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); flex: 1 1 320px; font-size: 0.86rem; }
.asn-facts dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); } .asn-facts dd { margin: 0; overflow-wrap: anywhere; }
.asn-prog__bar { height: 10px; border-radius: 999px; background: rgba(148, 163, 184, 0.25); overflow: hidden; } .asn-prog__bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22c55e, #15803d); transition: width 0.4s ease; }
.asn-prog small { display: block; margin-top: 5px; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.asn-pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 1rem 0.4rem 0.55rem; border-radius: 999px; color: #fff; font-size: 0.95rem; font-weight: 800; white-space: nowrap; }
.asn-pill__ico { width: 1.05rem; height: 1.05rem; padding: 0.17rem; box-sizing: content-box; border-radius: 50%; background: rgba(255, 255, 255, 0.24); }
.asn-pill.is-open { background: linear-gradient(135deg, #f97316, #c2410c); animation: asn-pulse 2.4s ease-in-out infinite; } .asn-pill.is-ended { background: linear-gradient(135deg, #15803d, #14532d); }
@keyframes asn-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(234, 88, 12, 0.45); } 50% { box-shadow: 0 0 0 6px rgba(234, 88, 12, 0); } }
@media (prefers-reduced-motion: reduce) { .asn-pill.is-open { animation: none; } }
.asn-link { font-weight: 800; color: #0369a1; text-decoration: none; } .asn-link:hover { text-decoration: underline; }
.asn-note { margin: 4px 0 10px; }
.asn-no { width: 1%; white-space: nowrap; font-weight: 700; color: var(--lg-mute, #64748b); } .asn-wrap { white-space: normal; min-width: 140px; }
.asn-search { min-width: 180px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--lg-line, #d1d5db); background: transparent; color: inherit; font-size: 0.85rem; }
.asn-more { text-align: center; margin: 10px 0 0; }
.asn-dlg { width: 100%; --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; background: var(--lg-card); color: var(--lg-ink); border-radius: 0.5rem; overflow: hidden; }
html.dark .asn-dlg { --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; }
.asn-dlg__head { display: flex; align-items: center; gap: 0.8rem; padding: 0.9rem 1.1rem; color: #fff; background: linear-gradient(135deg, #475569, #1e293b); } .asn-dlg__head h3 { margin: 0; font-size: 1rem; font-weight: 800; } .asn-dlg__head p { margin: 0.1rem 0 0; font-size: 0.8rem; opacity: 0.95; }
.asn-dlg__body { display: flex; flex-direction: column; gap: 0.8rem; padding: 1rem 1.1rem; max-height: 68vh; overflow-y: auto; } .asn-dlg__body > * { flex: none; max-width: 100%; } .asn-dlg__body .ac-f { display: flex; flex-direction: column; gap: 6px; width: 100%; } .asn-dlg__body textarea { width: 100%; border-radius: 12px; border: 1px solid var(--lg-line); background: transparent; padding: 10px 12px; color: inherit; }
.asn-hint { margin: 0; font-size: 0.8rem; color: var(--lg-mute); line-height: 1.45; } .asn-err { margin: 0; font-size: 0.82rem; font-weight: 700; color: #b91c1c; }
.asn-pick__bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; } .asn-chk { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.86rem; cursor: pointer; } .asn-pick__bar .asn-search { margin-left: auto; }
.asn-pick { list-style: none; margin: 0; padding: 6px; display: grid; gap: 4px; max-height: 300px; overflow-y: auto; border: 1px solid var(--lg-line); border-radius: 12px; }
.asn-pick li { border-radius: 8px; } .asn-pick li:nth-child(odd) { background: var(--lg-soft); } .asn-pick li.is-on { box-shadow: inset 0 0 0 1.5px rgba(185, 28, 28, 0.45); }
.asn-pick label { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 10px; padding: 6px 8px; cursor: pointer; }
.asn-pick__name { display: grid; min-width: 0; line-height: 1.25; } .asn-pick__name small { color: var(--lg-mute); font-size: 0.72rem; } .asn-pick__qty { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 0.82rem; white-space: nowrap; }
.asn-dlg__foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; padding: 0.8rem 1.1rem 1rem; border-top: 1px solid var(--lg-line); }
@media (max-width: 560px) { .asn-dlg__foot .lg-btn { flex: 1 1 100%; justify-content: center; } .asn-pick label { grid-template-columns: auto minmax(0, 1fr) auto; } .asn-pick label .lg-chip { grid-column: 2 / -1; justify-self: start; } .asn-pick__bar .asn-search { margin-left: 0; width: 100%; } }
</style>
