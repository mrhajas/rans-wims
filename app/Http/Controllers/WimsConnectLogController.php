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
            'meta' => $log->meta, 'error' => $log->error_message, 'retries' => $log->retry_count,
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
