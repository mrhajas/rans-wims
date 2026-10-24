// WIMSChat client state: one reactive store shared by the floating drawer and the full module page, so there is exactly
// one poller, one conversation list and one open thread no matter where the chat is shown.
//
// Real-time = short polling (WIMS has no websocket server): a cheap unread-badge poll while the chat is closed, and one
// combined poll every few seconds while it is open. Polling pauses while the tab is hidden or WIMS is offline.
import { reactive } from 'vue';
import { route } from 'ziggy-js';
import { offlineState } from '@/Core/offlineStatus';

export const chat = reactive({
  booted: false,
  booting: false,
  bootError: null,
  me: null,
  config: null,
  can: {},
  features: {},
  quota: null,
  unread: 0,
  invites: 0,
  invitations: [],
  online: [],
  drawerOpen: false,
  pageOpen: false, // the module page (Index.vue) is showing the workspace
  list: { items: [], more: false, loaded: false, loading: false, filter: 'all', q: '' },
  activeId: null,
  thread: { loading: false, messages: [], moreBefore: false, moreAfter: false, members: [], invited: [], typing: [], pinned: [], counts: { media: 0, files: 0 }, conversation: null, error: null, removed: false, anchor: 0 },
  pending: [],
  serverTime: null,
  nudgeAt: 0,
  playing: null,
  theme: 'teal',
  toast: null,
});

let timer = null;
let inFlight = false;
let started = false;
let pollCount = 0;
let audioCtx = null;
const prevUnread = {};

const http = () => window.axios;

export const workspaceVisible = () => chat.drawerOpen || chat.pageOpen;

// ---- helpers -----------------------------------------------------------------------------------------------------

export function errorText(e, fallback = 'Something went wrong. Please try again.') {
  const d = e && e.response && e.response.data;
  if (d && d.errors) {
    const first = Object.values(d.errors)[0];
    if (first && first[0]) return first[0];
  }
  if (d && d.message) return d.message;
  if (e && e.response && e.response.status === 403) return 'You no longer have permission to do this.';
  if (e && e.response && e.response.status === 429) return 'Too many requests. Please wait a moment.';
  if (e && !e.response) return 'You appear to be offline. Please check your connection.';
  return fallback;
}

export function toast(text, tone = 'info') {
  chat.toast = { text, tone, at: Date.now() };
  setTimeout(() => { if (chat.toast && Date.now() - chat.toast.at >= 3800) chat.toast = null; }, 4000);
}

export function human(bytes) {
  const b = Number(bytes || 0);
  if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
  return Math.max(1, Math.round(b / 1024)) + ' KB';
}

function uuid() {
  if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
  return 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
}

// ---- sound / desktop alert ---------------------------------------------------------------------------------------

function quiet() {
  const p = chat.me && chat.me.presence;
  return !chat.me || !chat.me.prefs.sound || (p && p.quiet);
}

export function ping(strong = false) {
  if (quiet()) return;
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    audioCtx = audioCtx || new Ctx();
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    const notes = strong ? [{ f: 740, s: 0, d: 0.1 }, { f: 988, s: 0.1, d: 0.1 }, { f: 1318, s: 0.2, d: 0.2 }] : [{ f: 988, s: 0, d: 0.1 }, { f: 1318, s: 0.09, d: 0.16 }];
    notes.forEach(n => {
      const o = audioCtx.createOscillator();
      const g = audioCtx.createGain();
      o.type = 'sine';
      o.frequency.value = n.f;
      const t0 = audioCtx.currentTime + n.s;
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(0.12, t0 + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + n.d);
      o.connect(g); g.connect(audioCtx.destination);
      o.start(t0); o.stop(t0 + n.d + 0.02);
    });
  } catch (e) { /* sound is a nicety */ }
}

