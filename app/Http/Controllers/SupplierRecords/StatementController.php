<?php

namespace App\Http\Controllers\SupplierRecords;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\SupplierInvoice;
use App\Support\SupplierRecords\SupplierDetails;
use Inertia\Inertia;

class StatementController extends Controller
{
    public function show($id)
    {
        $contact = Contact::where('category', 'Suppliers')->findOrFail($id);
        $supplier = array_merge($contact->only(['id', 'name']), SupplierDetails::get($contact));

        $invoices = SupplierInvoice::where('supplier_id', $supplier['id'])->orderBy('invoice_date')->get();
        $payments = Payment::where('supplier_id', $supplier['id'])->orderBy('paid_on')->get();

        $entries = collect()
            ->concat($invoices->map(fn ($i) => [
                'date' => (string) $i->invoice_date,
                'type' => 'Invoice',
                'reference' => $i->reference,
                'debit' => $i->total,
                'credit' => 0,
            ]))
            ->concat($payments->map(fn ($p) => [
                'date' => (string) $p->paid_on,
                'type' => 'Payment',
                'reference' => $p->reference,
                'debit' => 0,
                'credit' => $p->amount,
            ]))
            ->sortBy('date')
            ->values();

        $running = (float) ($supplier['opening_balance'] ?? 0);
        $ledger = $entries->map(function ($entry) use (&$running) {
            $running += $entry['debit'] - $entry['credit'];

            return array_merge($entry, ['balance' => $running]);
        });

        return Inertia::render('SupplierRecords/Statement/Show', [
            'supplier' => $supplier,
            'openingBalance' => (float) ($supplier['opening_balance'] ?? 0),
            'closingBalance' => $running,
            'ledger' => $ledger,
        ]);
    }
}
