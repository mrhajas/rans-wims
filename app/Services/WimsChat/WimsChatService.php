<?php

namespace App\Services\WimsChat;

use App\Models\Staff;
use App\Models\User;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Models\WimsChatMessage;
use App\Models\WimsChatUserPref;
use App\Services\FeatureManager;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read side of WIMSChat: who can be talked to, presence, the conversation list, unread counts, message payloads.
 * Everything is scoped to the signed-in person's account and memberships; the controllers never trust an id from the browser.
 */
class WimsChatService
{
    public function __construct(private WimsChatSettings $settings)
    {
    }

    public function cfg(User $user): WimsChatSettings
    {
        return $this->settings->forAccount($user->account_id);
    }

    public function feature(string $key, ?User $user = null): bool
    {
        return FeatureManager::canAccess($key, $user ?? auth()->user());
    }

    // ---- people ----------------------------------------------------------------------------------------------

    /** Ids of the active people in the account who may use WIMSChat at all (role / direct permission, or Super Admin). */
    public function permittedUserIds(int $accountId): array
    {
        return Cache::remember("wimschat.permitted.$accountId", 60, function () use ($accountId) {
            $permId = DB::table('permissions')->where('name', 'view-wimschat')->value('id');
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $permId)->pluck('role_id')
                ->merge(DB::table('roles')->where('name', 'Super Admin')->pluck('id'))->unique();
            $viaRole = DB::table('model_has_roles')->where('model_type', User::class)->whereIn('role_id', $roleIds)->pluck('model_id');
            $direct = DB::table('model_has_permissions')->where('model_type', User::class)->where('permission_id', $permId)->pluck('model_id');

            return User::query()->where('account_id', $accountId)->where('is_active', true)
                ->whereIn('id', $viaRole->merge($direct)->unique())->pluck('id')->map(fn ($i) => (int) $i)->all();
        });
    }

    public function canTalkTo(User $me, int $otherId): bool
    {
        return $otherId !== $me->id && in_array($otherId, $this->permittedUserIds($me->account_id), true);
    }

    /** Searchable list of colleagues (name, username, e-mail, staff number, position). */
    public function directory(User $me, ?string $q, int $limit = 30, bool $everyone = false): Collection
    {
        // $everyone: every active person of the account (the picker marks those who cannot use chat yet); otherwise only people who can chat
        $ids = $everyone
            ? User::query()->where('account_id', $me->account_id)->where('is_active', true)->where('id', '!=', $me->id)->pluck('id')->all()
            : array_values(array_diff($this->permittedUserIds($me->account_id), [$me->id]));
        $q = trim((string) $q);
        $query = User::query()->whereIn('users.id', $ids ?: [0])
            ->select('users.id', 'users.name', 'users.username', 'users.email', 'users.profile_photo_path', 'users.last_seen_at');
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $staffIds = Staff::query()->where(fn ($s) => $s->where('staff_number', 'like', $like)->orWhere('position', 'like', $like))
                ->whereNotNull('user_id')->pluck('user_id');
            $query->where(fn ($w) => $w->where('users.name', 'like', $like)->orWhere('users.username', 'like', $like)
                ->orWhere('users.email', 'like', $like)->orWhereIn('users.id', $staffIds));
        }

        return $query->orderBy('users.name')->limit($limit)->get();
    }

    public function positions(array $ids): array
    {
        return $ids ? Staff::query()->whereIn('user_id', $ids)->pluck('position', 'user_id')->all() : [];
    }

    /** @return array<int, WimsChatUserPref> */
    public function prefsMap(array $ids): array
    {
        return $ids ? WimsChatUserPref::query()->whereIn('user_id', $ids)->get()->keyBy('user_id')->all() : [];
    }

    public function userPrefs(?WimsChatUserPref $row): array
    {
        return array_merge(WimsChatSettings::USER_DEFAULTS, (array) ($row->prefs ?? []));
    }

    /** How a person appears to others right now. Activity decides online / away / offline; a chosen status refines it. */
    public function presence(User $u, ?WimsChatUserPref $pref, WimsChatSettings $cfg, bool $self = false): array
    {
        if (! FeatureManager::isEnabled('wimschat_presence')) {
            return ['key' => 'none', 'label' => '', 'color' => '#94a3b8', 'quiet' => false, 'last_seen' => null, 'last_seen_label' => null];
        }
        $map = $cfg->statusMap();
        $p = $this->userPrefs($pref);
        $seen = $u->last_seen_at ? Carbon::parse($u->last_seen_at) : null;
        $age = $seen ? $seen->diffInSeconds(now()) : null;
        $auto = $age !== null && $age <= (int) $cfg->get('active_within_seconds') ? 'online'
            : ($age !== null && $age <= (int) $cfg->get('away_timeout_minutes') * 60 ? 'away' : 'offline');
        $key = $auto;
        $hidden = false; // someone who chose Invisible / hides their presence must not leak "last seen" either
        $manual = $pref->status ?? 'auto';
        if ($cfg->flag('allow_manual_status') && $manual !== 'auto' && isset($map[$manual]) && ($auto !== 'offline' || $self)) {
            $key = $self ? $manual : ($map[$manual]['appears_as'] ?? $manual);
            $hidden = ! $self && $key === 'offline';
        }
        if (! $self && ! $p['show_online'] && $key !== 'offline') {
            $key = 'offline';
            $hidden = true;
        }
        $m = $map[$key] ?? ['label' => ucfirst($key), 'color' => '#94a3b8', 'quiet' => false];
        $showSeen = $cfg->flag('show_last_seen') && ($self || ($p['show_last_seen'] && ! $hidden));

        return [
            'key' => $key, 'label' => __($m['label']), 'color' => $m['color'], 'quiet' => (bool) ($m['quiet'] ?? false),
            'last_seen' => $showSeen && $seen ? $seen->toIso8601String() : null,
            'last_seen_label' => $showSeen && $seen && $key !== 'online' ? $seen->diffForHumans() : null,
        ];
    }

    public function brief(User $u, array $prefs, array $positions, WimsChatSettings $cfg, ?User $viewer = null): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'photo' => $u->profile_photo_url,
            'position' => $positions[$u->id] ?? null,
            'presence' => $this->presence($u, $prefs[$u->id] ?? null, $cfg, $viewer && $viewer->id === $u->id),
        ];
    }

    public function briefs(Collection $users, User $viewer, WimsChatSettings $cfg): array
    {
        $ids = $users->pluck('id')->all();
        $prefs = $this->prefsMap($ids);
        $pos = $this->positions($ids);

        return $users->map(fn ($u) => $this->brief($u, $prefs, $pos, $cfg, $viewer))->values()->all();
    }

    // ---- membership ------------------------------------------------------------------------------------------

    public function memberOrFail(WimsChatConversation $c, User $u): WimsChatMember
    {
        abort_unless((int) $c->account_id === (int) $u->account_id, 404);
        $m = WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $u->id)->whereNull('left_at')->first();
        abort_unless($m, 403, __('You no longer have permission to access this conversation.'));

        return $m;
    }

    public function isGroupAdmin(WimsChatMember $m, User $u): bool
    {
        return $m->isAdmin() || $u->can('manage-wimschat-groups');
    }

    // ---- group invitations -----------------------------------------------------------------------------------

    public function invitesCount(User $me): int
    {
        return $this->feature('wimschat_groups', $me)
            ? (int) WimsChatMember::query()->where('user_id', $me->id)->where('invite_status', 'pending')->count() : 0;
    }

    /** Open invitations of this person, newest first. */
    public function invitations(User $me): array
    {
        if (! $this->feature('wimschat_groups', $me)) {
            return [];
        }
        $rows = WimsChatMember::query()->where('user_id', $me->id)->where('invite_status', 'pending')->with('conversation')->orderByDesc('invited_at')->limit(30)->get()
            ->filter(fn ($r) => $r->conversation && (int) $r->conversation->account_id === (int) $me->account_id);
        $inviters = User::withTrashed()->whereIn('id', $rows->pluck('added_by')->filter())->select('id', 'name', 'username', 'profile_photo_path')->get()->keyBy('id');
        $counts = WimsChatMember::query()->whereIn('conversation_id', $rows->pluck('conversation_id'))->whereNull('left_at')->selectRaw('conversation_id c, COUNT(*) n')->groupBy('c')->pluck('n', 'c');

        return $rows->map(fn ($r) => [
            'conversation_id' => $r->conversation_id,
            'title' => $r->conversation->title,
            'description' => $r->conversation->description,
            'avatar' => $r->conversation->avatar_path ? route('wimschat.conversations.avatar', $r->conversation_id).'?v='.$r->conversation->updated_at->timestamp : null,
            'members_count' => (int) ($counts[$r->conversation_id] ?? 0),
            'invited_by' => ($i = $inviters[$r->added_by] ?? null) ? ['id' => $i->id, 'name' => $i->name, 'photo' => $i->profile_photo_url] : null,
            'invited_at' => optional($r->invited_at)->toIso8601String(),
        ])->values()->all();
    }

    /** People invited to a group who have not answered yet (shown to the group's people). */
    public function invitedPayload(WimsChatConversation $c, User $me): array
    {
        $rows = WimsChatMember::query()->where('conversation_id', $c->id)->where('invite_status', 'pending')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->select('id', 'name', 'username', 'profile_photo_path', 'last_seen_at')->get()->keyBy('id');
        $cfg = $this->cfg($me);
        $prefs = $this->prefsMap($users->keys()->all());
        $pos = $this->positions($users->keys()->all());

        return $rows->filter(fn ($r) => isset($users[$r->user_id]))->map(fn ($r) => ['user' => $this->brief($users[$r->user_id], $prefs, $pos, $cfg, $me), 'invited_at' => optional($r->invited_at)->toIso8601String()])->values()->all();
    }

    // ---- online strip ----------------------------------------------------------------------------------------

    /** Colleagues who are online / away right now (people hiding their presence are left out). */
    public function onlineUsers(User $me, int $limit = 16): array
    {
        if (! FeatureManager::isEnabled('wimschat_presence')) {
            return [];
        }
        $cfg = $this->cfg($me);
        $ids = array_values(array_diff($this->permittedUserIds($me->account_id), [$me->id]));
        $users = User::query()->whereIn('id', $ids ?: [0])->where('last_seen_at', '>=', now()->subMinutes((int) $cfg->get('away_timeout_minutes')))
            ->orderByDesc('last_seen_at')->limit($limit * 2)->get(['id', 'name', 'username', 'profile_photo_path', 'last_seen_at']);
        $briefs = $this->briefs($users, $me, $cfg);

        return array_slice(array_values(array_filter($briefs, fn ($b) => in_array($b['presence']['key'], ['online', 'away', 'busy', 'dnd'], true))), 0, $limit);
    }

    // ---- unread ----------------------------------------------------------------------------------------------

    private function unreadQuery(User $me)
    {
        return DB::table('wimschat_messages as m')
            ->join('wimschat_members as mem', function ($j) use ($me) {
                $j->on('mem.conversation_id', '=', 'm.conversation_id')->where('mem.user_id', '=', $me->id);
            })
            ->whereNull('mem.left_at')
            ->whereColumn('m.id', '>', 'mem.last_read_message_id')
            ->whereColumn('m.created_at', '>=', 'mem.joined_at')
            ->where(fn ($w) => $w->whereNull('m.user_id')->orWhere('m.user_id', '!=', $me->id))
            ->whereNull('m.deleted_at')->where('m.type', '!=', 'system');
    }

    /** [conversation_id => unread count] */
    public function unreadByConversation(User $me, ?array $ids = null): array
    {
        $q = $this->unreadQuery($me);
        if ($ids !== null) {
            $q->whereIn('m.conversation_id', $ids ?: [0]);
        }

        return $q->groupBy('m.conversation_id')->selectRaw('m.conversation_id as cid, COUNT(*) as c')->pluck('c', 'cid')->map(fn ($v) => (int) $v)->all();
    }

    /** Number the floating badge shows: unread messages in conversations that are not muted / archived / hidden, plus manual "unread" flags. */
    public function unreadTotal(User $me): int
    {
        $q = $this->unreadQuery($me)->whereNull('mem.hidden_at')->whereNull('mem.archived_at')
            ->where(fn ($w) => $w->where('mem.muted', false)->orWhere(fn ($x) => $x->whereNotNull('mem.muted_until')->where('mem.muted_until', '<', now())));
        $count = (int) $q->count();
        $flags = WimsChatMember::query()->where('user_id', $me->id)->whereNull('left_at')->where('marked_unread', true)->whereNull('hidden_at')->count();

        return $count + $flags;
    }

    /** Called by the badge poll: this person's browser has received everything up to the latest message. */
    public function markDelivered(User $me): void
    {
        DB::update('UPDATE wimschat_members m JOIN wimschat_conversations c ON c.id = m.conversation_id
            SET m.last_delivered_message_id = COALESCE(c.last_message_id, 0)
            WHERE m.user_id = ? AND m.left_at IS NULL AND m.last_delivered_message_id < COALESCE(c.last_message_id, 0)', [$me->id]);
    }

    // ---- conversation list -----------------------------------------------------------------------------------

    public function previewText(?WimsChatMessage $m, WimsChatSettings $cfg): array
    {
        if (! $m) {
            return ['text' => '', 'kind' => 'none'];
        }
        if ($m->trashed()) {
            return ['text' => __('This message was deleted'), 'kind' => 'deleted'];
        }
        if ($m->type === 'nudge') {
            return ['text' => '🔔 '.__('Nudge'), 'kind' => 'nudge'];
        }
        $body = trim((string) $m->body);
        $att = $m->relationLoaded('attachments') ? $m->attachments : collect();
        if ($body === '' && $att->count()) {
            $first = $att->first();
            if ($first->category === 'voice') {
                return ['text' => '🎤 '.__('Voice message').($first->duration_ms ? ' ('.floor($first->duration_ms / 60000).':'.str_pad((string) floor(($first->duration_ms % 60000) / 1000), 2, '0', STR_PAD_LEFT).')' : ''), 'kind' => 'attachment'];
            }

            return ['text' => ($first->category === 'images' ? '📷 ' : '📎 ').($att->count() > 1 ? __(':n files', ['n' => $att->count()]) : $first->original_name), 'kind' => 'attachment'];
        }
        $text = Str::limit(preg_replace('/\s+/u', ' ', $body), (int) $cfg->get('message_preview_chars'), '…');

        return ['text' => $text, 'kind' => $m->type === 'system' ? 'system' : 'text', 'has_attachment' => $att->count() > 0];
    }

    /**
     * The conversation list of one person.
     *
     * @param  array{filter?:string,q?:?string,since?:?Carbon,limit?:int,offset?:int,ids?:?array}  $o
     */
    public function conversationItems(User $me, array $o = []): array
    {
        $cfg = $this->cfg($me);
        $filter = $o['filter'] ?? 'all';
        $limit = min(100, max(1, (int) ($o['limit'] ?? 60)));
        $q = WimsChatMember::query()
            ->join('wimschat_conversations as c', 'c.id', '=', 'wimschat_members.conversation_id')
            ->where('wimschat_members.user_id', $me->id)->whereNull('wimschat_members.left_at')->whereNull('c.deleted_at')
            ->where('c.account_id', $me->account_id)
            ->select('wimschat_members.*');

        if (isset($o['ids'])) {
            $q->whereIn('wimschat_members.conversation_id', $o['ids'] ?: [0]);
        }
        if (! empty($o['since'])) {
            $since = $o['since'];
            $q->where(fn ($w) => $w->where('wimschat_members.updated_at', '>=', $since)->orWhere('c.updated_at', '>=', $since));
        }
        // "hidden" ones are only returned when asked for by id (so a hidden chat can be reopened) or in the search
        if (! isset($o['ids'])) {
            $q->whereNull('wimschat_members.hidden_at');
        }
        if ($filter === 'archived') {
            $q->whereNotNull('wimschat_members.archived_at');
        } elseif (empty($o['since']) && ! isset($o['ids'])) {
            $q->whereNull('wimschat_members.archived_at');
        }
        if ($filter === 'pinned') {
            $q->whereNotNull('wimschat_members.pinned_at');
        } elseif ($filter === 'groups') {
            $q->where('c.type', 'group');
        } elseif ($filter === 'direct') {
            $q->where('c.type', 'direct');
        }
        if (($s = trim((string) ($o['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $directIds = DB::table('wimschat_members as om')->join('users as u', 'u.id', '=', 'om.user_id')
                ->where('om.user_id', '!=', $me->id)->where(fn ($w) => $w->where('u.name', 'like', $like)->orWhere('u.username', 'like', $like))
                ->pluck('om.conversation_id');
            $q->where(fn ($w) => $w->where('c.title', 'like', $like)->orWhereIn('c.id', $directIds));
        }

        $rows = $q->orderByRaw('wimschat_members.pinned_at IS NULL')->orderByDesc('wimschat_members.pinned_at')
            ->orderByRaw('c.last_message_at IS NULL')->orderByDesc('c.last_message_at')->orderByDesc('c.id')
            ->offset((int) ($o['offset'] ?? 0))->limit($limit + 1)->with('conversation')->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $convIds = $rows->pluck('conversation_id')->all();
        $unread = $this->unreadByConversation($me, $convIds);
        $lastIds = $rows->map(fn ($r) => $r->conversation->last_message_id)->filter()->all();
        $last = $lastIds ? WimsChatMessage::withTrashed()->whereIn('id', $lastIds)->with('attachments:id,message_id,original_name,category,duration_ms')->get()->keyBy('id') : collect();

        // the other person of every direct conversation
        $others = DB::table('wimschat_members')->whereIn('conversation_id', $convIds)->where('user_id', '!=', $me->id)->whereNull('left_at')
            ->get(['conversation_id', 'user_id']);
        $counts = $others->groupBy('conversation_id')->map->count();
        $directOther = $others->groupBy('conversation_id')->map(fn ($g) => $g->first()->user_id);
        $users = User::withTrashed()->whereIn('id', $directOther->values()->unique())->select('id', 'name', 'username', 'profile_photo_path', 'last_seen_at')->get()->keyBy('id');
        $prefs = $this->prefsMap($users->keys()->all());
        $pos = $this->positions($users->keys()->all());

        $items = [];
        foreach ($rows as $m) {
            $c = $m->conversation;
            $item = [
                'id' => $c->id, 'type' => $c->type,
                'unread' => (int) ($unread[$c->id] ?? 0),
                'marked_unread' => (bool) $m->marked_unread,
                'pinned' => (bool) $m->pinned_at, 'archived' => (bool) $m->archived_at, 'muted' => $m->isMuted(), 'hidden' => (bool) $m->hidden_at,
                'role' => $m->role,
                'last' => $this->previewText($last[$c->last_message_id] ?? null, $cfg) + [
                    'at' => optional($c->last_message_at)->toIso8601String(),
                    'mine' => ($last[$c->last_message_id] ?? null) && $last[$c->last_message_id]->type !== 'system' && (int) $last[$c->last_message_id]->user_id === $me->id,
                ],
                'updated_at' => optional($c->last_message_at ?? $c->created_at)->toIso8601String(),
            ];
            if ($c->type === 'group') {
                $item += ['title' => $c->title, 'description' => $c->description, 'avatar' => $c->avatar_path ? route('wimschat.conversations.avatar', $c->id).'?v='.$c->updated_at->timestamp : null, 'members_count' => (int) ($counts[$c->id] ?? 0) + 1, 'user' => null];
            } else {
                $u = $users[$directOther[$c->id] ?? 0] ?? null;
                $item += [
                    'title' => $u->name ?? __('Former user'), 'description' => null, 'avatar' => null, 'members_count' => 2,
                    'user' => $u ? $this->brief($u, $prefs, $pos, $cfg, $me) : null,
                ];
            }
            $items[] = $item;
        }

        return ['items' => $items, 'more' => $more];
    }

    public function conversationItem(User $me, int $conversationId): ?array
    {
        return $this->conversationItems($me, ['ids' => [$conversationId]])['items'][0] ?? null;
    }

    // ---- typing ----------------------------------------------------------------------------------------------

    public function typingKey(int $conversationId): string
    {
        return 'wimschat.typing.'.$conversationId;
    }

    public function setTyping(int $conversationId, int $userId): void
    {
        $ttl = (int) $this->settings->get('typing_ttl_seconds') ?: 8;
        $map = Cache::get($this->typingKey($conversationId), []);
        $now = time();
        $map = array_filter($map, fn ($t) => $t > $now - $ttl);
        $map[$userId] = $now;
        Cache::put($this->typingKey($conversationId), $map, 60);
    }

    public function typingUsers(int $conversationId, int $exceptUserId, WimsChatSettings $cfg): array
    {
        $ttl = (int) $cfg->get('typing_ttl_seconds') ?: 8;
        $map = Cache::get($this->typingKey($conversationId), []);
        $ids = array_keys(array_filter($map, fn ($t, $id) => $t > time() - $ttl && (int) $id !== $exceptUserId, ARRAY_FILTER_USE_BOTH));
        if (! $ids) {
            return [];
        }
        $prefs = $this->prefsMap($ids);
        $visible = array_filter($ids, fn ($id) => $this->userPrefs($prefs[$id] ?? null)['typing_visible']);

        return $visible ? User::query()->whereIn('id', $visible)->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all() : [];
    }

    // ---- members payload -------------------------------------------------------------------------------------

    /** Everyone currently in the conversation with presence and read cursors. */
    public function membersPayload(WimsChatConversation $c, User $me): array
    {
        $cfg = $this->cfg($me);
        $rows = WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->get();
        $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->select('id', 'name', 'username', 'profile_photo_path', 'last_seen_at')->get()->keyBy('id');
        $prefs = $this->prefsMap($users->keys()->all());
        $pos = $this->positions($users->keys()->all());

        return $rows->map(function ($m) use ($users, $prefs, $pos, $cfg, $me) {
            $u = $users[$m->user_id] ?? null;
            $p = $this->userPrefs($prefs[$m->user_id] ?? null);

            return [
                'user' => $u ? $this->brief($u, $prefs, $pos, $cfg, $me) : ['id' => $m->user_id, 'name' => __('Former user'), 'username' => '', 'photo' => '/images/default-avatar.svg', 'position' => null, 'presence' => ['key' => 'offline', 'label' => '', 'color' => '#94a3b8']],
                'role' => $m->role,
                'last_read' => (int) $m->last_read_message_id,
                'last_delivered' => (int) $m->last_delivered_message_id,
                'receipts' => (bool) $p['read_receipts'] && $cfg->flag('read_receipts'),
            ];
        })->values()->all();
    }
}