function desktopAlert(title, body, tag) {
  try {
    if (!chat.me || !chat.me.prefs.desktop || !document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
    new Notification(title, { body: chat.me.prefs.preview ? body : '', tag, icon: '/icons/icon-192x192.png' });
  } catch (e) { /* ignore */ }
}

// ---- boot --------------------------------------------------------------------------------------------------------

export async function boot() {
  if (chat.booted || chat.booting) return;
  chat.booting = true;
  chat.bootError = null;
  try {
    const { data } = await http().get(route('wimschat.bootstrap'));
    chat.me = data.me; chat.config = data.config; chat.can = data.can; chat.features = data.features; chat.quota = data.config.quota; chat.unread = data.unread;
    chat.invites = data.invites || 0; chat.invitations = data.invitations || []; chat.online = data.online || [];
    applyTheme();
    chat.booted = true;
  } catch (e) {
    chat.bootError = errorText(e);
  } finally {
    chat.booting = false;
  }
}

/** Starts the background badge poll (called once from the launcher, which lives on every page). */
export async function start() {
  if (started) return;
  started = true;
  await boot();
  if (!chat.booted) { started = false; return; }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tickSoon(); });
  window.addEventListener('wims-connection-changed', () => tickSoon());
  schedule();
}

function interval() {
  const c = chat.config || {};
  // while the chat is closed only the unread badge is looked at: never more often than once a minute (every open tab asks, so this is what keeps the server light)
  return workspaceVisible() ? c.poll_open_ms || 3500 : Math.max(60000, c.poll_idle_ms || 60000);
}

function schedule(ms) {
  clearTimeout(timer);
  timer = setTimeout(tick, ms == null ? interval() : ms);
}

export function tickSoon() {
  schedule(150);
}

async function tick() {
  if (document.hidden || offlineState.status === 'offline' || inFlight || !chat.booted) {
    schedule();
    return;
  }
  inFlight = true;
  try {
    if (workspaceVisible()) await poll();
    else await pollUnread();
  } catch (e) {
    /* next tick retries */
  } finally {
    inFlight = false;
    schedule();
  }
}

async function pollUnread() {
  const { data } = await http().get(route('wimschat.unread'));
  if (data.unread > chat.unread || data.invites > chat.invites) ping();
  chat.unread = data.unread;
  chat.invites = data.invites || 0;
  chat.serverTime = null; // the next full poll re-syncs the list
}

async function poll() {
  const params = {};
  pollCount += 1;
  if (!chat.serverTime || pollCount % 8 === 1) params.lists = 1; // invitations + the "online now" strip refresh every ~30 s
  if (chat.serverTime) params.since = chat.serverTime;
  if (chat.activeId) params.conversation = chat.activeId;
  const { data } = await http().get(route('wimschat.poll'), { params });
  chat.serverTime = data.server_time;
  chat.unread = data.unread;
  if (data.invites > chat.invites) ping();
  chat.invites = data.invites || 0;
  if (data.invitations) chat.invitations = data.invitations;
  if (data.online) chat.online = data.online;
  if (data.conversations && data.conversations.length) upsertConversations(data.conversations, true);
  if (chat.activeId && params.conversation === chat.activeId) {
    if (data.removed) {
      chat.thread.removed = true;
      return;
    }
    if (data.messages && data.messages.length) mergeMessages(data.messages, true);
    if (data.members) chat.thread.members = data.members;
    if (data.invited) chat.thread.invited = data.invited;
    chat.thread.typing = data.typing || [];
  }
}

// ---- conversation list -------------------------------------------------------------------------------------------

function sortItems(items) {
  return items.sort((a, b) => {
    if (a.pinned !== b.pinned) return a.pinned ? -1 : 1;
    return String(b.updated_at || '').localeCompare(String(a.updated_at || ''));
  });
}

function visibleInFilter(i) {
  const f = chat.list.filter;
  if (i.hidden) return false;
  if (f === 'archived') return i.archived;
  if (i.archived) return false;
  if (f === 'unread') return i.unread > 0 || i.marked_unread;
  if (f === 'pinned') return i.pinned;
  if (f === 'groups') return i.type === 'group';
  if (f === 'direct') return i.type === 'direct';

  return true;
}

export function upsertConversations(items, fromPoll = false) {
  items.forEach(it => {
    const idx = chat.list.items.findIndex(x => x.id === it.id);
    if (fromPoll) {
      const was = prevUnread[it.id] ?? (idx > -1 ? chat.list.items[idx].unread : 0);
      if (it.unread > was && !it.muted && (it.id !== chat.activeId || document.hidden || !workspaceVisible())) {
        ping();
        desktopAlert(it.title, it.last && it.last.text, 'c' + it.id);
      }
    }
    prevUnread[it.id] = it.unread;
    if (idx > -1) chat.list.items.splice(idx, 1, it);
    else chat.list.items.push(it);
  });
  if (chat.list.q === '') {
    chat.list.items = chat.list.items.filter(visibleInFilter);
  }
  sortItems(chat.list.items);
}

