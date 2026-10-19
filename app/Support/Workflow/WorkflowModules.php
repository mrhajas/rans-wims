<?php

namespace App\Support\Workflow;

/**
 * What the shared Notification page (Who Gets Notified / SMS & Email messages) needs to know about each module that uses
 * it: its steps and statuses (when it has them), its events with their starting audience and channels, and the "#tags" its messages can
 * use. One file per module in Modules/; the page, the saving code and the sender are all driven from here.
 *
 * Every event starts with the audience and channels the module used before it had a workflow page, so nothing changes until a Super
 * Admin edits the page.
 */
class WorkflowModules
{
    private static ?array $cache = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $out = [];
            foreach (glob(__DIR__ . '/Modules/*.php') as $file) {
                $cfg = require $file;
                $out[$cfg['key']] = self::finish($cfg);
            }
            self::$cache = $out;
        }

        return self::$cache;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }

    /** The event a legacy "module/event" pair belongs to, for the data migration: ['module' => ..., 'event' => ...] by any alias. */
    public static function findAlias(string $alias): ?array
    {
        foreach (self::all() as $mKey => $m) {
            foreach ($m['events'] as $eKey => $e) {
                if (in_array($alias, $e['aliases'] ?? [], true)) {
                    return ['module' => $mKey, 'event' => $eKey];
                }
            }
        }

        return null;
    }

    /** Every event that absorbs a legacy alias (one email matrix function can feed several events). @return list<array{module:string,event:string}> */
    public static function findAll(string $alias): array
    {
        $out = [];
        foreach (self::all() as $mKey => $m) {
            foreach ($m['events'] as $eKey => $e) {
                if (in_array($alias, $e['aliases'] ?? [], true)) {
                    $out[] = ['module' => $mKey, 'event' => $eKey];
                }
            }
        }

        return $out;
    }

    /** Routes for every module's workflow page: [module key => [path, route name, update route name]]. */
    public static function routes(): array
    {
        $out = [];
        foreach (self::all() as $key => $m) {
            $out[$key] = ['path' => $m['path'], 'name' => $m['page_route'], 'update' => $m['update_route']];
        }

        return $out;
    }

    private static function finish(array $cfg): array
    {
        $cfg += ['steps_model' => null, 'statuses' => [], 'visibility' => null, 'step_note' => '', 'owner_label' => 'the person who created the record', 'permission' => 'super'];
        $cfg['tags'] = array_merge(self::commonTags(), $cfg['tags'] ?? []);

        return $cfg;
    }

    /** One event: its label, what it is, and the audience / channels it starts with. */
    public static function ev(string $label, string $description, array $o = []): array
    {
        $o += [
            'roles' => [], 'users' => [], 'owner' => false, 'step' => null, 'permission' => null, 'project' => false, 'everyone' => false, 'enabled' => true,
            'bell' => true, 'sms' => false, 'email' => false, 'whatsapp' => false, 'meta' => false,
            'sms_text' => "WIMS - #title\n#message", 'subject' => '#title', 'body' => '#message', 'whatsapp_text' => "WIMS - #title\n#message", 'meta_text' => "WIMS - #title\n#message",
            'icon' => 'bell', 'aliases' => [], 'step_owner' => null,
        ];

        return [
            'label' => $label, 'description' => $description, 'role_names' => $o['roles'], 'user_ids' => $o['users'], 'notify_owner' => $o['owner'],
            'notify_step_owner' => $o['step_owner'] ?? ($o['step'] !== null || $o['permission'] !== null), 'notify_everyone' => $o['everyone'], 'limit_to_project' => $o['project'],
            'step' => $o['step'], 'permission' => $o['permission'], 'is_enabled' => $o['enabled'],
            'bell_enabled' => $o['bell'], 'sms_enabled' => $o['sms'], 'email_enabled' => $o['email'], 'whatsapp_enabled' => $o['whatsapp'], 'meta_enabled' => $o['meta'],
            'sms_template' => $o['sms_text'], 'email_subject' => $o['subject'], 'email_body' => $o['body'], 'whatsapp_template' => $o['whatsapp_text'], 'meta_template' => $o['meta_text'],
            'email_show_details' => true, 'icon' => $o['icon'], 'aliases' => $o['aliases'],
        ];
    }

    public static function tag(string $tag, string $group, string $label, string $description, string $sample): array
    {
        return ['tag' => '#' . $tag, 'group' => $group, 'label' => $label, 'description' => $description, 'sample' => $sample];
    }

    private static function commonTags(): array
    {
        return [
            self::tag('title', 'The notice', 'Title', 'The headline of the notice, as the module words it.', 'Transfer Completed'),
            self::tag('message', 'The notice', 'Message', 'The sentence the module writes for this event.', 'RST-0042 completed: Main Store to Mombasa Market.'),
            self::tag('actor', 'This action', 'Done by', 'The person who has just done this step.', 'Abdulhakeem Nassir'),
            self::tag('actiontime', 'This action', 'Done at', 'Date and time of this step.', '12 Oct 2026, 14:05'),
            self::tag('remarks', 'This action', 'Remarks', 'The remarks typed with this step (empty if none).', 'Please correct the rates'),
            self::tag('recipient', 'This action', 'Recipient name', 'The name of the person this message is addressed to.', 'Flow PM'),
        ];
    }
}
