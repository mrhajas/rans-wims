<?php

namespace App\Http\Controllers;

use App\Models\ExecutiveReportLog;
use App\Models\ExecutiveReportSubscriber;
use App\Models\Item;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ExecutiveReportBuilder;
use App\Services\ExecutiveReportConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

class ExecutiveReportController extends Controller
{
    const LOGS_PER_PAGE = 100;

    /**
     * Authorizable::callAction() would auto-derive read-/create-/update-/
     * delete-executive-report (unregistered — only the lumped manage-
     * executive-reports exists), 403ing anyone granted manage-executive-
     * reports without Super Admin's Gate::before bypass before this class's
     * own hasPermissionTo() check below ever runs.
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        $subscribers = ExecutiveReportSubscriber::ofAccount()
            ->with('user:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'active', 'user_id', 'created_at']);

        $logs = ExecutiveReportLog::where('account_id', $request->user()->account_id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::LOGS_PER_PAGE)
            ->get(['id', 'week_start', 'week_end', 'recipient_count', 'status', 'notes', 'sent_at']);

        $cfg = ExecutiveReportConfig::forAccount($request->user()->account_id);
        $already = $subscribers->pluck('user_id')->filter()->all();

        return Inertia::render('ExecutiveReport/Index', [
            'contentConfig' => $cfg->toArray(),
            'catalogue' => collect(ExecutiveReportConfig::GROUPS)->map(fn ($items, $group) => ['group' => $group, 'items' => collect($items)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'hint' => $v[1]])->values()])->values(),
            'transactionTypes' => collect(ExecutiveReportConfig::TRANSACTIONS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'projectOptions' => Warehouse::query()->where('account_id', $request->user()->account_id)->where('active', 1)->visible()->orderBy('name')->get(['id', 'name', 'status']),
            'selectedItems' => $cfg->itemIds() ? Item::query()->whereIn('id', $cfg->itemIds())->get(['id', 'name', 'code']) : [],
            'userOptions' => User::query()->where('is_active', true)->whereNotNull('email')->where('email', '<>', '')->orderBy('name')->get(['id', 'name', 'email'])->map(fn ($u) => ['id' => $u->id, 'label' => $u->name.' ('.$u->email.')', 'name' => $u->name, 'email' => $u->email, 'subscribed' => in_array($u->id, $already, true)]),
            'subscribers' => $subscribers,
            'logs' => $logs,
            'reportSettings' => [
                'day' => get_settings('executive_report_day') ?: 'monday',
                'time' => get_settings('executive_report_time') ?: '07:00',
                'enabled' => (bool) get_settings('executive_report_enabled'),
            ],
        ]);
    }

    /** Executive Report workflow page: which event happened; the page decides who is told. */
    private function alert(string $event, string $title, string $message, array $values = []): void
    {
        $user = auth()->user();
        \App\Services\Workflow\WorkflowMessenger::dispatch('executive_reports', $event, \App\Models\Account::find($user->account_id), $user, [
            'account_id' => $user->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('executive-report.index'),
            'dedupe' => 'executive_reports|' . $event . '|' . ($values['subscriber'] ?? '') . '|' . intdiv(time(), 20), 'values' => $values,
        ]);
    }

    public function updateSettings(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        $data = $request->validate([
            'day' => 'required|in:sunday,monday,tuesday,wednesday,thursday,friday,saturday',
            'time' => 'required|date_format:H:i',
            'enabled' => 'required|boolean',
        ]);

        foreach (['executive_report_day' => $data['day'], 'executive_report_time' => $data['time'], 'executive_report_enabled' => $data['enabled'] ? '1' : '0'] as $key => $value) {
            Setting::updateOrCreate(['tec_key' => $key, 'account_id' => $request->user()->account_id], ['tec_value' => $value]);
        }

        $this->alert('settings_changed', __('Report Schedule Changed'), 'The weekly Executive Report is now ' . ($data['enabled'] ? 'on, every ' . ucfirst($data['day']) . ' at ' . $data['time'] : 'switched off') . '. Changed by ' . $request->user()->name . '.');

        return back()->with('message', 'Executive Report settings updated.');
    }

