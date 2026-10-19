<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\DeliveryNote;
use App\Models\ExternalStockMovement;
use App\Models\Transfer;
use App\Support\PrintDocuments;
use App\Support\PrintQrCode;
use App\Support\ReferenceLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    public function __construct()
    {
        // Every action is gated explicitly below (the route-name-derived ability
        // would otherwise map generate/print onto the parent module's permissions).
        $this->setAbilities([]);
    }

    /** What the Transfer/Checkin pages need to render the Generate button or the existing note's link. */
    public static function stateFor($record, string $sourceType): array
    {
        $note = DeliveryNote::where('source_type', $sourceType)->where('source_id', $record->id)->first();

        return self::buildState($record, $sourceType, $note);
    }

    /** Same state for every record on a list page, using a single query (keyed by record id). */
    public static function statesFor($records, string $sourceType): array
    {
        $records = collect($records);
        $notes = DeliveryNote::where('source_type', $sourceType)->whereIn('source_id', $records->pluck('id'))->get()->keyBy('source_id');

        return $records->mapWithKeys(fn ($record) => [$record->id => self::buildState($record, $sourceType, $notes->get($record->id))])->all();
    }

    private static function buildState($record, string $sourceType, ?DeliveryNote $note): array
    {
        $user = auth()->user();

        return [
            'note' => $note && $user->can('read-delivery-notes')
                ? ['id' => $note->id, 'reference' => $note->reference, 'url' => route('delivery-notes.show', $note->id)]
                : null,
            'exists' => (bool) $note,
            'can_generate' => ! $note && ! $record->draft && $user->can('create-delivery-notes'),
            'generate_url' => match ($sourceType) {
                DeliveryNote::SOURCE_TRANSFER => route('transfers.delivery-note.store', $record->id),
                DeliveryNote::SOURCE_EXTERNAL => route('external-stock.delivery-note.store', $record->id),
                default => route('checkins.delivery-note.store', $record->id),
            },
        ];
    }

    public function storeForTransfer(Request $request, Transfer $transfer)
    {
        if (in_array($transfer->approval_status, [\App\Models\Transfer::APPROVAL_PENDING, \App\Models\Transfer::APPROVAL_CANCELLED], true)) {
            return back()->with('error', __('This transfer has not been approved yet, so no paperwork can be issued for it.'));
        }

        return $this->generate($transfer, DeliveryNote::SOURCE_TRANSFER, 'Transfer');
    }

    public function storeForExternalStock(Request $request, ExternalStockMovement $movement)
    {
        if ($movement->status !== ExternalStockMovement::APPROVED) {
            return back()->with('error', __('This movement has not been approved yet, so no paperwork can be issued for it.'));
        }

        return $this->generate($movement, DeliveryNote::SOURCE_EXTERNAL, 'External Stock');
    }

    public function storeForCheckin(Request $request, Checkin $checkin)
    {
        return $this->generate($checkin, DeliveryNote::SOURCE_CHECKIN, 'Check-in');
    }

    private function generate($record, string $sourceType, string $label)
    {
        $this->authorize('create-delivery-notes');

        if ($record->draft) {
            return back()->with('error', __('A Delivery Note can only be generated once the :record is no longer a draft.', ['record' => $label]));
        }

        $note = DeliveryNote::where('source_type', $sourceType)->where('source_id', $record->id)->first();
        $created = false;

        if (! $note) {
            $note = ReferenceLock::run('delivery_notes', function () use ($record, $sourceType) {
                return DeliveryNote::create([
                    'reference' => DeliveryNote::nextReference(),
                    'source_type' => $sourceType,
                    'source_id' => $record->id,
                    'created_by_user_id' => auth()->id(),
                ]);
            });
            $created = true;

            log_activity(
                'Delivery Note ' . $note->reference . ' generated from ' . $label . ' ' . $record->reference,
                ['delivery_note' => $note->reference, 'source_type' => $sourceType, 'source_reference' => $record->reference],
                $note,
                'Delivery Note'
            );
            log_activity('Delivery Note ' . $note->reference . ' generated', ['delivery_note' => $note->reference], $record, $label);
            \App\Services\PaperworkNotifier::fire('delivery_note', $record, $note->reference);
        }

        return back()->with('message', $created
            ? __('Delivery Note :ref generated.', ['ref' => $note->reference])
            : __('A Delivery Note already exists for this record (:ref).', ['ref' => $note->reference]));
    }

    public function show(DeliveryNote $delivery_note)
    {
        $this->authorize('read-delivery-notes');

        return view('delivery-notes.document', $this->documentData($delivery_note, false) + ['pdfUrl' => route('delivery-notes.pdf', $delivery_note->id)]);
    }

    public function print(DeliveryNote $delivery_note)
    {
        $this->authorize('print-delivery-notes');

        return view('delivery-notes.document', $this->documentData($delivery_note, false) + [
            'pdfUrl' => route('delivery-notes.pdf', $delivery_note->id),
            'autoPrint' => true,
        ]);
    }

    public function pdf(DeliveryNote $delivery_note)
    {
        $this->authorize('print-delivery-notes');

        return Pdf::loadView('delivery-notes.document', $this->documentData($delivery_note, true))
            ->setPaper('a4')
            ->download('delivery-note-' . $delivery_note->reference . '.pdf');
    }

    private function documentData(DeliveryNote $note, bool $isPdf): array
    {
        $parent = $note->parent();
        abort_unless($parent, 404, __('The parent record of this Delivery Note no longer exists.'));

        if ($note->source_type === DeliveryNote::SOURCE_TRANSFER) {
            $parent->load(['items.item:id,code,name', 'items.unit:id,code,name', 'fromWarehouse', 'toWarehouse', 'user:id,name', 'receivedBy:id,name']);
        } else {
            $parent->load(['items.item:id,code,name', 'items.unit:id,code,name', 'contact', 'warehouse', 'user:id,name']);
        }
        $note->load('createdBy:id,name');

        return [
            'doc' => PrintDocuments::deliveryNote($note, $parent),
            'companyName' => optional(auth()->user()->account)->name,
            'qr' => PrintQrCode::dataUri(route('delivery-notes.show', $note->id)),
            'isPdf' => $isPdf,
            'signatures' => app(\App\Services\Signatories\SignatureResolver::class)->resolve('delivery_note', $note),
        ];
    }
}
