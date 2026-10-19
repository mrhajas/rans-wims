<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\WimsConnectRecipientChannel;
use App\Support\Workflow\WorkflowModules;

/**
 * For the workflow page: of the people an event is addressed to, how many can really receive it on each channel (an enabled address on
 * Recipients), and who cannot. The sender itself skips such people without any error; this only makes the gap visible.
 */
class WorkflowReach
{
    /** @return array<string, array{total:int, channels: array<string, array{ok:int, missing:list<string>, more:int}>}> */
    public static function forModule(string $module, int $accountId): array
    {
        $cfg = WorkflowModules::get($module);
        $rules = WorkflowSettings::rules($module, $accountId);
        $people = [];
        WorkflowMessenger::$reachMemo = [];
        WorkflowSettings::memoise(true);
        try {
            foreach ($cfg['events'] as $key => $def) {
                $people[$key] = WorkflowMessenger::audience($module, $rules[$key], $def, $accountId, 0, null, [], null);
            }
        } finally {
            WorkflowMessenger::$reachMemo = null;
            WorkflowSettings::memoise(false);
        }
        // the Recipients rows of everybody involved, read once
        $all = collect($people)->flatten(1)->pluck('id')->unique()->all();
        $rows = $all ? WimsConnectRecipientChannel::whereIn('user_id', $all)->get(['user_id', 'channel', 'address', 'is_enabled'])->groupBy('user_id') : collect();
        $out = [];
        foreach ($people as $key => $list) {
            $out[$key] = self::reach($list, $rows);
        }

        return $out;
    }

    /** @param \Illuminate\Support\Collection<int, User> $people */
    public static function reach($people, $rows = null): array
    {
        $ids = $people->pluck('id')->all();
        $rows ??= $ids ? WimsConnectRecipientChannel::whereIn('user_id', $ids)->get(['user_id', 'channel', 'address', 'is_enabled'])->groupBy('user_id') : collect();
        $channels = [];
        foreach (['sms', 'email', 'whatsapp', 'meta'] as $ch) {
            $ok = 0;
            $missing = [];
            foreach ($people as $u) {
                $row = ($rows->get($u->id) ?? collect())->firstWhere('channel', $ch);
                // email with no Recipients row at all still goes to the address of the login
                $reachable = $row ? ($row->is_enabled && filled($row->address)) : ($ch === 'email' && filled($u->email));
                $reachable ? $ok++ : $missing[] = $u->name;
            }
            $channels[$ch] = ['ok' => $ok, 'missing' => array_slice($missing, 0, 6), 'more' => max(0, count($missing) - 6)];
        }

        return ['total' => $people->count(), 'channels' => $channels];
    }
}
