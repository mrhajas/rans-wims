<?php

namespace App\Services\Workflow;

use App\Models\WorkflowNotificationRule;
use App\Support\Workflow\WorkflowModules;

/**
 * Reads and writes the "Who Gets Notified" / "Messages" settings of a workflow module. Whatever has not been saved yet comes from the
 * module's starting values (WorkflowModules), which reproduce how the module behaved before it had a workflow page.
 */
class WorkflowSettings
{
    public const FLAGS = ['is_enabled', 'notify_owner', 'notify_step_owner', 'notify_everyone', 'limit_to_project', 'bell_enabled', 'sms_enabled', 'email_enabled', 'whatsapp_enabled', 'meta_enabled', 'email_show_details'];

    public const TEXTS = ['sms_template', 'email_subject', 'email_body', 'whatsapp_template', 'meta_template'];

    /**
     * While a settings page is being built, the saved rules of a module are read once and reused (the page asks for one event after another, up to
     * 80 times). Everything that SENDS a notice reads the current rule every time: this copy is only used between memoise(true) and memoise(false).
     */
    private static array $saved = [];

    private static bool $memoise = false;

    public static function memoise(bool $on): void
    {
        self::$memoise = $on;
        self::$saved = [];
    }

    public static function forgetCache(): void
    {
        self::$saved = [];
    }

    private static function savedRow(string $module, int $accountId, string $eventKey): ?WorkflowNotificationRule
    {
        if (! self::$memoise) {
            return WorkflowNotificationRule::where('account_id', $accountId)->where('module', $module)->where('event_key', $eventKey)->first();
        }
        $k = $accountId . '|' . $module;
        self::$saved[$k] ??= WorkflowNotificationRule::where('account_id', $accountId)->where('module', $module)->get()->keyBy('event_key');

        return self::$saved[$k]->get($eventKey);
    }

    /** The rule of one event as it works right now (saved values over the starting values). */
    public static function effective(string $module, int $accountId, string $eventKey, ?WorkflowNotificationRule $row = null): array
    {
        $def = WorkflowModules::get($module)['events'][$eventKey] ?? null;
        abort_unless($def, 404);
        $row ??= self::savedRow($module, $accountId, $eventKey);

        $rule = ['role_names' => $def['role_names'], 'user_ids' => $def['user_ids']];
        foreach (self::FLAGS as $f) {
            $rule[$f] = (bool) ($row ? $row->{$f} : $def[$f]);
        }
        foreach (self::TEXTS as $t) {
            $rule[$t] = ($row && filled($row->{$t})) ? $row->{$t} : $def[$t];
        }
        if ($row) {
            $rule['role_names'] = $row->role_names ?? [];
            $rule['user_ids'] = $row->user_ids ?? [];
        }

        return $rule + ['saved' => (bool) $row];
    }

    /** @return array<string, array> event_key => rule fields for the page, for every event of the module */
    public static function rules(string $module, int $accountId, ?array $onlyRoleNames = null): array
    {
        $cfg = WorkflowModules::get($module);
        $rows = WorkflowNotificationRule::where('account_id', $accountId)->where('module', $module)->get()->keyBy('event_key');
        $out = [];

        foreach ($cfg['events'] as $key => $def) {
            $rule = self::effective($module, $accountId, $key, $rows->get($key));
            if ($onlyRoleNames !== null) {
                $rule['role_names'] = array_values(array_intersect($rule['role_names'], $onlyRoleNames));
            }
            $out[$key] = $rule + [
                'event_key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'icon' => $def['icon'], 'step' => $def['step'], 'permission' => $def['permission'],
                'defaults' => array_intersect_key($def, array_flip(self::TEXTS)),
            ];
        }

        return $out;
    }

    public static function save(string $module, int $accountId, array $rules): void
    {
        $cfg = WorkflowModules::get($module);
        foreach ($rules as $r) {
            $key = $r['event_key'] ?? null;
            if (! $key || ! isset($cfg['events'][$key])) {
                continue;
            }
            $def = $cfg['events'][$key];
            // a text left identical to the starting text is stored empty so a later change of the starting text still reaches it
            $keep = fn (?string $v, string $d) => (filled($v) && trim($v) !== trim($d)) ? $v : null;
            $data = ['role_names' => array_values($r['role_names'] ?? []), 'user_ids' => array_values(array_map('intval', $r['user_ids'] ?? []))];
            foreach (self::FLAGS as $f) {
                $data[$f] = (bool) ($r[$f] ?? ($f === 'is_enabled' || $f === 'email_show_details'));
            }
            foreach (self::TEXTS as $t) {
                $data[$t] = $keep($r[$t] ?? null, $def[$t]);
            }
            // withTrashed: a rule row that was soft-deleted still owns the unique (account, module, event) key, so saving must bring it back
            // instead of inserting a duplicate (which failed with a 500).
            $row = WorkflowNotificationRule::withTrashed()->firstOrNew(['account_id' => $accountId, 'module' => $module, 'event_key' => $key]);
            if ($row->trashed()) {
                $row->restore();
            }
            $row->fill($data)->save();
        }
    }
}
