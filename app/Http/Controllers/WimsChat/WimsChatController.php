<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\User;
use App\Models\WimsChatAttachment;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Models\WimsChatMessage;
use App\Models\WimsChatUserPref;
use App\Services\WimsChat\WimsChatLogger;
use App\Services\WimsChat\WimsChatSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** The WIMSChat module page plus the small JSON endpoints every part of the UI uses (bootstrap, badge, poll, people, search). */
class WimsChatController extends WimsChatBaseController
{
    public function index(Request $request)
    {
        $this->me();

        return Inertia::render('WimsChat/Index', ['open' => $request->integer('c') ?: null]);
    }

    /** Everything the chat UI needs before it can draw: me, limits, reactions, statuses, abilities, features, quota. */
    public function bootstrap()
    {
        $me = $this->me();
        $cfg = $this->cfg($me);
        $pref = WimsChatUserPref::query()->where('user_id', $me->id)->first();
        $policies = $cfg->policies();

        return response()->json([
            'me' => [
                'id' => $me->id, 'name' => $me->name, 'username' => $me->username, 'photo' => $me->profile_photo_url,
                'status' => $pref->status ?? 'auto',
                'presence' => $this->svc->presence($me, $pref, $cfg, true),
                'prefs' => $this->svc->userPrefs($pref),
            ],
            'config' => [
                'max_message_chars' => (int) $cfg->get('max_message_chars'),
                'page_size' => (int) $cfg->get('page_size'),
                'poll_open_ms' => max(1500, (int) $cfg->get('poll_open_ms')),
                'poll_idle_ms' => max(10000, (int) $cfg->get('poll_idle_ms')),
                'edit_window_minutes' => (int) $cfg->get('edit_window_minutes'),
                'delete_window_minutes' => (int) $cfg->get('delete_window_minutes'),
                'nudge_cooldown_seconds' => (int) $cfg->get('nudge_cooldown_seconds'),
                'search_min_chars' => (int) $cfg->get('search_min_chars'),
                'group_max_members' => (int) $cfg->get('group_max_members'),
                'reactions' => $cfg->reactions(),
                'statuses' => collect($cfg->statuses())->where('selectable', true)->map(fn ($s) => ['key' => $s['key'], 'label' => __($s['label']), 'color' => $s['color']])->values()->all(),
                'allow_manual_status' => $cfg->flag('allow_manual_status'),
                'themes' => array_values((array) $cfg->get('themes')),
                'voice' => ['on' => $cfg->voiceOn(), 'max_seconds' => (int) $cfg->get('voice_max_seconds')],
                'invite_required' => $cfg->flag('group_invite_required'),
                'message_retention_days' => (int) $cfg->get('message_retention_days'),
                'attachment_retention_days' => (int) $cfg->get('attachment_retention_days'),
                'read_receipts' => $cfg->flag('read_receipts'),
                'show_last_seen' => $cfg->flag('show_last_seen'),
                'max_file_mb' => max(1, min((int) $cfg->get('max_file_mb'), WimsChatSettings::serverMaxMb())),
                'max_files' => (int) $cfg->get('max_files_per_message'),
                'quota' => $this->files->quota($me, $cfg),
                'accept' => collect($policies)->where('enabled', true)->flatMap(fn ($p) => array_map(fn ($e) => '.'.$e, $p['extensions']))->values()->all(),
                'categories' => collect($policies)->map(fn ($p, $k) => ['key' => $k, 'label' => __($p['label'] ?? $k), 'enabled' => (bool) $p['enabled'], 'extensions' => $p['extensions'], 'max_mb' => $p['max_mb_effective']])->values()->all(),
            ],
            'features' => $this->features($me),
            'can' => $this->can($me),
            'unread' => $this->svc->unreadTotal($me),
            'invites' => $this->svc->invitesCount($me),
            'invitations' => $this->svc->invitations($me),
            'online' => $this->svc->onlineUsers($me),
        ]);
    }

    /** The cheap badge poll (closed launcher): one indexed count, and this browser has "received" what is there. */
    public function unread()
    {
        $me = $this->me();
        $this->svc->markDelivered($me);

        return response()->json(['unread' => $this->svc->unreadTotal($me), 'invites' => $this->svc->invitesCount($me), 'server_time' => now()->toIso8601String()]);
    }

