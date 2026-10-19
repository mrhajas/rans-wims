<?php

namespace App\Services\WimsChat;

use App\Models\User;
use App\Models\WimsChatAttachment;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Models\WimsChatMention;
use App\Models\WimsChatMessage;
use App\Models\WimsChatReaction;
use App\Services\FeatureManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write side of WIMSChat: sending, editing, deleting, reacting, pinning, forwarding, nudging, plus the payload the
 * browser renders for a message. Every rule (permission, feature switch, window, quota) is checked here on the server.
 */
class WimsChatMessenger
{
    public function __construct(private WimsChatService $svc, private WimsChatFiles $files)
    {
    }

    private function fail(string $message, string $field = 'body')
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** Strips control characters, normalises line breaks, enforces the length limit. */
    public function cleanBody(?string $text, WimsChatSettings $cfg): string
    {
        $text = (string) $text;
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? '';
        $text = trim(preg_replace("/\n{4,}/", "\n\n\n", $text));
        $max = (int) $cfg->get('max_message_chars');
        if ($max > 0 && mb_strlen($text) > $max) {
            $this->fail(__('Messages can be up to :n characters long.', ['n' => $max]));
        }

        return $text;
    }

    // ---- abilities -------------------------------------------------------------------------------------------

    /** What this person may do with messages in general (computed once per request, not per message). */
    public function abilities(User $u): array
    {
        $f = fn ($k) => FeatureManager::canAccess($k, $u);

        return [
            'send' => $u->can('send-wimschat-messages'),
            'edit' => $u->can('edit-wimschat-messages') && $f('wimschat_edit'),
            'delete' => $u->can('delete-wimschat-messages') && $f('wimschat_delete'),
            'delete_any' => $u->can('delete-any-wimschat-message') && $f('wimschat_delete'),
            'react' => $u->can('react-wimschat-messages') && $f('wimschat_reactions'),
            'forward' => $u->can('forward-wimschat-messages') && $f('wimschat_forward'),
            'pin' => $u->can('pin-wimschat-messages'),
            'attach' => $u->can('attach-wimschat-files') && $f('wimschat_attachments'),
            'voice' => $u->can('attach-wimschat-files') && $f('wimschat_attachments') && $f('wimschat_voice'),
            'view_att' => $u->can('view-wimschat-attachments') && $f('wimschat_previews'),
            'download_att' => $u->can('download-wimschat-attachments') && $f('wimschat_downloads'),
            'delete_att' => $u->can('delete-wimschat-attachments') && $f('wimschat_attachments'),
            'delete_any_att' => $u->can('delete-any-wimschat-attachment') && $f('wimschat_attachments'),
            'mention' => $u->can('mention-wimschat-users') && $f('wimschat_mentions'),
            'nudge' => $u->can('nudge-wimschat-users') && $f('wimschat_nudge'),
            'search' => $u->can('search-wimschat'),
        ];
    }

    private function withinWindow(WimsChatMessage $m, int $minutes): bool
    {
        return $minutes < 1 || $m->created_at->diffInMinutes(now()) <= $minutes;
    }

    // ---- send ------------------------------------------------------------------------------------------------

    /** @param  \Illuminate\Http\UploadedFile[]  $uploads */
    public function send(User $me, WimsChatConversation $c, WimsChatMember $mem, array $in, array $uploads = []): WimsChatMessage
    {
        $cfg = $this->svc->cfg($me);
        $ab = $this->abilities($me);
        abort_unless($ab['send'], 403, __('You do not have permission to send messages.'));

        $uuid = isset($in['client_uuid']) ? preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $in['client_uuid']) : null;
        if ($uuid && ($dup = WimsChatMessage::query()->where('user_id', $me->id)->where('client_uuid', $uuid)->first())) {
            return $dup; // the same message re-sent after a dropped connection
        }

