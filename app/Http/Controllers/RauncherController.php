<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Warehouse;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Adjustment;
use App\Models\Transfer;
use App\Models\MaterialRequest;
use App\Models\Contact;
use App\Models\Category;
use App\Models\Unit;
use App\Models\DailySiteReport;
use App\Models\User;

class RauncherController extends Controller
{
    private const PER_CATEGORY_LIMIT = 5;

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:190',
            'category' => 'nullable|string|max:50',
        ]);

        $term = trim($data['q'] ?? '');
        if ($term === '') {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $registry = $this->registry();

        // An explicit category/query search (e.g. "warehouses/GD1") targets one known
        // category. If the viewer can't read it, say so plainly instead of quietly
        // returning zero results (which reads as "no matches" rather than "no access").
        if (! empty($data['category'])) {
            if (! isset($registry[$data['category']])) {
                return response()->json(['results' => []]);
            }

            $entry = $registry[$data['category']];
            if (! $this->allowed($user, $entry['permission'])) {
                return response()->json([
                    'results' => [],
                    'message' => "You don't have permission to search {$entry['label']}.",
                ]);
            }

            return response()->json(['results' => $this->searchCategory($data['category'], $entry, $term)]);
        }

        $results = [];
        foreach ($registry as $key => $entry) {
            if (! $this->allowed($user, $entry['permission'])) {
                continue;
            }

            $results = array_merge($results, $this->searchCategory($key, $entry, $term));
        }

        return response()->json(['results' => $results]);
    }

    /** A registry entry's 'permission' is one permission name or a list of alternatives (any one is enough). */
    private function allowed($user, string|array $permission): bool
    {
        return collect((array) $permission)->contains(fn ($name) => $user->can($name));
    }

    private function searchCategory(string $key, array $entry, string $term): array
    {
        $matches = $entry['search']($term)->limit(self::PER_CATEGORY_LIMIT)->get();

        return $matches->map(fn ($model) => [
            'category' => $key,
            'label' => $entry['label'],
            'icon' => $entry['icon'],
            'title' => $entry['title']($model),
            'subtitle' => $entry['subtitle']($model),
            'url' => isset($entry['url']) ? $entry['url']($model) : route($entry['route'], $model->id),
        ])->all();
    }

    public function updateShortcut(Request $request)
    {
        $data = $request->validate([
            'ctrl' => 'required|boolean',
            'meta' => 'required|boolean',
            'shift' => 'required|boolean',
            'alt' => 'required|boolean',
            'key' => 'required|string|max:20',
        ]);

        abort_unless($data['ctrl'] || $data['meta'] || $data['shift'] || $data['alt'], 422, 'Shortcut must include at least one modifier key.');

        $user = $request->user();
        $extra = $user->extra_attributes ?? [];
        $extra['launcher_shortcut'] = $data;
        $user->extra_attributes = $extra;
        $user->save();

        return response()->json(['shortcut' => $data]);
    }

    private function registry(): array
    {
        return [
            // WIMSChat: only messages of conversations the person belongs to (same join the chat uses), and only if WIMSChat is on.
            'wimschat' => [
                'label' => 'WIMSChat messages',
                'icon' => 'chat',
                'permission' => 'search-wimschat',
                'url' => fn ($m) => route('wimschat.index', ['c' => $m->conversation_id]),
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

                    return \App\Models\WimsChatMessage::query()->select('wimschat_messages.*')
                        ->join('wimschat_members as mem', fn ($j) => $j->on('mem.conversation_id', '=', 'wimschat_messages.conversation_id')->where('mem.user_id', '=', $u->id)->whereNull('mem.left_at'))
                        ->whereColumn('wimschat_messages.created_at', '>=', 'mem.joined_at')->where('wimschat_messages.type', 'text')
                        ->where('wimschat_messages.body', 'like', $like)
                        ->when(! \App\Services\FeatureManager::canAccess('wimschat', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('user:id,name')->orderByDesc('wimschat_messages.id');
                },
                'title' => fn ($m) => \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $m->body), 70),
                'subtitle' => fn ($m) => optional($m->user)->name,
            ],
            // Site Meetings: meetings the person may see (their sites, or invited), only while the module is on.
            'site-meetings' => [
                'label' => 'Site Meetings',
                'icon' => 'calendar',
                'permission' => 'read-site-meetings',
                'url' => fn ($m) => route('site-meetings.show', $m->id),
                'search' => function ($term) {
                    $u = auth()->user();

                    return \App\Models\SiteMeeting::query()->visibleTo($u)->filter(['search' => $term])
                        ->when(! \App\Services\FeatureManager::canAccess('site_meetings', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('warehouse:id,name')->orderByDesc('meeting_date');
                },
                'title' => fn ($m) => $m->reference.' - '.$m->display_title,
                'subtitle' => fn ($m) => trim(optional($m->warehouse)->name.' · '.$m->meeting_date->format('d M Y')),
            ],
            // Transport Routes: trips by number / destination / vehicle, only while the module is on.
            'transport-records' => [
                'label' => 'Transport Routes Records',
                'icon' => 'truck',
                'permission' => 'read-transport-records',
                'route' => 'transport-records.show',
                'search' => fn ($term) => \App\Models\TransportRecord::query()
                    ->filter(['search' => $term])
                    ->when(! \App\Services\FeatureManager::canAccess('transport_management', auth()->user()), fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderByDesc('trip_date'),
                'title' => fn ($m) => $m->trip_number.($m->destination_label ? ' - '.$m->destination_label : ''),
                'subtitle' => fn ($m) => trim(optional($m->trip_date)->format('d M Y').' · '.number_format((float) $m->amount, 2)),
            ],
            // Loading & Unloading personnel (contacts of that category, or with history): opens their ledger.
            'loading-personnel' => [
                'label' => 'Loading & Unloading Personnel',
                'icon' => 'users',
                'permission' => 'read-transport-loading',
                'url' => fn ($m) => route('transport-loading.show', $m->id),
                'search' => fn ($term) => Contact::query()
                    ->whereIn('id', \App\Services\Transport\LoadingLedger::workerIds() ?: [0])
                    ->where(function ($q) use ($term) {
                        $q->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%");
                    })
                    ->when(! \App\Services\FeatureManager::canAccess('transport_management', auth()->user()), fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderBy('name'),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => 'Balance '.number_format(\App\Services\Transport\LoadingLedger::balanceOf($m->id), 2),
            ],
            'items' => [
                'label' => 'Items',
                'icon' => 'bag',
                'permission' => 'read-items',
                'route' => 'items.show',
                'search' => fn ($term) => Item::query()->search($term),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->code,
            ],
            'warehouses' => [
                'label' => 'Projects / Warehouses',
                'icon' => 'building',
                'permission' => 'read-warehouses',
                'url' => fn ($m) => route('reports.warehousestock', ['warehouseId' => $m->id]),
                'search' => fn ($term) => Warehouse::query()->visible()->where(function ($q) use ($term) {
                    $q->where('code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                }),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->code,
            ],
            'checkins' => [
                'label' => 'Checkins',
                'icon' => 'in',
                'permission' => 'read-checkins',
                'route' => 'checkins.show',
                'search' => fn ($term) => Checkin::query()->search($term),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'subtitle' => fn ($m) => optional($m->date)->format('Y-m-d'),
            ],
            'checkouts' => [
                'label' => 'Checkouts',
                'icon' => 'out',
                'permission' => 'read-checkouts',
                'route' => 'checkouts.show',
                'search' => fn ($term) => Checkout::query()->search($term),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'subtitle' => fn ($m) => optional($m->date)->format('Y-m-d'),
            ],
            'adjustments' => [
                'label' => 'Adjustments',
                'icon' => 'adjustments',
                'permission' => 'read-adjustments',
                'route' => 'adjustments.show',
                'search' => fn ($term) => Adjustment::query()->search($term),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'subtitle' => fn ($m) => optional($m->date)->format('Y-m-d'),
            ],
            'transfers' => [
                'label' => 'Transfers',
                'icon' => 'refresh',
                'permission' => 'read-transfers',
                'route' => 'transfers.show',
                'search' => fn ($term) => Transfer::query()->search($term),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'subtitle' => fn ($m) => optional($m->date)->format('Y-m-d'),
            ],
            // Subcontractor Management: agreements, variations and payment requests the person may see, only while the module is on. No amounts in the result line.
            'subcontract-agreements' => [
                'label' => 'Subcontract Agreements',
                'icon' => 'doc-text',
                'permission' => 'read-subcontract-agreements',
                'route' => 'subcontracts.agreements.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    return \App\Models\SubcontractAgreement::query()->visibleTo($u)
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('title', 'like', $like)->orWhere('site', 'like', $like)->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)))
                        ->when(! \App\Services\FeatureManager::canAccess('subcontract_agreements', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('contact:id,name')->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . $m->title,
                'subtitle' => fn ($m) => trim(optional($m->contact)->name . ' · ' . $m->status),
            ],
            'subcontract-requests' => [
                'label' => 'Subcontract Payment Requests',
                'icon' => 'send',
                'permission' => 'read-subcontract-requests',
                'route' => 'subcontracts.requests.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    return \App\Models\SubcontractPaymentRequest::query()->whereIn('agreement_id', \App\Models\SubcontractAgreement::query()->visibleTo($u)->select('id'))
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('description', 'like', $like)->orWhereHas('agreement', fn ($a) => $a->where('title', 'like', $like)->orWhere('reference', 'like', $like)))
                        ->when(! \App\Services\FeatureManager::canAccess('subcontract_requests', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('agreement:id,reference,title')->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . optional($m->agreement)->title,
                'subtitle' => fn ($m) => trim(optional($m->agreement)->reference . ' · ' . $m->status),
            ],
            'subcontract-claims' => [
                'label' => 'Contract Payment Requests',
                'icon' => 'collection',
                'permission' => 'read-subcontract-claims',
                'route' => 'subcontracts.claims.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    return \App\Models\SubcontractClaim::query()
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('description', 'like', $like)->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)))
                        ->where(fn ($q) => $u->hasRole('Super Admin') || $u->view_all ? $q : $q->where('requested_by_user_id', $u->id)->orWhereHas('lines', fn ($l) => $l->whereIn('agreement_id', \App\Models\SubcontractAgreement::query()->visibleTo($u)->select('id'))))
                        ->when(! \App\Services\FeatureManager::canAccess('subcontract_claims', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('contact:id,name')->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . optional($m->contact)->name,
                'subtitle' => fn ($m) => trim($m->status . ' · ' . $m->currency),
            ],
            'subcontract-variations' => [
                'label' => 'Subcontract Variations',
                'icon' => 'adjustments',
                'permission' => 'read-subcontract-variations',
                'route' => 'subcontracts.variations.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    return \App\Models\SubcontractVariation::query()->whereIn('agreement_id', \App\Models\SubcontractAgreement::query()->visibleTo($u)->select('id'))
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('title', 'like', $like))
                        ->when(! \App\Services\FeatureManager::canAccess('subcontract_variations', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('agreement:id,reference')->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . $m->title,
                'subtitle' => fn ($m) => trim(optional($m->agreement)->reference . ' · ' . $m->status),
            ],
            // External Stock: movements the person may see (their warehouses), only while the module is on.
            'external-stock' => [
                'label' => 'External Stock',
                'icon' => 'truck',
                'permission' => 'read-external-stock',
                'route' => 'external-stock.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

                    return \App\Models\ExternalStockMovement::query()->visibleTo($u)
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $like)))
                        ->when(! \App\Services\FeatureManager::canAccess('external_stock', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->with('contact:id,name')->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . optional($m->contact)->name,
                'subtitle' => fn ($m) => trim(__($m->kindLabel()) . ' · ' . $m->status),
            ],
            // Accounts Tracking: transactions of the accounts the person may see, only while the module is on.
            'acct-transactions' => [
                'label' => 'Account Transactions',
                'icon' => 'cash',
                'permission' => 'read-acct-transactions',
                'route' => 'acct.transactions.show',
                'search' => function ($term) {
                    $u = auth()->user();
                    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
                    $ids = $u->can('view-all-acct-accounts') ? null : \App\Models\Accounts\AcctAccount::query()->visibleTo((int) $u->id)->pluck('id')->all();

                    return \App\Models\Accounts\AcctTransaction::query()
                        ->when($ids !== null, fn ($q) => $q->whereIn('account_id', $ids ?: [0]))
                        ->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('description', 'like', $like)->orWhere('counterparty_name', 'like', $like)->orWhere('voucher_no', 'like', $like))
                        ->when(! \App\Services\FeatureManager::canAccess('accounts_tracking', $u), fn ($q) => $q->whereRaw('1 = 0'))
                        ->orderByDesc('id');
                },
                'title' => fn ($m) => $m->reference . ' - ' . \Illuminate\Support\Str::limit((string) ($m->description ?: $m->counterparty_name), 50),
                'subtitle' => fn ($m) => trim(optional($m->txn_date)->format('d M Y') . ' · ' . (\App\Models\Accounts\AcctTransaction::STATUS[$m->status] ?? $m->status)),
            ],
            'material-requests' => [
                'label' => 'Material Requests',
                'icon' => 'clip',
                'permission' => ['read-material-requests', 'view-all-material-requests'],
                'route' => 'material-requests.show',
                'search' => fn ($term) => \App\Support\MaterialRequestVisibility::scopeQueryFor(MaterialRequest::query(), auth()->user())->search($term),
                'title' => fn ($m) => $m->reference ?: ('#' . $m->id),
                'subtitle' => fn ($m) => $m->status,
            ],
            'contacts' => [
                'label' => 'Contacts',
                'icon' => 'users',
                'permission' => 'read-contacts',
                'route' => 'contacts.edit',
                'search' => fn ($term) => Contact::query()->search($term),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->category,
            ],
            'categories' => [
                'label' => 'Categories',
                'icon' => 'folder',
                'permission' => 'read-categories',
                'route' => 'categories.edit',
                'search' => fn ($term) => Category::query()->search($term),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->code,
            ],
            'units' => [
                'label' => 'Units',
                'icon' => 'table',
                'permission' => 'read-units',
                'route' => 'units.edit',
                'search' => fn ($term) => Unit::query()->search($term),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->code,
            ],
            'daily-site-reports' => [
                'label' => 'Daily Site Reports',
                'icon' => 'doc-text',
                'permission' => 'read-daily-site-reports',
                'route' => 'daily-site-reports.edit',
                'search' => fn ($term) => DailySiteReport::query()->search($term),
                'title' => fn ($m) => $m->report_number ?: ('#' . $m->id),
                'subtitle' => fn ($m) => optional($m->report_date)->format('Y-m-d'),
            ],
            'users' => [
                'label' => 'Users',
                'icon' => 'user',
                'permission' => 'read-users',
                'route' => 'users.edit',
                'search' => fn ($term) => User::query()->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%");
                }),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->email,
            ],
            // Suppliers are real Contact rows (category "Suppliers") — searched
            // directly so "suppliers/acme" returns a distinct, targeted group
            // instead of relying on the generic "contacts" category below.
            'suppliers' => [
                'label' => 'Suppliers',
                'icon' => 'truck',
                'permission' => 'read-supplier-records',
                'route' => 'supplier-records.suppliers.show',
                'search' => fn ($term) => Contact::query()->where('category', 'Suppliers')->search($term),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->phone ?: $m->email,
            ],
            'purchase-orders' => [
                'label' => 'Purchase Orders',
                'icon' => 'truck',
                'permission' => 'read-supplier-records',
                'route' => 'supplier-records.purchase-orders.show',
                'search' => fn ($term) => \App\Models\PurchaseOrder::query()->search($term),
                'title' => fn ($m) => $m->reference,
                'subtitle' => fn ($m) => $m->status,
            ],
            'supplier-invoices' => [
                'label' => 'Supplier Invoices',
                'icon' => 'doc-text',
                'permission' => 'read-supplier-records',
                'route' => 'supplier-records.supplier-invoices.show',
                'search' => fn ($term) => \App\Models\SupplierInvoice::query()->search($term),
                'title' => fn ($m) => $m->reference,
                'subtitle' => fn ($m) => $m->status,
            ],

            'payment-vouchers' => [
                'label' => 'Payment Vouchers',
                'icon' => 'doc-text',
                'permission' => 'read-payment-vouchers',
                'route' => 'payment-vouchers.show',
                'search' => fn ($term) => \App\Models\PaymentVoucher::query()->where(function ($q) use ($term) {
                    $q->where('pv_number', 'like', "%{$term}%")
                        ->orWhere('payee_name', 'like', "%{$term}%");
                }),
                'title' => fn ($m) => $m->pv_number,
                'subtitle' => fn ($m) => $m->payee_name,
            ],
            'payment-voucher-batches' => [
                'label' => 'Payment Voucher Batches',
                'icon' => 'folder',
                'permission' => 'read-payment-vouchers',
                'route' => 'payment-vouchers.batches.show',
                'search' => fn ($term) => \App\Models\PaymentVoucherBatch::query()->where(function ($q) use ($term) {
                    $q->where('batch_number', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%");
                }),
                'title' => fn ($m) => $m->batch_number,
                'subtitle' => fn ($m) => $m->type,
            ],
            'staffs' => [
                'label' => 'RANS Staffs',
                'icon' => 'users',
                'permission' => 'read-staffs',
                'route' => 'staffs.show',
                'search' => fn ($term) => \App\Models\Staff::query()->where(function ($q) use ($term) {
                    $q->where('staff_number', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('mobile_no', 'like', "%{$term}%");
                }),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->staff_number,
            ],
            'assets' => [
                'label' => 'Assets',
                'icon' => 'chip',
                'permission' => 'read-assets',
                'route' => 'assets.show',
                'search' => fn ($term) => \App\Models\Asset::query()->inAssetRegister()->filter(['search' => $term]),
                'title' => fn ($m) => $m->name,
                'subtitle' => fn ($m) => $m->asset_number,
            ],
            'compliance-records' => [
                'label' => 'Compliance',
                'icon' => 'clip',
                'permission' => 'read-compliance-records',
                'route' => 'compliance-records.show',
                'search' => fn ($term) => \App\Models\ComplianceRecord::query()->filter(['search' => $term]),
                'title' => fn ($m) => $m->title,
                'subtitle' => fn ($m) => $m->compliance_number,
            ],
        ];
    }
}