export async function loadList(reset = true) {
  if (chat.list.loading) return;
  chat.list.loading = true;
  try {
    const params = { filter: chat.list.filter, q: chat.list.q || undefined, offset: reset ? 0 : chat.list.items.length };
    const { data } = await http().get(route('wimschat.conversations.index'), { params });
    chat.list.items = reset ? data.items : chat.list.items.concat(data.items);
    data.items.forEach(i => { prevUnread[i.id] = i.unread; });
    chat.list.more = data.more;
    chat.list.loaded = true;
    chat.unread = data.unread;
    if (!chat.serverTime) chat.serverTime = new Date().toISOString();
  } catch (e) {
    toast(errorText(e), 'error');
  } finally {
    chat.list.loading = false;
  }
}

export function setFilter(f) {
  chat.list.filter = f;
  loadList(true);
}

export function setQuery(q) {
  chat.list.q = q;
  loadList(true);
}

// ---- thread ------------------------------------------------------------------------------------------------------

function resetThread() {
  Object.assign(chat.thread, { loading: false, messages: [], moreBefore: false, moreAfter: false, members: [], invited: [], typing: [], pinned: [], counts: { media: 0, files: 0 }, conversation: null, error: null, removed: false, anchor: 0 });
}

export async function openConversation(id, aroundId = null) {
  if (!id) {
    chat.activeId = null;
    resetThread();
    return;
  }
  const switching = chat.activeId !== id;
  chat.activeId = id;
  if (switching) resetThread();
  chat.thread.loading = true;
  chat.thread.error = null;
  try {
    const params = aroundId ? { around_id: aroundId, initial: 1 } : { initial: 1 };
    const { data } = await http().get(route('wimschat.conversations.messages', { conversation: id }), { params });
    if (chat.activeId !== id) return;
    Object.assign(chat.thread, {
      messages: data.messages, moreBefore: data.more_before, moreAfter: data.more_after, members: data.members || [], invited: data.invited || [], typing: data.typing || [],
      pinned: data.pinned || [], counts: data.counts || { media: 0, files: 0 }, conversation: data.conversation, removed: false, anchor: aroundId || 0,
    });
    if (data.conversation) upsertConversations([data.conversation]);
    if (!chat.serverTime) chat.serverTime = data.server_time;
    if (!aroundId) markRead();
  } catch (e) {
    chat.thread.error = errorText(e, 'Unable to open this conversation.');
    if (e.response && (e.response.status === 403 || e.response.status === 404)) chat.thread.removed = true;
  } finally {
    chat.thread.loading = false;
  }
}

export async function loadOlder() {
  if (!chat.activeId || !chat.thread.moreBefore || chat.thread.loading || !chat.thread.messages.length) return;
  const first = chat.thread.messages[0].id;
  chat.thread.loading = true;
  try {
    const { data } = await http().get(route('wimschat.conversations.messages', { conversation: chat.activeId }), { params: { before_id: first } });
    chat.thread.messages = data.messages.concat(chat.thread.messages);
    chat.thread.moreBefore = data.more_before;
  } catch (e) {
    toast(errorText(e), 'error');
  } finally {
    chat.thread.loading = false;
  }
}

export function mergeMessages(list, fromPoll = false) {
  const t = chat.thread;
  let added = false;
  list.forEach(m => {
    if (m.conversation_id !== chat.activeId) return;
    const idx = t.messages.findIndex(x => x.id === m.id);
    if (idx > -1) {
      t.messages.splice(idx, 1, m);
    } else {
      // a server answer for something we sent: drop the local bubble
      chat.pending = chat.pending.filter(p => p.uuid !== m.client_uuid);
      if (t.moreAfter && fromPoll) return; // reading history away from the newest messages: do not append
      t.messages.push(m);
      added = true;
      if (fromPoll && !m.mine && m.type !== 'system') {
        if (m.type === 'nudge') {
          chat.nudgeAt = Date.now();
          ping(true);
        } else if (document.hidden) {
          ping();
        }
      }
    }
  });
  if (added) t.messages.sort((a, b) => a.id - b.id);
  if (added && !document.hidden && workspaceVisible()) markReadSoon();
}

let readTimer = null;
export function markReadSoon() {
  clearTimeout(readTimer);
  readTimer = setTimeout(markRead, 500);
}

