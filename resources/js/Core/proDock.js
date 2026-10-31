import { reactive } from 'vue';

// The Pro menu is kept alive by the application itself (Shared/Sidebar/ProDockHost.vue sits beside the page, not inside it), so changing page only
// changes the content: the menu is never drawn again, keeps its scroll position and only moves its highlight. The pages that show it say so here.

export const proDock = reactive({
  collapsed: false,
  want: {},
  get visible() { return Object.values(this.want).some(Boolean); },
});

export function proDockWant(uid, on) { proDock.want = { ...proDock.want, [uid]: !!on }; }
export function proDockDrop(uid) { const w = { ...proDock.want }; delete w[uid]; proDock.want = w; }
// `save` is for the person pressing the fold button of the Pro menu: it keeps its own remembered state, apart from the Classic menu's
export function proDockCollapse(v, save = false) {
  proDock.collapsed = !!v;
  if (save) { try { window.localStorage.setItem('wims_pro_collapsed', v ? '1' : '0'); } catch (e) { /* no storage */ } }
}
