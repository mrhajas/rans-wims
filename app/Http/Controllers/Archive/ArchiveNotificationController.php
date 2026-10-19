<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveNotificationRule;
use App\Services\ArchiveNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

/**
 * The schedule of the daily "expiring / expired documents" summary (time of day and how many days ahead it looks). Who is told about
 * each Archive event, on which channels and with which words is set on the Archive workflow page (route archive.workflow).
 * Saving needs the user's password again.
 */
class ArchiveNotificationController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('manage-archive-notifications'), 403);

        $rule = ArchiveNotificationRule::ruleFor($user->account_id, ArchiveNotificationRule::EXPIRY_DIGEST);

        return Inertia::render('Archive/Notifications', [
            'schedule' => [
                'send_time' => $rule['send_time'] ?: '08:00',
                'lead_days' => (int) ($rule['lead_days'] ?: 30),
                'last_run_at' => $rule['last_run_at'] ? $rule['last_run_at']->toIso8601String() : null,
                'enabled' => ArchiveNotifier::digestEnabled($user->account_id),
            ],
            'canOpenWorkflow' => $user->hasRole('Super Admin'),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('manage-archive-notifications'), 403);

        $data = $request->validate([
            'password' => ['required', 'string'],
            'send_time' => ['required', 'date_format:H:i'],
            'lead_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['errors' => ['password' => [__('The password you entered is incorrect.')]]], 422);
        }

        $rule = ArchiveNotificationRule::ruleFor($user->account_id, ArchiveNotificationRule::EXPIRY_DIGEST);
        ArchiveNotificationRule::query()->updateOrCreate(
            ['account_id' => $user->account_id, 'event_key' => ArchiveNotificationRule::EXPIRY_DIGEST],
            ['send_time' => $data['send_time'], 'lead_days' => $data['lead_days'], 'enabled' => $rule['enabled'], 'notify_bell' => $rule['notify_bell'], 'send_email' => $rule['send_email'], 'role_names' => $rule['role_names'], 'user_ids' => $rule['user_ids'], 'exclude_actor' => $rule['exclude_actor']]
        );

        ArchiveNotifier::event(ArchiveNotificationRule::SCHEDULE_CHANGED, $user, null, ['send_time' => $data['send_time'], 'days' => (string) $data['lead_days']]);
        log_activity(__('Archive expiry summary schedule updated.'), ['send_time' => $data['send_time'], 'lead_days' => $data['lead_days']], $user, 'Archive');

        return response()->json(['success' => true]);
    }
}