export async function markRead() {
  const id = chat.activeId;
  if (!id || document.hidden || !workspaceVisible()) return;
  const last = chat.thread.messages.length ? chat.thread.messages[chat.thread.messages.length - 1].id : 0;
  const item = chat.list.items.find(i => i.id === id);
  if (item && item.unread === 0 && !item.marked_unread) {
    // nothing to clear locally; still confirm the cursor once so the sender sees "read"
    const me = chat.thread.members.find(m => m.user.id === chat.me.id);
    if (me && me.last_read >= last) return;
  }
  try {
    const { data } = await http().post(route('wimschat.conversations.read', { conversation: id }), { message_id: last });
    chat.unread = data.unread;
    if (data.conversation) upsertConversations([data.conversation]);
    const me = chat.thread.members.find(m => m.user.id === chat.me.id);
    if (me) me.last_read = Math.max(me.last_read, last);
  } catch (e) { /* the next poll fixes it */ }
}

// ---- sending -----------------------------------------------------------------------------------------------------

export function pendingFor(id) {
  return chat.pending.filter(p => p.conversation_id === id);
}

export async function sendMessage({ body, files = [], replyTo = null, voice = null }) {
  const convId = chat.activeId;
  if (!convId) return;
  const local = reactive({
    id: 'l-' + uuid(), uuid: uuid(), local: true, state: 'sending', progress: 0, error: null, conversation_id: convId, _files: files, _reply: replyTo && replyTo.id,
    user: { id: chat.me.id, name: chat.me.name, photo: chat.me.photo }, mine: true, type: 'text', body: body || null, created_at: new Date().toISOString(),
    _voice: voice,
    attachments: files.map((f, i) => ({ id: 'lf-' + i, name: f.name, size: f.size, is_image: !voice && f.type.startsWith('image/'), is_voice: !!voice, duration_ms: voice ? voice.duration_ms : null, localUrl: voice ? URL.createObjectURL(f) : (f.type.startsWith('image/') ? URL.createObjectURL(f) : null) })),
    reactions: [], mentions: [], deleted: false, edited: false, forwarded: null, pinned: false, can: {},
    reply_to: replyTo ? { id: replyTo.id, user: replyTo.user && replyTo.user.name, text: replyTo.body || '📎', deleted: false } : null,
  });
  chat.pending.push(local);
  return transmit(local);
}

async function transmit(local) {
  local.state = 'sending';
  local.error = null;
  local.progress = 0;
  const fd = new FormData();
  if (local.body) fd.append('body', local.body);
  fd.append('client_uuid', local.uuid);
  if (local._reply) fd.append('reply_to_id', local._reply);
  if (local._voice) { fd.append('voice', '1'); fd.append('duration_ms', String(local._voice.duration_ms)); }
  (local._files || []).forEach(f => fd.append('files[]', f));
  try {
    const { data } = await http().post(route('wimschat.messages.store', { conversation: local.conversation_id }), fd, {
      onUploadProgress: e => { if (e.total) local.progress = Math.round((e.loaded / e.total) * 100); },
    });
    chat.pending = chat.pending.filter(p => p.uuid !== local.uuid);
    chat.thread.moreAfter = false;
    if (chat.activeId === local.conversation_id) {
      if (!chat.thread.messages.find(m => m.id === data.message.id)) chat.thread.messages.push(data.message);
      chat.thread.messages.sort((a, b) => a.id - b.id);
    }
    if (data.conversation) upsertConversations([data.conversation]);
    if (data.quota) chat.quota = data.quota;
    return data.message;
  } catch (e) {
    local.state = 'failed';
    local.error = errorText(e, 'Unable to send message. Please try again.');
    if (e.response && e.response.data && e.response.data.quota) chat.quota = e.response.data.quota;
    return null;
  }
}

export function retryPending(local) {
  return transmit(local);
}

export function discardPending(local) {
  chat.pending = chat.pending.filter(p => p.uuid !== local.uuid);
}

// ---- message actions ---------------------------------------------------------------------------------------------

function replaceMessage(m) {
  const i = chat.thread.messages.findIndex(x => x.id === m.id);
  if (i > -1) chat.thread.messages.splice(i, 1, m);
}

export async function editMessage(id, body) {
  const { data } = await http().put(route('wimschat.messages.update', { message: id }), { body });
  replaceMessage(data.message);
}

