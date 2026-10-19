<?php

namespace App\Http\Controllers;

use App\Http\Resources\BulkSmsRecipientCollection;
use App\Models\BulkSmsMessage;
use App\Models\BulkSmsRecipient;
use App\Models\BulkSmsRecipientBookEntry;
use App\Models\Contact;
use App\Models\User;
use App\Models\WimsConnectRecipientChannel;
use App\Services\WimsConnect\BulkSmsSender;
use App\Services\WimsConnect\ChannelAvailability;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * Ad-hoc bulk outbound messaging over WhatsApp, Meta, or SMS: compose one
 * message, send it to any number of typed-in or Contact-imported
 * recipients, optionally scheduled. Deliberately bypasses
 * CommunicationService/RecipientResolver — that pipeline is keyed on WIMS
 * domain events and registered WimsConnectRecipientChannel rows, neither of
 * which fits a raw ad-hoc phone number. Sends go straight through
 * GatewayManager, same as WimsConnectGatewayController::test().
 */
class WimsConnectBulkConnectController extends Controller
{
    /**
     * Authorizable::callAction() auto-derives a legacy ability from the
     * route/method name (e.g. index -> "read-wims-connect", store ->
     * "create-wims-connect" — routeName[0] is just "wims-connect" for every
     * WimsConnect route) and checks it *before* any code in this class runs.
     * That's harmless for the other WimsConnect controllers since they also
     * hard-gate to Super Admin only, but it would silently block a
     * non-Super-Admin role holding the real wims-connect-bulk-connect
     * permissions below with an unrelated legacy check. Clearing the map
     * disables that auto-check so these explicit permission checks are the
     * only gate.
     */
    // WhatsApp/Meta (the Business Cloud API) accept long messages; SMS
    // does not — kept as a hard per-channel cap, not just the soft
    // multi-segment warning Compose.vue also shows for SMS.
    protected const CHANNEL_MAX_LENGTH = ['whatsapp' => 5000, 'meta' => 5000, 'sms' => 150];

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        return Inertia::render('WimsConnect/BulkConnect/Compose', [
            'channelLive' => ChannelAvailability::liveMap(),
            'channelMaxLength' => self::CHANNEL_MAX_LENGTH,
            'contactCategories' => Contact::categoryOptions(),
        ]);
    }

    public function suggestRecipients(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $suggestions = BulkSmsRecipientBookEntry::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderByDesc('last_used_at')
            ->limit(10)
            ->get(['name', 'phone']);

        return response()->json($suggestions);
    }

    public function importContacts(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        // Contacts with no phone number are still returned (not filtered
        // out) — Compose.vue lists them with a "No mobile number" badge and
        // an un-checkable row, rather than silently hiding them from a
        // search that would otherwise look like it matched nothing.
        $contacts = Contact::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'phone', 'category']);

        return response()->json($contacts);
    }

    /**
     * WIMS users have no phone column of their own — a user's number for a
     * given channel (WhatsApp/Meta/SMS can each carry a different address)
     * lives in WimsConnectRecipientChannel, one row per user per channel.
     * Users with no enabled row for the currently selected channel are
     * still returned (phone: null) so Compose.vue can show them with a
     * "No mobile number" badge instead of just vanishing from the list.
     */
    public function importUsers(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        $q = trim((string) $request->query('q', ''));
        $channel = $request->query('channel', 'whatsapp');

        $users = User::query()
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email']);

        $addresses = WimsConnectRecipientChannel::whereIn('user_id', $users->pluck('id'))
            ->where('channel', $channel)
            ->where('is_enabled', true)
            ->pluck('address', 'user_id');

        return response()->json($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $addresses[$u->id] ?? null,
        ])->values());
    }

    public function downloadExcelTemplate(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        $rows = collect([
            ['name' => 'John Doe', 'phone' => '+255674020223'],
            ['name' => 'Jane Smith', 'phone' => '0674020224'],
        ]);

        return (new FastExcel($rows))->download('bulk-connect-recipients-template.xlsx');
    }

    public function importExcel(Request $request)
    {
        abort_unless($request->user()->can('send-wims-connect-bulk-connect'), 403);

        $request->validate(['excel' => 'required|file|mimes:xls,xlsx']);

        $path = $request->file('excel')->store('imports');

        $recipients = [];
        $skipped = 0;

        try {
            (new FastExcel())->import(Storage::path($path), function ($line) use (&$recipients, &$skipped) {
                $name = trim((string) ($line['name'] ?? ''));
                $phone = trim((string) ($line['phone'] ?? ''));

                if ($name === '' || $phone === '') {
                    $skipped++;

                    return null;
                }

                $recipients[] = ['name' => $name, 'phone' => $phone];
            });
        } catch (\Throwable $e) {
            Storage::delete($path);

            return response()->json(['message' => __('Could not read that Excel file. Make sure it matches the template format (name, phone columns).')], 422);
        }

        Storage::delete($path);

        return response()->json(['recipients' => $recipients, 'skipped' => $skipped]);
    }

    public function store(Request $request, BulkSmsSender $sender)
    {
        abort_unless($request->user()->can('send-wims-connect-bulk-connect'), 403);

        $maxLength = self::CHANNEL_MAX_LENGTH[$request->input('channel')] ?? 1600;

        $data = $request->validate([
            'channel' => ['required', Rule::in(ChannelAvailability::CHANNELS)],
            'body' => ['required', 'string', "max:{$maxLength}"],
            'recipients' => 'required|array|min:1',
            'recipients.*.name' => 'required|string|max:120',
            'recipients.*.phone' => 'required|string|max:32',
            'scheduled_at' => 'nullable|date',
        ]);

        // The frontend already disables non-live channels, but a stale page
        // (or a direct API call) could still submit one — never silently
        // accept a send that can't actually go anywhere.
        if (! (ChannelAvailability::liveMap()[$data['channel']] ?? false)) {
            return back()->with('error', __('That channel is not currently live. Refresh the page and pick a live channel.'));
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        $isScheduled = $scheduledAt && now()->lt($scheduledAt);

        $message = BulkSmsMessage::create([
            'channel' => $data['channel'],
            'body' => $data['body'],
            'status' => $isScheduled ? 'Scheduled' : 'Sending',
            'scheduled_at' => $isScheduled ? $scheduledAt : null,
            'sent_by_user_id' => $request->user()->id,
            'total_recipients' => count($data['recipients']),
        ]);

        foreach ($data['recipients'] as $recipient) {
            BulkSmsRecipient::create([
                'bulk_sms_message_id' => $message->id,
                'name' => trim((string) ($recipient['name'] ?? '')) ?: null,
                'phone' => trim($recipient['phone']),
                'status' => 'Pending',
            ]);
            BulkSmsRecipientBookEntry::remember(trim((string) ($recipient['name'] ?? '')), trim($recipient['phone']));
        }

        if ($isScheduled) {
            log_activity('WIMS Connect Bulk Connect Scheduled', ['message_id' => $message->id, 'channel' => $message->channel, 'recipients' => $message->total_recipients, 'scheduled_at' => (string) $scheduledAt], $message, 'WIMS Connect');

            return back()->with('message', __('Message scheduled for :count recipient(s).', ['count' => $message->total_recipients]));
        }

        $sender->dispatchNow($message);

        log_activity('WIMS Connect Bulk Connect Sent', ['message_id' => $message->id, 'channel' => $message->channel, 'recipients' => $message->total_recipients, 'sent' => $message->sent_count, 'failed' => $message->failed_count], $message, 'WIMS Connect');

        return back()->with('message', __(':sent sent, :failed failed, out of :total.', ['sent' => $message->sent_count, 'failed' => $message->failed_count, 'total' => $message->total_recipients]));
    }

    public function history(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect-bulk-connect'), 403);

        $filters = $request->only(['channel', 'status', 'search', 'date_from', 'date_to']);

        $recipients = $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('WimsConnect/BulkConnect/History', [
            'recipients' => new BulkSmsRecipientCollection($recipients),
            'filters' => $filters,
            'statuses' => BulkSmsRecipient::STATUSES,
            'channels' => ChannelAvailability::CHANNELS,
            'channelLive' => ChannelAvailability::liveMap(),
        ]);
    }

    public function retry(Request $request, BulkSmsRecipient $recipient, BulkSmsSender $sender)
    {
        abort_unless($request->user()->can('send-wims-connect-bulk-connect'), 403);

        if ($recipient->status !== 'Failed') {
            return back()->with('error', __('Only failed messages can be retried.'));
        }

        $result = $sender->sendOne($recipient);

        log_activity(
            $result->success ? 'WIMS Connect Bulk Connect Retried' : 'WIMS Connect Bulk Connect Retry Failed',
            ['recipient_id' => $recipient->id, 'error' => $result->error],
            $recipient,
            'WIMS Connect'
        );

        if ($result->success) {
            return back()->with('message', __('Message re-sent.'));
        }

        return back()->with('error', __('Retry failed: :error', ['error' => $result->error ?? __('Unknown error')]));
    }

    public function print(Request $request)
    {
        abort_unless($request->user()->can('print-wims-connect-bulk-connect'), 403);

        $data = $this->exportPayload($request);
        log_activity('WIMS Connect Bulk Connect History Printed', ['count' => count($data['recipients'])], $request->user(), 'WIMS Connect');

        return view('reports.wims-connect-bulk-connect', $data + ['autoPrint' => true]);
    }

    public function download(Request $request)
    {
        abort_unless($request->user()->can('print-wims-connect-bulk-connect'), 403);

        $data = $this->exportPayload($request);
        log_activity('WIMS Connect Bulk Connect History Downloaded', ['count' => count($data['recipients'])], $request->user(), 'WIMS Connect');

        return Pdf::loadView('reports.wims-connect-bulk-connect', $data)
            ->setPaper('a4', 'landscape')
            ->download('wims-connect-bulk-connect-history-'.now()->format('Ymd-His').'.pdf');
    }

    protected function filteredQuery(array $filters)
    {
        return BulkSmsRecipient::with('message')
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->whereHas('message', fn ($m) => $m->where('channel', $v)))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('name', 'like', "%{$v}%")
                        ->orWhere('phone', 'like', "%{$v}%")
                        ->orWhereHas('message', fn ($m) => $m->where('body', 'like', "%{$v}%"));
                });
            })
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    protected function exportPayload(Request $request): array
    {
        $filters = $request->only(['channel', 'status', 'search', 'date_from', 'date_to']);

        $recipients = $this->filteredQuery($filters)
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'phone' => $r->phone,
                'channel' => $r->message?->channel,
                'message' => $r->message?->body,
                'status' => $r->status,
                'sent_at' => $r->sent_at?->format('Y-m-d H:i'),
                'error_message' => $r->error_message,
            ])
            ->values()
            ->all();

        return [
            'title' => 'WIMS Connect - Bulk Connect History',
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'recipients' => $recipients,
        ];
    }
}