    /**
     * The one poll of the open panel: what changed since the last answer — conversation list rows, the open
     * conversation's new / edited / deleted / reacted messages, people typing, read cursors and presence.
     */
    public function poll(Request $request)
    {
        $me = $this->me();
        $cfg = $this->cfg($me);
        $now = now();
        $since = $request->filled('since') ? Carbon::parse($request->input('since'))->subSeconds(2) : null;
        $out = ['server_time' => $now->toIso8601String(), 'unread' => $this->svc->unreadTotal($me), 'invites' => $this->svc->invitesCount($me), 'conversations' => [], 'removed' => false];
        if ($request->boolean('lists')) {
            $out['invitations'] = $this->svc->invitations($me);
            $out['online'] = $this->svc->onlineUsers($me);
        }

        $this->svc->markDelivered($me);
        if ($since) {
            $out['conversations'] = $this->svc->conversationItems($me, ['since' => $since, 'limit' => 60])['items'];
        }

        if ($cid = $request->integer('conversation')) {
            $c = WimsChatConversation::query()->find($cid);
            $member = $c ? WimsChatMember::query()->where('conversation_id', $cid)->where('user_id', $me->id)->whereNull('left_at')->first() : null;
            if (! $c || ! $member) {
                $out['removed'] = true;

                return response()->json($out);
            }
            if ($since) {
                $changed = WimsChatMessage::withTrashed()->where('conversation_id', $cid)->where('updated_at', '>=', $since)
                    ->where('created_at', '>=', $member->joined_at ?? $c->created_at)->orderBy('id')->limit(100)->get();
                $out['messages'] = $this->messenger->payload($changed, $me, $c);
            }
            $out['members'] = $this->svc->membersPayload($c, $me);
            $out['invited'] = $c->isGroup() ? $this->svc->invitedPayload($c, $me) : [];
            $out['typing'] = $this->svc->feature('wimschat_typing', $me) ? $this->svc->typingUsers($cid, $me->id, $cfg) : [];
            $out['title'] = $c->title;
        }

        return response()->json($out);
    }

    // ---- people ----------------------------------------------------------------------------------------------

    public function users(Request $request)
    {
        $me = $this->me();
        $everyone = $request->boolean('all');
        $users = $this->svc->directory($me, $request->input('q'), $everyone ? 500 : 30, $everyone);
        $can = array_flip($this->svc->permittedUserIds($me->account_id));
        $briefs = array_map(fn ($b) => $b + ['can_chat' => isset($can[$b['id']])], $this->svc->briefs($users, $me, $this->cfg($me)));

        return response()->json(['users' => $briefs]);
    }

