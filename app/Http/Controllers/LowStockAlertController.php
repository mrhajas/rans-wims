<?php

namespace App\Http\Controllers;

use App\Mail\LowStockSummaryMail;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeatureManager;
use App\Services\LowStock\LowStockConfig;
use App\Services\LowStock\LowStockService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/** WIMS Notification > Low Stock Alert. */
class LowStockAlertController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->hasRole('Super Admin') || $request->user()->can('manage-low-stock-alerts'), 403);
    }

    public function index(Request $request)
    {
        $this->allow($request);
        $account = (int) $request->user()->account_id;
        $cfg = LowStockConfig::get($account);
        $digest = LowStockService::build($cfg, $account);
        $itemIds = collect($cfg['groups'])->pluck('item_ids')->flatten()->unique()->values();

        return Inertia::render('LowStockAlert/Index', [
            'config' => $cfg,
            'users' => User::query()->where('account_id', $account)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'projects' => Warehouse::query()->where('account_id', $account)->where('active', 1)->orderBy('name')->get(['id', 'name', 'status']),
            'pickedItems' => $itemIds->isNotEmpty() ? Item::query()->whereIn('id', $itemIds)->get(['id', 'name', 'code']) : [],
            'now' => ['totals' => $digest['totals'], 'top' => collect($digest['projects'])->filter(fn ($p) => $p['out'] + $p['low'] > 0)->take(5)->map(fn ($p) => ['name' => $p['name'], 'items' => $p['out'] + $p['low'], 'out' => $p['out']])->values()],
            'channels' => ['sms' => FeatureManager::isEnabled('wims_connect') && FeatureManager::isEnabled('wims_connect_sms'), 'email' => config('mail.default') !== 'log'],
            'myEmail' => $request->user()->email,
            'recipientCount' => LowStockService::recipients($cfg, $account)->count(),
        ]);
    }

    public function update(Request $request)
    {
        $this->allow($request);
        $data = $request->validate([
            'enabled' => 'boolean', 'email' => 'boolean', 'sms' => 'boolean', 'scope_by_assignment' => 'boolean', 'only_when_low' => 'boolean', 'warehouse_email' => 'boolean', 'exclude_main_warehouses' => 'boolean', 'show_all_items' => 'boolean', 'attach_pdf' => 'boolean',
            'user_ids' => 'array', 'user_ids.*' => 'integer|exists:users,id', 'role_names' => 'array', 'role_names.*' => 'string|exists:roles,name',
            'send_time' => 'required|date_format:H:i', 'send_days' => 'array', 'send_days.*' => 'integer|between:0,6',
            'groups' => 'array|max:30', 'groups.*.name' => 'required|string|max:60', 'groups.*.enabled' => 'boolean', 'groups.*.color' => 'nullable|string|max:7',
            'groups.*.keywords' => 'array', 'groups.*.exclude' => 'array', 'groups.*.item_ids' => 'array', 'groups.*.item_ids.*' => 'integer',
            'groups.*.below' => 'nullable|numeric|min:0', 'groups.*.projects.mode' => 'in:all,selected', 'groups.*.projects.ids' => 'array', 'groups.*.projects.ids.*' => 'integer',
        ]);
        LowStockConfig::save($data, (int) $request->user()->account_id);
        log_activity(__('Low Stock Alert settings saved.'), [], $request->user(), 'Settings');
        $account = (int) $request->user()->account_id;
        \App\Services\Workflow\WorkflowMessenger::dispatch('stock_alerts', 'settings_changed', \App\Models\Account::find($account), $request->user(), [
            'account_id' => $account, 'title' => __('Low Stock Alert Settings Changed'), 'message' => __('The Low Stock Alert settings were changed by :name.', ['name' => $request->user()->name]),
            'url' => route('low-stock-alert.index'), 'skip_actor' => true, 'dedupe' => 'settings|' . $request->user()->id . '|' . intdiv(time(), 20),
            'details' => [__('Changed by') => $request->user()->name, __('Switched on') => ! empty($data['enabled']) ? __('Yes') : __('No')],
        ]);

        return back()->with('message', __('Low Stock Alert settings saved.'));
    }

    /** The email exactly as it would look today, in a new tab. */
    public function preview(Request $request)
    {
        $this->allow($request);
        $account = (int) $request->user()->account_id;
        $cfg = LowStockConfig::get($account);
        $digest = LowStockService::build($cfg, $account);

        return (new LowStockSummaryMail($digest, $request->user()->name, CarbonImmutable::now(LowStockService::TZ)->format('l, d F Y · H:i').' EAT', $cfg, true))->render();
    }

    /** The alert as a PDF to download. The download is logged by the Download / Print Logs middleware (route name contains "pdf") and in the Activity Logs. */
    public function pdf(Request $request)
    {
        $this->allow($request);
        $account = (int) $request->user()->account_id;
        $cfg = LowStockConfig::get($account);
        $digest = LowStockService::build($cfg, $account);
        $now = CarbonImmutable::now(LowStockService::TZ);
        $file = 'low-stock-alert-'.$now->format('Y-m-d-His').'.pdf';

        log_activity(__('Low Stock Alert PDF downloaded.'), ['file' => $file, 'items' => $digest['totals']['items'], 'projects' => $digest['totals']['projects']], $request->user(), 'Low Stock Alert');

        return response(LowStockService::pdf($digest, $cfg, $now->format('l, d F Y · H:i').' EAT', $request->user()->name), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /** A sample email with today's real figures, sent to the address typed (default: the person pressing the button). */
    public function sendTest(Request $request)
    {
        $this->allow($request);
        $data = $request->validate(['to' => 'required|email|max:190']);
        $account = (int) $request->user()->account_id;
        $cfg = LowStockConfig::get($account);
        $digest = LowStockService::build($cfg, $account);
        try {
            $stamp = CarbonImmutable::now(LowStockService::TZ)->format('l, d F Y · H:i').' EAT';
            Mail::mailer(config('mail.test_mailer') ?: null)->to($data['to'])->send(new LowStockSummaryMail($digest, $request->user()->name, $stamp, $cfg, true, $cfg['attach_pdf'] ? LowStockService::pdf($digest, $cfg, $stamp, $request->user()->name) : null));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('The sample could not be sent: :m', ['m' => $e->getMessage()]));
        }

        return back()->with('message', __('A sample was sent to :to.', ['to' => $data['to']]));
    }

    /** Send today's alert right now to everyone chosen (does not use up today's scheduled send). */
    public function sendNow(Request $request)
    {
        $this->allow($request);
        $r = LowStockService::send((int) $request->user()->account_id, true);

        return back()->with('message', __('Sent now: :e email(s) and :s text message(s) to :n people.', ['e' => $r['emails'], 's' => $r['sms'], 'n' => $r['recipients']]));
    }
}
