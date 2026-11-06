<template>
  <section v-if="archived" class="an-box print:hidden" role="note">
    <span class="an-ico"><icons name="archive-box" class="w-5 h-5" /></span>
    <div class="an-text">
      <b>{{ $t(':n older :what are in the archive', { n: archived.rows.toLocaleString(), what: what }) }}</b>
      <span>{{ $t('From :a to :b. They stay in this list for :d days, then WIMS Logs moves them to a compressed archive. Nothing is deleted.', { a: day(archived.from), b: day(archived.to), d: archived.keep_days }) }}</span>
    </div>
    <Link v-if="archived.can_read" :href="route('wims-logs.browse', { source: archived.source })" class="lg-btn tone tone-solid an-go" style="--tone: #059669"><span class="tone-ico"><icons name="search" class="w-4 h-4" /></span>{{ $t('Read archived records') }}</Link>
  </section>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// "N older records are in the archive": shown above a list whose old rows WIMS Logs has moved out, with a way to read them
export default {
  components: { Link },
  props: { archived: { type: Object, default: null }, what: { type: String, default: 'records' } },
  methods: { day(d) { return d ? String(d).slice(0, 10) : '-'; } },
};
</script>

<style scoped>
.an-box { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; margin: 0 0 1rem; padding: 0.85rem 1rem; border-radius: 1.1rem; border: 1.5px solid rgba(5, 150, 105, 0.28); background: linear-gradient(135deg, rgba(5, 150, 105, 0.1), rgba(99, 102, 241, 0.07)); animation: an-in 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; }
.an-ico { flex: none; width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: 0.9rem; color: #fff; background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 8px 14px -8px #059669; }
.an-text { display: grid; gap: 0.1rem; flex: 1 1 18rem; min-width: 0; } .an-text b { font-size: 0.92rem; } .an-text span { font-size: 0.78rem; color: #64748b; line-height: 1.4; }
.an-go { transition: transform 0.2s ease; } .an-go:hover { transform: translateY(-2px); }
@keyframes an-in { from { opacity: 0; transform: translateY(10px); } }
@media (max-width: 640px) { .an-go { width: 100%; justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .an-box { animation: none; } }
</style>
