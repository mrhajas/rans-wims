<?php

namespace App\Http\Controllers\WimsChat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WimsChatConversation;
use App\Models\WimsChatMember;
use App\Services\WimsChat\WimsChatFiles;
use App\Services\WimsChat\WimsChatMessenger;
use App\Services\WimsChat\WimsChatService;
use App\Services\WimsChat\WimsChatSettings;

/**
 * Shared helpers for the WIMSChat controllers. Every check is made on the server; the browser's idea of what a person
 * may do is only used to hide buttons. The Authorizable auto-derivation is off because WIMSChat has its own abilities.
 */
abstract class WimsChatBaseController extends Controller
{
    public function __construct(protected WimsChatService $svc, protected WimsChatMessenger $messenger, protected WimsChatFiles $files)
    {
        $this->setAbilities([]);
    }

    protected function me(): User
    {
        $u = auth()->user();
        abort_unless($u && $u->can('view-wimschat'), 403, __('You do not have permission to use WIMSChat.'));

        return $u;
    }

    protected function cfg(User $u): WimsChatSettings
    {
        return $this->svc->cfg($u);
    }

    /** @return array{0:WimsChatConversation,1:WimsChatMember} */
    protected function convFor(int $id, User $me): array
    {
        $c = WimsChatConversation::query()->findOrFail($id);

        return [$c, $this->svc->memberOrFail($c, $me)];
    }

    /** Capability map the browser uses to show / hide controls (the server re-checks every action). */
    protected function can(User $u): array
    {
        $a = $this->messenger->abilities($u);
        $f = fn ($k) => $this->svc->feature($k, $u);

        return $a + [
            'start' => $u->can('start-wimschat-conversations') && $f('wimschat_direct'),
            'create_group' => $u->can('create-wimschat-groups') && $f('wimschat_groups'),
            'manage_groups' => $u->can('manage-wimschat-groups') && $f('wimschat_groups'),
            'settings' => $u->can('manage-wimschat-settings'),
            'analytics' => $u->can('view-wimschat-analytics') && $f('wimschat_analytics'),
            'logs' => $u->can('view-wimschat-logs'),
        ];
    }

    protected function features(User $u): array
    {
        $out = [];
        foreach (['direct', 'groups', 'attachments', 'previews', 'downloads', 'emoji', 'reactions', 'nudge', 'voice', 'mentions', 'edit', 'delete', 'forward', 'presence', 'typing', 'notifications', 'analytics'] as $k) {
            $out[$k] = $this->svc->feature('wimschat_'.$k, $u);
        }

        return $out;
    }
}
