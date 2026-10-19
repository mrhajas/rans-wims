<?php

namespace App\Http\Controllers;

use App\Http\Resources\SecurityReminderCampaignCollection;
use App\Models\SecurityReminderCampaign;
use App\Models\SecurityReminderRecipient;
use App\Models\User;
use App\Notifications\AppNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Lets Super Admin see every user's 2FA status at a glance and send a
 * one-time on-screen nudge (strong password + 2FA setup, never mandatory)
 * to some or all of them. "One-time" needs no dedup logic of its own — it
 * falls out of AppNotification's normal behavior: each send() call creates
 * exactly one fresh notification per targeted user, which they see once in
 * their bell dropdown until Super Admin explicitly sends another.
 */
class SecurityReminderController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-security-reminders'), 403);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('two_factor', '');

        $allActive = User::ofAccount()
            ->where('is_active', true)
            ->with('roles:id,name')
            ->withMax('securityReminderRecipients as last_reminded_at', 'notified_at')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->roles->pluck('name')->first(),
                'two_factor_enabled' => $u->hasAnyTwoFactorMethod(),
                'last_reminded_at' => $u->last_reminded_at,
            ]);

        // Stats always reflect every active user, independent of the
        // search/status filters below — the overview should never look
        // different just because someone typed into the search box.
        $stats = [
            'total' => $allActive->count(),
            'two_factor_enabled' => $allActive->where('two_factor_enabled', true)->count(),
            'two_factor_disabled' => $allActive->where('two_factor_enabled', false)->count(),
        ];

        $users = $allActive
            ->when($search !== '', fn ($c) => $c->filter(fn ($u) => str_contains(strtolower($u['name']), strtolower($search)) || str_contains(strtolower($u['email']), strtolower($search))))
            ->when($status === 'enabled', fn ($c) => $c->where('two_factor_enabled', true))
            ->when($status === 'disabled', fn ($c) => $c->where('two_factor_enabled', false))
            ->values();

        return Inertia::render('SecurityReminders/Index', [
            'users' => $users,
            'filters' => ['search' => $search, 'two_factor' => $status],
            'stats' => $stats,
        ]);
    }

    public function send(Request $request)
    {
        abort_unless($request->user()->can('send-security-reminders'), 403);

        $data = $request->validate([
            'mode' => 'required|in:all,selected',
            'user_ids' => 'required_if:mode,selected|array',
            'user_ids.*' => 'integer',
            'message' => 'nullable|string|max:1000',
        ]);

        $targets = $data['mode'] === 'all'
            ? User::ofAccount()->where('is_active', true)->get()
            : User::ofAccount()->where('is_active', true)->whereIn('id', $data['user_ids'] ?? [])->get();

        abort_if($targets->isEmpty(), 422, 'No users matched.');

        $campaign = SecurityReminderCampaign::create([
            'sent_by_user_id' => $request->user()->id,
            'message' => $data['message'] ?? null,
            'recipient_mode' => $data['mode'],
            'recipient_count' => $targets->count(),
        ]);

        $customMessage = trim((string) ($data['message'] ?? ''));

        foreach ($targets as $user) {
            SecurityReminderRecipient::create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'two_factor_enabled_at_send' => $user->hasAnyTwoFactorMethod(),
                'notified_at' => now(),
            ]);

            $user->notify(new AppNotification([
                'module' => 'security_reminders',
                'type' => 'security_setup_reminder',
                'title' => __('Strengthen Your Account Security'),
                'message' => $customMessage !== ''
                    ? $customMessage
                    : __('We recommend setting a strong password and enabling Two-Factor Authentication to keep your account secure. This only takes a minute.'),
                'url' => route('profile.show'),
                'created_at' => now()->toDateTimeString(),
            ]));
        }

        log_activity(
            'Security Reminder Sent',
            ['campaign_id' => $campaign->id, 'mode' => $campaign->recipient_mode, 'recipient_count' => $campaign->recipient_count],
            $campaign,
            'Security Reminder'
        );

        return back()->with('message', __(':count user(s) notified.', ['count' => $targets->count()]));
    }

    public function history(Request $request)
    {
        abort_unless($request->user()->can('read-security-reminders'), 403);

        $filters = $request->only(['search', 'date_from', 'date_to']);

        $campaigns = $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SecurityReminderCampaign $c) => $this->campaignRow($c));

        return Inertia::render('SecurityReminders/History', [
            'campaigns' => new SecurityReminderCampaignCollection($campaigns),
            'filters' => $filters,
        ]);
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->can('print-security-reminders'), 403);

        $data = $this->exportPayload($request);
        log_activity('Security Reminder History Printed', ['count' => count($data['campaigns'])], $request->user(), 'Security Reminder');

        return view('reports.security-reminders', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->can('print-security-reminders'), 403);

        $data = $this->exportPayload($request);
        log_activity('Security Reminder History Downloaded', ['count' => count($data['campaigns'])], $request->user(), 'Security Reminder');

        return Pdf::loadView('reports.security-reminders', $data)
            ->setPaper('a4', 'landscape')
            ->download('security-reminders-history-'.now()->format('Ymd-His').'.pdf');
    }

    protected function filteredQuery(array $filters)
    {
        return SecurityReminderCampaign::with(['sentBy:id,name', 'recipients.user:id,name'])
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('message', 'like', "%{$v}%")
                        ->orWhereHas('sentBy', fn ($s) => $s->where('name', 'like', "%{$v}%"));
                });
            })
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    protected function campaignRow(SecurityReminderCampaign $c): array
    {
        $names = $c->recipients->map(fn ($r) => optional($r->user)->name)->filter()->values();

        return [
            'id' => $c->id,
            'sent_by' => optional($c->sentBy)->name ?? __('Unknown'),
            'message' => $c->message,
            'recipient_mode' => $c->recipient_mode,
            'recipient_count' => $c->recipient_count,
            'recipient_names' => $names->take(5)->all(),
            'recipient_names_more' => max(0, $names->count() - 5),
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }

    protected function exportPayload(Request $request): array
    {
        $filters = $request->only(['search', 'date_from', 'date_to']);

        $campaigns = $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(function (SecurityReminderCampaign $c) {
                $row = $this->campaignRow($c);
                $row['recipient_names_full'] = $c->recipients->map(fn ($r) => optional($r->user)->name)->filter()->implode(', ');

                return $row;
            })
            ->values()
            ->all();

        return [
            'title' => 'Security Reminders - History',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'campaigns' => $campaigns,
        ];
    }
}
