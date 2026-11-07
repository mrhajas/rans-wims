<?php

namespace App\Http\Controllers;

use App\Mail\WimsConnectTestMail;
use App\Models\User;
use App\Models\WimsConnectLog;
use App\Services\FeatureManager;
use App\Services\WimsConnect\GatewayManager;
use App\Services\WimsConnect\TestTargets;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * WIMS Connect > Test Email / SMS: sends a short test message to ONE person, by email or by SMS, after checking that WIMS holds an address for
 * them on that channel. Nothing here changes any setting; every test is written to the Delivery Logs and the activity log.
 */
class WimsConnectTestController extends Controller
{
    public const EVENT = 'test-message-user';

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->hasRole('Super Admin') || $request->user()->can('send-wims-connect-test'), 403);
    }

    private function defaultBody(string $channel): string
    {
        return $channel === 'sms'
            ? __('WIMS test SMS. If you received this, SMS to your number works. No action is needed.')
            : __('This is a test email from WIMS. If you can read this, email to your address works. No action is needed.');
    }

    public function index(Request $request)
    {
        $this->allow($request);

        $users = User::query()->with('staff:id,user_id,mobile_no,personal_email')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username', 'email', 'extra_attributes', 'two_factor_phone'])
            ->map(function (User $u) {
                $t = TestTargets::for($u);

                return [
                    'id' => $u->id, 'name' => $u->name, 'username' => $u->username,
                    'email' => $t['email'], 'sms' => $t['sms'],
                    'label' => $u->name . ($u->username ? ' (' . $u->username . ')' : ''),
                ];
            })->values();

        return Inertia::render('WimsConnect/TestMessage', [
            'users' => $users,
            'defaults' => ['email' => $this->defaultBody('email'), 'sms' => $this->defaultBody('sms')],
            'channels' => [
                'email' => ['on' => true, 'note' => config('mail.default') === 'log' ? __('Mail is set to "log" on this server, so an email is written to the log and does not leave.') : null],
                'sms' => ['on' => FeatureManager::isEnabled('wims_connect') && FeatureManager::isEnabled('wims_connect_sms'), 'note' => FeatureManager::isEnabled('wims_connect_sms') && FeatureManager::isEnabled('wims_connect') ? null : __('SMS is switched off in Global Feature Control (WIMS Connect or its SMS Gateway).')],
            ],
            'recent' => WimsConnectLog::query()->with('recipient:id,name')->whereIn('event_key', [self::EVENT, 'wims-connect-test'])->orderByDesc('id')->limit(8)
                ->get(['id', 'recipient_user_id', 'channel', 'address', 'status', 'error_message', 'created_at'])
                ->map(fn ($l) => ['id' => $l->id, 'to' => optional($l->recipient)->name, 'channel' => $l->channel, 'address' => $l->address, 'status' => $l->status, 'error' => $l->error_message, 'at' => optional($l->created_at)->toDateTimeString()])->values(),
        ]);
    }

    public function send(Request $request, GatewayManager $gateways)
    {
        $this->allow($request);

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'channel' => 'required|in:email,sms',
            'message' => 'nullable|string|max:300',
        ]);
        $user = User::query()->with('staff:id,user_id,mobile_no,personal_email')->findOrFail($data['user_id']);
        $channel = $data['channel'];
        $target = TestTargets::for($user)[$channel];
        $body = trim((string) ($data['message'] ?? '')) ?: $this->defaultBody($channel);

        if (! $target) {
            return response()->json(['success' => false, 'message' => $channel === 'sms'
                ? __(':name has no mobile number on record. Add it on the user (Phone) or the staff profile, or use Sync with staff on the user page.', ['name' => $user->name])
                : __(':name has no email address on record. Add it on the user or the staff profile, or use Sync with staff on the user page.', ['name' => $user->name])], 422);
        }

        $by = $request->user();
        $error = null;

        if ($channel === 'sms') {
            if (! FeatureManager::isEnabled('wims_connect') || ! FeatureManager::isEnabled('wims_connect_sms')) {
                return response()->json(['success' => false, 'message' => __('SMS is switched off in Global Feature Control (WIMS Connect or its SMS Gateway).')], 422);
            }
            $result = $gateways->send('sms', $target['address'], new OutboundMessage($body));
            $error = $result->success ? null : Str::limit(strip_tags((string) $result->error), 300);
            WimsConnectLog::create([
                'idempotency_key' => 'test-user:sms:' . Str::uuid(), 'event_key' => self::EVENT, 'recipient_user_id' => $user->id, 'address' => $target['address'],
                'channel' => 'sms', 'message' => $body, 'status' => $result->success ? 'SENT' : 'FAILED', 'error_message' => $error, 'sent_at' => $result->success ? now() : null,
                'meta' => ['sent_by' => $by->id, 'address_from' => $target['source']],
            ]);
        } else {
            try {
                // the Delivery Logs row of an email is written by the mail listener (event "wims-connect-test")
                Mail::to($target['address'])->send(new WimsConnectTestMail($user->name, $body, $by->name, optional($by->account)->name));
            } catch (\Throwable $e) {
                report($e);
                $error = Str::limit(strip_tags($e->getMessage()), 300) ?: __('The email could not be sent.');
            }
        }

        log_activity($error ? 'WIMS Connect Test Message Failed' : 'WIMS Connect Test Message Sent', ['to_user' => $user->name, 'channel' => $channel, 'address' => $target['address'], 'address_from' => $target['source'], 'error' => $error], $user, 'WIMS Connect');

        if ($error) {
            return response()->json(['success' => false, 'message' => __('The test :channel to :to failed: :error', ['channel' => $channel === 'sms' ? 'SMS' : 'email', 'to' => $target['address'], 'error' => $error])], 422);
        }

        return response()->json(['success' => true, 'message' => __('Test :channel sent to :name (:to).', ['channel' => $channel === 'sms' ? 'SMS' : 'email', 'name' => $user->name, 'to' => $target['address']])]);
    }
}
