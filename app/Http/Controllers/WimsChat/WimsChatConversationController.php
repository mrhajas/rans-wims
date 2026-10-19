<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\User;
use App\Models\WimsChatAttachment;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Models\WimsChatMessage;
use App\Services\WimsChat\WimsChatLogger;
use App\Services\WimsChat\WimsChatNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Conversations: the list, starting a chat, groups and their members, personal state (pin / archive / mute / read), history and shared media. */
class WimsChatConversationController extends WimsChatBaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        $r = $request->validate(['filter' => 'nullable|in:all,unread,pinned,groups,direct,archived', 'q' => 'nullable|string|max:100', 'offset' => 'nullable|integer|min:0']);
        $filter = $r['filter'] ?? 'all';
        $res = $this->svc->conversationItems($me, ['filter' => $filter === 'unread' ? 'all' : $filter, 'q' => $r['q'] ?? null, 'offset' => $r['offset'] ?? 0, 'limit' => $filter === 'unread' ? 100 : 60]);
        if ($filter === 'unread') {
            $res['items'] = array_values(array_filter($res['items'], fn ($i) => $i['unread'] > 0 || $i['marked_unread']));
        }

        return response()->json($res + ['unread' => $this->svc->unreadTotal($me)]);
    }

    /** Opens (or creates) the one-to-one conversation with a colleague. */
    public function store(Request $request)
    {
        $me = $this->me();
        abort_unless($me->can('start-wimschat-conversations') && $this->svc->feature('wimschat_direct', $me), 403, __('You cannot start conversations.'));
        $data = $request->validate(['user_id' => 'required|integer']);
        abort_unless($this->svc->canTalkTo($me, (int) $data['user_id']), 403, __('You cannot start a conversation with this person.'));

        $key = WimsChatConversation::directKey($me->id, (int) $data['user_id']);
        $created = false;
        $c = WimsChatConversation::query()->where('direct_key', $key)->first();
        if (! $c) {
            try {
                $c = DB::transaction(function () use ($me, $data, $key) {
                    $c = WimsChatConversation::create(['account_id' => $me->account_id, 'type' => 'direct', 'direct_key' => $key, 'created_by' => $me->id]);
                    foreach ([$me->id, (int) $data['user_id']] as $uid) {
                        WimsChatMember::create(['conversation_id' => $c->id, 'user_id' => $uid, 'role' => 'member', 'joined_at' => now()]);
                    }

                    return $c;
                });
                $created = true;
            } catch (\Illuminate\Database\QueryException $e) {
                $c = WimsChatConversation::query()->where('direct_key', $key)->firstOrFail(); // raced with the other person
            }
        }
        // coming back to a chat you removed from your view / archive
        WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $me->id)
            ->update(['left_at' => null, 'hidden_at' => null, 'archived_at' => null, 'joined_at' => DB::raw('COALESCE(joined_at, NOW())')]);
        if ($created) {
            WimsChatLogger::log($me, 'CHAT_CREATE', $c, ['with' => (int) $data['user_id']]);
        }

        return response()->json(['conversation' => $this->svc->conversationItem($me, $c->id), 'created' => $created]);
    }

    // ---- groups ----------------------------------------------------------------------------------------------

    public function storeGroup(Request $request)
    {
        $me = $this->me();
        abort_unless($me->can('create-wimschat-groups') && $this->svc->feature('wimschat_groups', $me), 403, __('You cannot create groups.'));
        $cfg = $this->cfg($me);
        $data = $request->validate([
            'title' => 'required|string|min:2|max:120', 'description' => 'nullable|string|max:500',
            'members' => 'required|array|min:1', 'members.*' => 'integer',
        ]);
        $members = collect($data['members'])->map(fn ($i) => (int) $i)->unique()->filter(fn ($i) => $this->svc->canTalkTo($me, $i))->values();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['members' => __('Choose at least one person for the group.')]);
        }
        $max = (int) $cfg->get('group_max_members');
        if ($max > 0 && $members->count() + 1 > $max) {
            throw ValidationException::withMessages(['members' => __('A group can have up to :n people.', ['n' => $max])]);
        }

        $invite = $cfg->flag('group_invite_required');
        $c = DB::transaction(function () use ($me, $data, $members, $invite) {
            $c = WimsChatConversation::create(['account_id' => $me->account_id, 'type' => 'group', 'title' => trim($data['title']), 'description' => $data['description'] ?? null, 'created_by' => $me->id]);
            WimsChatMember::create(['conversation_id' => $c->id, 'user_id' => $me->id, 'role' => 'admin', 'joined_at' => now(), 'added_by' => $me->id]);
            foreach ($members as $uid) {
                WimsChatMember::create(['conversation_id' => $c->id, 'user_id' => $uid, 'role' => 'member', 'added_by' => $me->id] + $this->seat($invite));
            }
            $m = $this->messenger->system($c, $me, __(':w created the group', ['w' => $me->name]));
            WimsChatMember::query()->where('conversation_id', $c->id)->update(['last_read_message_id' => $m->id, 'last_delivered_message_id' => $m->id]);

            return $c;
        });
        WimsChatLogger::log($me, 'GROUP_CREATE', $c, ['members' => $members->count() + 1, 'invited' => $invite]);
        WimsChatNotifier::groupChange($c, $me, $members->all(), $invite ? 'invite' : 'added');

        return response()->json(['conversation' => $this->svc->conversationItem($me, $c->id)]);
    }

    /** A seat in a group: either an open invitation (not "in" until accepted: left_at stays set) or an active member. */
    private function seat(bool $invite): array
    {
        return $invite
            ? ['joined_at' => null, 'left_at' => now(), 'invite_status' => 'pending', 'invited_at' => now()]
            : ['joined_at' => now(), 'left_at' => null, 'invite_status' => null, 'invited_at' => null];
    }

    private function groupAdmin(WimsChatConversation $c, WimsChatMember $m, User $me): void
    {
        abort_unless($c->isGroup(), 404);
        abort_unless($this->svc->feature('wimschat_groups', $me), 403, __('Groups are switched off.'));
        abort_unless($this->svc->isGroupAdmin($m, $me), 403, __('Only group administrators can do this.'));
    }

    public function updateGroup(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $this->groupAdmin($c, $m, $me);
        $data = $request->validate([
            'title' => 'sometimes|required|string|min:2|max:120', 'description' => 'nullable|string|max:500',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048', 'remove_avatar' => 'nullable|boolean',
        ]);
        $old = $c->title;
        if (array_key_exists('title', $data)) {
            $c->title = trim($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $c->description = $data['description'];
        }
        if ($request->hasFile('avatar')) {
            $c->avatar_path = $this->storeAvatar($request->file('avatar'), $c);
        } elseif (! empty($data['remove_avatar']) && $c->avatar_path) {
            Storage::disk('local')->delete($c->avatar_path);
            $c->avatar_path = null;
        }
        $descriptionChanged = $c->isDirty('description');
        $pictureChanged = $c->isDirty('avatar_path');
        $c->save();
        if ($old !== $c->title) {
            $this->messenger->system($c, $me, __(':w renamed the group to “:t”', ['w' => $me->name, 't' => $c->title]));
        }
        if ($old !== $c->title || $descriptionChanged || $pictureChanged) {
            WimsChatNotifier::groupUpdated($c, $me, $old !== $c->title ? __('renamed from :o', ['o' => $old]) : ($descriptionChanged ? __('new description') : __('new picture')));
        }
        WimsChatLogger::log($me, 'GROUP_UPDATE', $c);

        return response()->json(['conversation' => $this->svc->conversationItem($me, $c->id)]);
    }

    private function storeAvatar($file, WimsChatConversation $c): ?string
    {
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            throw ValidationException::withMessages(['avatar' => __('That image could not be read.')]);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(256, 256);
        imagecopyresampled($dst, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 256, 256, $side, $side);
        if ($c->avatar_path) {
            Storage::disk('local')->delete($c->avatar_path);
        }
        $rel = 'wimschat/avatars/'.Str::uuid().'.jpg';
        Storage::disk('local')->makeDirectory('wimschat/avatars');
        imagejpeg($dst, Storage::disk('local')->path($rel), 85);

        return $rel;
    }

    public function avatar(int $conversation)
    {
        $me = $this->me();
        [$c] = $this->convFor($conversation, $me);
        abort_unless($c->avatar_path && Storage::disk('local')->exists($c->avatar_path), 404);

        return response()->file(Storage::disk('local')->path($c->avatar_path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function addMembers(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $this->groupAdmin($c, $m, $me);
        $cfg = $this->cfg($me);
        $data = $request->validate(['members' => 'required|array|min:1', 'members.*' => 'integer']);
        $ids = collect($data['members'])->map(fn ($i) => (int) $i)->unique()->filter(fn ($i) => $this->svc->canTalkTo($me, $i))->values();

        $invite = $cfg->flag('group_invite_required');
        $active = WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->pluck('user_id');
        $pending = WimsChatMember::query()->where('conversation_id', $c->id)->where('invite_status', 'pending')->pluck('user_id');
        $new = $ids->reject(fn ($i) => $active->contains($i) || $pending->contains($i))->values();
        $max = (int) $cfg->get('group_max_members');
        if ($max > 0 && $active->count() + $pending->count() + $new->count() > $max) {
            throw ValidationException::withMessages(['members' => __('A group can have up to :n people.', ['n' => $max])]);
        }
        $names = User::query()->whereIn('id', $new)->pluck('name', 'id');
        foreach ($new as $uid) {
            $row = WimsChatMember::query()->firstOrNew(['conversation_id' => $c->id, 'user_id' => $uid]);
            $row->fill(['role' => 'member', 'hidden_at' => null, 'archived_at' => null, 'added_by' => $me->id,
                'last_read_message_id' => (int) $c->last_message_id, 'last_delivered_message_id' => (int) $c->last_message_id] + $this->seat($invite))->save();
            $this->messenger->system($c, $me, $invite ? __(':w invited :n', ['w' => $me->name, 'n' => $names[$uid] ?? '']) : __(':w added :n', ['w' => $me->name, 'n' => $names[$uid] ?? '']));
            WimsChatLogger::log($me, $invite ? 'MEMBER_INVITE' : 'MEMBER_ADD', $c, ['member_id' => $uid]);
        }
        WimsChatNotifier::groupChange($c, $me, $new->all(), $invite ? 'invite' : 'added');

        return response()->json(['members' => $this->svc->membersPayload($c, $me), 'invited' => $this->svc->invitedPayload($c, $me), 'conversation' => $this->svc->conversationItem($me, $c->id)]);
    }

    public function removeMember(int $conversation, int $user)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $this->groupAdmin($c, $m, $me);
        abort_if($user === $me->id, 422, __('Use “Leave group” to leave.'));
        $row = WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $user)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhere('invite_status', 'pending'))->firstOrFail();
        $name = User::withTrashed()->where('id', $user)->value('name');
        if ($row->invite_status === 'pending') {
            $row->forceFill(['invite_status' => 'declined'])->save(); // invitation withdrawn
            $this->messenger->system($c, $me, __(':w withdrew the invitation for :n', ['w' => $me->name, 'n' => $name]));
            WimsChatLogger::log($me, 'MEMBER_INVITE_CANCEL', $c, ['member_id' => $user]);
        } else {
            $row->forceFill(['left_at' => now(), 'pinned_at' => null])->save();
            $this->messenger->system($c, $me, __(':w removed :n', ['w' => $me->name, 'n' => $name]));
            WimsChatLogger::log($me, 'MEMBER_REMOVE', $c, ['member_id' => $user]);
            WimsChatNotifier::groupChange($c, $me, [$user], 'removed');
        }

        return response()->json(['members' => $this->svc->membersPayload($c, $me), 'invited' => $this->svc->invitedPayload($c, $me)]);
    }

    public function memberRole(Request $request, int $conversation, int $user)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $this->groupAdmin($c, $m, $me);
        $data = $request->validate(['role' => 'required|in:member,admin']);
        $row = WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $user)->whereNull('left_at')->firstOrFail();
        $row->forceFill(['role' => $data['role']])->save();
        WimsChatLogger::log($me, 'MEMBER_ROLE', $c, ['member_id' => $user, 'role' => $data['role']]);
        if ($row->wasChanged('role')) {
            WimsChatNotifier::roleChanged($c, $me, $user, $data['role']);
        }

        return response()->json(['members' => $this->svc->membersPayload($c, $me)]);
    }

    public function leave(int $conversation)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        abort_unless($c->isGroup(), 404);
        $m->forceFill(['left_at' => now(), 'pinned_at' => null, 'role' => 'member'])->save();
        // a group must never be left without an administrator
        if (! WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->where('role', 'admin')->exists()) {
            WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->orderBy('joined_at')->limit(1)->update(['role' => 'admin']);
        }
        $this->messenger->system($c, $me, __(':w left the group', ['w' => $me->name]));
        WimsChatLogger::log($me, 'MEMBER_LEAVE', $c);
        WimsChatNotifier::left($c, $me);

        return response()->json(['ok' => true]);
    }

    // ---- invitations -----------------------------------------------------------------------------------------

    /** Groups this person has been invited to and has not answered yet. */
    public function invitations()
    {
        $me = $this->me();

        return response()->json(['invitations' => $this->svc->invitations($me)]);
    }

    /** Accept (enter the group; only messages from now on are visible) or decline an invitation. */
    public function respond(Request $request, int $conversation)
    {
        $me = $this->me();
        $d = $request->validate(['accept' => 'required|boolean']);
        $c = WimsChatConversation::query()->findOrFail($conversation);
        $row = WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $me->id)->where('invite_status', 'pending')->first();
        abort_unless($row && $c->isGroup(), 404, __('This invitation is no longer available.'));

        if ($d['accept']) {
            $row->forceFill([
                'left_at' => null, 'invite_status' => null, 'joined_at' => now(), 'hidden_at' => null, 'archived_at' => null,
                'last_read_message_id' => (int) $c->last_message_id, 'last_delivered_message_id' => (int) $c->last_message_id,
            ])->save();
            $this->messenger->system($c, $me, __(':w joined the group', ['w' => $me->name]));
            WimsChatLogger::log($me, 'MEMBER_JOIN', $c);
            WimsChatNotifier::joined($c, $me, (int) $row->added_by);

            return response()->json(['ok' => true, 'conversation' => $this->svc->conversationItem($me, $c->id), 'invites' => $this->svc->invitesCount($me)]);
        }
        $row->forceFill(['invite_status' => 'declined'])->save();
        WimsChatLogger::log($me, 'MEMBER_DECLINE', $c);
        WimsChatNotifier::declined($c, $me, (int) $row->added_by);

        return response()->json(['ok' => true, 'invites' => $this->svc->invitesCount($me)]);
    }

    // ---- personal state --------------------------------------------------------------------------------------

    public function state(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $d = $request->validate([
            'pinned' => 'sometimes|boolean', 'archived' => 'sometimes|boolean', 'muted' => 'sometimes|boolean',
            'mute_minutes' => 'nullable|integer|min:1|max:525600', 'hidden' => 'sometimes|boolean', 'marked_unread' => 'sometimes|boolean',
        ]);
        $upd = [];
        if (array_key_exists('pinned', $d)) {
            $upd['pinned_at'] = $d['pinned'] ? now() : null;
            WimsChatLogger::log($me, $d['pinned'] ? 'CONVERSATION_PIN' : 'CONVERSATION_UNPIN', $c);
        }
        if (array_key_exists('archived', $d)) {
            $upd['archived_at'] = $d['archived'] ? now() : null;
            WimsChatLogger::log($me, $d['archived'] ? 'CONVERSATION_ARCHIVE' : 'CONVERSATION_RESTORE', $c);
        }
        if (array_key_exists('muted', $d)) {
            $upd['muted'] = (bool) $d['muted'];
            $upd['muted_until'] = $d['muted'] && ! empty($d['mute_minutes']) ? now()->addMinutes((int) $d['mute_minutes']) : null;
            WimsChatLogger::log($me, $d['muted'] ? 'CONVERSATION_MUTE' : 'CONVERSATION_UNMUTE', $c);
        }
        if (! empty($d['hidden'])) {
            // removed from my view only: the messages stay for everyone else and for the audit trail
            $upd['hidden_at'] = now();
            $upd['pinned_at'] = null;
            $upd['last_read_message_id'] = (int) $c->last_message_id;
            WimsChatLogger::log($me, 'CONVERSATION_HIDE', $c);
        }
        if (array_key_exists('marked_unread', $d)) {
            $upd['marked_unread'] = (bool) $d['marked_unread'];
        }
        if ($upd) {
            $m->forceFill($upd)->save();
        }

        return response()->json(['conversation' => $this->svc->conversationItem($me, $c->id), 'unread' => $this->svc->unreadTotal($me)]);
    }

    public function read(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $m] = $this->convFor($conversation, $me);
        $upTo = min((int) $request->input('message_id', $c->last_message_id), (int) $c->last_message_id);
        if ($upTo > (int) $m->last_read_message_id || $m->marked_unread) {
            $m->forceFill([
                'last_read_message_id' => max($upTo, (int) $m->last_read_message_id), 'last_delivered_message_id' => max($upTo, (int) $m->last_delivered_message_id),
                'marked_unread' => false,
            ])->save();
        }
        WimsChatNotifier::clearFor($me, $c->id);

        return response()->json(['unread' => $this->svc->unreadTotal($me), 'conversation' => $this->svc->conversationItem($me, $c->id)]);
    }

    public function typing(int $conversation)
    {
        $me = $this->me();
        [$c] = $this->convFor($conversation, $me);
        if ($this->svc->feature('wimschat_typing', $me)) {
            $this->svc->setTyping($c->id, $me->id);
        }

        return response()->json(['ok' => true]);
    }

    // ---- history ---------------------------------------------------------------------------------------------

    /** Newest page, an older page (before_id), or a window around a message (around_id, from search). */
    public function messages(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $member] = $this->convFor($conversation, $me);
        $cfg = $this->cfg($me);
        $limit = max(10, min(100, (int) $cfg->get('page_size')));
        $from = $member->joined_at ?? $c->created_at;
        $base = fn () => WimsChatMessage::withTrashed()->where('conversation_id', $c->id)->where('created_at', '>=', $from);

        if ($around = $request->integer('around_id')) {
            $before = $base()->where('id', '<=', $around)->orderByDesc('id')->limit((int) ($limit / 2))->get()->reverse();
            $after = $base()->where('id', '>', $around)->orderBy('id')->limit((int) ($limit / 2))->get();
            $rows = $before->concat($after)->values();
            $rows = new \Illuminate\Database\Eloquent\Collection($rows->all());
            $moreBefore = $rows->isNotEmpty() && $base()->where('id', '<', $rows->first()->id)->exists();
            $moreAfter = $rows->isNotEmpty() && $base()->where('id', '>', $rows->last()->id)->exists();
        } elseif ($beforeId = $request->integer('before_id')) {
            $rows = $base()->where('id', '<', $beforeId)->orderByDesc('id')->limit($limit + 1)->get();
            $moreBefore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
            $rows = new \Illuminate\Database\Eloquent\Collection($rows->all());
            $moreAfter = false;
        } else {
            $rows = $base()->orderByDesc('id')->limit($limit + 1)->get();
            $moreBefore = $rows->count() > $limit;
            $rows = new \Illuminate\Database\Eloquent\Collection($rows->take($limit)->reverse()->values()->all());
            $moreAfter = false;
        }

        $out = ['messages' => $this->messenger->payload($rows, $me, $c), 'more_before' => $moreBefore, 'more_after' => $moreAfter, 'server_time' => now()->toIso8601String()];
        if ($request->boolean('initial')) {
            $pinned = $base()->whereNotNull('pinned_at')->orderByDesc('pinned_at')->limit(20)->get();
            $out += [
                'conversation' => $this->svc->conversationItem($me, $c->id),
                'members' => $this->svc->membersPayload($c, $me),
                'invited' => $c->isGroup() ? $this->svc->invitedPayload($c, $me) : [],
                'pinned' => collect($this->messenger->payload(new \Illuminate\Database\Eloquent\Collection($pinned->all()), $me, $c))->map(fn ($p) => ['id' => $p['id'], 'user' => optional($p['user'])['name'] ?? '', 'text' => Str::limit((string) $p['body'], 120) ?: '📎', 'at' => $p['created_at']])->values()->all(),
                'typing' => $this->svc->feature('wimschat_typing', $me) ? $this->svc->typingUsers($c->id, $me->id, $cfg) : [],
                'counts' => [
                    'media' => WimsChatAttachment::query()->where('conversation_id', $c->id)->where('category', 'images')->count(),
                    'files' => WimsChatAttachment::query()->where('conversation_id', $c->id)->where('category', '!=', 'images')->count(),
                ],
            ];
        }

        return response()->json($out);
    }

    /** Shared media / files / links of the conversation. */
    public function media(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $member] = $this->convFor($conversation, $me);
        $kind = $request->input('kind', 'media');
        $ab = $this->messenger->abilities($me);
        $policies = $this->cfg($me)->policies();

        if ($kind === 'links') {
            $rows = WimsChatMessage::query()->where('conversation_id', $c->id)->where('type', 'text')->where('body', 'like', '%http%')
                ->where('created_at', '>=', $member->joined_at ?? $c->created_at)->with('user:id,name')->orderByDesc('id')->limit(80)->get();
            $links = [];
            foreach ($rows as $r) {
                preg_match_all('~https?://[^\s<>"\']+~i', (string) $r->body, $mm);
                foreach ($mm[0] as $url) {
                    $url = rtrim($url, '.,);]');
                    $host = parse_url($url, PHP_URL_HOST);
                    if ($host) {
                        $links[] = ['url' => $url, 'host' => $host, 'user' => optional($r->user)->name, 'at' => $r->created_at->toIso8601String(), 'message_id' => $r->id];
                    }
                }
            }

            return response()->json(['links' => array_slice($links, 0, 60)]);
        }

        $q = WimsChatAttachment::query()->where('conversation_id', $c->id)->with('uploader:id,name')
            ->where('created_at', '>=', $member->joined_at ?? $c->created_at)
            ->when($kind === 'media', fn ($x) => $x->where('category', 'images'), fn ($x) => $x->where('category', '!=', 'images'))
            ->orderByDesc('id');
        $rows = $q->limit(60)->get();

        return response()->json(['items' => $rows->map(function ($a) use ($ab, $policies) {
            $p = $policies[$a->category] ?? ['preview' => false, 'download' => false];

            return [
                'id' => $a->id, 'message_id' => $a->message_id, 'name' => $a->original_name, 'size' => (int) $a->size_bytes, 'ext' => $a->extension, 'category' => $a->category,
                'user' => optional($a->uploader)->name, 'at' => $a->created_at->toIso8601String(), 'purged' => (bool) $a->purged_at,
                'thumb_url' => $a->thumb_path && $ab['view_att'] ? route('wimschat.attachments.thumb', $a->id) : null,
                'view_url' => ! $a->purged_at && $ab['view_att'] && ! empty($p['preview']) ? route('wimschat.attachments.view', $a->id) : null,
                'download_url' => ! $a->purged_at && $ab['download_att'] && ! empty($p['download']) ? route('wimschat.attachments.download', $a->id) : null,
            ];
        })->all()]);
    }
}
