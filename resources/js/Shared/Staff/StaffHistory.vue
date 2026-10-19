<template>
  <section class="lg-card sth">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Employment history') }}</h2>
        <p class="lg-card-sub">{{ $t('Everything that happened to this person: contracts added, renewed and stopped, retirement and status changes, with the reasons. Nothing here is ever removed.') }}</p>
      </div>
      <span class="sth-cnt">{{ history.length }}</span>
    </header>

    <p v-if="!history.length" class="sth-empty">{{ $t('No history has been recorded yet.') }}</p>
    <ol v-else class="sth-list">
      <li v-for="h in history" :key="h.id" :class="'k-' + kind(h)">
        <span class="sth-ico"><icons :name="icon(h)" class="w-4 h-4" /></span>
        <div class="sth-body">
          <div class="sth-top"><b>{{ h.title }}</b><time>{{ $date(h.occurred_on) }}</time></div>
          <div v-if="h.reason_type || (h.from_status && h.to_status)" class="sth-meta">
            <span v-if="h.reason_type" class="lg-chip" :class="reasonChip(h)" style="text-transform: none">{{ $t(stopReasons[h.reason_type] || h.reason_type) }}</span>
            <span v-if="h.from_status && h.to_status" class="sth-flow"><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ h.from_status }}</span><icons name="chevron-down" class="w-3 h-3 -rotate-90" /><span class="lg-chip" :class="statusChip(h.to_status)" style="text-transform: none">{{ h.to_status }}</span></span>
          </div>
          <p v-if="h.reason" class="sth-reason">{{ h.reason }}</p>
          <ul v-if="changes(h).length" class="sth-changes"><li v-for="c in changes(h)" :key="c.k"><span>{{ c.k }}</span>{{ c.from || '-' }} <icons name="chevron-down" class="w-3 h-3 -rotate-90" /> <b>{{ c.to || '-' }}</b></li></ul>
          <small class="sth-by">{{ h.by || $t('System') }} · {{ $date(h.at) }} {{ $time(h.at) }}</small>
        </div>
      </li>
    </ol>
  </section>
</template>

<script>
export default {
  props: { history: { type: Array, default: () => [] }, stopReasons: { type: Object, default: () => ({}) } },
  methods: {
    kind(h) { return { contract_added: 'add', contract_renewed: 'renew', contract_stopped: 'stop', contract_deleted: 'del', contract_edited: 'edit', retired: 'retire', reinstated: 'back', status_changed: 'status', staff_created: 'add' }[h.event] || 'edit'; },
    icon(h) { return { contract_added: 'plus', contract_renewed: 'refresh', contract_stopped: 'cross', contract_deleted: 'trash', contract_edited: 'edit', retired: 'user', reinstated: 'tick', status_changed: 'refresh', staff_created: 'users' }[h.event] || 'doc-text'; },
    statusChip(s) { return { Active: 'lg-chip-green', 'Retired Active': 'lg-chip-amber', Retired: 'lg-chip-violet', Inactive: 'lg-chip-slate', Terminated: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    reasonChip(h) { return ['Terminated by management', 'Dismissed', 'Redundancy'].includes(h.reason_type) ? 'lg-chip-red' : ['Retired', 'Mutual agreement', 'Contract not renewed'].includes(h.reason_type) ? 'lg-chip-amber' : 'lg-chip-slate'; },
    changes(h) {
      const c = h.details && h.details.changes;
      return c ? Object.entries(c).map(([k, v]) => ({ k: k.replace('_', ' '), from: v.from, to: v.to })) : [];
    },
  },
};
</script>

<style>
.sth-cnt { margin-left: auto; padding: 0 10px; border-radius: 99px; font-size: 0.8rem; font-weight: 800; background: rgba(100, 116, 139, 0.18); }
.sth-empty { margin: 0; font-size: 0.88rem; color: #64748b; }
.sth-list { list-style: none; margin: 0; padding: 0 0 0 6px; }
.sth-list > li { position: relative; display: flex; gap: 0.8rem; padding: 0 0 1.1rem 1.4rem; border-left: 2px solid #cbd5e1; margin-left: 14px; } .sth-list > li:last-child { border-left-color: transparent; padding-bottom: 0; }
.sth-ico { position: absolute; left: -15px; top: 0; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; color: #fff; background: #64748b; box-shadow: 0 0 0 4px #fff; }
.k-add .sth-ico { background: #3b82f6; } .k-renew .sth-ico, .k-back .sth-ico { background: #10b981; } .k-stop .sth-ico, .k-del .sth-ico { background: #f43f5e; } .k-retire .sth-ico { background: #f59e0b; } .k-status .sth-ico { background: #8b5cf6; } .k-edit .sth-ico { background: #64748b; }
.sth-body { flex: 1; min-width: 0; padding-left: 0.4rem; } .sth-top { display: flex; align-items: baseline; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; } .sth-top b { font-size: 0.92rem; } .sth-top time { font-size: 0.78rem; font-weight: 800; color: #64748b; }
.sth-meta { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.3rem; } .sth-flow { display: inline-flex; align-items: center; gap: 0.3rem; color: #94a3b8; }
.sth-reason { margin: 0.4rem 0 0; padding: 0.5rem 0.75rem; border-radius: 12px; font-size: 0.86rem; line-height: 1.45; white-space: pre-line; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); }
.sth-changes { list-style: none; margin: 0.4rem 0 0; padding: 0; display: grid; gap: 2px; font-size: 0.8rem; color: #475569; } .sth-changes li { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; } .sth-changes li span { min-width: 5.5rem; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; }
.sth-by { display: block; margin-top: 0.4rem; font-size: 0.74rem; color: #94a3b8; }
html.dark .sth-ico { box-shadow: 0 0 0 4px #0f172a; }

/* polish */
.sth-list > li { animation: sth-in 0.45s ease both; } .sth-list > li:nth-child(2) { animation-delay: 0.06s; } .sth-list > li:nth-child(3) { animation-delay: 0.12s; } .sth-list > li:nth-child(4) { animation-delay: 0.18s; } .sth-list > li:nth-child(n+5) { animation-delay: 0.24s; }
.sth-list > li:first-child .sth-ico { animation: sth-pulse 2.6s ease-out infinite; }
.sth-ico { transition: transform 0.2s ease; } .sth-list > li:hover .sth-ico { transform: scale(1.12) rotate(-6deg); }
.sth-body { padding: 0.45rem 0.7rem; border-radius: 14px; transition: background 0.18s ease, transform 0.18s ease; } .sth-list > li:hover .sth-body { background: rgba(99, 102, 241, 0.06); transform: translateX(2px); }
.sth-list > li { border-left-image: none; } .sth-list > li:not(:last-child) { border-image: linear-gradient(180deg, #cbd5e1, rgba(203, 213, 225, 0.3)) 1; border-top: 0; border-right: 0; border-bottom: 0; border-left-width: 2px; }
@keyframes sth-in { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: none; } }
@keyframes sth-pulse { 0% { box-shadow: 0 0 0 4px #fff, 0 0 0 4px rgba(99, 102, 241, 0.5); } 100% { box-shadow: 0 0 0 4px #fff, 0 0 0 13px rgba(99, 102, 241, 0); } }
@media (prefers-reduced-motion: reduce) { .sth-list > li, .sth-list > li:first-child .sth-ico { animation: none !important; } }
</style>
