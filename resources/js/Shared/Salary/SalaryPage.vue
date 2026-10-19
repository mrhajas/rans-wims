<template>
  <div class="lg-page px-4 md:px-0 sl-page">
    <slot />
  </div>
</template>

<script>
// Shared page shell for the Salary module: gives every page the common `.lg-page` design tokens
// plus the small `sl-*` helper classes below (two-column layouts, key/value rows, mini panels...).
export default {};
</script>

<style>
.sl-page .lg-chip { text-transform: none; }
.sl-page .sl-split { display: grid; gap: 1rem; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); align-items: start; }
.sl-page .sl-split > * { min-width: 0; margin-bottom: 0; }
@media (max-width: 1023.98px) { .sl-page .sl-split { grid-template-columns: minmax(0, 1fr); } }
.sl-page .sl-split-even { grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 1023.98px) { .sl-page .sl-split-even { grid-template-columns: minmax(0, 1fr); } }
.sl-page .sl-split-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
@media (max-width: 1023.98px) { .sl-page .sl-split-3 { grid-template-columns: minmax(0, 1fr); } }
.sl-page .sl-stack { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.sl-page .sl-stack > .lg-card, .sl-page .sl-stack > form > .lg-card:last-of-type { margin-bottom: 0; }
.sl-page .sl-mb { margin-bottom: 1rem; }
.sl-page .sl-kpis { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin-bottom: 1rem; }
@media (max-width: 480px) { .sl-page .sl-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; } }

.sl-page .sl-sub { min-width: 0; padding: 0.75rem 0.95rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sl-page .sl-sub + .sl-sub { margin-top: 8px; }
.sl-page .sl-sub-label { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sl-page .sl-sub-value { margin-top: 2px; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sl-page .sl-sub-value.is-good { color: #047857; }
.sl-page .sl-sub-value.is-bad { color: #be123c; }
.sl-page .sl-sub-note { margin-top: 2px; font-size: 0.7rem; color: var(--lg-faint); }
.sl-page .sl-sub-grid { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
.sl-page .sl-sub-grid .sl-sub + .sl-sub { margin-top: 0; }
@media (max-width: 520px) { .sl-page .sl-sub-grid { grid-template-columns: minmax(0, 1fr); } }

.sl-page .sl-kv > div { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 9px 0; border-bottom: 1px solid var(--lg-line); font-size: 0.86rem; }
.sl-page .sl-kv > div:last-child { border-bottom: 0; }
.sl-page .sl-kv dt, .sl-page .sl-kv .k { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sl-page .sl-kv dd, .sl-page .sl-kv .v { font-weight: 700; color: var(--lg-ink); text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sl-page .sl-kv .is-total { font-size: 0.95rem; }
.sl-page .sl-kv .is-total dd, .sl-page .sl-kv .is-total dt { color: var(--lg-ink); }
.sl-page .sl-kv .is-accent dd { color: #4338ca; }
html.dark .sl-page .sl-kv .is-accent dd { color: #a5b4fc; }
.sl-page .sl-hint { font-size: 0.76rem; line-height: 1.5; color: var(--lg-mute); }
.sl-page .sl-link { font-weight: 800; color: #1d4ed8; }
.sl-page .sl-link:hover { text-decoration: underline; }
html.dark .sl-page .sl-link { color: #93c5fd; }
.sl-page .sl-empty { padding: 28px 16px; text-align: center; font-size: 0.85rem; color: var(--lg-faint); border: 1px dashed var(--lg-line); border-radius: 16px; }
.sl-page .sl-link-btn { font-size: 0.78rem; font-weight: 800; color: #1d4ed8; }
.sl-page .sl-link-btn:hover { text-decoration: underline; }
html.dark .sl-page .sl-link-btn { color: #93c5fd; }
.sl-page .is-good { color: #047857; }
.sl-page .is-bad { color: #be123c; }
.sl-page .is-warn { color: #b45309; }
.sl-page .is-accent { color: #4338ca; }
html.dark .sl-page .is-good { color: #6ee7b7; }
html.dark .sl-page .is-bad { color: #fda4af; }
html.dark .sl-page .is-warn { color: #fcd34d; }
html.dark .sl-page .is-accent { color: #a5b4fc; }

.sl-page .sl-form { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.sl-page .sl-form > .lg-card { margin-bottom: 0; }
.sl-page .sl-input { padding: 0.5rem 0.75rem; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.84rem; min-width: 0; }
.sl-page .sl-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.sl-page .sl-input:disabled { opacity: 0.55; cursor: not-allowed; }
.sl-page .sl-check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; }
.sl-page .sl-check-sm { font-size: 0.76rem; }

/* list cards (advances / loans / opening balances) */
.sl-page .sl-cards { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(min(340px, 100%), 1fr)); }
@media (max-width: 480px) { .sl-page .sl-cards { grid-template-columns: minmax(0, 1fr); } }
.sl-page .sl-card-row { display: block; min-width: 0; padding: 1rem 1.1rem; border-radius: 20px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); transition: transform 0.15s ease, border-color 0.15s ease; }
.sl-page .sl-card-row:hover { transform: translateY(-2px); border-color: #93c5fd; }
.sl-page .sl-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.sl-page .sl-card-head .lg-person-name { min-width: 0; flex: 1; }
.sl-page .sl-card-name { font-size: 0.92rem; font-weight: 800; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sl-page .sl-card-sub { font-size: 0.72rem; color: var(--lg-faint); }
.sl-page .sl-card-figs { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 14px; margin-bottom: 8px; font-size: 0.82rem; color: var(--lg-mute); }
.sl-page .sl-card-figs strong { color: var(--lg-ink); font-weight: 800; font-variant-numeric: tabular-nums; }
.sl-page .sl-card-figs strong.is-bad { color: #be123c; }
html.dark .sl-page .sl-card-figs strong.is-bad { color: #fda4af; }
.sl-page .sl-progress { height: 8px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); overflow: hidden; }
.sl-page .sl-progress > div { height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #34d399, #047857); transition: width 0.4s ease; }
.sl-page .sl-progress-note { margin-top: 4px; text-align: right; font-size: 0.7rem; color: var(--lg-faint); }

/* report tabs */
.sl-page .sl-tabs { display: flex; gap: 6px; margin-bottom: 1rem; padding: 6px; overflow-x: auto; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.sl-page .sl-tab { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: var(--lg-mute); white-space: nowrap; transition: background 0.15s ease, color 0.15s ease; }
.sl-page .sl-tab:hover { color: var(--lg-navy); background: var(--lg-soft); }
.sl-page .sl-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }

/* tables */
.sl-page .lg-table tfoot td { padding: 12px 16px; font-size: 0.83rem; font-weight: 800; color: var(--lg-ink); background: var(--lg-soft); border-top: 1.5px solid var(--lg-line); }
.sl-page .lg-table .lg-clickrow { cursor: pointer; }
.sl-page .sl-flush { padding: 0.85rem; }
@media (max-width: 767.98px) { .sl-page .sl-flush { padding: 0.5rem; } }

.sl-page .sl-banner { margin-bottom: 1rem; }
.sl-page .sl-banner-list > div + div { margin-top: 2px; }
</style>
