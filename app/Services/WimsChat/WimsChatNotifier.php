<?php

namespace App\Services\WimsChat;

use App\Models\User;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Models\WimsChatMessage;
use App\Services\FeatureManager;
use App\Services\Workflow\WorkflowMessenger;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifications of WIMSChat, through the WIMSChat workflow page (who is told, on which channels, with which words). Plain messages only
 * reach people who are not actively in WIMS (and are folded into ONE bell row per conversation); mentions, replies, nudges and group
 * changes always do. The person each notice is for is always a member of that conversation (or the person added / removed / who invited),
 * and nobody else can be given the notice, so the workflow page cannot be used to read other people's chats. Personal choices (a muted
 * conversation, "Do Not Disturb", being active in WIMS) are checked here before anything is handed over.
 */
class WimsChatNotifier
{
    public const MODULE = 'wimschat';

    private static function on(User $user, string $event): bool
    {
        try {
            return (bool) WorkflowSettings::effective(self::MODULE, (int) ($user->account_id ?: 1), $event)['is_enabled'];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function afterSend(WimsChatMessage $m, WimsChatConversation $c, User $sender, array $mentionIds, ?int $replyUserId): void
    {
        try {
            if (! FeatureManager::isEnabled('wimschat_notifications')) {
                return;
            }
            $svc = app(WimsChatService::class);
            $cfg = $svc->cfg($sender);
            $mentionOn = self::on($sender, 'mention');
            $replyOn = self::on($sender, 'reply');
            $messageOn = self::on($sender, 'message');
            $preview = Str::limit(preg_replace('/\s+/u', ' ', (string) $m->body) ?: ($m->attachments->count() ? '📎 '.$m->attachments->first()->original_name : ''), 90, '…');
            $where = $c->type === 'group' ? $sender->name.' · '.$c->title : $sender->name;

            $mention = collect();
            $reply = collect();
            $away = collect();
            foreach (self::recipients($c, $sender) as [$member, $user]) {
                if (in_array($user->id, $mentionIds, true) && $mentionOn) {
                    $mention->push($user);

                    continue;
                }
                if ($replyUserId === $user->id && $replyOn) {
                    $reply->push($user);

                    continue;
                }
                if (! $messageOn || $member->isMuted()) {
                    continue;
                }
                $pref = $svc->prefsMap([$user->id])[$user->id] ?? null;
                if ($svc->presence($user, $pref, $cfg)['key'] === 'online' || $svc->presence($user, $pref, $cfg)['quiet']) {
                    continue;
                }
                $away->push($user);
            }

            $values = ['sender' => $sender->name, 'conversation' => $c->type === 'group' ? (string) $c->title : $sender->name, 'preview' => $preview];
            $text = ($c->type === 'group' ? $c->title.': ' : '').$preview;
            self::send('mention', $mention, $sender, $c, $m, __(':w mentioned you', ['w' => $sender->name]), $text, $values, 'mention:'.$m->id.':'.uniqid());
            self::send('reply', $reply, $sender, $c, $m, __(':w replied to you', ['w' => $sender->name]), $text, $values, 'reply:'.$m->id.':'.uniqid());
            self::away($away, $sender, $c, $m, $where.': '.$preview, $values);
        } catch (\Throwable $e) {
            Log::warning('WIMSChat notification failed', ['error' => $e->getMessage()]);
        }
    }

    public static function nudge(WimsChatConversation $c, User $sender): void
    {
        try {
            if (! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($sender, 'nudge')) {
                return;
            }
            $users = collect(self::recipients($c, $sender))->map(fn ($pair) => $pair[1]);
            self::send('nudge', $users, $sender, $c, $c, __(':w nudged you', ['w' => $sender->name]), $c->type === 'group' ? (string) $c->title : __('Open WIMSChat to reply.'), ['sender' => $sender->name, 'conversation' => $c->type === 'group' ? (string) $c->title : $sender->name, 'preview' => ''], 'nudge:'.$c->id.':'.uniqid());
        } catch (\Throwable $e) {
            Log::warning('WIMSChat nudge notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Invited to / added to / removed from a group. $kind: invite | added | removed (a bool is still accepted: true = added). */
    public static function groupChange(WimsChatConversation $c, User $actor, array $userIds, $kind): void
    {
        $kind = is_bool($kind) ? ($kind ? 'added' : 'removed') : $kind;
        try {
            if (! FeatureManager::isEnabled('wimschat_notifications')) {
                return;
            }
            $map = [
                'invite' => ['group_invite', __(':w invited you to join a group', ['w' => $actor->name])],
                'added' => ['group_added', __(':w added you to a group', ['w' => $actor->name])],
                'removed' => ['group_removed', __(':w removed you from a group', ['w' => $actor->name])],
            ];
            [$event, $title] = $map[$kind];
            if (! self::on($actor, $event)) {
                return;
            }
            $users = User::query()->whereIn('id', $userIds)->where('id', '!=', $actor->id)->get();
            $text = $kind === 'invite' ? (string) $c->title.' · '.__('Open WIMSChat to accept or decline.') : (string) $c->title;
            self::send($event, $users, $actor, $c, $c, $title, $text, ['sender' => $actor->name, 'conversation' => (string) $c->title, 'preview' => ''], $event.':'.$c->id.':'.uniqid(), $kind === 'removed' || $kind === 'invite' ? null : $c->id);
        } catch (\Throwable $e) {
            Log::warning('WIMSChat group notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Tells the person who invited someone that they have joined. */
    public static function joined(WimsChatConversation $c, User $who, int $inviterId): void
    {
        try {
            if (! $inviterId || $inviterId === $who->id || ! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($who, 'group_joined')) {
                return;
            }
            if (! ($inviter = User::query()->where('is_active', true)->find($inviterId))) {
                return;
            }
            self::send('group_joined', collect([$inviter]), $who, $c, $c, __(':w joined your group', ['w' => $who->name]), (string) $c->title, ['sender' => $who->name, 'conversation' => (string) $c->title, 'preview' => ''], 'joined:'.$c->id.':'.$who->id.':'.uniqid(), $c->id);
        } catch (\Throwable $e) {
            Log::warning('WIMSChat join notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** A group administrator renamed the group or changed its description / picture: the other active members are told. */
    public static function groupUpdated(WimsChatConversation $c, User $actor, string $what): void
    {
        try {
            if (! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($actor, 'group_updated')) {
                return;
            }
            $users = collect(self::recipients($c, $actor))->map(fn ($pair) => $pair[1]);
            self::send('group_updated', $users, $actor, $c, $c, __(':w changed the group', ['w' => $actor->name]), (string) $c->title . ' · ' . $what, ['sender' => $actor->name, 'conversation' => (string) $c->title, 'preview' => $what], 'group_updated:'.$c->id.':'.uniqid());
        } catch (\Throwable $e) {
            Log::warning('WIMSChat group update notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** A member was made an administrator (or an ordinary member again): that member is told. */
    public static function roleChanged(WimsChatConversation $c, User $actor, int $userId, string $role): void
    {
        try {
            if ($userId === $actor->id || ! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($actor, 'group_role_changed')) {
                return;
            }
            $users = User::query()->where('id', $userId)->where('is_active', true)->get();
            $title = $role === 'admin' ? __(':w made you a group administrator', ['w' => $actor->name]) : __(':w made you an ordinary member of a group', ['w' => $actor->name]);
            self::send('group_role_changed', $users, $actor, $c, $c, $title, (string) $c->title, ['sender' => $actor->name, 'conversation' => (string) $c->title, 'preview' => ''], 'group_role:'.$c->id.':'.$userId.':'.uniqid());
        } catch (\Throwable $e) {
            Log::warning('WIMSChat role notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** A member left the group: the remaining group administrators are told. */
    public static function left(WimsChatConversation $c, User $who): void
    {
        try {
            if (! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($who, 'group_left')) {
                return;
            }
            $users = collect(self::recipients($c, $who))->filter(fn ($pair) => $pair[0]->role === 'admin')->map(fn ($pair) => $pair[1]);
            self::send('group_left', $users, $who, $c, $c, __(':w left the group', ['w' => $who->name]), (string) $c->title, ['sender' => $who->name, 'conversation' => (string) $c->title, 'preview' => ''], 'group_left:'.$c->id.':'.$who->id.':'.uniqid());
        } catch (\Throwable $e) {
            Log::warning('WIMSChat leave notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Tells the person who invited someone that the invitation was declined. */
    public static function declined(WimsChatConversation $c, User $who, int $inviterId): void
    {
        try {
            if (! $inviterId || $inviterId === $who->id || ! FeatureManager::isEnabled('wimschat_notifications') || ! self::on($who, 'group_declined')) {
                return;
            }
            if (! ($inviter = User::query()->where('is_active', true)->find($inviterId))) {
                return;
            }
            self::send('group_declined', collect([$inviter]), $who, $c, $c, __(':w declined your invitation', ['w' => $who->name]), (string) $c->title, ['sender' => $who->name, 'conversation' => (string) $c->title, 'preview' => ''], 'declined:'.$c->id.':'.$who->id.':'.uniqid(), $c->id);
        } catch (\Throwable $e) {
            Log::warning('WIMSChat decline notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** A Super Admin saved the WIMSChat settings. Goes to the roles / people chosen on the workflow page (not to chat members). */
    public static function settingsChanged(User $actor, int $changed): void
    {
        try {
            WorkflowMessenger::dispatch(self::MODULE, 'settings_changed', \App\Models\Account::find($actor->account_id), $actor, [
                'account_id' => (int) ($actor->account_id ?: 1), 'title' => __('WIMSChat settings changed'),
                'message' => __('The WIMSChat settings were changed by :n (:c setting(s) changed).', ['n' => $actor->name, 'c' => $changed]),
                'url' => route('wimschat.settings'), 'dedupe' => 'wimschat|settings|'.intdiv(time(), 20), 'values' => ['sender' => $actor->name],
            ]);
        } catch (\Throwable $e) {
            Log::warning('WIMSChat settings notification failed', ['error' => $e->getMessage()]);
        }
    }

    /** Opening a conversation clears its bell rows. */
    public static function clearFor(User $user, int $conversationId): void
    {
        try {
            $url = self::url($conversationId);
            // older rows carry conversation_id; rows sent through the workflow page carry the conversation's link
            $user->unreadNotifications()->where('data->module', 'wimschat')
                ->where(fn ($q) => $q->where('data->conversation_id', $conversationId)->orWhere('data->url', $url))
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {
            // harmless
        }
    }

    /** @return array<int, array{0:WimsChatMember,1:User}> everyone but the sender */
    private static function recipients(WimsChatConversation $c, User $sender): array
    {
        $members = WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->where('user_id', '!=', $sender->id)->get();
        $users = User::query()->whereIn('id', $members->pluck('user_id'))->where('is_active', true)->get()->keyBy('id');
        $out = [];
        foreach ($members as $m) {
            if (isset($users[$m->user_id])) {
                $out[] = [$m, $users[$m->user_id]];
            }
        }

        return $out;
    }

    private static function url(?int $conversationId): string
    {
        return $conversationId ? route('wimschat.index', ['c' => $conversationId]) : route('wimschat.index');
    }

    /** Hands one notice to the workflow engine. $users are the members it is for; nobody outside them can receive it. */
    private static function send(string $event, Collection $users, User $actor, WimsChatConversation $c, ?Model $record, string $title, string $message, array $values, string $dedupe, ?int $conversationId = -1): void
    {
        if ($users->isEmpty()) {
            return;
        }
        $conversationId = $conversationId === -1 ? $c->id : $conversationId;
        WorkflowMessenger::dispatch(self::MODULE, $event, $record, $actor, [
            'account_id' => (int) ($actor->account_id ?: 1), 'title' => $title, 'message' => $message, 'url' => self::url($conversationId),
            'owner_users' => $users, 'candidates' => $users, 'skip_actor' => true, 'dedupe' => $dedupe, 'values' => $values,
        ]);
    }

    /**
     * Plain messages for people who are away. The bell keeps ONE row per conversation: when the person already has an unread row for
     * it, that row is updated instead of a new one (and no new e-mail is sent), exactly as before the workflow page existed.
     */
    private static function away(Collection $users, User $sender, WimsChatConversation $c, WimsChatMessage $m, string $text, array $values): void
    {
        if ($users->isEmpty()) {
            return;
        }
        $dedupe = 'message:'.$m->id.':'.uniqid();
        $url = self::url($c->id);
        $rule = WorkflowSettings::effective(self::MODULE, (int) ($sender->account_id ?: 1), 'message');
        $audience = $rule['bell_enabled'] ? WorkflowMessenger::audience(self::MODULE, $rule, \App\Support\Workflow\WorkflowModules::get(self::MODULE)['events']['message'], (int) ($sender->account_id ?: 1), 0, $sender, ['owner_users' => $users, 'candidates' => $users, 'skip_actor' => true], $m)->keyBy('id') : collect();

        foreach ($users as $user) {
            if (! $audience->has($user->id)) {
                continue;
            }
            $existing = $user->unreadNotifications()->where('data->module', 'wimschat')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('data->type', 'MESSAGE')->where('data->conversation_id', $c->id))->orWhere(fn ($w) => $w->where('data->type', 'wimschat_message')->where('data->url', $url)))
                ->first();
            if (! $existing) {
                continue;
            }
            $d = $existing->data;
            $d['count'] = (int) ($d['count'] ?? 1) + 1;
            $d['title'] = __('WIMSChat - :n new messages', ['n' => $d['count']]);
            $d['message'] = $text;
            $d['created_at'] = now()->toDateTimeString();
            $existing->forceFill(['data' => $d, 'created_at' => now()])->save();
            // the engine's duplicate guard for this person and notice: the bell row and the e-mail were just taken care of
            Cache::put('alerts:'.md5(self::MODULE.'|message|'.$user->id.'|'.$dedupe), 1, 120);
        }

        self::send('message', $users, $sender, $c, $m, __('New WIMSChat message'), $text, $values, $dedupe);
    }
}