        $body = $this->cleanBody($in['body'] ?? '', $cfg);
        if ($uploads && ! $ab['attach']) {
            $this->fail(__('You cannot attach files.'), 'files');
        }
        $isVoice = ! empty($in['voice']);
        if ($isVoice && ! $ab['voice']) {
            $this->fail(__('Voice notes are not available to you.'), 'files');
        }
        $descriptors = $uploads ? $this->files->inspect($uploads, $me, $cfg, $isVoice, isset($in['duration_ms']) ? (int) $in['duration_ms'] : null) : [];
        if ($body === '' && ! $descriptors) {
            $this->fail(__('Type a message or attach a file.'));
        }

        $reply = null;
        if (! empty($in['reply_to_id'])) {
            $reply = WimsChatMessage::withTrashed()->where('conversation_id', $c->id)->find((int) $in['reply_to_id']);
        }

        $mentionIds = $ab['mention'] && $body !== '' ? $this->mentionedIds($c, $me, $body) : [];

        try {
            $message = DB::transaction(function () use ($me, $c, $body, $reply, $uuid, $descriptors, $mentionIds, $cfg) {
                $message = WimsChatMessage::create([
                    'account_id' => $me->account_id, 'conversation_id' => $c->id, 'user_id' => $me->id, 'type' => 'text',
                    'body' => $body !== '' ? $body : null, 'reply_to_id' => $reply->id ?? null, 'client_uuid' => $uuid,
                ]);
                foreach ($descriptors as $d) {
                    $this->files->store($d, $message, $me, $cfg);
                }
                foreach ($mentionIds as $uid) {
                    WimsChatMention::query()->updateOrCreate(['message_id' => $message->id, 'user_id' => $uid]);
                }
                $this->afterInsert($c, $message, $me);

                return $message;
            });
        } catch (QueryException $e) {
            // a duplicate client_uuid raced in: hand back the winner
            if ($uuid && ($dup = WimsChatMessage::query()->where('user_id', $me->id)->where('client_uuid', $uuid)->first())) {
                return $dup;
            }
            throw $e;
        }

        $message->load('attachments');
        WimsChatLogger::log($me, 'MESSAGE_SEND', $c, ['message_id' => $message->id, 'attachments' => $message->attachments->count(), 'mentions' => count($mentionIds)]);
        foreach ($message->attachments as $a) {
            WimsChatLogger::log($me, 'ATTACHMENT_UPLOAD', $c, ['message_id' => $message->id, 'attachment_id' => $a->id, 'filename' => $a->original_name, 'size' => $a->size_bytes]);
        }
        WimsChatNotifier::afterSend($message, $c, $me, $mentionIds, $reply && $reply->user_id && (int) $reply->user_id !== $me->id ? (int) $reply->user_id : null);

