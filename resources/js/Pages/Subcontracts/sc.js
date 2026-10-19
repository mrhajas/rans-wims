// Shared by the Subcontractor Management pages: money comes from decimal STRINGS (never through floating point), status colours, error helper.
import { fmtMoney } from '@/Pages/Accounts/acct';

export const sc = {
  computed: {
    defaultCur() { return (this.a && this.a.currency) || (this.r && this.r.currency) || (this.v && this.v.currency) || (typeof this.currency === 'string' ? this.currency : '') || ''; },
  },
  methods: {
    // an amount with its currency: the one given, else the currency of the record on the page (a, r, v, currency)
    m(v, cur) { if (v === null || v === undefined || v === '') return '-'; const c = cur || this.defaultCur; return (c ? c + ' ' : '') + fmtMoney(v, cur || c, null); },
    // class of an amount: maroon bold by default, red when negative
    ac(v, base) { return [base || 'amt', String(v).startsWith('-') ? 'amt-neg' : '']; },
    mc(v, cur) { return v === null || v === undefined || v === '' ? '-' : (cur ? cur + ' ' : '') + fmtMoney(v, cur, null); },
    err(k) { return (this.$page.props.errors || {})[k]; },
    neg(v) { return String(v).startsWith('-'); },
    chip(s) {
      return {
        Draft: 'lg-chip-slate', Submitted: 'lg-chip-amber', 'Under Review': 'lg-chip-sky', Reviewed: 'lg-chip-sky', Returned: 'lg-chip-violet', Rejected: 'lg-chip-red', Approved: 'lg-chip-blue', Active: 'lg-chip-green', Suspended: 'lg-chip-amber',
        Completed: 'lg-chip-green', Archived: 'lg-chip-slate', Terminated: 'lg-chip-red', Cancelled: 'lg-chip-slate', Paid: 'lg-chip-green', Authorised: 'lg-chip-blue', Pending: 'lg-chip-amber', Deferred: 'lg-chip-amber', 'Partially Approved': 'lg-chip-teal', 'Partly Paid': 'lg-chip-amber', Unpaid: 'lg-chip-slate', open: 'lg-chip-red', approved: 'lg-chip-green', imported: 'lg-chip-green', staged: 'lg-chip-amber', reverted: 'lg-chip-slate',
      }[s] || 'lg-chip-slate';
    },
  },
};

// Field settings of a record kind (Subcontract Settings > Fields): is an optional built-in field in use, is it required.
export const fieldCfg = {
  methods: {
    fon(f, key) { return !f || !f.builtin || !f.builtin[key] || f.builtin[key].on !== false; },
    freq(f, key) { return !!(f && f.builtin && f.builtin[key] && f.builtin[key].required); },
    fstar(f, key) { return this.freq(f, key) ? ' *' : ''; },
  },
};

// A find field above a table that is already on the page (no server round trip): `has(...)` is true when the typed text appears in any of the values.
export const tableFind = {
  data() { return { tq: '' }; },
  methods: {
    has(...vals) { const q = (this.tq || '').trim().toLowerCase(); if (!q) return true; return vals.some(v => v !== null && v !== undefined && String(v).toLowerCase().includes(q)); },
  },
};
