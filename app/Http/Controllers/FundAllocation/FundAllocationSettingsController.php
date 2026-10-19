<?php

namespace App\Http\Controllers\FundAllocation;

use App\Http\Controllers\Controller;
use App\Models\FundAccount;
use App\Models\FundCategory;
use App\Models\FundPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Funds Allocation settings: the company accounts (bank / cash / mobile, each with a currency), the allocation
 * categories (A ADMINISTRATION, B PROCUREMENT, ...) and the payment methods (TT, CASH, MOBILE, ...).
 * Everything here needs manage-fund-allocation-settings; the permission is authorised explicitly per action
 * (route-name derived abilities would not match this module's permission names).
 */
class FundAllocationSettingsController extends Controller
{
    private const PERMISSION = 'manage-fund-allocation-settings';

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->authorize(self::PERMISSION);

        // Only count usage on sheets that still exist (never trashed rows).
        $itemCounts = DB::table('fund_allocation_items as i')
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'i.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->whereNull('i.deleted_at')
            ->groupBy('i.fund_category_id')
            ->selectRaw('i.fund_category_id as id, COUNT(*) as c')
            ->pluck('c', 'id');

        $lineCounts = DB::table('fund_allocation_item_lines as l')
            ->whereNull('l.deleted_at')
            ->join('fund_allocation_items as i', function ($j) {
                $j->on('i.id', '=', 'l.fund_allocation_item_id')->whereNull('i.deleted_at');
            })
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'i.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->groupBy('l.fund_payment_method_id')
            ->selectRaw('l.fund_payment_method_id as id, COUNT(*) as c')
            ->pluck('c', 'id');

        $balanceCounts = DB::table('fund_allocation_balances as b')
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'b.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->whereNull('b.deleted_at')
            ->whereNotNull('b.fund_account_id')
            ->groupBy('b.fund_account_id')
            ->selectRaw('b.fund_account_id as id, COUNT(*) as c')
            ->pluck('c', 'id');

        $accounts = FundAccount::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FundAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'account_number' => $a->account_number,
                'description' => $a->description,
                'is_active' => (bool) $a->is_active,
                'sort_order' => (int) $a->sort_order,
                'sheets_count' => (int) ($balanceCounts[$a->id] ?? 0),
            ])->values();

        $categories = FundCategory::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FundCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sort_order' => (int) $c->sort_order,
                'is_active' => (bool) $c->is_active,
                'is_system' => (bool) $c->is_system,
                'items_count' => (int) ($itemCounts[$c->id] ?? 0),
            ])->values();

        $methods = FundPaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (FundPaymentMethod $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'short_label' => $m->short_label,
                'currency' => $m->currency ?: 'TZS',
                'sort_order' => (int) $m->sort_order,
                'is_active' => (bool) $m->is_active,
                'lines_count' => (int) ($lineCounts[$m->id] ?? 0),
            ])->values();

        return Inertia::render('FundAllocation/Settings', [
            'accounts' => $accounts,
            'categories' => $categories,
            'methods' => $methods,
            'types' => FundAccount::TYPES,
        ]);
    }

    /* ----------------------------------------------------------------- accounts */

    public function storeAccount(Request $request)
    {
        $this->authorize(self::PERMISSION);

        $data = $this->validateAccount($request);
        $data['sort_order'] = $data['sort_order'] ?? $this->nextSort(FundAccount::class);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['created_by_user_id'] = $request->user()->id;

        $account = FundAccount::create($data);
        log_activity(__('Fund account created.'), ['name' => $account->name, 'currency' => $account->currency], $account, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Fund account', 'added', $request->user());

        return back()->with('message', __('Account added.'));
    }

    public function updateAccount(Request $request, FundAccount $fund_account)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_account->trashed(), 404);

        $fund_account->update($this->validateAccount($request, true));
        log_activity(__('Fund account updated.'), ['name' => $fund_account->name], $fund_account, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Fund account', 'updated', $request->user());

        return back()->with('message', __('Account updated.'));
    }

    public function destroyAccount(Request $request, FundAccount $fund_account)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_account->trashed(), 404);

        // Past sheets keep their own copy of the account label/currency/amount, so deleting is safe.
        $fund_account->delete();
        log_activity(__('Fund account deleted.'), ['name' => $fund_account->name], $fund_account, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Fund account', 'deleted', $request->user());

        return back()->with('message', __('Account deleted.'));
    }

    /* --------------------------------------------------------------- categories */

    public function storeCategory(Request $request)
    {
        $this->authorize(self::PERMISSION);

        $data = $this->validateCategory($request);
        $data['sort_order'] = $data['sort_order'] ?? $this->nextSort(FundCategory::class);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['is_system'] = false;

        $category = FundCategory::create($data);
        log_activity(__('Fund category created.'), ['name' => $category->name], $category, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Category', 'added', $request->user());

        return back()->with('message', __('Category added.'));
    }

    public function updateCategory(Request $request, FundCategory $fund_category)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_category->trashed(), 404);

        // System categories may be renamed / reordered / deactivated; is_system itself is never client-writable.
        $fund_category->update($this->validateCategory($request, $fund_category));
        log_activity(__('Fund category updated.'), ['name' => $fund_category->name], $fund_category, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Category', 'updated', $request->user());

        return back()->with('message', __('Category updated.'));
    }

    public function destroyCategory(Request $request, FundCategory $fund_category)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_category->trashed(), 404);

        if ($fund_category->is_system) {
            return back()->with('error', __('A built-in category cannot be deleted. Deactivate it instead.'));
        }
        if ($this->categoryInUse($fund_category)) {
            return back()->with('error', __('This category is used by allocation sheets and cannot be deleted. Deactivate it instead.'));
        }

        $fund_category->delete();
        log_activity(__('Fund category deleted.'), ['name' => $fund_category->name], $fund_category, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Category', 'deleted', $request->user());

        return back()->with('message', __('Category deleted.'));
    }

    /* ------------------------------------------------------------------ methods */

    public function storeMethod(Request $request)
    {
        $this->authorize(self::PERMISSION);

        $data = $this->validateMethod($request);
        $data['sort_order'] = $data['sort_order'] ?? $this->nextSort(FundPaymentMethod::class);
        $data['is_active'] = $data['is_active'] ?? true;

        $method = FundPaymentMethod::create($data);
        log_activity(__('Fund payment method created.'), ['name' => $method->name, 'short_label' => $method->short_label], $method, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Payment method', 'added', $request->user());

        return back()->with('message', __('Payment method added.'));
    }

    public function updateMethod(Request $request, FundPaymentMethod $fund_payment_method)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_payment_method->trashed(), 404);

        $fund_payment_method->update($this->validateMethod($request, $fund_payment_method));
        log_activity(__('Fund payment method updated.'), ['name' => $fund_payment_method->name], $fund_payment_method, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Payment method', 'updated', $request->user());

        return back()->with('message', __('Payment method updated.'));
    }

    public function destroyMethod(Request $request, FundPaymentMethod $fund_payment_method)
    {
        $this->authorize(self::PERMISSION);
        abort_if($fund_payment_method->trashed(), 404);

        if ($this->methodInUse($fund_payment_method)) {
            return back()->with('error', __('This payment method is used by allocation sheets and cannot be deleted. Deactivate it instead.'));
        }

        $fund_payment_method->delete();
        log_activity(__('Fund payment method deleted.'), ['name' => $fund_payment_method->name], $fund_payment_method, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::settings('Payment method', 'deleted', $request->user());

        return back()->with('message', __('Payment method deleted.'));
    }

    /* ------------------------------------------------------------------ reorder */

    /** Move one row up/down: the whole list is renumbered (10, 20, 30...) so equal sort_order values never block a swap. */
    public function reorder(Request $request)
    {
        $this->authorize(self::PERMISSION);

        $data = $request->validate([
            'type' => ['required', Rule::in(['accounts', 'categories', 'methods'])],
            'id' => 'required|integer',
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $model = ['accounts' => FundAccount::class, 'categories' => FundCategory::class, 'methods' => FundPaymentMethod::class][$data['type']];

        DB::transaction(function () use ($model, $data) {
            $ids = $model::query()->orderBy('sort_order')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $pos = array_search((int) $data['id'], $ids, true);
            abort_if($pos === false, 404);

            $target = $data['direction'] === 'up' ? $pos - 1 : $pos + 1;
            if ($target >= 0 && $target < count($ids)) {
                [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
            }
            foreach ($ids as $i => $id) {
                $model::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });

        \App\Services\FundAllocationNotifier::settings(['accounts' => 'Fund account', 'categories' => 'Category', 'methods' => 'Payment method'][$data['type']] . ' order', 'changed (moved ' . $data['direction'] . ')', $request->user());

        return back();
    }

    /* ---------------------------------------------------------------- internals */

    protected function validateAccount(Request $request, bool $partial = false): array
    {
        if ($request->has('currency')) {
            $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        }
        // Two separate rules: a "sometimes|required" string inside an array is not split by Laravel (that was the error page).
        $req = $partial ? ['sometimes', 'required'] : ['required'];

        return $request->validate([
            'name' => [...$req, 'string', 'max:120'],
            'type' => [...$req, Rule::in(FundAccount::TYPES)],
            'currency' => [...$req, 'string', 'regex:/^[A-Z]{3}$/'],
            'account_number' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ], [
            'currency.regex' => __('Use a 3-letter currency code such as TZS or USD.'),
        ]);
    }

    protected function validateCategory(Request $request, ?FundCategory $category = null): array
    {
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', $category ? 'filled' : 'string', 'string', 'max:120',
                Rule::unique('fund_categories', 'name')->whereNull('deleted_at')->ignore($category?->id)],
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ], [
            'name.unique' => __('A category with this name already exists.'),
        ]);
    }

    protected function validateMethod(Request $request, ?FundPaymentMethod $method = null): array
    {
        if ($request->has('short_label')) {
            $request->merge(['short_label' => strtoupper(trim((string) $request->input('short_label')))]);
        }

        return $request->validate([
            'name' => [$method ? 'sometimes' : 'required', $method ? 'filled' : 'string', 'string', 'max:120'],
            'short_label' => [$method ? 'sometimes' : 'required', $method ? 'filled' : 'string', 'string', 'max:12',
                Rule::unique('fund_payment_methods', 'short_label')->whereNull('deleted_at')->ignore($method?->id)],
            'currency' => 'sometimes|in:TZS,USD',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ], [
            'short_label.unique' => __('This short label is already used by another payment method.'),
        ]);
    }

    protected function nextSort(string $model): int
    {
        return ((int) $model::query()->max('sort_order')) + 10;
    }

    protected function categoryInUse(FundCategory $category): bool
    {
        return DB::table('fund_allocation_items as i')
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'i.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->whereNull('i.deleted_at')
            ->where('i.fund_category_id', $category->id)
            ->exists();
    }

    protected function methodInUse(FundPaymentMethod $method): bool
    {
        return DB::table('fund_allocation_item_lines as l')
            ->whereNull('l.deleted_at')
            ->join('fund_allocation_items as i', function ($j) {
                $j->on('i.id', '=', 'l.fund_allocation_item_id')->whereNull('i.deleted_at');
            })
            ->join('fund_allocations as a', function ($j) {
                $j->on('a.id', '=', 'i.fund_allocation_id')->whereNull('a.deleted_at');
            })
            ->where('l.fund_payment_method_id', $method->id)
            ->exists();
    }
}
