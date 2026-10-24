<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateContract;
use App\Models\ProjectCertificateContractClient;
use App\Models\Warehouse;
use App\Support\SupplierRecords\Currencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

/**
 * The one-time (editable) contract setup per project — client, consultant,
 * contract sum/currency/dates. Warehouse itself has no such fields (see the
 * plan's architecture notes), so this is a sibling table keyed 1:1 on
 * warehouse_id, created the first time a project gets a certificate.
 */
class ProjectCertificateContractController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function edit(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->with(['client:id,name', 'consultant:id,name', 'attachments', 'addendums', 'clients.client:id,name'])->first();
        $certificates = ProjectCertificate::where('warehouse_id', $warehouse->id)->with('warehouse:id,code')->orderByDesc('certificate_number')->get([
            'id', 'warehouse_id', 'certificate_number', 'reference', 'status', 'net_payment_amount', 'certificate_date', 'version', 'currency',
        ])->map(fn (ProjectCertificate $c) => [
            'id' => $c->id,
            'reference' => $c->reference,
            'certificate_number' => $c->certificate_number,
            'status' => $c->status,
            'net_payment_amount' => (float) $c->net_payment_amount,
            // .toDateString(), not the raw Carbon-cast attribute — that
            // serializes as a full UTC ISO datetime (e.g.
            // "2026-07-01T21:00:00.000000Z" for a date that's 2026-07-02
            // in the app's own timezone), which this list was rendering
            // to the page completely unformatted.
            'certificate_date' => optional($c->certificate_date)->toDateString(),
            'version' => $c->version,
            'version_letter' => $c->version > 1 ? $c->versionLetter() : null,
            // The certificate's OWN currency (a certificate can be issued
            // in a different currency than its contract — effectiveCurrency()
            // falls back to $contract->currency, already loaded above,
            // rather than lazy-loading the relation per row), not the
            // contract's — a USD certificate's net payment must never be
            // labeled with the contract's TZS.
            'currency' => $c->currency ?: ($contract->currency ?? 'TZS'),
        ]);

        $contractData = $contract?->toArray();
        $addendumStats = ['total_contract_sum' => 0, 'total_addendum_sum' => 0, 'total_project_amount' => 0];
        if ($contract) {
            // toArray()'s default date-cast serialization is a full UTC
            // ISO datetime (e.g. "2026-02-27T21:00:00.000000Z" for a date
            // that's 2026-03-01 in the app's own Africa/Dar_es_Salaam
            // timezone) — bound straight into an <input type="date">, the
            // browser rejects that as invalid and leaves the field blank,
            // but the STALE string stays sitting in the form's JS state
            // (v-model never fires a change event on a rejected value) and
            // gets submitted verbatim on the next save. Carbon then
            // re-derives the date from that UTC instant without converting
            // back to local time first, silently shifting the stored date
            // back a day. Reformatted here, once, so the frontend never
            // sees anything but a plain local Y-m-d.
            foreach (['contract_start_date', 'contract_end_date'] as $field) {
                if (! empty($contractData[$field])) {
                    $contractData[$field] = $contract->$field->toDateString();
                }
            }

            $contractData['attachments'] = $contract->attachments->map(fn (Attachment $a) => [
                'id' => $a->id,
                'name' => $a->title ?: $a->filename,
                'download_url' => route('project-certificates.contract.attachments.download', [$warehouse->id, $a->id]),
                'delete_url' => route('project-certificates.contract.attachments.destroy', [$warehouse->id, $a->id]),
            ])->values();

            // Overrides the raw eager-loaded relation array toArray()
            // already put here — this adds each row's client name and its
            // computed amount_in_tzs (a method, not a real attribute).
            $contractData['clients'] = $contract->clients->map(fn (ProjectCertificateContractClient $c) => [
                'id' => $c->id,
                'client_contact_id' => $c->client_contact_id,
                'client_name' => $c->client?->name,
                'currency' => $c->currency,
                'amount' => (float) $c->amount,
                'exchange_rate' => $c->exchange_rate !== null ? (float) $c->exchange_rate : null,
                'amount_in_tzs' => $c->amountInTzs(),
            ])->values();

            $contractData['addendums'] = $contract->addendums->map(fn (\App\Models\ProjectCertificateContractAddendum $a) => [
                'id' => $a->id,
                'addendum_number' => $a->addendum_number,
                'title' => $a->title,
                'addendum_date' => optional($a->addendum_date)->toDateString(),
                'status' => $a->status,
                'total_amount' => (float) $a->total_amount,
            ])->values();

            // Only approved addendums count toward the official Total
            // Project Amount — draft/pending ones are proposals, not yet a
            // binding variation to the contract.
            //
            // "Total Contract Sum" uses the STORED revised_contract_sum, not
            // a fresh original + contract_variation_amount add — that raw
            // sum is only the VAT-exclusive figure when vat_included is
            // off; when vat_included is on it's what the user typed as a
            // VAT-INCLUSIVE number (see update()'s comment above), so
            // re-adding it here would mix a VAT-inclusive contract base
            // with addendum item totals that carry no VAT treatment of
            // their own — the same "certificate math excludes VAT"
            // convention the rest of this module already follows, so
            // revised_contract_sum (already backed out to VAT-exclusive on
            // every save) is the only figure that's safe to add addendums
            // to here.
            $approvedSum = round((float) $contract->addendums->where('status', \App\Models\ProjectCertificateContractAddendum::STATUS_APPROVED)->sum('total_amount'), 2);
            $totalContractSum = (float) $contract->revised_contract_sum;
            $addendumStats = [
                'total_contract_sum' => $totalContractSum,
                'total_addendum_sum' => $approvedSum,
                'total_project_amount' => round($totalContractSum + $approvedSum, 2),
            ];
        }

        return Inertia::render('ProjectCertificates/ContractForm', [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $contractData,
            'certificates' => $certificates,
            'clients' => Contact::where('category', 'Clients')->orderBy('name')->get(['id', 'name']),
            'consultants' => Contact::where('category', 'Consultants')->orderBy('name')->get(['id', 'name']),
            'currencies' => Currencies::all(),
            'defaultCurrency' => get_settings('currency_code') ?: 'TZS',
            'defaultContractorName' => 'RANS COMPANY LIMITED',
            'addendumStats' => $addendumStats,
            'canCreateAddendum' => $request->user()->can('manage-contract-project-certificates'),
        ]);
    }

    /**
     * Powers the inline "Add Client"/"Add Consultant" modal on the contract
     * form — creates a real Contact (same table the rest of WIMS already
     * uses for Suppliers/Consultants/etc.) without navigating away, and
     * returns it so the calling dropdown can select it immediately.
     */
    public function quickStoreContact(Request $request)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:50|unique:contacts,name',
            'category' => ['required', Rule::in(['Clients', 'Consultants'])],
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:190',
        ]);

        $contact = Contact::create($data);

        log_activity(__(':category contact added from Project Certificates.', ['category' => $data['category']]), $data, $contact, 'Contact');

        return response()->json(['id' => $contact->id, 'name' => $contact->name]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $data = $request->validate([
            'consultant_contact_id' => 'nullable|exists:contacts,id',
            'contractor_name' => 'nullable|string|max:190',
            'contract_reference' => 'nullable|string|max:190',
            'vat_included' => 'required|boolean',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'vat_exempted' => 'nullable|boolean',
            'contract_variation_amount' => 'nullable|numeric',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date|after_or_equal:contract_start_date',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240|mimes:' . attachment_exts('jpg,jpeg,png,pdf,doc,docx,xlsx'),
            // A contract can now be co-funded by more than one client, each
            // paying in their own currency — see the migration and
            // ProjectCertificateContractClient's own docblock.
            'clients' => 'required|array|min:1',
            'clients.*.client_contact_id' => 'required|exists:contacts,id',
            'clients.*.currency' => 'required|string|max:10',
            'clients.*.amount' => 'required|numeric|min:0',
            'clients.*.exchange_rate' => 'nullable|numeric|min:0.000001',
        ]);

        // Every non-TZS client needs an agreed rate to convert into the
        // TZS Original Contract Sum below — no silent 0/1 fallback for
        // real money.
        foreach ($data['clients'] as $i => $c) {
            if (strtoupper($c['currency']) !== 'TZS' && empty($c['exchange_rate'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "clients.{$i}.exchange_rate" => __('Enter the agreed exchange rate for this client\'s currency.'),
                ]);
            }
        }

        $data['vat_rate'] = $data['vat_rate'] ?? 18.00;
        $data['vat_exempted'] = (bool) ($data['vat_exempted'] ?? false);

        // Original Contract Sum is now derived — every client's
        // contribution converted to TZS (1:1 when already TZS) and
        // summed. The contract's own currency locks to TZS to match, so
        // Revised Sum/VAT/addendum totals/dashboards downstream never
        // mislabel this aggregate with a single client's currency.
        $originalContractSum = round(collect($data['clients'])->sum(
            fn ($c) => strtoupper($c['currency']) === 'TZS' ? (float) $c['amount'] : round((float) $c['amount'] * (float) $c['exchange_rate'], 2)
        ), 2);
        $data['currency'] = 'TZS';
        $data['client_contact_id'] = $data['clients'][0]['client_contact_id'];
        $data['original_contract_sum'] = $originalContractSum;

        // Original + Variation, already VAT-inclusive when `vat_included`
        // is on, VAT-exclusive otherwise. The stored revised_contract_sum
        // is always the VAT-exclusive figure, since that's what the
        // certificate calculation engine and every downstream report/
        // dashboard treat as "the" contract value. vat_rate itself is left
        // as typed either way (not zeroed when exempted) so a QS toggling
        // "VAT Exempted" off later gets their rate back — every formula
        // reads it through this 0%-when-exempted override instead.
        $effectiveVatRate = $data['vat_exempted'] ? 0.0 : (float) $data['vat_rate'];
        $sumAsEntered = round($originalContractSum + (float) ($data['contract_variation_amount'] ?? 0), 2);
        $data['revised_contract_sum'] = $data['vat_included']
            ? round($sumAsEntered / (1 + $effectiveVatRate / 100), 2)
            : $sumAsEntered;

        // Once a certificate on this project has left Draft, its
        // calculations may already be relied on by a reviewer/approver/the
        // client — the base contract sum must stop moving for free at that
        // point. Further genuine variations go through a formal Addendum
        // instead (see ProjectCertificateContractAddendumController),
        // exactly as this app's own "addendum-adjusted Total Project
        // Amount" convention already implies. Metadata (names, dates,
        // reference) stays freely editable regardless.
        $existingContract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->first();
        if ($existingContract && $existingContract->certificates()->where('status', '!=', ProjectCertificate::STATUS_DRAFT)->exists()) {
            $financialSumChanged = abs((float) $existingContract->revised_contract_sum - (float) $data['revised_contract_sum']) > 0.01;
            abort_if($financialSumChanged, 422, 'This project already has a non-draft certificate, the contract sum can no longer be changed directly. Record further variations through an Addendum instead.');
        }

        // Neither `attachments` nor `clients` is a real column on the
        // contract itself — mass-assignment guards ($fillable) already
        // ignore them, but they're removed explicitly so the intent at
        // the updateOrCreate() call site below is obvious.
        $clientsInput = $data['clients'];
        unset($data['attachments'], $data['clients']);

        $contract = DB::transaction(function () use ($warehouse, $data, $clientsInput, $request) {
            $contract = ProjectCertificateContract::updateOrCreate(
                ['warehouse_id' => $warehouse->id],
                $data + ['created_by' => $request->user()->id]
            );

            // Full replace-all-clients-per-save, same rationale as
            // ProjectCertificateContractAddendumController::syncItems() —
            // a contract's client list is small and always submitted whole.
            $contract->clients()->delete();
            foreach (array_values($clientsInput) as $i => $c) {
                ProjectCertificateContractClient::create([
                    'contract_id' => $contract->id,
                    'client_contact_id' => $c['client_contact_id'],
                    'currency' => strtoupper($c['currency']),
                    'amount' => round((float) $c['amount'], 2),
                    'exchange_rate' => strtoupper($c['currency']) === 'TZS' ? 1 : round((float) $c['exchange_rate'], 6),
                    'sort_order' => $i,
                ]);
            }

            return $contract;
        });

        if ($request->hasFile('attachments')) {
            $contract->saveAttachments($request->file('attachments'), 'contract');
        }

        log_activity(
            __('Project Certificate contract details saved.'),
            ['warehouse' => $warehouse->name, 'currency' => $contract->currency, 'revised_contract_sum' => $contract->revised_contract_sum],
            $contract,
            'Project Certificate'
        );

        \App\Services\CertificateNotifier::contract('contract_saved', $warehouse, null, $request->user(), __('Project Contract Saved'), $warehouse->name . ': contract details saved by ' . $request->user()->name . '.');

        return redirect()->route('project-certificates.project.index', $warehouse->id)->with('message', __('Contract details saved.'));
    }

    /**
     * A whole-page snapshot PDF — everything shown on the Contract Setup
     * screen (contract terms, VAT, the addendum register with its VAT
     * excl./incl. totals, and the project's certificate list), not just
     * one addendum. Mirrors edit()'s own aggregation logic so the figures
     * on the printed page always match what's on screen.
     */
    public function print(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->pdfPayload($request, $warehouse);
        log_activity('Project Contract Setup Printed', ['warehouse' => $warehouse->name], $data['contract'], 'Project Certificate');

        return view('reports.project-certificate-contract', $data + ['autoPrint' => true]);
    }

    public function download(Request $request, Warehouse $warehouse)
    {
        abort_unless($request->user()->can('export-project-certificates'), 403);

        $data = $this->pdfPayload($request, $warehouse);
        log_activity('Project Contract Setup Downloaded', ['warehouse' => $warehouse->name], $data['contract'], 'Project Certificate');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.project-certificate-contract', $data)
            ->setPaper('a4', 'portrait')
            ->download('contract-setup-' . $warehouse->code . '.pdf');
    }

    protected function pdfPayload(Request $request, Warehouse $warehouse): array
    {
        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)
            ->with(['client:id,name', 'consultant:id,name', 'addendums', 'clients.client:id,name'])
            ->first();
        abort_unless($contract, 422, 'Set up this project\'s contract details before printing.');

        $certificates = ProjectCertificate::where('warehouse_id', $warehouse->id)
            ->orderByDesc('certificate_number')
            ->get(['id', 'warehouse_id', 'certificate_number', 'reference', 'status', 'net_payment_amount', 'certificate_date', 'version', 'currency'])
            ->map(fn (ProjectCertificate $c) => [
                'reference' => $c->reference,
                'certificate_number' => $c->certificate_number,
                'status' => $c->status,
                'net_payment_amount' => (float) $c->net_payment_amount,
                'certificate_date' => optional($c->certificate_date)->toDateString(),
                'version_letter' => $c->version > 1 ? $c->versionLetter() : null,
                'currency' => $c->currency ?: $contract->currency,
            ]);

        // Same aggregation as edit() — approved addendums only, added onto
        // the already-VAT-exclusive revised_contract_sum. See that method's
        // own comment for why revised_contract_sum (not a fresh original +
        // variation add) is the only figure safe to add addendums to.
        $approvedSum = round((float) $contract->addendums->where('status', \App\Models\ProjectCertificateContractAddendum::STATUS_APPROVED)->sum('total_amount'), 2);
        $totalContractSum = (float) $contract->revised_contract_sum;
        $totalProjectAmount = round($totalContractSum + $approvedSum, 2);

        // 0% whenever exempted, regardless of whatever rate is stored —
        // mirrors ContractForm.vue's own effectiveVatRate computed.
        $effectiveVatRate = $contract->vat_exempted ? 0.0 : (float) $contract->vat_rate;
        $vatInclusive = fn (float $v) => round($v * (1 + $effectiveVatRate / 100), 2);

        return [
            'warehouse' => $warehouse->only(['id', 'name', 'code']),
            'contract' => $contract,
            'certificates' => $certificates,
            'addendumStats' => [
                'total_contract_sum' => $totalContractSum,
                'total_addendum_sum' => $approvedSum,
                'total_project_amount' => $totalProjectAmount,
                'total_contract_sum_inclusive' => $vatInclusive($totalContractSum),
                'total_addendum_sum_inclusive' => $vatInclusive($approvedSum),
                'total_project_amount_inclusive' => $vatInclusive($totalProjectAmount),
            ],
            'effectiveVatRate' => $effectiveVatRate,
            'companyName' => optional($request->user()->account)->name,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
        ];
    }

    public function downloadAttachment(Request $request, Warehouse $warehouse, Attachment $attachment)
    {
        abort_unless($request->user()->can('view-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->firstOrFail();
        abort_unless((int) $attachment->model_id === $contract->id && $attachment->model_type === ProjectCertificateContract::class, 404);

        $name = $attachment->title ?: $attachment->filename;

        return Storage::disk($attachment->disk)->download($attachment->filepath, $name);
    }

    public function destroyAttachment(Request $request, Warehouse $warehouse, Attachment $attachment)
    {
        abort_unless($request->user()->can('manage-contract-project-certificates'), 403);

        $contract = ProjectCertificateContract::where('warehouse_id', $warehouse->id)->firstOrFail();
        abort_unless((int) $attachment->model_id === $contract->id && $attachment->model_type === ProjectCertificateContract::class, 404);

        Storage::disk($attachment->disk)->delete($attachment->filepath);
        $attachment->delete();

        log_activity(__('Contract attachment removed.'), ['warehouse' => $warehouse->name, 'filename' => $attachment->filename], $contract, 'Project Certificate');

        return back()->with('message', __('Attachment removed.'));
    }
}