export async function deleteMessage(id) {
  const { data } = await http().delete(route('wimschat.messages.destroy', { message: id }));
  replaceMessage(data.message);
  if (data.quota) chat.quota = data.quota;
  tickSoon();
}

export async function reactTo(id, emoji) {
  const { data } = await http().post(route('wimschat.messages.react', { message: id }), { emoji });
  replaceMessage(data.message);
}

export async function pinMessage(id) {
  const { data } = await http().post(route('wimschat.messages.pin', { message: id }));
  replaceMessage(data.message);
  const t = chat.thread;
  if (data.pinned) t.pinned.unshift({ id, user: data.message.user && data.message.user.name, text: (data.message.body || '📎').slice(0, 120), at: data.message.created_at });
  else t.pinned = t.pinned.filter(p => p.id !== id);
}

export async function forwardMessage(id, conversationIds) {
  const { data } = await http().post(route('wimschat.messages.forward', { message: id }), { conversations: conversationIds });
  tickSoon();
  return data.forwarded;
}

export async function sendNudge() {
  if (!chat.activeId) return;
  const { data } = await http().post(route('wimschat.nudge', { conversation: chat.activeId }));
  chat.thread.messages.push(data.message);
  chat.thread.messages.sort((a, b) => a.id - b.id);
  if (data.conversation) upsertConversations([data.conversation]);
}

export async function deleteAttachment(id) {
  await http().delete(route('wimschat.attachments.destroy', { attachment: id }));
  tickSoon();
}

let lastTyping = 0;
export function notifyTyping() {
  if (!chat.activeId || !chat.features.typing || Date.now() - lastTyping < 3000) return;
  lastTyping = Date.now();
  http().post(route('wimschat.typing', { conversation: chat.activeId })).catch(() => {});
}

// ---- conversation actions ----------------------------------------------------------------------------------------

export async function setState(id, patch) {
  const { data } = await http().patch(route('wimschat.conversations.state', { conversation: id }), patch);
  chat.unread = data.unread;
  if (data.conversation) {
    chat.list.items = chat.list.items.filter(i => i.id !== id);
    upsertConversations([data.conversation]);
  }
  if (patch.hidden && chat.activeId === id) openConversation(null);
  return data.conversation;
}

export async function startDirect(userId) {
  const { data } = await http().post(route('wimschat.conversations.store'), { user_id: userId });
  upsertConversations([data.conversation]);
  await openConversation(data.conversation.id);
  return data.conversation;
}

export async function createGroup(payload) {
  const { data } = await http().post(route('wimschat.groups.store'), payload);
  upsertConversations([data.conversation]);
  await openConversation(data.conversation.id);
  return data.conversation;
}

export function openFromNotification(id) {
  chat.drawerOpen = true;
  openConversation(id);
}

// ---- derived -----------------------------------------------------------------------------------------------------

/** sending → sent → delivered → read, from the other people's cursors. */
export function messageStatus(m) {
  if (m.local) return m.state;
  const others = chat.thread.members.filter(x => x.user.id !== chat.me.id);
  if (!others.length) return 'sent';
  if (chat.config.read_receipts && others.every(o => o.receipts && o.last_read >= m.id)) return 'read';
  if (others.every(o => o.last_delivered >= m.id || o.last_read >= m.id)) return 'delivered';

  return 'sent';
}

export function savePrefs(patch) {
  return http().put(route('wimschat.prefs.update'), patch).then(({ data }) => {
    chat.me.status = data.status;
    chat.me.prefs = data.prefs;
    chat.me.presence = data.presence;
    applyTheme();
  });
}

// ---- invitations -------------------------------------------------------------------------------------------------

export async function respondInvitation(conversationId, accept) {
  const { data } = await http().post(route('wimschat.groups.respond', { conversation: conversationId }), { accept });
  chat.invitations = chat.invitations.filter(i => i.conversation_id !== conversationId);
  chat.invites = data.invites;
  if (accept && data.conversation) {
    upsertConversations([data.conversation]);
    await openConversation(data.conversation.id);
  }
  return data;
}

// ---- personal colour theme ---------------------------------------------------------------------------------------

export function currentTheme() {
  const t = chat.me && chat.me.prefs ? chat.me.prefs.theme : 'teal';
  const allowed = (chat.config && chat.config.themes) || [];

  return allowed.includes(t) ? t : allowed[0] || 'teal';
}

export function applyTheme() {
  chat.theme = currentTheme();
}
