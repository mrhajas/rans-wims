import { router } from '@inertiajs/vue3';

// Click-to-sort for a list that the server pages: the order is asked of the server (so it covers every page, not only the one on screen).
// Click once: ascending; again: descending; again: back to the usual order.
export const serverSort = {
  computed: {
    sortKey() { return (this.filters && this.filters.sort) || ''; },
    sortDir() { return (this.filters && this.filters.dir) || 'asc'; },
  },
  methods: {
    sortBy(col) {
      const f = { ...(this.filters || {}) };
      if (f.sort !== col) { f.sort = col; f.dir = 'asc'; } else if ((f.dir || 'asc') === 'asc') { f.dir = 'desc'; } else { delete f.sort; delete f.dir; }
      const q = {}; Object.keys(f).forEach(k => { if (f[k] !== '' && f[k] !== null && f[k] !== undefined) q[k] = f[k]; });
      router.get(window.location.pathname, q, { preserveState: true, preserveScroll: true, replace: true });
    },
  },
};
