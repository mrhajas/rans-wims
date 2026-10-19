<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Checkin;
use App\Models\Contact;
use App\Models\ExternalStockMovement;
use App\Models\GatePass;
use App\Models\Transfer;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class GatePassController extends Controller
{
    public function __construct()
    {
        // Every action is gated explicitly below (the route-name-derived ability would otherwise
        // map generate/print onto the parent module's permissions).
        $this->setAbilities([]);
    }

    /** What the Transfer/Checkin pages need to render the Generate button or the existing pass's link. */
    public static function stateFor($record, string $sourceType): array
    {
        $pass = GatePass::with(['driver:id,name,phone', 'asset'])->where('source_type', $sourceType)->where('source_id', $record->id)->first();

        return self::buildState($record, $sourceType, $pass);
    }

    /** Same state for every record on a list page, using a single query (keyed by record id). */
    public static function statesFor($records, string $sourceType): array
    {
        $records = collect($records);
        $passes = GatePass::with(['driver:id,name,phone', 'asset'])->where('source_type', $sourceType)->whereIn('source_id', $records->pluck('id'))->get()->keyBy('source_id');

        return $records->mapWithKeys(fn ($record) => [$record->id => self::buildState($record, $sourceType, $passes->get($record->id))])->all();
    }

    private static function buildState($record, string $sourceType, ?GatePass $pass): array
    {
        $user = auth()->user();
        $canCreate = $user->can('create-gate-passes');

        return [
            'note' => $pass && $user->can('read-gate-passes')
                ? ['id' => $pass->id, 'reference' => $pass->reference, 'url' => route('gate-passes.show', $pass->id)]
                : null,
            'exists' => (bool) $pass,
            'can_generate' => ! $pass && ! $record->draft && $canCreate,
            'can_edit' => (bool) $pass && $canCreate,
            'generate_url' => match ($sourceType) {
                GatePass::SOURCE_TRANSFER => route('transfers.gate-pass.store', $record->id),
                GatePass::SOURCE_EXTERNAL => route('external-stock.gate-pass.store', $record->id),
                default => route('checkins.gate-pass.store', $record->id),
            },
            'update_url' => $pass ? route('gate-passes.update', $pass->id) : null,
            'options_url' => route('gate-passes.options'),
            'record_reference' => $record->reference,
            // an External Stock movement already knows its driver and vehicle: the pass starts from them
            'prefill' => ! $pass && $record instanceof ExternalStockMovement ? [
                'driver_contact_id' => $record->driver_contact_id, 'driver_name' => $record->driver_name, 'driver_phone' => $record->driver_phone,
                'asset_id' => $record->asset_id, 'vehicle_plate' => $record->vehicle_plate, 'vehicle_details' => $record->vehicle_details,
            ] : null,
            'details' => $pass ? [
                'driver_contact_id' => $pass->driver_contact_id,
                'driver_name' => $pass->driver_name,
                'driver_phone' => $pass->driver_phone,
                'asset_id' => $pass->asset_id,
                'vehicle_plate' => $pass->vehicle_plate,
                'vehicle_details' => $pass->vehicle_details,
                'driver_label' => trim(($pass->driverDisplay()['name'] ?? '') . ' ' . ($pass->driverDisplay()['phone'] ? '(' . $pass->driverDisplay()['phone'] . ')' : '')),
                'vehicle_label' => trim(($pass->vehicleDisplay()['plate'] ?? '') . ' ' . ($pass->vehicleDisplay()['details'] ?? '')),
            ] : null,
        ];
    }

    /** Drivers (Contacts in the "Drivers" category) and vehicles (Assets) — the same lists the Transport module uses. */
    public function options()
    {
        abort_unless(auth()->user()->can('create-gate-passes'), 403);

        $drivers = Contact::where('category', 'Drivers')->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : ''), 'name' => $c->name, 'phone' => $c->phone])
            ->values();

        $vehicles = Asset::vehicles()->orderBy('name')
            ->get(['id', 'name', 'registration_number', 'manufacturer', 'model', 'year', 'color', 'operating_personnel_contact_id'])
            ->map(function ($a) {
                $details = collect([$a->manufacturer, $a->model, $a->year, $a->color])->filter()->implode(' ');

                return [
                    'id' => $a->id,
                    'label' => trim(($a->registration_number ?: $a->name) . ($details ? ' - ' . $details : ($a->registration_number ? ' - ' . $a->name : ''))),
                    'default_driver_id' => $a->operating_personnel_contact_id,
                ];
            })->values();

        return response()->json(['drivers' => $drivers, 'vehicles' => $vehicles]);
    }

    public function storeForTransfer(Request $request, Transfer $transfer)
    {
        if (in_array($transfer->approval_status, [\App\Models\Transfer::APPROVAL_PENDING, \App\Models\Transfer::APPROVAL_CANCELLED], true)) {
            return back()->with('error', __('This transfer has not been approved yet, so no paperwork can be issued for it.'));
        }

        return $this->generate($request, $transfer, GatePass::SOURCE_TRANSFER, 'Transfer');
    }

    public function storeForCheckin(Request $request, Checkin $checkin)
    {
        return $this->generate($request, $checkin, GatePass::SOURCE_CHECKIN, 'Check-in');
    }

    public function storeForExternalStock(Request $request, ExternalStockMovement $movement)
    {
        if ($movement->status !== ExternalStockMovement::APPROVED) {
            return back()->with('error', __('This movement has not been approved yet, so no paperwork can be issued for it.'));
        }

        return $this->generate($request, $movement, GatePass::SOURCE_EXTERNAL, 'External Stock');
    }

    private function generate(Request $request, $record, string $sourceType, string $label)
    {
        $this->authorize('create-gate-passes');

        if ($record->draft) {
            return back()->with('error', __('A Gate Pass can only be generated once the :record is no longer a draft.', ['record' => $label]));
        }

        $pass = GatePass::where('source_type', $sourceType)->where('source_id', $record->id)->first();

        if ($pass) {
            return back()->with('message', __('A Gate Pass already exists for this record (:ref).', ['ref' => $pass->reference]));
        }

        $data = $this->validated($request);

        $pass = ReferenceLock::run('gate_passes', function () use ($record, $sourceType, $data) {
            return GatePass::create($data + [
                'reference' => GatePass::nextReference(),
                'source_type' => $sourceType,
                'source_id' => $record->id,
                'created_by_user_id' => auth()->id(),
            ]);
        });

        $pass->load(['driver', 'asset']);
        $summary = ['gate_pass' => $pass->reference, 'driver' => $pass->driverDisplay()['name'], 'vehicle' => $pass->vehicleDisplay()['plate']];

        log_activity('Gate Pass ' . $pass->reference . ' generated from ' . $label . ' ' . $record->reference, $summary + ['source_type' => $sourceType, 'source_reference' => $record->reference], $pass, 'Gate Pass');
        log_activity('Gate Pass ' . $pass->reference . ' generated', $summary, $record, $label);
        \App\Services\PaperworkNotifier::fire('gate_pass', $record, $pass->reference);

        return back()->with('message', __('Gate Pass :ref generated.', ['ref' => $pass->reference]));
    }

    /** Change the driver / vehicle on an existing pass. */
    public function update(Request $request, GatePass $gate_pass)
    {
        $this->authorize('create-gate-passes');

        $data = $this->validated($request);
        $gate_pass->update($data);
        $gate_pass->load(['driver', 'asset']);

        log_activity(
            'Gate Pass ' . $gate_pass->reference . ' driver / vehicle updated',
            ['gate_pass' => $gate_pass->reference, 'driver' => $gate_pass->driverDisplay()['name'], 'vehicle' => $gate_pass->vehicleDisplay()['plate']],
            $gate_pass,
            'Gate Pass'
        );

        return back()->with('message', __('Gate Pass :ref updated.', ['ref' => $gate_pass->reference]));
    }

    /**
     * Driver and vehicle are each either a registered one (picked from the list) or typed in. A registered
     * choice wins and the typed fields are cleared, so a pass never carries two conflicting answers.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'driver_contact_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value && ! Contact::where('id', $value)->where('category', 'Drivers')->exists()) {
                    $fail(__('Choose a driver from the list.'));
                }
            }],
            'driver_name' => ['nullable', 'string', 'max:190', 'required_without:driver_contact_id'],
            'driver_phone' => ['nullable', 'string', 'max:60'],
            'asset_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value && ! Asset::vehicles()->whereKey($value)->exists()) {
                    $fail(__('Choose a vehicle from the list.'));
                }
            }],
            'vehicle_plate' => ['nullable', 'string', 'max:60', 'required_without:asset_id'],
            'vehicle_details' => ['nullable', 'string', 'max:190'],
        ], [
            'driver_name.required_without' => __('Select the assigned driver, or type the driver\'s name.'),
            'vehicle_plate.required_without' => __('Select the vehicle, or type its registration number.'),
        ]);

        $clean = fn ($v) => ($v = is_string($v) ? trim($v) : $v) === '' ? null : $v;

        $driverId = $data['driver_contact_id'] ?? null;
        $assetId = $data['asset_id'] ?? null;

        return [
            'driver_contact_id' => $driverId,
            'driver_name' => $driverId ? null : $clean($data['driver_name'] ?? null),
            'driver_phone' => $driverId ? null : $clean($data['driver_phone'] ?? null),
            'asset_id' => $assetId,
            'vehicle_plate' => $assetId ? null : $clean($data['vehicle_plate'] ?? null),
            'vehicle_details' => $assetId ? null : $clean($data['vehicle_details'] ?? null),
        ];
    }

    public function show(GatePass $gate_pass)
    {
        $this->authorize('read-gate-passes');

        return view('gate-passes.document', $this->documentData($gate_pass, false) + ['pdfUrl' => route('gate-passes.pdf', $gate_pass->id)]);
    }

    public function print(GatePass $gate_pass)
    {
        $this->authorize('print-gate-passes');

        return view('gate-passes.document', $this->documentData($gate_pass, false) + [
            'pdfUrl' => route('gate-passes.pdf', $gate_pass->id),
            'autoPrint' => true,
        ]);
    }

    public function pdf(GatePass $gate_pass)
    {
        $this->authorize('print-gate-passes');

        return Pdf::loadView('gate-passes.document', $this->documentData($gate_pass, true))
            ->setPaper('a4')
            ->download('gate-pass-' . $gate_pass->reference . '.pdf');
    }

    private function documentData(GatePass $pass, bool $isPdf): array
    {
        $parent = $pass->parent();
        abort_unless($parent, 404, __('The parent record of this Gate Pass no longer exists.'));

        if ($pass->source_type === GatePass::SOURCE_TRANSFER) {
            $parent->load(['items.item:id,code,name', 'items.unit:id,code,name', 'fromWarehouse', 'toWarehouse', 'user:id,name', 'receivedBy:id,name']);
        } else {
            $parent->load(['items.item:id,code,name', 'items.unit:id,code,name', 'contact', 'warehouse', 'user:id,name']);
        }
        $pass->load(['createdBy:id,name', 'driver', 'asset']);

        return [
            'doc' => PrintDocuments::gatePass($pass, $parent),
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('gate-passes.show', $pass->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('gate_pass', $pass),
        ];
    }
}
