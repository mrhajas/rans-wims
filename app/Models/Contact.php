<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;

class Contact extends Model
{
    use Notifiable;

    public const CATEGORIES = [
        'Engineers', 'Technicians', 'Suppliers', 'Foreman', 'SubContractors', 'Workers', 'General',
        'Drivers', 'Dispatchers', 'Movers', 'Logistics', 'Consultants', 'Architects', 'Surveyors', 'Security', 'Equipment Rental',
        'Clients', 'Loading & UnLoading', 'External Personnel',
    ];

    /** Contacts in this category hold stock in our warehouses (External Stock module). */
    public const EXTERNAL_CATEGORY = 'External Personnel';

    /** Contacts in this category are the Loading & Unloading workers (Transport Routes > Loading & Unloading attendance). */
    public const LOADING_CATEGORY = 'Loading & UnLoading';

    /**
     * Every category a contact can be put in: the built-in list plus any category someone typed into the Category box
     * before (they are kept on the contacts themselves, so a new one simply shows up here from the next visit).
     */
    public static function categoryOptions(): array
    {
        $used = static::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all();
        $known = array_map('mb_strtolower', self::CATEGORIES);
        $extra = array_values(array_filter($used, fn ($c) => ! in_array(mb_strtolower($c), $known, true)));
        $extra = array_values(array_unique($extra));
        sort($extra, SORT_NATURAL | SORT_FLAG_CASE);

        return array_merge(self::CATEGORIES, $extra);
    }

    /** A typed category is tidied (spaces) and, when it matches an existing one ignoring case, takes that spelling: no "drivers" next to "Drivers". */
    public static function normalizeCategory(?string $category): ?string
    {
        $category = trim(preg_replace('/\s+/u', ' ', (string) $category));
        if ($category === '') {
            return null;
        }
        foreach (static::categoryOptions() as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($category)) {
                return $existing;
            }
        }

        return $category;
    }

    public function checkins()
    {
        return $this->hasMany(Checkin::class);
    }

    public function checkouts()
    {
        return $this->hasMany(Checkout::class);
    }

    /**
     * A Contact in the "Suppliers" category is the Supplier entity across
     * the whole Supplier Records module (Quotations, Purchase Orders,
     * Supplier Invoices, Payment Requests, Payments, Ledger entries,
     * Reconciliations — all seven reference contacts.id with
     * restrictOnDelete()). Without this guard, del()/delP() would either
     * silently soft-delete a supplier still referenced by live financial
     * records (orphaning every supplierName() lookup across those pages) or
     * hit the DB's own restrictOnDelete() constraint as a raw, unhandled
     * QueryException instead of the friendly "can not be deleted" message
     * every other guarded delete in this app gives.
     */
    protected function hasSupplierRecordsHistory(): bool
    {
        return \App\Models\Quotation::where('supplier_id', $this->id)->exists()
            || \App\Models\PurchaseOrder::where('supplier_id', $this->id)->exists()
            || \App\Models\SupplierInvoice::where('supplier_id', $this->id)->exists()
            || \App\Models\PaymentRequest::where('supplier_id', $this->id)->exists()
            || \App\Models\Payment::where('supplier_id', $this->id)->exists()
            || \App\Models\SupplierLedgerEntry::where('supplier_id', $this->id)->exists()
            || \App\Models\SupplierReconciliation::where('supplier_id', $this->id)->exists();
    }

    public function del()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists() || $this->hasSupplierRecordsHistory()) {
            return false;
        }

        return $this->delete();
    }

    public function delP()
    {
        if ($this->checkins()->exists() || $this->checkouts()->exists() || $this->hasSupplierRecordsHistory()) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () {
                log_activity(__choice('delete_text', ['record' => 'Contact']), $this, $this, 'Contact');

                return $this->forceDelete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return false; // still referenced (e.g. by a deleted document)
            }

            throw $e;
        }
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['trashed'] ?? null, fn ($q, $t) => $q->{$t . 'Trashed'}())
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->search($search));
    }

    public function scopeSearch($query, $s)
    {
        $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%"));
    }
}
