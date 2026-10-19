<?php

namespace App\Services\AiTools;

use App\Models\User;
use App\Models\WimsChatAttachment;
use App\Models\WimsChatMember;
use App\Models\WimsChatMessage;
use App\Services\FeatureManager;
use App\Services\WimsChat\WimsChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ARIS answers about WIMSChat. Every handler checks the WIMSChat permission and the Global Feature switch and reads only
 * through the person's own conversation memberships (the same joins the chat itself uses), so ARIS can never reveal a
 * message or file that the asking user could not open directly.
 */
class WimsChatTool
{
    private function denied(User $user): ?array
    {
        if (! FeatureManager::canAccess('wimschat', $user) || ! $user->can('view-wimschat')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have access to WIMSChat.'];
        }

        return null;
    }

    public function unread(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $svc = app(WimsChatService::class);
        $by = $svc->unreadByConversation($user);
        $items = $svc->conversationItems($user, ['ids' => array_keys($by) ?: [0], 'limit' => 100])['items'];
        $rows = collect($items)->filter(fn ($i) => $i['unread'] > 0)->sortByDesc('unread')->take(8)->map(fn ($i) => "{$i['title']} ({$i['unread']})")->values()->all();

        return ['found' => true, 'total' => $svc->unreadTotal($user), 'conversations' => $rows];
    }

    public function search(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        if (! $user->can('search-wimschat')) {
            return ['error' => 'not_permitted', 'message' => 'You do not have permission to search WIMSChat.'];
        }
        $term = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($term) < 2) {
            return ['found' => false, 'message' => 'Tell me what to look for in WIMSChat.'];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $rows = WimsChatMessage::query()->select('wimschat_messages.*')
            ->join('wimschat_members as mem', fn ($j) => $j->on('mem.conversation_id', '=', 'wimschat_messages.conversation_id')->where('mem.user_id', '=', $user->id)->whereNull('mem.left_at'))
            ->whereColumn('wimschat_messages.created_at', '>=', 'mem.joined_at')->where('wimschat_messages.type', 'text')->where('wimschat_messages.body', 'like', $like)
            ->with('user:id,name')->orderByDesc('wimschat_messages.id')->limit(6)->get();

        return ['found' => $rows->isNotEmpty(), 'query' => $term, 'count' => $rows->count(),
            'items' => $rows->map(fn ($m) => ($m->user->name ?? 'Someone').' ('.$m->created_at->format('d M, H:i').'): '.Str::limit(preg_replace('/\s+/u', ' ', (string) $m->body), 90))->all(), ];
    }

    public function files(array $input, User $user): array
    {
        if ($d = $this->denied($user)) {
            return $d;
        }
        $term = trim((string) ($input['query'] ?? ''));
        $q = WimsChatAttachment::query()->select('wimschat_attachments.*')
            ->join('wimschat_members as mem', fn ($j) => $j->on('mem.conversation_id', '=', 'wimschat_attachments.conversation_id')->where('mem.user_id', '=', $user->id)->whereNull('mem.left_at'))
            ->whereColumn('wimschat_attachments.created_at', '>=', 'mem.joined_at')->whereNull('wimschat_attachments.purged_at');
        if ($term !== '') {
            $q->where('wimschat_attachments.original_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
        }
        $rows = $q->with('uploader:id,name')->orderByDesc('wimschat_attachments.id')->limit(6)->get();

        return ['found' => $rows->isNotEmpty(), 'count' => $rows->count(), 'items' => $rows->map(fn ($a) => "{$a->original_name} from ".($a->uploader->name ?? 'someone').' ('.$a->created_at->format('d M').')')->all()];
    }
}