    /** Report Content: which sections, transactions, projects and items the weekly report covers. */
    public function updateContent(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        $data = $request->validate([
            'sections' => 'array', 'sections.*' => 'boolean',
            'transactions' => 'array', 'transactions.*' => 'in:'.implode(',', array_keys(ExecutiveReportConfig::TRANSACTIONS)),
            'projects.mode' => 'required|in:all,selected', 'projects.ids' => 'array', 'projects.ids.*' => 'integer',
            'items.mode' => 'required|in:all,selected', 'items.ids' => 'array', 'items.ids.*' => 'integer',
            'limits' => 'array', 'limits.*' => 'integer|between:1,50',
        ]);
        ExecutiveReportConfig::save($data, (int) $request->user()->account_id);

        $this->alert('content_changed', __('Report Content Changed'), 'What the weekly Executive Report covers was changed by ' . $request->user()->name . '.');

        return back()->with('message', 'Report content saved.');
    }

    /** Builds this week's report exactly as it would be sent, and opens the PDF, without emailing anyone. */
    public function preview(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        @set_time_limit(180);
        $weekEnd = now()->subDay()->endOfDay();
        $weekStart = $weekEnd->copy()->subDays(6)->startOfDay();
        $built = app(ExecutiveReportBuilder::class)->generate((int) $request->user()->account_id, $weekStart, $weekEnd);

        return Pdf::loadView('executive-report-print', [
            'metrics' => $built['metrics'], 'insights' => $built['insights'], 'summary' => $built['summary'], 'cfg' => $built['cfg'],
            'companyName' => $built['companyName'], 'generatedAt' => now()->format('Y-m-d H:i'), 'logoPath' => $this->logo(),
        ])->stream('executive-intelligence-report-preview.pdf');
    }

    private function logo(): ?string
    {
        $logo = public_path('images/logo.png');

        return file_exists($logo) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : null;
    }

    public function sendTest(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        Artisan::call('executive-report:send', ['--force' => true, '--account' => $request->user()->account_id]);

        return back()->with('message', 'Test Executive Intelligence Report sent to all active subscribers.');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        // Subscribers are WIMS users: their name and email come from their account (and follow it if the email changes).
        $data = $request->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer|exists:users,id']);
        $accountId = $request->user()->account_id;
        $added = 0;
        $names = [];
        foreach (User::query()->whereIn('id', $data['user_ids'])->get() as $user) {
            if (blank($user->email) || ExecutiveReportSubscriber::ofAccount($accountId)->where('user_id', $user->id)->exists()) {
                continue;
            }
            ExecutiveReportSubscriber::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => true, 'account_id' => $accountId]);
            $added++;
            $names[] = $user->name;
        }
        if ($added) {
            $this->alert('subscriber_added', __('Report Subscriber Added'), implode(', ', $names) . ($added === 1 ? ' was' : ' were') . ' added to the weekly Executive Report by ' . $request->user()->name . '.', ['subscriber' => implode(', ', $names)]);
        }

        return back()->with('message', $added === 1 ? 'Subscriber added.' : $added.' subscribers added.');
    }

    public function update(Request $request, ExecutiveReportSubscriber $executiveReportSubscriber)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        $data = $request->validate(['active' => 'required|boolean']);

        $executiveReportSubscriber->update($data);
        $this->alert('subscriber_changed', __('Report Subscriber Switched'), $executiveReportSubscriber->name . ' was switched ' . ($executiveReportSubscriber->active ? 'on' : 'off') . ' for the weekly Executive Report by ' . $request->user()->name . '.', ['subscriber' => (string) $executiveReportSubscriber->name]);

        return back()->with('message', 'Subscriber updated.');
    }

    public function destroy(Request $request, ExecutiveReportSubscriber $executiveReportSubscriber)
    {
        abort_unless($request->user()->hasPermissionTo('manage-executive-reports'), 403);

        $executiveReportSubscriber->delete();
        $this->alert('subscriber_removed', __('Report Subscriber Removed'), $executiveReportSubscriber->name . ' was removed from the weekly Executive Report by ' . $request->user()->name . '.', ['subscriber' => (string) $executiveReportSubscriber->name]);

        return back()->with('message', 'Subscriber removed.');
    }
}
