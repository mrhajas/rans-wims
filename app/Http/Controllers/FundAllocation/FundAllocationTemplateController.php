<?php

namespace App\Http\Controllers\FundAllocation;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\FundAccount;
use App\Models\FundAllocation;
use App\Models\FundAllocationTemplate;
use App\Models\FundCategory;
use App\Models\FundPaymentMethod;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\FundAllocationTemplateSheet;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Funds Allocation > Template: the monthly sheet kept as a template. It is opened, changed and saved here, and "Generate from Template"
 * turns it into a Draft allocation sheet (the normal approval and payment steps then follow). Three abilities: read / update / generate.
 */
class FundAllocationTemplateController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]); // every action checks its own ability below
    }

    private function may(Request $request, string $ability): bool
    {
        $u = $request->user();

        return $u->hasRole('Super Admin') || $u->can($ability);
    }

    private function template(): FundAllocationTemplate
    {
        return FundAllocationTemplate::query()->orderBy('id')->first()
            ?? FundAllocationTemplate::query()->create(['name' => 'Funds allocation template', 'data' => FundAllocationTemplateSheet::default()]);
    }

    public function show(Request $request)
    {
        abort_unless($this->may($request, 'read-fund-allocation-templates') || $this->may($request, 'update-fund-allocation-templates'), 403);
        $t = $this->template();
        $categories = FundCategory::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $byName = $categories->mapWithKeys(fn ($c) => [mb_strtoupper(trim($c->name)) => $c->id]);

        $data = $this->normalised($t->data, $byName);

        return Inertia::render('FundAllocation/Template', [
            'template' => ['name' => $t->name, 'data' => $data, 'updated_at' => optional($t->updated_at)->toIso8601String(), 'updated_by' => optional($t->updatedBy)->name],
            'categories' => $categories,
            // the same lists the normal allocation form chooses from
            'accounts' => FundAccount::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'type', 'currency', 'account_number']),
            'methods' => FundPaymentMethod::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'short_label', 'currency']),
            'contacts' => Contact::query()->orderBy('name')->get(['id', 'name', 'category']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'username']),
            'projects' => Warehouse::ofAccount()->active()->orderBy('name')->get(['id', 'name', 'status']),
            'warehouses' => Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status']),
            'can' => [
                'update' => $this->may($request, 'update-fund-allocation-templates'),
                'generate' => $this->may($request, 'generate-fund-allocation-templates'),
            ],
        ]);
    }

    /** Fills in anything an older saved template does not have, and matches the starting sections to their categories by name. */
    private function normalised(array $data, $byName): array
    {
        $rate = (float) ($data['usd_rate'] ?? 1) ?: 1;
        foreach ($data['balances'] ?? [] as $k => $b) {
            $cur = strtoupper((string) ($b['currency'] ?? 'TZS')) ?: 'TZS';
            $data['balances'][$k] = ['label' => $b['label'] ?? '', 'fund_account_id' => $b['fund_account_id'] ?? null, 'currency' => $cur, 'amount' => (float) ($b['amount'] ?? 0), 'exchange_rate' => $cur === 'TZS' ? 1 : ((float) ($b['exchange_rate'] ?? 0) ?: $rate)];
        }
        foreach ($data['sections'] as &$s) {
            $s['category_id'] = $s['category_id'] ?? ($byName[mb_strtoupper(trim((string) ($s['category'] ?? '')))] ?? null);
            foreach ($s['items'] as &$i) {
                $i += ['contact_id' => null, 'beneficiary_user_id' => null, 'beneficiary_warehouse_id' => null, 'extra_lines' => [], 'warehouse_id' => null, 'notes' => null];
            }
            unset($i);
        }
        unset($s);

        return $data;
    }

    /** Print / download of the SAVED template, in the WIMS style. */
    public function print(Request $request)
    {
        abort_unless($this->may($request, 'read-fund-allocation-templates') || $this->may($request, 'update-fund-allocation-templates'), 403);

        return view('fund-allocations.template-print', $this->printData($request, false));
    }

    public function pdf(Request $request)
    {
        abort_unless($this->may($request, 'read-fund-allocation-templates') || $this->may($request, 'update-fund-allocation-templates'), 403);

        return Pdf::loadView('fund-allocations.template-print', $this->printData($request, true))->setPaper('a4', 'landscape')->download('funds-allocation-template-'.now()->format('Ymd').'.pdf');
    }

    private function printData(Request $request, bool $isPdf): array
    {
        $t = $this->template();
        $byName = FundCategory::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtoupper(trim($n)) => $id]);
        $d = $this->normalised($t->data, $byName);
        $cats = FundCategory::query()->pluck('name', 'id');
        foreach ($d['sections'] as &$s) {
            $s['name'] = $cats[$s['category_id']] ?? ($s['category'] ?? '');
        }
        unset($s);

        return [
            'd' => $d, 'isPdf' => $isPdf, 'by' => $request->user()->name, 'generatedAt' => now(), 'savedAt' => $t->updated_at, 'savedBy' => optional($t->updatedBy)->name,
            'companyName' => optional($request->user()->account)->name ?? 'RANS Company Limited',
            'accounts' => FundAccount::query()->pluck('name', 'id'),
            'autoPrint' => ! $isPdf,
        ];
    }

    public function update(Request $request)
    {
        abort_unless($this->may($request, 'update-fund-allocation-templates'), 403);
        $data = $this->validated($request);
        $t = $this->template();
        $t->update(['data' => $data, 'updated_by_user_id' => $request->user()->id]);
        log_activity(__('Funds allocation template saved.'), ['sections' => count($data['sections']), 'items' => collect($data['sections'])->sum(fn ($s) => count($s['items']))], $t, 'Funds Allocation');

        \App\Services\FundAllocationNotifier::template('template_changed', null, $request->user(), __('The allocation template was saved by :name.', ['name' => $request->user()->name]));

        return back()->with('message', __('Template saved.'));
    }

    /** A Draft allocation sheet made from the SAVED template (the page saves any change first). */
    public function generate(Request $request)
    {
        abort_unless($this->may($request, 'generate-fund-allocation-templates'), 403);
        $t = $this->template();
        $d = $t->data;
        $methods = FundPaymentMethod::query()->get()->keyBy(fn ($m) => mb_strtoupper(trim((string) $m->short_label)));
        $method = fn (string $label) => $methods->get($label) ?? $methods->first(fn ($m) => str_contains(mb_strtoupper($m->name), $label));
        $lines = ['tt' => $method('TT'), 'cash' => $method('CASH'), 'usd' => $method('USD')];
        $rate = max(0.0001, (float) ($d['usd_rate'] ?? 1));
        $byName = FundCategory::query()->active()->get()->mapWithKeys(fn ($c) => [mb_strtoupper(trim($c->name)) => $c->id]);
        $user = $request->user();

        $allocation = ReferenceLock::run('fund_allocations', fn () => DB::transaction(function () use ($d, $lines, $rate, $byName, $user) {
            $a = FundAllocation::create([
                'reference' => FundAllocation::nextReference(),
                'allocation_date' => now()->toDateString(),
                'title' => $d['title'] ?? null,
                'notes' => ! empty($d['note']) ? $d['note'] : null,
                'status' => FundAllocation::STATUS_DRAFT,
                'prepared_by_user_id' => $user->id,
            ]);
            foreach (array_values($d['balances'] ?? []) as $i => $b) {
                $cur = strtoupper($b['currency'] ?? 'TZS') ?: 'TZS';
                $a->balances()->create(['fund_account_id' => $b['fund_account_id'] ?? null, 'label' => $b['label'], 'currency' => $cur, 'amount' => (float) $b['amount'], 'exchange_rate' => $cur === 'TZS' ? 1 : ((float) ($b['exchange_rate'] ?? 0) ?: $rate), 'sort_order' => ($i + 1) * 10]);
            }
            $n = 0;
            foreach ($d['sections'] as $s) {
                $catId = $s['category_id'] ?? ($byName[mb_strtoupper(trim((string) ($s['category'] ?? '')))] ?? null);
                if (! $catId) {
                    continue;
                }
                foreach ($s['items'] as $it) {
                    $item = $a->items()->create([
                        'fund_category_id' => $catId, 'sort_order' => (++$n) * 10, 'description' => $it['description'], 'beneficiary' => $it['beneficiary'] ?: null,
                        'contact_id' => $it['contact_id'] ?? null, 'beneficiary_user_id' => $it['beneficiary_user_id'] ?? null, 'beneficiary_warehouse_id' => $it['beneficiary_warehouse_id'] ?? null,
                        'warehouse_id' => $it['warehouse_id'] ?? null, 'notes' => $it['notes'] ?? null,
                        'request_amount' => (float) $it['request'], 'allocated_amount' => (float) $it['allocation'],
                    ]);
                    foreach ((array) ($it['extra_lines'] ?? []) as $x) {
                        if ((float) $x['amount'] > 0) {
                            $item->lines()->create(['fund_payment_method_id' => $x['fund_payment_method_id'], 'amount' => round((float) $x['amount'], 2)]);
                        }
                    }
                    foreach (['tt', 'cash', 'usd'] as $k) {
                        $amount = round((float) ($it[$k] ?? 0), 2);
                        if ($amount > 0 && $lines[$k]) {
                            $item->lines()->create([
                                'fund_payment_method_id' => $lines[$k]->id, 'amount' => $amount,
                                'exchange_rate' => $k === 'usd' ? $rate : null, 'usd_amount' => $k === 'usd' ? round($amount / $rate, 2) : null,
                            ]);
                        }
                    }
                }
            }
            log_activity(__('Allocation sheet generated from the template.'), ['reference' => $a->reference, 'items' => $n], $a, 'Funds Allocation');

            return $a;
        }));

        \App\Services\FundAllocationNotifier::template('template_generated', $allocation, $user, __('Draft :ref was generated from the template by :name.', ['ref' => $allocation->reference, 'name' => $user->name]));

        return redirect()->route('fund-allocations.show', $allocation->id)->with('message', __('Draft :ref was generated from the template.', ['ref' => $allocation->reference]));
    }

    private function validated(Request $request): array
    {
        $num = ['nullable', 'numeric', 'min:0', 'max:9999999999999'];
        $v = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'date' => ['nullable', 'date'],
            'usd_rate' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:500'],
            'balances' => ['array', 'max:50'],
            'balances.*.label' => ['required', 'string', 'max:190'],
            'balances.*.fund_account_id' => ['nullable', 'integer', Rule::exists('fund_accounts', 'id')->whereNull('deleted_at')],
            'balances.*.currency' => ['nullable', 'string', 'size:3'],
            'balances.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'balances.*.exchange_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'sections' => ['required', 'array', 'min:1', 'max:40'],
            'sections.*.category_id' => ['required', 'integer', Rule::exists('fund_categories', 'id')->whereNull('deleted_at')],
            'sections.*.items' => ['array', 'max:300'],
            'sections.*.items.*.description' => ['required', 'string', 'max:255'],
            'sections.*.items.*.beneficiary' => ['nullable', 'string', 'max:255'],
            'sections.*.items.*.contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')],
            'sections.*.items.*.beneficiary_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'sections.*.items.*.beneficiary_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'sections.*.items.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'sections.*.items.*.notes' => ['nullable', 'string', 'max:2000'],
            'sections.*.items.*.request' => $num, 'sections.*.items.*.allocation' => $num,
            'sections.*.items.*.tt' => $num, 'sections.*.items.*.cash' => $num, 'sections.*.items.*.usd' => $num,
            'sections.*.items.*.extra_lines' => ['nullable', 'array', 'max:10'],
            'sections.*.items.*.extra_lines.*.fund_payment_method_id' => ['required', 'integer', Rule::exists('fund_payment_methods', 'id')->whereNull('deleted_at')],
            'sections.*.items.*.extra_lines.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ]);
        abort_if(collect($v['sections'])->sum(fn ($s) => count($s['items'] ?? [])) > 500, 422, __('A template can hold at most 500 items.'));
        // a balance in another currency needs its exchange rate
        foreach ($v['balances'] ?? [] as $i => $b) {
            if (strtoupper($b['currency'] ?? 'TZS') !== 'TZS' && ! ((float) ($b['exchange_rate'] ?? 0) > 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages(["balances.$i.exchange_rate" => __('An exchange rate (1 :cur = ? TZS) is required for a non-TZS balance.', ['cur' => strtoupper($b['currency'])])]);
            }
        }
        $names = FundCategory::query()->pluck('name', 'id');
        $blank = fn ($x) => isset($x) && trim((string) $x) !== '' ? trim((string) $x) : null;

        return [
            'title' => trim($v['title']), 'date' => $v['date'] ?? null, 'usd_rate' => (float) $v['usd_rate'], 'note' => $blank($v['note'] ?? null),
            'balances' => collect($v['balances'] ?? [])->map(function ($b) {
                $cur = strtoupper($b['currency'] ?? 'TZS') ?: 'TZS';

                return ['label' => trim($b['label']), 'fund_account_id' => $b['fund_account_id'] ?? null, 'currency' => $cur, 'amount' => round((float) $b['amount'], 2), 'exchange_rate' => $cur === 'TZS' ? 1 : (float) $b['exchange_rate']];
            })->values()->all(),
            'sections' => collect($v['sections'])->map(fn ($s) => [
                'category_id' => (int) $s['category_id'], 'category' => $names[(int) $s['category_id']] ?? null,
                'items' => collect($s['items'] ?? [])->map(fn ($i) => [
                    'description' => trim($i['description']), 'beneficiary' => $blank($i['beneficiary'] ?? null),
                    'contact_id' => $i['contact_id'] ?? null ?: null,
                    'beneficiary_user_id' => empty($i['contact_id']) ? ($i['beneficiary_user_id'] ?? null ?: null) : null,
                    'beneficiary_warehouse_id' => empty($i['contact_id']) && empty($i['beneficiary_user_id']) ? ($i['beneficiary_warehouse_id'] ?? null ?: null) : null,
                    'request' => round((float) ($i['request'] ?? 0), 2), 'allocation' => round((float) ($i['allocation'] ?? 0), 2),
                    'tt' => round((float) ($i['tt'] ?? 0), 2), 'cash' => round((float) ($i['cash'] ?? 0), 2), 'usd' => round((float) ($i['usd'] ?? 0), 2),
                    'extra_lines' => collect($i['extra_lines'] ?? [])->map(fn ($l) => ['fund_payment_method_id' => (int) $l['fund_payment_method_id'], 'amount' => round((float) $l['amount'], 2)])->values()->all(),
                    'warehouse_id' => $i['warehouse_id'] ?? null ?: null, 'notes' => $blank($i['notes'] ?? null),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
