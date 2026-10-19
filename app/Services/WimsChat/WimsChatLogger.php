<?php

namespace App\Services\WimsChat;

use App\Models\User;
use App\Models\WimsChatConversation;

/**
 * Writes WIMSChat actions into the normal WIMS activity log (System Logs, module "WIMSChat"). Only metadata is stored —
 * who, what, which conversation / message / file id, IP and device. Message text and file contents are NEVER logged.
 */
class WimsChatLogger
{
    public const LOG_NAME = 'WIMSChat';

    private const VERBS = [
        'CHAT_CREATE' => 'started a conversation',
        'GROUP_CREATE' => 'created the group "%t"',
        'GROUP_UPDATE' => 'updated the group "%t"',
        'MEMBER_ADD' => 'added a member to "%t"',
        'MEMBER_REMOVE' => 'removed a member from "%t"',
        'MEMBER_INVITE' => 'invited a member to "%t"',
        'MEMBER_INVITE_CANCEL' => 'withdrew an invitation to "%t"',
        'MEMBER_JOIN' => 'accepted the invitation and joined "%t"',
        'MEMBER_DECLINE' => 'declined the invitation to "%t"',
        'RETENTION_PURGE' => 'deleted chat data older than the retention period',
        'MEMBER_ROLE' => 'changed a member\'s role in "%t"',
        'MEMBER_LEAVE' => 'left "%t"',
        'MESSAGE_SEND' => 'sent a message',
        'MESSAGE_EDIT' => 'edited a message',
        'MESSAGE_DELETE' => 'deleted a message',
        'MESSAGE_FORWARD' => 'forwarded a message',
        'MESSAGE_PIN' => 'pinned a message',
        'MESSAGE_UNPIN' => 'unpinned a message',
        'REACTION_ADD' => 'reacted to a message',
        'REACTION_REMOVE' => 'removed a reaction',
        'NUDGE' => 'sent a nudge',
        'ATTACHMENT_UPLOAD' => 'uploaded "%f"',
        'ATTACHMENT_VIEW' => 'previewed "%f"',
        'ATTACHMENT_DOWNLOAD' => 'downloaded "%f"',
        'ATTACHMENT_DELETE' => 'deleted the attachment "%f"',
        'ATTACHMENT_REJECTED' => 'tried to upload "%f" (rejected)',
        'ATTACHMENT_PURGED' => 'purged expired attachments',
        'CONVERSATION_ARCHIVE' => 'archived a conversation',
        'CONVERSATION_RESTORE' => 'restored a conversation from the archive',
        'CONVERSATION_MUTE' => 'muted a conversation',
        'CONVERSATION_UNMUTE' => 'unmuted a conversation',
        'CONVERSATION_PIN' => 'pinned a conversation',
        'CONVERSATION_UNPIN' => 'unpinned a conversation',
        'CONVERSATION_HIDE' => 'removed a conversation from their view',
        'SETTINGS_CHANGE' => 'changed WIMSChat settings',
        'QUOTA_CHANGE' => 'changed WIMSChat file limits / daily quotas',
        'REPORT_EXPORTED' => 'exported a WIMSChat report',
    ];

    public static function log(?User $user, string $action, ?WimsChatConversation $conversation = null, array $extra = []): void
    {
        try {
            $request = request();
            $agent = user_agent_info((string) $request->userAgent());
            $text = strtr(self::VERBS[$action] ?? strtolower($action), [
                '%t' => $conversation->title ?? '',
                '%f' => $extra['filename'] ?? '',
            ]);

            $builder = activity(self::LOG_NAME)->causedBy($user)->event(strtolower($action));
            // scheduled commands have no signed-in user and no conversation: name the account explicitly
            if (($accountId = $extra['account_id'] ?? ($user->account_id ?? null)) && ! $conversation) {
                $builder->tap(function ($activity) use ($accountId) {
                    $activity->account_id = $accountId;
                });
            }
            if ($conversation) {
                $builder->performedOn($conversation);
            }
            $builder->withProperties(array_merge([
                'action' => $action,
                'conversation_id' => $conversation->id ?? null,
                'conversation_type' => $conversation->type ?? null,
                'ip' => $request->ip(),
                'browser' => $agent['browser'] ?? null,
                'device' => $agent['device'] ?? null,
                'os' => $agent['os'] ?? null,
                'user_agent' => (string) $request->userAgent(),
            ], $extra))->log(trim(($user->name ?? 'System').' '.$text));
        } catch (\Throwable $e) {
            report($e); // logging must never break a chat action
        }
    }
}
