<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SubcontractImportBatch as B;
use App\Models\SubcontractImportSheet as S;
use App\Services\Subcontracts\SubcontractImporter;
use App\Services\Subcontracts\SubcontractNotifier;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Upload, review and confirm the historical workbook. Nothing reaches the live tables until a person with the confirm ability confirms. */
class ImportController extends Controller
{
    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index()
    {
        $this->need('import-subcontracts', 'confirm-import-subcontracts', 'reconcile-subcontracts');

        return Inertia::render('Subcontracts/Import/Index', ['batches' => B::query()->orderByDesc('id')->limit(50)->get()->map(fn ($b) => ['id' => $b->id, 'reference' => $b->reference, 'file' => $b->file_name, 'status' => $b->status, 'sheets' => $b->sheet_count, 'at' => optional($b->created_at)->toDateTimeString(),
            'imported_at' => optional($b->imported_at)->toDateTimeString(), 'summary' => $b->summary])->values(), 'can' => ['import' => $this->can('import-subcontracts')]]);
    }

    public function store(Request $request)
    {
        $this->need('import-subcontracts');
        $request->validate(['file' => 'required|file|max:30720|mimes:xlsx'], ['file.mimes' => __('Upload the Excel workbook (.xlsx).')]);
        try {
            $batch = SubcontractImporter::stage($request->file('file'), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('subcontracts.import.show', $batch->id)->with('message', __(':r staged: nothing is imported until you confirm it.', ['r' => $batch->reference]));
    }

    public function show(int $batch)
    {
        $this->need('import-subcontracts', 'confirm-import-subcontracts', 'reconcile-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $b = B::findOrFail($batch);
        $contacts = Contact::query()->where('category', 'SubContractors')->orderBy('name')->get(['id', 'name']);
        $sheets = $b->sheets()->get()->map(function ($s) {
            $p = $s->parsed ?? [];
            $conv = $p && ! isset($p['error']) ? SubcontractImporter::converted($p, $s->currency ?: 'TZS') : ['contract' => 0, 'paid' => 0];

            return ['id' => $s->id, 'sheet' => $s->sheet_name, 'state' => $s->sheet_state, 'layout' => $s->layout, 'title' => $s->title, 'name' => $s->suggested_name, 'as_at' => optional($s->as_at_date)->toDateString(), 'currency' => $s->currency, 'contact_id' => $s->contact_id, 'warehouse_id' => $s->warehouse_id,
                'decision' => $s->decision, 'flags' => $s->flags ?? [], 'totals' => ($s->totals ?? []) + ['converted_contract' => $conv['contract'], 'converted_paid' => $conv['paid']], 'new_contact' => ($s->totals ?? [])['new_contact'] ?? null, 'agreement_id' => $s->agreement_id,
                'items' => $p['item_count'] ?? 0, 'groups' => array_map(fn ($g) => ['name' => $g['name'], 'items' => count($g['items']), 'currency' => $g['currency'], 'rate' => $g['rate']], $p['groups'] ?? []), 'pays' => count($p['pays'] ?? []), 'error' => $p['error'] ?? null];
        });

        return Inertia::render('Subcontracts/Import/Show', ['b' => ['id' => $b->id, 'reference' => $b->reference, 'file' => $b->file_name, 'status' => $b->status, 'summary' => $b->summary, 'at' => optional($b->created_at)->toDateTimeString()], 'sheets' => $sheets->values(),
            'contacts' => $contacts->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]), 'projects' => $this->projects(), 'currencies' => collect(SubcontractSettings::get()['currencies'])->map(fn ($c) => ['value' => $c, 'label' => $c])->values(),
            'can' => ['confirm' => $this->can('confirm-import-subcontracts') && $b->status === 'staged', 'edit' => $this->can('import-subcontracts') && $b->status === 'staged', 'revert' => $this->can('confirm-import-subcontracts') && $b->status === 'imported', 'create_contacts' => $this->can('create-contacts')]]);
    }

    public function updateSheet(Request $request, int $batch, int $sheet)
    {
        $this->need('import-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $b = B::findOrFail($batch);
        abort_unless($b->status === 'staged', 422, __('This batch is no longer open for changes.'));
        $s = S::where('batch_id', $b->id)->findOrFail($sheet);
        $d = $request->validate(['decision' => 'required|in:import,archive,skip,pending', 'contact_id' => 'nullable|integer', 'new_contact' => 'nullable|string|max:50', 'warehouse_id' => 'nullable|integer', 'currency' => 'required|in:' . implode(',', SubcontractSettings::get()['currencies']),
            'name' => 'required|string|max:190', 'groups' => 'nullable|array', 'groups.*.currency' => 'nullable|in:TZS,USD', 'groups.*.rate' => 'nullable|numeric|min:0.000001|max:99999999']);
        if (! empty($d['contact_id']) && ! Contact::query()->where('id', $d['contact_id'])->where('category', 'SubContractors')->exists()) {
            return back()->withErrors(['contact_id' => __('Choose a Contact in the SubContractors category.')]);
        }
        $parsed = $s->parsed;
        foreach ($d['groups'] ?? [] as $i => $g) {
            if (isset($parsed['groups'][$i])) {
                $parsed['groups'][$i]['currency'] = $g['currency'] ?? null;
                $parsed['groups'][$i]['rate'] = ! empty($g['rate']) ? (float) $g['rate'] : null;
                if (($g['currency'] ?? null) === 'USD' && $d['currency'] !== 'USD' && empty($g['rate'])) {
                    return back()->withErrors(['groups' => __('A US dollar block in a TZS agreement needs its exchange rate.')]);
                }
            }
        }
        $totals = $s->totals ?? [];
        $totals['new_contact'] = ! empty($d['new_contact']) ? trim($d['new_contact']) : null;
        $s->update(['decision' => $d['decision'], 'contact_id' => $d['contact_id'] ?? null ?: null, 'warehouse_id' => $d['warehouse_id'] ?? null ?: null, 'currency' => $d['currency'], 'suggested_name' => $d['name'], 'parsed' => $parsed, 'totals' => $totals]);
        log_activity('Subcontract import sheet "' . $s->sheet_name . '" set to ' . $d['decision'], ['batch' => $b->reference, 'contact' => $s->contact_id, 'currency' => $s->currency], $b, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }

    public function confirm(Request $request, int $batch)
    {
        $this->need('confirm-import-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $b = B::findOrFail($batch);
        $result = SubcontractImporter::confirm($b, $request->user());
        $open = \Illuminate\Support\Facades\DB::table('subcontract_reconciliations')->whereIn('agreement_id', array_filter(array_column($result, 'agreement_id')))->where('status', 'open')->count();
        if ($open) {
            SubcontractNotifier::fire('import_review', 'import', $b->refresh(), $request->user(), __(':n imported accounts have differences to reconcile.', ['n' => $open]));
        }

        return redirect()->route('subcontracts.import.show', $b->id)->with('message', __(':n sheets imported; :o need reconciliation.', ['n' => count(array_filter($result, fn ($r) => $r['status'] === 'imported')), 'o' => $open]));
    }

    public function revert(Request $request, int $batch)
    {
        $this->need('confirm-import-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));
        $r = SubcontractImporter::revert(B::findOrFail($batch), $request->user());

        return back()->with('message', __(':n imported agreements were taken back.', ['n' => $r['agreements']]));
    }

    public function report(int $batch)
    {
        $this->need('read-subcontract-reports', 'import-subcontracts');
        abort_unless($this->money(), 403, __('Reconciliation and the Excel import show contract and payment figures: you need the permission to see financial figures.'));

        return redirect()->route('subcontracts.reports.index', ['type' => 'import']);
    }
}
