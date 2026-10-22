<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Visitor;
use App\Services\FeatureManager;

/**
 * The records that belong to a contact, by its category, for the contact's edit page: a visitor has visiting logs (no Payment Vouchers), a ticket or visa agent has an agent statement,
 * a subcontractor has a statement, loading and unloading staff have their attendance, a driver has the history of their trips, External Personnel have their stock statement.
 * A panel is offered only to people who may open it and only while its module is switched on.
 */
class ContactPanels
{
    /** @return list<array{key:string,title:string,about:string,icon:string,tone:string,links:list<array{label:string,url:string}>}> */
    public static function for(Contact $c, $user): array
    {
        $cat = mb_strtolower(trim((string) $c->category));
        $can = fn (string $perm, string $feature = null) => $user->can($perm) && ($feature === null || FeatureManager::canAccess($feature, $user));
        $panels = [];
        $panel = function (string $key, string $title, string $about, string $icon, string $tone, array $links) use (&$panels) {
            $links = array_values(array_filter($links));
            if ($links) {
                $panels[] = compact('key', 'title', 'about', 'icon', 'tone', 'links');
            }
        };
        $link = fn (string $label, string $route, array $params = [], ?bool $ok = true) => $ok ? ['label' => $label, 'url' => route($route, $params)] : null;
        $showPayments = true;

        if ($cat === 'visitors') {
            $showPayments = false;
            $visitor = Visitor::query()->where('contact_id', $c->id)->first();
            $panel('visiting', __('Visiting Logs'), __('The visits of this person to the premises, who they came to see, and the items they sent or brought to reception.'), 'users', 'sky', [
                $visitor ? $link(__('Open the visitor record and previous visits'), 'visitor-logs.visitors.show', ['visitor' => $visitor->id], $can('read-visitors', 'visitor_directory')) : null,
                $link(__('Visits in the Visitor Register'), 'visitor-logs.visits.index', ['search' => $c->name], $can('read-visitor-logs', 'visitor_register')),
                $link(__('Incoming Mail & Deliveries from this person'), 'visitor-logs.items.index', ['search' => $c->name], $can('read-incoming-items', 'visitor_incoming')),
            ]);
        } elseif (in_array($cat, ['ticket agent', 'visa agent'], true)) {
            $panel('agent', __('Agent Statement and History'), __('What this agent is owed, the travels they issued, the payments made and the running statement.'), 'plane', 'indigo', [
                $link(__('Open the statement and history'), 'travels.agents.show', ['contact' => $c->id], $can('read-travel-agents', 'travel_agents')),
            ]);
        } elseif (in_array($cat, ['subcontractors', 'subcontractor'], true)) {
            $panel('subcontract', __('Subcontractor Statement'), __('The agreements, claims and payments of this subcontractor, and their statement.'), 'doc-text', 'amber', [
                $link(__('Open the statement'), 'subcontracts.statements.contact', ['contact' => $c->id], $can('read-subcontracts', 'subcontracts')),
                $link(__('Open the register'), 'subcontracts.register.show', ['contact' => $c->id], $can('read-subcontracts', 'subcontracts')),
            ]);
        } elseif ($cat === 'loading & unloading') {
            $panel('loading', __('Loading & Unloading Attendance'), __('The days and trips this person worked, what they earned and what was paid.'), 'collection', 'teal', [
                $link(__('Open the attendance'), 'transport-loading.show', ['contact' => $c->id], $can('read-transport-loading', 'transport_management')),
            ]);
        } elseif ($cat === 'drivers') {
            $panel('driver', __('Driver History and Statement'), __('Every transport route this driver drove, with dates, vehicles and status.'), 'truck', 'sky', [
                $link(__('Open the trip history'), 'transport-records.index', ['driver_contact_id' => $c->id], $can('read-transport-records', 'transport_management')),
                $link(__('Driver statement (report)'), 'transport-reports.index', ['report_type' => 'records', 'driver_contact_id' => $c->id], $can('read-transport-records', 'transport_management')),
            ]);
        } elseif ($cat === 'external personnel') {
            $showPayments = false;
            $panel('external', __('External Stock Statement'), __('The stock this person holds in our warehouses, and every approved movement.'), 'truck', 'amber', [
                $link(__('Open the stock statement'), 'external-stock.reports.index', ['type' => 'statement', 'contact_id' => $c->id], $can('read-external-stock', 'external_stock')),
                $link(__('Movements of this person'), 'external-stock.index', ['contact_id' => $c->id], $can('read-external-stock', 'external_stock')),
            ]);
        } elseif ($cat === 'suppliers') {
            $panel('supplier', __('Supplier Statement'), __('Invoices, payments and the running balance of this supplier.'), 'cash', 'rose', [
                $link(__('Open the statement'), 'supplier-records.suppliers.statement', ['supplier' => $c->id], $can('read-supplier-records', 'supplier_records')),
                $link(__('Open the supplier record'), 'supplier-records.suppliers.show', ['supplier' => $c->id], $can('read-supplier-records', 'supplier_records')),
            ]);
        }
        if ($showPayments && $can('read-payment-vouchers')) {
            $panel('vouchers', __('Payment Vouchers'), __('Payment vouchers made out to this contact.'), 'doc-text', 'pink', [$link(__('View'), 'payment-vouchers.index', ['payee_contact_id' => $c->id])]);
        }

        return $panels;
    }
}
