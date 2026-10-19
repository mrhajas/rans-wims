<?php

namespace App\Support;

use App\Models\Adjustment;
use App\Models\Asset;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\ComplianceRecord;
use App\Models\Contact;
use App\Models\IssueTransaction;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\PaymentVoucher;
use App\Models\ProjectCertificate;
use App\Models\Staff;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The whitelist behind Task Manager's "@module (CODE)" checklist mentions
 * (e.g. "@materialrequest (RMR-0086)"). Deliberately opt-in and scoped to
 * real business-record modules a task might reference — never settings,
 * logs, backups, or anything admin-only, so a checklist item can't be used
 * to probe/link into areas it has no business pointing at.
 *
 * Each entry:
 * - match(query, code): exact-match lookup used when resolving a finished
 *   mention (clicking the link).
 * - search(query, term): partial/LIKE lookup used by the "@" autocomplete
 *   while typing.
 * - code(record): the short string inserted into "@module (CODE)".
 * - title(record): a human label shown next to the code in the
 *   autocomplete dropdown so two similar codes are easy to tell apart.
 */
class TaskReferenceRegistry
{
    public static function modules(): array
    {
        return [
            'checkin' => [
                'label' => 'Checkin',
                'icon' => 'in',
                'model' => Checkin::class,
                'route' => 'checkins.show',
                'param' => 'checkin',
                'match' => fn (Builder $q, string $code) => $q->where('reference', $code),
                'search' => fn (Builder $q, string $term) => $q->where('reference', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->reference,
                'title' => fn ($r) => Str::limit((string) $r->details, 40) ?: optional($r->created_at)->format('M j, Y'),
            ],
            'checkout' => [
                'label' => 'Checkout',
                'icon' => 'out',
                'model' => Checkout::class,
                'route' => 'checkouts.show',
                'param' => 'checkout',
                'match' => fn (Builder $q, string $code) => $q->where('reference', $code),
                'search' => fn (Builder $q, string $term) => $q->where('reference', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->reference,
                'title' => fn ($r) => Str::limit((string) $r->details, 40) ?: optional($r->created_at)->format('M j, Y'),
            ],
            'transfer' => [
                'label' => 'Transfer',
                'icon' => 'truck',
                'model' => Transfer::class,
                'route' => 'transfers.show',
                'param' => 'transfer',
                'match' => fn (Builder $q, string $code) => $q->where('reference', $code),
                'search' => fn (Builder $q, string $term) => $q->where('reference', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->reference,
                'title' => fn ($r) => Str::limit((string) $r->details, 40) ?: optional($r->created_at)->format('M j, Y'),
            ],
            'materialrequest' => [
                'label' => 'Material Request',
                'icon' => 'doc-text',
                'model' => MaterialRequest::class,
                'route' => 'material-requests.show',
                'param' => 'material_request',
                'match' => fn (Builder $q, string $code) => $q->where('reference', $code),
                'search' => fn (Builder $q, string $term) => $q->where('reference', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->reference,
                'title' => fn ($r) => Str::limit((string) $r->reason, 40) ?: ucfirst((string) $r->status),
            ],
            'item' => [
                'label' => 'Item',
                'icon' => 'chip',
                'model' => Item::class,
                'route' => 'items.show',
                'param' => 'item',
                'match' => fn (Builder $q, string $code) => $q->where('code', $code)->orWhere('sku', $code),
                'search' => fn (Builder $q, string $term) => $q->where('code', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->code ?: $r->sku,
                'title' => fn ($r) => $r->name,
            ],
            'contact' => [
                'label' => 'Contact',
                'icon' => 'user',
                'model' => Contact::class,
                // Contacts have no "show" page in this app — the global
                // search launcher (RauncherController) links to the edit
                // page for the same reason.
                'route' => 'contacts.edit',
                'param' => 'contact',
                'match' => fn (Builder $q, string $code) => $q->where('name', 'like', "%{$code}%"),
                'search' => fn (Builder $q, string $term) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->name,
                'title' => fn ($r) => $r->category ?: $r->email,
            ],
            'compliance' => [
                'label' => 'Compliance Record',
                'icon' => 'lock-closed',
                'model' => ComplianceRecord::class,
                'route' => 'compliance-records.show',
                'param' => 'compliance_record',
                'match' => fn (Builder $q, string $code) => $q->where('compliance_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('compliance_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->compliance_number,
                'title' => fn ($r) => Str::limit((string) $r->title, 40),
            ],
            'paymentvoucher' => [
                'label' => 'Payment Voucher',
                'icon' => 'cash',
                'model' => PaymentVoucher::class,
                'route' => 'payment-vouchers.show',
                'param' => 'payment_voucher',
                'match' => fn (Builder $q, string $code) => $q->where('pv_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('pv_number', 'like', "%{$term}%")
                    ->orWhere('payee_name', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->pv_number,
                'title' => fn ($r) => $r->payee_name,
            ],
            'certificate' => [
                'label' => 'Certificate',
                'icon' => 'doc',
                'model' => ProjectCertificate::class,
                'route' => 'project-certificates.show',
                'param' => 'certificate',
                // The full "RANS/2026/.../00002" reference is computed at
                // read time (see ProjectCertificate::getReferenceAttribute),
                // not a stored column — match on the raw certificate_number
                // instead, which is what users are expected to type.
                'match' => fn (Builder $q, string $code) => $q->where('certificate_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('certificate_number', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->certificate_number,
                'title' => fn ($r) => Str::limit((string) $r->description, 40) ?: $r->certificate_type,
            ],
            'staff' => [
                'label' => 'Staff',
                'icon' => 'users',
                'model' => Staff::class,
                'route' => 'staffs.show',
                'param' => 'staff',
                'match' => fn (Builder $q, string $code) => $q->where('staff_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('staff_number', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->staff_number,
                'title' => fn ($r) => $r->name,
            ],
            'asset' => [
                'label' => 'Asset',
                'icon' => 'bag',
                'model' => Asset::class,
                'route' => 'assets.show',
                'param' => 'asset',
                'match' => fn (Builder $q, string $code) => $q->where('asset_number', $code)
                    ->orWhere('tag_number', $code)
                    ->orWhere('serial_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('asset_number', 'like', "%{$term}%")
                    ->orWhere('tag_number', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->asset_number ?: ($r->tag_number ?: $r->serial_number),
                'title' => fn ($r) => $r->name,
            ],
            'issueandreturn' => [
                'label' => 'Issue & Return',
                'icon' => 'truck',
                'model' => IssueTransaction::class,
                'route' => 'issue-materials.show',
                'param' => 'issueMaterial',
                'match' => fn (Builder $q, string $code) => $q->where('issue_number', $code),
                'search' => fn (Builder $q, string $term) => $q->where('issue_number', 'like', "%{$term}%")
                    ->orWhere('issued_to', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->issue_number,
                'title' => fn ($r) => $r->issued_to ?: $r->project,
            ],
            'adjustment' => [
                'label' => 'Adjustment',
                'icon' => 'adjustments',
                'model' => Adjustment::class,
                'route' => 'adjustments.show',
                'param' => 'adjustment',
                'match' => fn (Builder $q, string $code) => $q->where('reference', $code),
                'search' => fn (Builder $q, string $term) => $q->where('reference', 'like', "%{$term}%"),
                'code' => fn ($r) => $r->reference,
                'title' => fn ($r) => ucfirst((string) $r->type),
            ],
        ];
    }

    public static function moduleKeys(): array
    {
        return array_keys(self::modules());
    }

    /** Frontend-friendly list for the "@" autocomplete's first step (pick a module). */
    public static function moduleList(): array
    {
        return collect(self::modules())
            ->map(fn ($config, $key) => ['key' => $key, 'label' => $config['label'], 'icon' => $config['icon']])
            ->values()
            ->all();
    }
}
