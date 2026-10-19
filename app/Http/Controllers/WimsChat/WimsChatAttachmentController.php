<?php

namespace App\Http\Controllers\WimsChat;

use App\Models\WimsChatAttachment;
use App\Services\WimsChat\WimsChatLogger;

/** Attachments are only ever streamed through these authorised routes — never a public URL. */
class WimsChatAttachmentController extends WimsChatBaseController
{
    /** The attachment, only if its message is visible to this person (member of the conversation, after they joined). */
    private function visible(int $id, $me): WimsChatAttachment
    {
        $a = WimsChatAttachment::query()->findOrFail($id);
        $message = $a->message;
        abort_if(! $message || $message->trashed(), 404);
        $member = $this->svc->memberOrFail($message->conversation, $me);
        abort_unless($message->created_at >= ($member->joined_at ?? $message->conversation->created_at), 404);
        abort_if($a->purged_at, 410, __('This file was removed after its retention period.'));

        return $a;
    }

    public function thumb(int $attachment)
    {
        $me = $this->me();
        abort_unless($this->messenger->abilities($me)['view_att'], 403, __('You cannot preview attachments.'));
        $a = $this->visible($attachment, $me);
        abort_unless($a->thumb_path, 404);

        return $this->files->response($a, 'inline', true);
    }

    public function view(int $attachment)
    {
        $me = $this->me();
        abort_unless($this->messenger->abilities($me)['view_att'], 403, __('You cannot preview attachments.'));
        $a = $this->visible($attachment, $me);
        $policy = $this->cfg($me)->policies()[$a->category] ?? null;
        abort_unless($policy && ! empty($policy['preview']), 403, __('This kind of file cannot be previewed. Download it instead.'));
        WimsChatLogger::log($me, 'ATTACHMENT_VIEW', $a->message->conversation, ['attachment_id' => $a->id, 'filename' => $a->original_name]);

        return $this->files->response($a, 'inline');
    }

    public function download(int $attachment)
    {
        $me = $this->me();
        abort_unless($this->messenger->abilities($me)['download_att'], 403, __('You cannot download attachments.'));
        $a = $this->visible($attachment, $me);
        $policy = $this->cfg($me)->policies()[$a->category] ?? null;
        abort_unless($policy && ! empty($policy['download']), 403, __('Downloading this kind of file is switched off.'));
        WimsChatLogger::log($me, 'ATTACHMENT_DOWNLOAD', $a->message->conversation, ['attachment_id' => $a->id, 'filename' => $a->original_name]);

        return $this->files->response($a, 'attachment');
    }

    public function destroy(int $attachment)
    {
        $me = $this->me();
        $a = $this->visible($attachment, $me);
        $ab = $this->messenger->abilities($me);
        abort_unless(((int) $a->user_id === $me->id && $ab['delete_att']) || $ab['delete_any_att'], 403, __('You cannot delete this attachment.'));
        $a->delete();
        $a->message->touch();
        WimsChatLogger::log($me, 'ATTACHMENT_DELETE', $a->message->conversation, ['attachment_id' => $a->id, 'filename' => $a->original_name]);

        return response()->json(['ok' => true]);
    }
}