        return $message;
    }

    /** Moves the conversation's "last message", reveals it again for people who had hidden it, and marks the sender as caught up. */
    private function afterInsert(WimsChatConversation $c, WimsChatMessage $m, User $sender): void
    {
        $c->forceFill(['last_message_id' => $m->id, 'last_message_at' => $m->created_at])->save();
        WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->where('user_id', '!=', $sender->id)
            ->update(['hidden_at' => null, 'archived_at' => DB::raw('CASE WHEN muted = 1 THEN archived_at ELSE NULL END'), 'updated_at' => now()]);
        WimsChatMember::query()->where('conversation_id', $c->id)->where('user_id', $sender->id)
            ->update(['last_read_message_id' => $m->id, 'last_delivered_message_id' => $m->id, 'hidden_at' => null, 'marked_unread' => false, 'updated_at' => now()]);
    }

    public function system(WimsChatConversation $c, ?User $actor, string $text): WimsChatMessage
    {
        $m = WimsChatMessage::create(['account_id' => $c->account_id, 'conversation_id' => $c->id, 'user_id' => $actor->id ?? null, 'type' => 'system', 'body' => $text]);
        $c->forceFill(['last_message_id' => $m->id, 'last_message_at' => $m->created_at])->save();

        return $m;
    }

    /** @mention parsing: only people who are in this conversation can be mentioned. */
    private function mentionedIds(WimsChatConversation $c, User $me, string $body): array
    {
        if (! str_contains($body, '@')) {
            return [];
        }
        preg_match_all('/(?<![\w@])@([A-Za-z0-9._-]{2,40})/u', $body, $mm);
        $names = array_unique(array_map('strtolower', $mm[1] ?? []));
        if (! $names) {
            return [];
        }
        $memberIds = WimsChatMember::query()->where('conversation_id', $c->id)->whereNull('left_at')->where('user_id', '!=', $me->id)->pluck('user_id');

        return User::query()->whereIn('id', $memberIds)->whereIn(DB::raw('LOWER(username)'), $names)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // ---- edit / delete ---------------------------------------------------------------------------------------

    public function edit(User $me, WimsChatMessage $m, string $text): WimsChatMessage
    {
        $cfg = $this->svc->cfg($me);
        $ab = $this->abilities($me);
        abort_unless($ab['edit'] && (int) $m->user_id === $me->id && $m->type === 'text' && ! $m->trashed(), 403, __('You cannot edit this message.'));
        abort_unless($this->withinWindow($m, (int) $cfg->get('edit_window_minutes')), 403, __('The time allowed for editing this message has passed.'));
        $body = $this->cleanBody($text, $cfg);
        if ($body === '' && ! $m->attachments()->exists()) {
            $this->fail(__('A message cannot be empty.'));
        }
        $m->forceFill(['body' => $body !== '' ? $body : null, 'edited_at' => now()])->save();

        $c = $m->conversation;
        $new = $ab['mention'] ? array_diff($this->mentionedIds($c, $me, $body), $m->mentions()->pluck('user_id')->all()) : [];
        foreach ($new as $uid) {
            WimsChatMention::query()->updateOrCreate(['message_id' => $m->id, 'user_id' => $uid]);
        }
        if ($new) {
            WimsChatNotifier::afterSend($m->load('attachments'), $c, $me, array_values($new), null);
        }
        WimsChatLogger::log($me, 'MESSAGE_EDIT', $c, ['message_id' => $m->id]);

        return $m;
    }

    public function delete(User $me, WimsChatMessage $m): void
    {
        $cfg = $this->svc->cfg($me);
        $ab = $this->abilities($me);
        $mine = (int) $m->user_id === $me->id;
        abort_unless(! $m->trashed() && $m->type !== 'system' && (($mine && $ab['delete'] && $this->withinWindow($m, (int) $cfg->get('delete_window_minutes'))) || $ab['delete_any']), 403, __('You cannot delete this message.'));

        // the row (and its text) stays in the database for audit; it is only hidden from the conversation
        $m->attachments()->get()->each(fn ($a) => $a->delete());
        $m->delete();
        WimsChatLogger::log($me, 'MESSAGE_DELETE', $m->conversation, ['message_id' => $m->id, 'moderated' => ! $mine]);
    }

    // ---- reactions / pins ------------------------------------------------------------------------------------

    /** Toggles one reaction; returns true when it was added. */
    public function react(User $me, WimsChatMessage $m, string $emoji): bool
    {
        $cfg = $this->svc->cfg($me);
        abort_unless($this->abilities($me)['react'] && ! $m->trashed() && $m->type !== 'system', 403, __('You cannot react to this message.'));
        abort_unless(in_array($emoji, $cfg->reactions(), true), 422, __('That reaction is not available.'));

        $existing = WimsChatReaction::query()->where('message_id', $m->id)->where('user_id', $me->id)->where('emoji', $emoji)->first();
        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            WimsChatReaction::create(['message_id' => $m->id, 'user_id' => $me->id, 'emoji' => $emoji, 'created_at' => now()]);
            $added = true;
        }
        $m->touch(); // lets the other browsers pick the change up on their next poll
        WimsChatLogger::log($me, $added ? 'REACTION_ADD' : 'REACTION_REMOVE', $m->conversation, ['message_id' => $m->id, 'emoji' => $emoji]);

        return $added;
    }

    public function pin(User $me, WimsChatMessage $m): bool
    {
        abort_unless($this->abilities($me)['pin'] && ! $m->trashed() && $m->type !== 'system', 403, __('You cannot pin messages.'));
        $pin = ! $m->pinned_at;
        $m->forceFill(['pinned_at' => $pin ? now() : null, 'pinned_by' => $pin ? $me->id : null])->save();
        WimsChatLogger::log($me, $pin ? 'MESSAGE_PIN' : 'MESSAGE_UNPIN', $m->conversation, ['message_id' => $m->id]);

        return $pin;
    }

    // ---- forward / nudge -------------------------------------------------------------------------------------

    /** @return int number of conversations it was forwarded to */
    public function forward(User $me, WimsChatMessage $m, array $conversationIds): int
    {
        $ab = $this->abilities($me);
        abort_unless($ab['forward'] && $ab['send'] && ! $m->trashed() && $m->type === 'text', 403, __('You cannot forward this message.'));
        $cfg = $this->svc->cfg($me);
        $m->loadMissing('attachments');
        $n = 0;
        foreach (array_slice(array_unique(array_map('intval', $conversationIds)), 0, 5) as $cid) {
            $target = WimsChatConversation::query()->find($cid);
            $member = $target ? WimsChatMember::query()->where('conversation_id', $cid)->where('user_id', $me->id)->whereNull('left_at')->first() : null;
            if (! $target || ! $member || $target->account_id !== $me->account_id) {
                continue;
            }
            DB::transaction(function () use ($me, $target, $m, &$n) {
                $copy = WimsChatMessage::create([
                    'account_id' => $me->account_id, 'conversation_id' => $target->id, 'user_id' => $me->id, 'type' => 'text',
                    'body' => $m->body, 'forwarded_from_id' => $m->id,
                ]);
                foreach ($m->attachments as $a) {
                    if (! $a->purged_at) {
                        $this->files->copyRecord($a, $copy, $me);
                    }
                }
                $this->afterInsert($target, $copy, $me);
                $n++;
                WimsChatNotifier::afterSend($copy->load('attachments'), $target, $me, [], null);
            });
            WimsChatLogger::log($me, 'MESSAGE_FORWARD', $target, ['message_id' => $m->id]);
        }

        return $n;
    }

    public function nudge(User $me, WimsChatConversation $c): WimsChatMessage
    {
        $cfg = $this->svc->cfg($me);
        abort_unless($this->abilities($me)['nudge'], 403, __('You cannot nudge people.'));
        $cool = (int) $cfg->get('nudge_cooldown_seconds');
        if ($cool > 0) {
            $last = WimsChatMessage::query()->where('conversation_id', $c->id)->where('user_id', $me->id)->where('type', 'nudge')->latest('id')->first();
            if ($last && $last->created_at->diffInSeconds(now()) < $cool) {
                abort(429, __('Please wait :n seconds before nudging again.', ['n' => $cool - $last->created_at->diffInSeconds(now())]));
            }
        }
        $m = DB::transaction(function () use ($c, $me) {
            $m = WimsChatMessage::create(['account_id' => $me->account_id, 'conversation_id' => $c->id, 'user_id' => $me->id, 'type' => 'nudge']);
            $this->afterInsert($c, $m, $me);

            return $m;
        });
        WimsChatLogger::log($me, 'NUDGE', $c, ['message_id' => $m->id]);
        WimsChatNotifier::nudge($c, $me);

        return $m;
    }

    // ---- payload ---------------------------------------------------------------------------------------------

    /**
     * What the browser needs to draw these messages. Deleted messages keep their place but lose their content.
     *
     * @param  Collection<int, WimsChatMessage>  $messages
     */
    public function payload(Collection $messages, User $me, ?WimsChatConversation $c = null): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $cfg = $this->svc->cfg($me);
        $ab = $this->abilities($me);
        $policies = $cfg->policies();
        $editMin = (int) $cfg->get('edit_window_minutes');
        $delMin = (int) $cfg->get('delete_window_minutes');

        $messages->loadMissing(['user:id,name,username,profile_photo_path', 'attachments', 'reactions.user:id,name', 'mentions', 'replyTo.user:id,name']);
        $fwdIds = $messages->pluck('forwarded_from_id')->filter()->unique();
        $fwdUsers = $fwdIds->isEmpty() ? collect() : WimsChatMessage::withTrashed()->whereIn('id', $fwdIds)->with('user:id,name')->get()->keyBy('id');

        return $messages->map(function (WimsChatMessage $m) use ($me, $ab, $policies, $editMin, $delMin, $cfg, $fwdUsers) {
            $deleted = $m->trashed();
            $mine = (int) $m->user_id === $me->id;
            $out = [
                'id' => $m->id, 'conversation_id' => $m->conversation_id, 'type' => $m->type,
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'username' => $m->user->username, 'photo' => $m->user->profile_photo_url] : null,
                'mine' => $mine, 'deleted' => $deleted,
                'body' => $deleted ? null : $m->body,
                'edited' => (bool) $m->edited_at,
                'created_at' => $m->created_at->toIso8601String(),
                'updated_at' => $m->updated_at->toIso8601String(),
                'pinned' => (bool) $m->pinned_at,
                'client_uuid' => $m->client_uuid,
                'forwarded' => $m->forwarded_from_id ? ['from' => optional(optional($fwdUsers[$m->forwarded_from_id] ?? null)->user)->name] : null,
                'mentions' => $deleted ? [] : $m->mentions->pluck('user_id')->all(),
                'reply_to' => null, 'attachments' => [], 'reactions' => [],
                'can' => [
                    'edit' => ! $deleted && $mine && $m->type === 'text' && $ab['edit'] && ($editMin < 1 || $m->created_at->diffInMinutes(now()) <= $editMin),
                    'delete' => ! $deleted && $m->type !== 'system' && (($mine && $ab['delete'] && ($delMin < 1 || $m->created_at->diffInMinutes(now()) <= $delMin)) || $ab['delete_any']),
                    'react' => ! $deleted && $m->type !== 'system' && $ab['react'],
                    'forward' => ! $deleted && $m->type === 'text' && $ab['forward'] && $ab['send'],
                    'pin' => ! $deleted && $m->type !== 'system' && $ab['pin'],
                    'reply' => ! $deleted && $m->type !== 'system' && $ab['send'],
                ],
            ];
            if ($m->reply_to_id && $m->replyTo) {
                $r = $m->replyTo;
                $out['reply_to'] = ['id' => $r->id, 'user' => optional($r->user)->name, 'deleted' => $r->trashed(),
                    'text' => $r->trashed() ? __('This message was deleted') : (\Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $r->body), 120) ?: ($r->type === 'nudge' ? __('Nudge') : '📎')), ];
            }
            if (! $deleted) {
                foreach ($m->attachments as $a) {
                    $policy = $policies[$a->category] ?? ['preview' => false, 'download' => false];
                    $out['attachments'][] = [
                        'id' => $a->id, 'name' => $a->original_name, 'size' => (int) $a->size_bytes, 'ext' => $a->extension, 'category' => $a->category,
                        'mime' => $a->mime_type, 'is_image' => $a->category === 'images', 'width' => $a->width, 'height' => $a->height,
                        'purged' => (bool) $a->purged_at, 'is_voice' => $a->category === 'voice', 'duration_ms' => $a->duration_ms,
                        'thumb_url' => $a->thumb_path && $ab['view_att'] ? route('wimschat.attachments.thumb', $a->id) : null,
                        'view_url' => ! $a->purged_at && $ab['view_att'] && ! empty($policy['preview']) ? route('wimschat.attachments.view', $a->id) : null,
                        'download_url' => ! $a->purged_at && $ab['download_att'] && ! empty($policy['download']) ? route('wimschat.attachments.download', $a->id) : null,
                        'can_delete' => ($mine && $ab['delete_att']) || $ab['delete_any_att'],
                    ];
                }
                $out['reactions'] = $m->reactions->groupBy('emoji')->map(fn ($g, $emoji) => [
                    'emoji' => $emoji, 'count' => $g->count(), 'mine' => $g->contains('user_id', $me->id),
                    'users' => $g->take(6)->map(fn ($r) => optional($r->user)->name)->filter()->values()->all(),
                ])->values()->all();
            }

            return $out;
        })->values()->all();
    }
}
