<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\WimsChatMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/** Messages: send (text and files), edit, delete, react, pin, forward, nudge. The rules live in WimsChatMessenger. */
class WimsChatMessageController extends WimsChatBaseController
{
    /** @return array<string,mixed> one message payload */
    private function one(WimsChatMessage $m, $me): array
    {
        return $this->messenger->payload(new EloquentCollection([$m]), $me, $m->conversation)[0];
    }

    private function messageFor(int $id, $me): WimsChatMessage
    {
        $m = WimsChatMessage::withTrashed()->findOrFail($id);
        $member = $this->svc->memberOrFail($m->conversation, $me); // must be in the conversation
        abort_unless($m->created_at >= ($member->joined_at ?? $m->conversation->created_at), 404);

        return $m;
    }

    public function store(Request $request, int $conversation)
    {
        $me = $this->me();
        [$c, $member] = $this->convFor($conversation, $me);
        $data = $request->validate([
            'body' => 'nullable|string', 'reply_to_id' => 'nullable|integer', 'client_uuid' => 'nullable|string|max:64',
            'files' => 'nullable|array', 'files.*' => 'file', 'voice' => 'nullable|boolean', 'duration_ms' => 'nullable|integer|min:0|max:3600000',
        ]);
        $m = $this->messenger->send($me, $c, $member, $data, (array) $request->file('files', []));
        $m->load('conversation');

        return response()->json([
            'message' => $this->one($m, $me),
            'conversation' => $this->svc->conversationItem($me, $c->id),
            'quota' => $this->files->quota($me, $this->cfg($me)),
        ]);
    }

    public function update(Request $request, int $message)
    {
        $me = $this->me();
        $m = $this->messageFor($message, $me);
        $data = $request->validate(['body' => 'required|string']);
        $this->messenger->edit($me, $m, $data['body']);

        return response()->json(['message' => $this->one($m->refresh(), $me)]);
    }

    public function destroy(int $message)
    {
        $me = $this->me();
        $m = $this->messageFor($message, $me);
        $this->messenger->delete($me, $m);

        return response()->json(['message' => $this->one(WimsChatMessage::withTrashed()->find($m->id), $me), 'quota' => $this->files->quota($me, $this->cfg($me))]);
    }

    public function react(Request $request, int $message)
    {
        $me = $this->me();
        $m = $this->messageFor($message, $me);
        $data = $request->validate(['emoji' => 'required|string|max:32']);
        $added = $this->messenger->react($me, $m, $data['emoji']);

        return response()->json(['added' => $added, 'message' => $this->one($m->refresh(), $me)]);
    }

    public function pin(int $message)
    {
        $me = $this->me();
        $m = $this->messageFor($message, $me);
        $pinned = $this->messenger->pin($me, $m);

        return response()->json(['pinned' => $pinned, 'message' => $this->one($m->refresh(), $me)]);
    }

    public function forward(Request $request, int $message)
    {
        $me = $this->me();
        $m = $this->messageFor($message, $me);
        $data = $request->validate(['conversations' => 'required|array|min:1|max:5', 'conversations.*' => 'integer']);
        $n = $this->messenger->forward($me, $m, $data['conversations']);

        return response()->json(['forwarded' => $n]);
    }

    public function nudge(int $conversation)
    {
        $me = $this->me();
        [$c] = $this->convFor($conversation, $me);
        $m = $this->messenger->nudge($me, $c);
        $m->load('conversation');

        return response()->json(['message' => $this->one($m, $me), 'conversation' => $this->svc->conversationItem($me, $c->id)]);
    }
}
