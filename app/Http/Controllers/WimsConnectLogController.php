<?php

namespace App\Http\Controllers;

use App\Http\Resources\WimsConnectLogCollection;
use App\Jobs\WimsConnect\SendExternalCommunicationJob;
use App\Models\User;
use App\Models\WimsConnectLog;
use App\Services\WimsConnect\ChannelAvailability;
use App\Services\WimsConnect\OutboundLog;
use App\Support\CommunicationEventRegistry;
use App\Support\Workflow\WorkflowModules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WimsConnectLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $filters = $request->only(['channel', 'event_key', 'status', 'recipient_user_id', 'date_from', 'date_to']);

        $logs = $this->filteredQuery($filters)
            ->select(['id', 'event_key', 'source_type', 'source_id', 'recipient_user_id', 'address', 'channel', 'message', 'subject', 'status', 'error_message', 'retry_count', 'sent_at', 'delivered_at', 'created_at'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $retryableCount = $this->filteredQuery($filters)->where('channel', '!=', 'email')->whereIn('status', ['FAILED', 'RETRYING'])->count();

        return Inertia::render('WimsConnect/DeliveryLogs', [
            'logs' => new WimsConnectLogCollection($logs),
            'retryableCount' => $retryableCount,
            'filters' => $filters,
            'eventOptions' => $this->eventOptions(),
            'eventLabels' => $this->labelsFor($logs->getCollection()->pluck('event_key')->unique()->all()),
            'statuses' => WimsConnectLog::STATUSES,
            'channelLive' => ChannelAvailability::liveMap(),
            'users' => User::where('account_id', $request->user()->account_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /** Readable names for the events on the page (the Event column). */
    private function labelsFor(array $keys): array
    {
        $labels = OutboundLog::eventLabels();
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $labels[$key] ?? ucfirst(str_replace(['-', '_'], ' ', (string) $key));
        }

        return $out;
    }

    /**
     * Every event WIMS can send something for, so the filter lists all of them (not only the ones already in the log): each event of every
     * workflow page ("Module: Event"), the WIMS Connect events, and the system messages (sign-in codes, password resets, bulk messages).
     * A workflow event is filtered by "module.event", which matches the different spellings the log rows use.
     */
    private function eventOptions(): array
    {
        $out = [];
        $covered = [];
        foreach (WorkflowModules::all() as $mKey => $m) {
            $group = trim(preg_replace('/\s*(Workflow|Notification)$/', '', (string) $m['title']));
            foreach ($m['events'] as $eKey => $e) {
                $out[] = ['value' => $mKey . '.' . $eKey, 'label' => $group . ': ' . $e['label'], 'icon' => $e['icon'] ?? 'bell'];
                foreach ($this->eventVariants($mKey . '.' . $eKey) as $v) {
                    $covered[$v] = true;
                }
            }
        }
        // the WIMS Connect events that are not workflow events (workflow events are already listed above, once)
        foreach (CommunicationEventRegistry::events() as $k => $e) {
            if (! isset($covered[$k])) {
                $out[] = ['value' => $k, 'label' => $e['label'] ?? $k, 'icon' => $e['icon'] ?? null];
            }
        }
        foreach (['two_factor_otp' => __('Sign-in code'), 'reset-password' => __('Password reset'), 'bulk-connect' => __('Bulk Connect message'), 'warehouse-activity' => __('Warehouse activity email'), 'email' => __('Other email')] as $k => $label) {
            $out[] = ['value' => $k, 'label' => __('System') . ': ' . $label, 'icon' => 'cog'];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /** The values a filter on one event stands for (a workflow event is written several ways in the log). */
    private function eventVariants(string $value): array
    {
        if (! str_contains($value, '.')) {
            return [$value];
        }
        [$m, $e] = explode('.', $value, 2);

        return array_values(array_unique([
            $m . '-' . str_replace('_', '-', $e), $m . '_' . $e, str_replace('_', '-', $m) . '-' . str_replace('_', '-', $e), str_replace('_', '-', $m) . '_' . $e,
        ]));
    }

    /** The whole message of one log row: the text of an SMS / WhatsApp / Meta message, or the subject, details and body of an email. */
    public function show(Request $request, WimsConnectLog $log)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        $log->load('recipient:id,name');
        $labels = OutboundLog::eventLabels();

        return response()->json([
            'id' => $log->id, 'channel' => $log->channel, 'status' => $log->status, 'event' => $labels[$log->event_key] ?? ucfirst(str_replace(['-', '_'], ' ', (string) $log->event_key)),
            'recipient' => $log->recipient?->name, 'address' => $log->address, 'subject' => $log->subject, 'message' => $log->message, 'body_html' => $log->body_html,
            'meta' => $log->meta, 'resendable' => ! self::resendBlocked($log), 'error' => $log->error_message, 'retries' => $log->retry_count,
            'created_at' => optional($log->created_at)->toIso8601String(), 'sent_at' => optional($log->sent_at)->toIso8601String(), 'delivered_at' => optional($log->delivered_at)->toIso8601String(),
        ]);
    }

    public function retry(Request $request, WimsConnectLog $log)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        if ($log->channel === 'email') {
            return back()->with('error', __('Email reports are not re-sent from here.'));
        }

        if (! in_array($log->status, ['FAILED', 'RETRYING'], true)) {
            return back()->with('error', __('Only failed or retrying messages can be retried.'));
        }

        $log->update(['status' => 'QUEUED', 'error_message' => null]);

        SendExternalCommunicationJob::dispatch($log->id);

        log_activity('WIMS Connect Message Retried', ['log_id' => $log->id, 'channel' => $log->channel], $log, 'WIMS Connect');

        return back()->with('message', __('Message re-queued for delivery.'));
    }

    /**
     * Sends the message of a log row again, by its own channel, and answers with what happened (the modal shows it):
     *  - SMS / WhatsApp / Meta: a new row with the same text and number goes through the gateway (the old row is kept as it was);
     *  - email: the same subject and body are mailed to the same address (the files of the first email are not kept, so they are not attached);
     *  - app alert: the same alert is put in the user's bell again.
     * Codes and reset links (hidden content) are never sent again.
     */
    public function resend(Request $request, WimsConnectLog $log)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $meta = (array) $log->meta;
        if (self::resendBlocked($log)) {
            return $this->resendAnswer(false, __('This message holds a code or a secret link, so it is never sent again.'), 422);
        }

        try {
            switch ($log->channel) {
                case 'email':
                    return $this->resendEmail($log, $meta);
                case 'app':
                    return $this->resendApp($log, $meta);
                case 'sms':
                case 'whatsapp':
                case 'meta':
                    return $this->resendGateway($log);
            }
        } catch (\Throwable $e) {
            report($e);

            return $this->resendAnswer(false, __('Could not send it again: :e', ['e' => \Illuminate\Support\Str::limit(strip_tags($e->getMessage()), 200)]), 500);
        }

        return $this->resendAnswer(false, __('This channel cannot be sent again.'), 422);
    }

    /** Sign-in codes, password reset links and gateway test messages are never sent again (their rows carry no code, or are not real messages). */
    public static function resendBlocked(WimsConnectLog $log): bool
    {
        return ! empty(((array) $log->meta)['sensitive']) || in_array($log->event_key, ['two_factor_otp', 'two-factor-otp', 'reset-password', 'test-message'], true);
    }

    private function resendAnswer(bool $ok, string $message, int $status = 200, array $extra = [])
    {
        return response()->json(['ok' => $ok, 'message' => $message] + $extra, $ok ? 200 : $status);
    }

    private function resendGateway(WimsConnectLog $log)
    {
        if (trim((string) $log->message) === '' || trim((string) $log->address) === '') {
            return $this->resendAnswer(false, __('This message has no text or no number, so it cannot be sent again.'), 422);
        }
        if (empty(ChannelAvailability::liveMap()[$log->channel])) {
            return $this->resendAnswer(false, __(':c is not live (switch it on and connect its gateway in WIMS Connect first).', ['c' => strtoupper($log->channel)]), 422);
        }

        $new = WimsConnectLog::create([
            'idempotency_key' => 'resend:' . \Illuminate\Support\Str::uuid(), 'event_key' => $log->event_key, 'source_type' => $log->source_type, 'source_id' => $log->source_id,
            'recipient_user_id' => $log->recipient_user_id, 'address' => $log->address, 'channel' => $log->channel, 'message' => $log->message,
            'status' => 'QUEUED', 'retry_count' => 0, 'meta' => ['resent_from' => $log->id],
        ]);
        SendExternalCommunicationJob::dispatch($new->id);
        $new->refresh();
        log_activity('WIMS Connect Message Sent Again', ['log_id' => $log->id, 'new_log_id' => $new->id, 'channel' => $log->channel], $log, 'WIMS Connect');

        if (in_array($new->status, ['FAILED'], true)) {
            return $this->resendAnswer(false, __('The gateway refused it: :e', ['e' => $new->error_message ?: __('unknown error')]), 422, ['new_id' => $new->id, 'status' => $new->status]);
        }

        return $this->resendAnswer(true, in_array($new->status, ['SENT', 'DELIVERED'], true) ? __('Sent again to :a.', ['a' => $log->address]) : __('Queued to be sent again to :a.', ['a' => $log->address]), 200, ['new_id' => $new->id, 'status' => $new->status]);
    }

    private function resendEmail(WimsConnectLog $log, array $meta)
    {
        $address = trim((string) $log->address);
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $this->resendAnswer(false, __('This email has no valid address.'), 422);
        }
        $html = trim((string) $log->body_html);
        $text = trim((string) $log->message);
        if ($html === '' && $text === '') {
            return $this->resendAnswer(false, __('This email has no saved content, so it cannot be sent again.'), 422);
        }
        $html = $html !== '' ? $html : '<div style="font-family:Arial,sans-serif;font-size:14px;white-space:pre-wrap">' . e($text) . '</div>';
        $subject = trim((string) $log->subject) ?: __('(no subject)');
        $before = (int) WimsConnectLog::max('id');

        try {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($address, $subject, $log) {
                $m->to($address)->subject($subject);
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader(OutboundLog::EVENT_HEADER, (string) $log->event_key);
                $h->addTextHeader('X-WIMS-Resent-From', (string) $log->id);
            });
        } catch (\Throwable $e) {
            OutboundLog::mailFailed($e);

            return $this->resendAnswer(false, __('The mail server refused it: :e', ['e' => \Illuminate\Support\Str::limit(strip_tags($e->getMessage()), 200)]), 422);
        }

        $new = WimsConnectLog::where('id', '>', $before)->where('channel', 'email')->whereRaw('LOWER(address) = ?', [strtolower($address)])->orderByDesc('id')->first();
        log_activity('WIMS Connect Message Sent Again', ['log_id' => $log->id, 'new_log_id' => optional($new)->id, 'channel' => 'email'], $log, 'WIMS Connect');
        $note = ! empty($meta['attachments']) ? ' ' . __('The files of the first email (:n) are not attached again.', ['n' => count((array) $meta['attachments'])]) : '';

        return $this->resendAnswer(true, __('Email sent again to :a.', ['a' => $address]) . $note, 200, ['new_id' => optional($new)->id, 'status' => 'SENT']);
    }

    private function resendApp(WimsConnectLog $log, array $meta)
    {
        $user = $log->recipient_user_id ? User::find($log->recipient_user_id) : null;
        if (! $user || ! $user->is_active) {
            return $this->resendAnswer(false, __('The person this alert was for is not an active user.'), 422);
        }
        $payload = (array) ($meta['payload'] ?? []);
        if (! $payload) {
            $payload = ['title' => (string) $log->subject, 'message' => (string) $log->message, 'type' => (string) $log->event_key];
        }
        unset($payload['send_email'], $payload['email_only']); // only the bell: an email has its own row and its own button
        $before = (int) WimsConnectLog::max('id');
        $user->notify(new \App\Notifications\AppNotification($payload));
        $new = WimsConnectLog::where('id', '>', $before)->where('channel', 'app')->where('recipient_user_id', $user->id)->orderByDesc('id')->first();
        log_activity('WIMS Connect Message Sent Again', ['log_id' => $log->id, 'new_log_id' => optional($new)->id, 'channel' => 'app'], $log, 'WIMS Connect');

        return $this->resendAnswer(true, $new ? __('Alert sent again to :n.', ['n' => $user->name]) : __('Alert sent to :n (it is delivered in a moment and then shows in this log).', ['n' => $user->name]), 200, ['new_id' => optional($new)->id, 'status' => 'SENT']);
    }

    public function retryAll(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $filters = $request->only(['channel', 'event_key', 'status', 'recipient_user_id', 'date_from', 'date_to']);

        $logs = $this->filteredQuery($filters)->where('channel', '!=', 'email')->whereIn('status', ['FAILED', 'RETRYING'])->get(['id']);

        $requeued = 0;
        foreach ($logs as $log) {
            try {
                WimsConnectLog::whereKey($log->id)->update(['status' => 'QUEUED', 'error_message' => null]);
                SendExternalCommunicationJob::dispatch($log->id);
                $requeued++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        log_activity('WIMS Connect Messages Bulk Retried', ['count' => $requeued, 'filters' => $filters], $request->user(), 'WIMS Connect');

        return back()->with('message', $requeued > 0
            ? __(':count message(s) re-queued for delivery.', ['count' => $requeued])
            : __('No failed or retrying messages to retry.'));
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $data = $this->exportPayload($request);
        log_activity('WIMS Connect Delivery Logs Printed', ['count' => count($data['logs'])], $request->user(), 'WIMS Connect');

        return view('reports.wims-connect-logs', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $data = $this->exportPayload($request);
        log_activity('WIMS Connect Delivery Logs Downloaded', ['count' => count($data['logs'])], $request->user(), 'WIMS Connect');

        return Pdf::loadView('reports.wims-connect-logs', $data)
            ->setPaper('a4', 'landscape')
            ->download('wims-connect-delivery-logs-'.now()->format('Ymd-His').'.pdf');
    }

    protected function filteredQuery(array $filters)
    {
        return WimsConnectLog::with('recipient:id,name')
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['event_key'] ?? null, fn ($q, $v) => $q->whereIn('event_key', $this->eventVariants((string) $v)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['recipient_user_id'] ?? null, fn ($q, $v) => $q->where('recipient_user_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    protected function exportPayload(Request $request): array
    {
        $filters = $request->only(['channel', 'event_key', 'status', 'recipient_user_id', 'date_from', 'date_to']);
        $events = CommunicationEventRegistry::events();

        $logs = $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn ($log) => [
                'event_label' => $events[$log->event_key]['label'] ?? $log->event_key,
                'recipient' => $log->recipient?->name,
                'address' => $log->address,
                'channel' => $log->channel,
                'status' => $log->status,
                'sent_at' => $log->sent_at?->format('Y-m-d H:i'),
                'retry_count' => $log->retry_count,
            ])
            ->values()
            ->all();

        return [
            'title' => 'WIMS Connect - Delivery Logs',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'logs' => $logs,
        ];
    }
}
