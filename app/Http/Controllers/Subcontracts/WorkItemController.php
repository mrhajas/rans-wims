<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractVariation;
use App\Models\SubcontractWorkGroup;
use App\Models\SubcontractWorkItem as WI;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractFields;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The work schedule of an agreement: groups (building / location / phase) and items (unit, quantity, rate, amount). The amount is always calculated
 * here, never trusted from the browser. The schedule of an agreement can change only while it is a Draft; later additions and reductions are variations.
 */
class WorkItemController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function editableAgreement(int $id, ?int $variationId = null): A
    {
        $a = $this->agreementOrFail($id);
        if ($variationId) {
            $this->need('create-subcontract-variations');
            $v = SubcontractVariation::query()->where('agreement_id', $a->id)->findOrFail($variationId);
            abort_unless($v->status === SubcontractVariation::DRAFT, 422, __('Items can be added to a variation only while it is a draft.'));
            abort_unless($a->isPayable(), 422, __('Variations are for approved agreements.'));
        } else {
            $this->need('create-subcontract-agreements', 'update-subcontract-agreements');
            abort_unless($a->status === A::DRAFT, 422, __('The work schedule of an approved agreement changes only through a variation.'));
        }

        return $a;
    }

    public function storeGroup(Request $request, int $agreement)
    {
        $a = $this->editableAgreement($agreement);
        $d = $request->validate(['name' => 'required|string|max:190', 'kind' => 'required|in:' . implode(',', array_keys(SubcontractWorkGroup::KINDS)), 'warehouse_id' => 'nullable|integer']);
        $g = SubcontractWorkGroup::create($d + ['agreement_id' => $a->id, 'sort_order' => (int) $a->groups()->max('sort_order') + 1]);
        log_activity('Work group "' . $g->name . '" added to ' . $a->reference, ['group' => $g->id], $a, 'Subcontracts');

        return back()->with('message', __('Group added.'));
    }

    public function updateGroup(Request $request, int $agreement, int $group)
    {
        $a = $this->editableAgreement($agreement);
        $g = SubcontractWorkGroup::where('agreement_id', $a->id)->findOrFail($group);
        $d = $request->validate(['name' => 'required|string|max:190', 'kind' => 'required|in:' . implode(',', array_keys(SubcontractWorkGroup::KINDS)), 'warehouse_id' => 'nullable|integer']);
        $g->update($d);
        log_activity('Work group "' . $g->name . '" changed on ' . $a->reference, ['group' => $g->id], $a, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }

    public function destroyGroup(int $agreement, int $group)
    {
        $a = $this->editableAgreement($agreement);
        $g = SubcontractWorkGroup::where('agreement_id', $a->id)->findOrFail($group);
        abort_if(WI::where('group_id', $g->id)->exists(), 422, __('Remove or move the items of this group first.'));
        $g->delete();
        log_activity('Work group "' . $g->name . '" removed from ' . $a->reference, [], $a, 'Subcontracts');

        return back()->with('message', __('Group removed.'));
    }

    public function storeItem(Request $request, int $agreement)
    {
        $a = $this->editableAgreement($agreement, $request->filled('variation_id') ? (int) $request->input('variation_id') : null);
        $d = $this->validated($request, $a);
        $item = DB::transaction(function () use ($a, $d) {
            return WI::create($d + ['agreement_id' => $a->id, 'line_no' => (int) WI::where('agreement_id', $a->id)->max('line_no') + 1]);
        });
        log_activity('Work item added to ' . $a->reference, ['item' => $item->id, 'amount' => (string) $item->amount, 'variation' => $item->variation_id], $a, 'Subcontracts');

        return back()->with('message', __('Item added.'));
    }

    public function updateItem(Request $request, int $agreement, int $item)
    {
        $it = WI::where('agreement_id', $agreement)->findOrFail($item);
        $a = $this->editableAgreement($agreement, $it->variation_id);
        $d = $this->validated($request, $a, $it->variation_id, $it);
        $before = (string) $it->amount;
        $it->update($d);
        log_activity('Work item changed on ' . $a->reference, ['item' => $it->id, 'amount_before' => $before, 'amount_after' => (string) $it->amount], $a, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }

    public function destroyItem(int $agreement, int $item)
    {
        $it = WI::where('agreement_id', $agreement)->findOrFail($item);
        $a = $this->editableAgreement($agreement, $it->variation_id);
        abort_if(DB::table('subcontract_request_items')->where('work_item_id', $it->id)->exists() || DB::table('subcontract_historical_payments')->where('work_item_id', $it->id)->whereNull('deleted_at')->exists(), 422, __('This item has payments or requests against it.'));
        $it->delete();
        log_activity('Work item removed from ' . $a->reference, ['item' => $it->id, 'amount' => (string) $it->amount], $a, 'Subcontracts');

        return back()->with('message', __('Item removed.'));
    }

    /** Sets the original agreement amount to the sum of the schedule (Draft only). */
    public function useScheduleTotal(int $agreement)
    {
        $a = $this->editableAgreement($agreement);
        DB::transaction(function () use (&$a) {
            $a = A::withoutGlobalScopes()->whereKey($a->id)->lockForUpdate()->first();
            abort_unless($a->status === A::DRAFT, 422, __('The work schedule of an approved agreement changes only through a variation.'));
            $sum = (string) WI::where('agreement_id', $a->id)->where('status', 'active')->whereNull('variation_id')->sum('amount');
            $a->update(['original_amount' => bcadd($sum, '0', 2)]);
        });
        log_activity('Original amount of ' . $a->reference . ' set to the work schedule total', ['original_amount' => $a->original_amount], $a, 'Subcontracts');

        return back()->with('message', __('The original amount is now the schedule total.'));
    }

    private function validated(Request $request, A $a, ?int $forcedVariation = null, ?WI $existing = null): array
    {
        $s = SubcontractSettings::get();
        $d = SubcontractFields::validate($request, 'work_item', [
            'group_id' => 'nullable|integer', 'variation_id' => 'nullable|integer', 'description' => 'required|string|max:2000', 'category' => 'nullable|string|max:80', 'beneficiary_name' => 'nullable|string|max:190',
            'unit' => 'nullable|string|max:30', 'quantity' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:99999999999', 'rate' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:99999999999', 'pricing_method' => 'required|in:' . implode(',', array_keys(WI::PRICING)),
            'amount' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:-99999999999999|max:99999999999999', 'item_type' => 'nullable|in:original,additional,revised', 'remarks' => 'nullable|string|max:1000',
        ], $existing ? ($existing->extra_attributes ?: []) : null);
        $errors = [];
        if (! empty($d['group_id']) && ! SubcontractWorkGroup::where('agreement_id', $a->id)->whereKey($d['group_id'])->exists()) {
            $errors['group_id'] = __('Choose a group of this agreement.');
        }
        if ($d['pricing_method'] === 'qty_rate' && (($d['quantity'] ?? '') === '' || ($d['rate'] ?? '') === '')) {
            $errors['quantity'] = __('Enter the quantity and the rate.');
        }
        if ($d['pricing_method'] !== 'qty_rate' && ($d['amount'] ?? '') === '') {
            $errors['amount'] = __('Enter the amount.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (array_key_exists('unit', $d) || ! $existing) {
            $d['unit'] = SubcontractSettings::normalizeUnit($d['unit'] ?? null);
        }
        $d['amount'] = SubcontractAmounts::itemAmount($d['pricing_method'], $d['quantity'] ?? 0, $d['rate'] ?? 0, $d['amount'] ?? 0);
        if (bccomp(ltrim($d['amount'], '-'), '9999999999999.99', 2) > 0) {
            throw ValidationException::withMessages(['amount' => __('This amount is too large.')]);
        }
        if ($d['pricing_method'] !== 'qty_rate') {
            $d['quantity'] = $d['pricing_method'] === 'lump_sum' || ($d['quantity'] ?? '') === '' ? null : SubcontractAmounts::scale4($d['quantity']);
            $d['rate'] = null;
        } else {
            // stored exactly as the amount was calculated (4 places, half up), so quantity x rate always equals the amount
            $d['quantity'] = SubcontractAmounts::scale4($d['quantity']);
            $d['rate'] = SubcontractAmounts::scale4($d['rate']);
        }
        // an existing item keeps the variation it belongs to (the request cannot move it); a new one may name a variation, but only one of this agreement
        $d['variation_id'] = $existing ? $existing->variation_id : ($forcedVariation ?: ($request->filled('variation_id') ? (int) $request->input('variation_id') : null));
        if (! $existing && $d['variation_id'] && ! SubcontractVariation::query()->where('agreement_id', $a->id)->whereKey($d['variation_id'])->exists()) {
            throw ValidationException::withMessages(['variation_id' => __('Choose a variation of this agreement.')]);
        }
        $d['item_type'] = $d['variation_id'] ? ($d['item_type'] ?? 'additional') : 'original';
        foreach (['category', 'beneficiary_name', 'remarks'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
            }
        }

        return $d;
    }
}
