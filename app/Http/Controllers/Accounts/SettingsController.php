<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Accounts\AcctAccount;
use App\Models\Accounts\AcctApprovalRule;
use App\Models\Accounts\AcctCategory;
use App\Models\Accounts\AcctCurrency;
use App\Services\Accounts\AcctAudit;
use App\Services\Accounts\AcctSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Settings, approval rules and categories. Every limit is a setting, not a number in code. */
class SettingsController extends AcctController
{
    public function index()
    {
        $this->need('manage-acct-settings', 'manage-acct-approval-rules');
        $me = $this->me();

        return Inertia::render('Accounts/Settings', $this->shared() + [
            'settings' => AcctSettings::get(), 'rules' => AcctApprovalRule::query()->orderBy('applies_to')->orderBy('sort_order')->orderBy('min_amount')->get(), 'accounts' => AcctAccount::query()->orderBy('name')->get(['id', 'name', 'currency'])->map(fn ($a) => ['value' => $a->id, 'label' => $a->name . ' (' . $a->currency . ')']),
            'categories' => AcctCategory::query()->orderBy('sort_order')->orderBy('name')->get(), 'currencyCodes' => AcctCurrency::query()->where('is_active', true)->pluck('code'),
            'can' => ['settings' => $me->can('manage-acct-settings'), 'rules' => $me->can('manage-acct-approval-rules'), 'controls' => $me->can('manage-acct-settings') && $me->hasRole('Super Admin')],
        ]);
    }

    /** The settings that decide who can approve what and which dates are locked. They are changed only by a Super Admin, with the password asked again (see controls()). */
    public const CONTROLS = ['maker_checker', 'super_admin_self_approval', 'closed_through'];

