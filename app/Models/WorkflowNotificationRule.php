<?php

namespace App\Models;

class WorkflowNotificationRule extends Model
{
    public static $hasAccount = false;

    protected $fillable = [
        'account_id', 'module', 'event_key', 'is_enabled', 'role_names', 'user_ids', 'notify_owner', 'notify_step_owner', 'notify_everyone', 'limit_to_project',
        'bell_enabled', 'sms_enabled', 'email_enabled', 'whatsapp_enabled', 'meta_enabled',
        'sms_template', 'email_subject', 'email_body', 'whatsapp_template', 'meta_template', 'email_show_details',
    ];

    public $casts = [
        'role_names' => 'array', 'user_ids' => 'array', 'is_enabled' => 'boolean', 'notify_owner' => 'boolean', 'notify_step_owner' => 'boolean', 'notify_everyone' => 'boolean',
        'limit_to_project' => 'boolean', 'bell_enabled' => 'boolean', 'sms_enabled' => 'boolean', 'email_enabled' => 'boolean', 'whatsapp_enabled' => 'boolean',
        'meta_enabled' => 'boolean', 'email_show_details' => 'boolean',
    ];

    /** Any change to a rule empties the per-request copy kept by WorkflowSettings, so nothing stale is ever read. */
    protected static function booted()
    {
        parent::booted();
        $forget = fn () => \App\Services\Workflow\WorkflowSettings::forgetCache();
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
        // a rolled-back transaction can undo rules that were read inside it
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, $forget);
    }
}
