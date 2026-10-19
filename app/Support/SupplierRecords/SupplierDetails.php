<?php

namespace App\Support\SupplierRecords;

use App\Models\Contact;

/**
 * Supplier-only financial/bank fields, stored in the *real*,
 * already-provisioned `extra_attributes` schemaless bag every Contact row
 * already has (App\Models\Model::getExtraAttributesAttribute()) — no new
 * column needed for these fields specifically. Only meaningful for Contacts
 * tagged category = "Suppliers"; every other Supplier Records entity
 * (Purchase Orders, Invoices, Payments, ...) has its own real table — see
 * app/Models/{PurchaseOrder,SupplierInvoice,Payment,...}.php.
 */
class SupplierDetails
{
    private const KEY = 'supplier_details';

    private const DEFAULTS = [
        'tax_id' => null,
        'payment_terms_days' => 30,
        'currency' => 'TZS',
        'credit_limit' => 0,
        'opening_balance' => 0,
        'bank_name' => null,
        'bank_account' => null,
        // Purchase/sales tax rate (e.g. VAT) — defaults the "Tax %" field
        // when creating a Purchase Order or Invoice for this supplier,
        // since the rate varies by supplier/country. Still just a default:
        // the PO's own "Include Tax" toggle decides whether it's applied at
        // all, and the percentage can be overridden per order.
        'tax_percentage' => 18,
        // Tax varies by supplier/country, not by transaction — a default
        // rate here is applied when Finance records a Retention or
        // Withholding Tax ledger entry against this supplier, and can still
        // be overridden per entry.
        'withholding_tax_percentage' => 0,
        'retention_percentage' => 0,
    ];

    public static function get(Contact $contact): array
    {
        $stored = $contact->extra_attributes->get(self::KEY, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function save(Contact $contact, array $attributes): array
    {
        $data = array_merge(self::get($contact), array_intersect_key($attributes, self::DEFAULTS));

        // SchemalessAttributes has no save() of its own — the established
        // pattern in this codebase (see RauncherController::updateShortcut)
        // is to read/mutate it as a plain array via the base Model's
        // `extra_attributes` array cast, then persist with a normal save().
        $extra = $contact->extra_attributes->toArray();
        $extra[self::KEY] = $data;
        $contact->extra_attributes = $extra;
        $contact->save();

        return $data;
    }
}
