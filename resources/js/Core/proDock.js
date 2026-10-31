import { reactive } from 'vue';

// The Pro menu is kept alive by the application itself (Shared/Sidebar/ProDockHost.vue sits beside the page, not inside it), so changing page only
// changes the content: the menu is never drawn again, keeps its scroll position and only moves its highlight. The pages that show it say so here.
const read = () => { try { return window.localStorage.getItem('wims_sidebar_hidden') === '1'; } catch (e) { return false; } };

export const proDock = reactive({
  collapsed: typeof window === 'undefined' ? false : read(),
  want: {},
  get visible() { return Object.values(this.want).some(Boolean); },
});

export function proDockWant(uid, on) { proDock.want = { ...proDock.want, [uid]: !!on }; }
export function proDockDrop(uid) { const w = { ...proDock.want }; delete w[uid]; proDock.want = w; }
export function proDockCollapse(v) {
  proDock.collapsed = !!v;
  try { window.localStorage.setItem('wims_sidebar_hidden', v ? '1' : '0'); } catch (e) { /* no storage */ }
}