    public function save(Request $request)
    {
        $this->need('manage-acct-settings');
        $d = $request->validate([
            'date_rule' => 'required|in:txn_date,value_date', 'pending_alert_hours' => 'required|integer|min:1|max:2000', 'transfer_pending_hours' => 'required|integer|min:1|max:2000',
            'unusual_factor' => 'required|numeric|min:1.5|max:1000', 'alert_cooldown_hours' => 'required|integer|min:1|max:720', 'rate_tolerance_pct' => 'required|numeric|min:0|max:50', 'allow_inverse_rates' => 'required|boolean', 'recon_overdue_days' => 'required|integer|min:1|max:365',
            'large_txn' => 'nullable|array', 'large_txn.*' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,4})?$/'], 'attachment_required_over' => 'nullable|array', 'attachment_required_over.*' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,4})?$/'], 'discrepancy_dual_over' => 'nullable|array', 'discrepancy_dual_over.*' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'denominations' => 'nullable|array', 'denominations.*' => 'nullable|array', 'denominations.*.*' => 'numeric|min:0.01',
            'maker_checker' => 'nullable|boolean', 'super_admin_self_approval' => 'nullable|boolean', 'closed_through' => 'nullable|date',
        ]);
        $old = AcctSettings::get();
        // maker-checker, Super Admin self-approval and the closed period are NOT changed here, whoever sends them: they have their own action for a Super Admin
        // that asks for the password again. Sending a different value is refused and recorded; sending the current value (an old form) is harmless.
        $asked = array_intersect_key($request->all(), array_flip(self::CONTROLS));
        foreach ($asked as $k => $v) {
            if ($this->differs($k, $v, $old[$k] ?? null)) {
                AcctAudit::log('settings.control_refused', 'settings', null, $k, [$k => $old[$k] ?? null], [$k => $v], __('Changing this setting needs a Super Admin and a fresh password.'), $this->me());

                return back()->with('error', e(__('Only a Super Admin can change who may approve or which period is closed, and the password is asked again for it.')));
            }
        }
        foreach (['large_txn', 'attachment_required_over', 'discrepancy_dual_over'] as $k) {
            $d[$k] = array_filter((array) ($d[$k] ?? []), fn ($v) => $v !== null && $v !== '');
        }
        $d['denominations'] = array_map(fn ($l) => array_values(array_unique(array_map('floatval', (array) $l))), (array) ($d['denominations'] ?? []));
        $d['allow_inverse_rates'] = $request->boolean('allow_inverse_rates');
        $new = AcctSettings::save(array_diff_key($d, array_flip(self::CONTROLS)));
        AcctAudit::log('settings.updated', 'settings', null, null, array_diff_key($old, ['denominations' => 1]), array_diff_key($new, ['denominations' => 1]), null, $this->me());

        return back()->with('message', __('Settings saved.'));
    }

    /** Maker-checker, Super Admin self-approval and the closed period: Super Admin only, behind the password confirmation (route middleware). */
    public function controls(Request $request)
    {
        $this->need('manage-acct-settings');
        abort_unless($this->me()->hasRole('Super Admin'), 403);
        $d = $request->validate(['maker_checker' => 'required|boolean', 'super_admin_self_approval' => 'required|boolean', 'closed_through' => 'nullable|date']);
        $d['maker_checker'] = $request->boolean('maker_checker');
        $d['super_admin_self_approval'] = $request->boolean('super_admin_self_approval');
        $d['closed_through'] = ($d['closed_through'] ?? '') === '' ? null : \Carbon\Carbon::parse($d['closed_through'])->toDateString();
        $old = AcctSettings::get();
        $new = AcctSettings::save($d);
        AcctAudit::log('settings.controls_changed', 'settings', null, null, array_intersect_key($old, array_flip(self::CONTROLS)), array_intersect_key($new, array_flip(self::CONTROLS)), null, $this->me());

        return back()->with('message', __('Approval and control settings saved.'));
    }

    private function differs(string $key, $sent, $current): bool
    {
        if ($key === 'closed_through') {
            $a = ($sent === null || $sent === '') ? null : (strtotime((string) $sent) ? date('Y-m-d', strtotime((string) $sent)) : (string) $sent);
            $b = ($current === null || $current === '') ? null : date('Y-m-d', strtotime((string) $current));

            return $a !== $b;
        }

        return filter_var($sent, FILTER_VALIDATE_BOOLEAN) !== (bool) $current;
    }

    public function saveRule(Request $request, ?int $id = null)
    {
        $this->need('manage-acct-approval-rules');
        $d = $request->validate(['name' => 'required|string|max:120', 'applies_to' => 'required|in:outflow,inflow,transfer,adjustment', 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'min_amount' => 'required|numeric|min:0', 'max_amount' => 'nullable|numeric|gt:min_amount',
            'approvals_required' => 'required|integer|min:1|max:5', 'account_id' => 'nullable|integer', 'is_active' => 'required|boolean', 'sort_order' => 'nullable|integer|min:0|max:1000']);
        if (($d['max_amount'] ?? null) === '') {
            $d['max_amount'] = null;
        }
        if ((($d['min_amount'] > 0) || ($d['max_amount'] ?? null) !== null) && empty($d['currency'])) {
            return back()->with('error', e(__('An amount threshold needs a currency: amounts are never compared across currencies.')));
        }
        $d['sort_order'] ??= 0;
        $rule = $id ? AcctApprovalRule::query()->findOrFail($id) : new AcctApprovalRule;
        $old = $rule->exists ? $rule->toArray() : null;
        $rule->fill($d)->save();
        AcctAudit::log($id ? 'rule.updated' : 'rule.created', 'rule', $rule->id, $rule->name, $old, $rule->toArray(), null, $this->me());

        return back()->with('message', __('Rule saved. It applies to new submissions only.'));
    }

    public function deleteRule(int $id)
    {
        $this->need('manage-acct-approval-rules');
        $rule = AcctApprovalRule::query()->findOrFail($id);
        AcctAudit::log('rule.deleted', 'rule', $rule->id, $rule->name, $rule->toArray(), null, null, $this->me());
        $rule->delete();

        return back()->with('message', __('Rule removed.'));
    }

    public function saveCategory(Request $request, ?int $id = null)
    {
        $this->need('manage-acct-settings');
        $d = $request->validate(['name' => 'required|string|max:120', 'direction' => 'required|in:in,out,both', 'is_active' => 'required|boolean', 'sort_order' => 'nullable|integer|min:0|max:1000']);
        $c = $id ? AcctCategory::query()->findOrFail($id) : new AcctCategory;
        if ($c->exists && $c->is_system) {
            return back()->with('error', e(__('A built-in category cannot be changed.')));
        }
        $c->fill($d + ['sort_order' => $d['sort_order'] ?? 100])->save();
        AcctAudit::log($id ? 'category.updated' : 'category.created', 'category', $c->id, $c->name, null, $d, null, $this->me());

        return back()->with('message', __('Saved.'));
    }
}