    public function user(int $user)
    {
        $me = $this->me();
        abort_unless($this->svc->canTalkTo($me, $user) || $user === $me->id, 404);
        $u = User::query()->findOrFail($user);
        $cfg = $this->cfg($me);
        $brief = $this->svc->briefs(collect([$u]), $me, $cfg)[0];

        $staff = \App\Models\Staff::query()->where('user_id', $u->id)->first();
        $out = $brief + [
            'email' => $me->can('read-users') ? $u->email : null,
            'phone' => $staff && $me->can('read-staffs') ? $staff->mobile_no : null,
            'staff_number' => $staff && $me->can('read-staffs') ? $staff->staff_number : null,
        ];

        // conversations the two of you share
        $mine = WimsChatMember::query()->where('user_id', $me->id)->whereNull('left_at')->pluck('conversation_id');
        $shared = WimsChatMember::query()->where('user_id', $u->id)->whereNull('left_at')->whereIn('conversation_id', $mine)->pluck('conversation_id');
        $out['mutual_groups'] = WimsChatConversation::query()->whereIn('id', $shared)->where('type', 'group')->limit(10)->get(['id', 'title'])->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])->all();

        return response()->json(['user' => $out]);
    }

    // ---- personal settings -----------------------------------------------------------------------------------

    public function updatePrefs(Request $request)
    {
        $me = $this->me();
        $cfg = $this->cfg($me);
        $keys = array_keys(WimsChatSettings::USER_DEFAULTS);
        $statusKeys = array_merge(['auto'], collect($cfg->statuses())->where('selectable', true)->pluck('key')->all());
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in($statusKeys)],
            'prefs' => 'nullable|array',
            'prefs.density' => ['nullable', Rule::in(['comfortable', 'compact'])],
            'prefs.theme' => ['nullable', Rule::in((array) $cfg->get('themes'))],
        ]);
        $pref = WimsChatUserPref::query()->firstOrNew(['user_id' => $me->id]);
        $current = $this->svc->userPrefs($pref->exists ? $pref : null);
        foreach ((array) ($data['prefs'] ?? []) as $k => $v) {
            if (! in_array($k, $keys, true)) {
                continue;
            }
            $current[$k] = is_bool(WimsChatSettings::USER_DEFAULTS[$k]) ? (bool) $v : $v;
        }
        if (isset($data['status']) && $cfg->flag('allow_manual_status')) {
            $pref->status = $data['status'];
        }
        $pref->prefs = $current;
        $pref->user_id = $me->id;
        $pref->save();

        return response()->json(['ok' => true, 'status' => $pref->status ?? 'auto', 'prefs' => $current, 'presence' => $this->svc->presence($me->refresh(), $pref, $cfg, true)]);
    }

    // ---- search ----------------------------------------------------------------------------------------------

    /** Server-side search over messages and attachment names the person can see. */
    public function search(Request $request)
    {
        $me = $this->me();
        abort_unless($me->can('search-wimschat'), 403, __('You cannot search WIMSChat.'));
        $cfg = $this->cfg($me);
        $data = $request->validate([
            'q' => 'nullable|string|max:200', 'conversation' => 'nullable|integer', 'user' => 'nullable|integer',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'kind' => 'nullable|in:all,files,media,links,unread',
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $kind = $data['kind'] ?? 'all';
        $min = (int) $cfg->get('search_min_chars');
        if ($q !== '' && mb_strlen($q) < $min) {
            return response()->json(['messages' => [], 'conversations' => []]);
        }
        if ($q === '' && $kind === 'all' && empty($data['user']) && empty($data['from']) && empty($data['to']) && empty($data['conversation'])) {
            return response()->json(['messages' => [], 'conversations' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $m = WimsChatMessage::query()->select('wimschat_messages.*')
            ->join('wimschat_members as mem', function ($j) use ($me) {
                $j->on('mem.conversation_id', '=', 'wimschat_messages.conversation_id')->where('mem.user_id', '=', $me->id)->whereNull('mem.left_at');
            })
            ->whereColumn('wimschat_messages.created_at', '>=', 'mem.joined_at')
            ->where('wimschat_messages.type', 'text')
            ->where('wimschat_messages.account_id', $me->account_id);
        if (! empty($data['conversation'])) {
            $m->where('wimschat_messages.conversation_id', (int) $data['conversation']);
        }
        if (! empty($data['user'])) {
            $m->where('wimschat_messages.user_id', (int) $data['user']);
        }
        if (! empty($data['from'])) {
            $m->where('wimschat_messages.created_at', '>=', Carbon::parse($data['from'])->startOfDay());
        }
        if (! empty($data['to'])) {
            $m->where('wimschat_messages.created_at', '<=', Carbon::parse($data['to'])->endOfDay());
        }
        if ($kind === 'unread') {
            $m->whereColumn('wimschat_messages.id', '>', 'mem.last_read_message_id')->where('wimschat_messages.user_id', '!=', $me->id);
        }
        if ($kind === 'links') {
            $m->where('wimschat_messages.body', 'like', '%http%');
        }
        if (in_array($kind, ['files', 'media'], true)) {
            $m->whereExists(fn ($e) => $e->select(DB::raw(1))->from('wimschat_attachments as a')->whereColumn('a.message_id', 'wimschat_messages.id')->whereNull('a.deleted_at')
                ->when($kind === 'media', fn ($x) => $x->where('a.category', 'images'), fn ($x) => $x->where('a.category', '!=', 'images')));
        }
        if ($q !== '') {
            $m->where(fn ($w) => $w->where('wimschat_messages.body', 'like', $like)
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('wimschat_attachments as a')->whereColumn('a.message_id', 'wimschat_messages.id')->whereNull('a.deleted_at')->where('a.original_name', 'like', $like)));
        }
        $rows = $m->with(['user:id,name,profile_photo_path', 'attachments:id,message_id,original_name,category'])->orderByDesc('wimschat_messages.id')->limit(40)->get();

        $convIds = $rows->pluck('conversation_id')->unique()->all();
        $titles = [];
        if ($convIds) {
            $items = $this->svc->conversationItems($me, ['ids' => $convIds, 'limit' => 100])['items'];
            foreach ($items as $i) {
                $titles[$i['id']] = ['title' => $i['title'], 'type' => $i['type']];
            }
        }
        $messages = $rows->map(function ($r) use ($titles, $q) {
            $text = (string) $r->body;
            $pos = $q !== '' ? mb_stripos($text, $q) : false;
            $snippet = $pos === false ? \Illuminate\Support\Str::limit($text, 140) : ($pos > 40 ? '…' : '').mb_substr($text, max(0, $pos - 40), 160).(mb_strlen($text) > $pos + 120 ? '…' : '');

            return [
                'id' => $r->id, 'conversation_id' => $r->conversation_id, 'conversation' => $titles[$r->conversation_id]['title'] ?? '',
                'type' => $titles[$r->conversation_id]['type'] ?? 'direct',
                'user' => optional($r->user)->name, 'snippet' => $snippet, 'at' => $r->created_at->toIso8601String(),
                'files' => $r->attachments->map(fn ($a) => ['name' => $a->original_name, 'category' => $a->category])->all(),
            ];
        })->all();

        $conversations = $q !== '' ? $this->svc->conversationItems($me, ['q' => $q, 'limit' => 12, 'filter' => 'all'])['items'] : [];

        return response()->json(['messages' => $messages, 'conversations' => $conversations]);
    }
}
