<?php

namespace App\Services\Administration;

use App\Models\AdminCharityPerson;
use App\Models\AdminDocument;
use App\Models\AdminDocumentCharity;
use App\Models\AdminDocumentItem;
use App\Models\AdminItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The shared rules of the Administration documents: the template, the charity section pulled from the Charity list, saving the lines (every typed line is remembered
 * as an item so it can be reported on) and duplicating a document to the next week.
 */
class AdminDocumentService
{
    /** The standing lines of the template, in their order. */
    public static function templateLines(): array
    {
        return AdminItem::query()->where('in_template', true)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($i) => ['admin_item_id' => $i->id, 'description' => $i->name, 'beneficiary' => (string) $i->default_beneficiary, 'beneficiary_user_id' => $i->default_beneficiary_user_id, 'category' => $i->category, 'staff_id' => $i->staff_id, 'amount' => (string) $i->default_amount])->values()->all();
    }

    /** The wording of a charity line for a person and a month, from the Administration settings. */
    public static function charityDescription(string $name, Carbon $month): string
    {
        return mb_strtoupper(strtr(AdminDocument::charityWording(), [':name' => $name, ':month' => $month->translatedFormat('F Y')]));
    }

    /** The Charity section as it would be pulled today: every active person of the Charity list with their monthly amount. */
    public static function charityRows(Carbon $month): array
    {
        return AdminCharityPerson::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($p) => ['admin_charity_person_id' => $p->id, 'name' => $p->name, 'description' => self::charityDescription($p->name, $month), 'amount' => (string) $p->monthly_amount, 'monthly_amount' => (string) $p->monthly_amount])->values()->all();
    }

    /** Replaces the lines of a document. A line with no item gets (or makes) the item of its name, so the report by item sees every line. */
    public static function saveLines(AdminDocument $d, array $lines): void
    {
        $d->lines()->forceDelete();
        $n = 0;
        foreach ($lines as $l) {
            $desc = trim(preg_replace('/\s+/', ' ', (string) ($l['description'] ?? '')));
            if ($desc === '') {
                continue;
            }
            $amount = round((float) ($l['amount'] ?? 0), 2);
            $given = trim((string) ($l['category'] ?? '')) ?: null;
            $staffId = ! empty($l['staff_id']) && \App\Models\Staff::query()->whereKey((int) $l['staff_id'])->exists() ? (int) $l['staff_id'] : null;
            $item = ! empty($l['admin_item_id']) ? AdminItem::withTrashed()->find((int) $l['admin_item_id']) : null;
            if (! $item || AdminItem::keyOf($item->name) !== AdminItem::keyOf($desc)) {
                $item = AdminItem::forName($desc, ($l['beneficiary'] ?? null) ?: null, $amount, $given ? mb_substr($given, 0, 60) : null, $staffId);
            }
            // the line keeps the category and the staff member as they were when it was made (what the item says unless the line was given its own)
            $d->lines()->create(['admin_item_id' => $item->id, 'description' => $desc, 'beneficiary' => trim((string) ($l['beneficiary'] ?? '')) ?: $d->beneficiary_name, 'beneficiary_user_id' => $l['beneficiary_user_id'] ?? null,
                'category' => $given ? mb_substr($given, 0, 60) : $item->category, 'staff_id' => $staffId ?: $item->staff_id, 'amount' => $amount, 'sort_order' => ++$n]);
        }
    }

    public static function saveCharity(AdminDocument $d, array $rows): void
    {
        $d->charityLines()->forceDelete();
        $n = 0;
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $desc = trim((string) ($r['description'] ?? '')) ?: $name;
            if ($desc === '') {
                continue;
            }
            $d->charityLines()->create(['admin_charity_person_id' => $r['admin_charity_person_id'] ?? null, 'name' => $name ?: $desc, 'description' => $desc, 'amount' => round((float) ($r['amount'] ?? 0), 2),
                'monthly_amount' => round((float) ($r['monthly_amount'] ?? $r['amount'] ?? 0), 2), 'sort_order' => ++$n]);
        }
    }

    /** Makes the next document with the same lines and charity section, the date moved on one week (never into the past), for the person who asked. */
    public static function duplicate(AdminDocument $src, User $by): AdminDocument
    {
        return DB::transaction(function () use ($src, $by) {
            $date = $src->doc_date->copy()->addWeek();
            if ($date->lt(now()->startOfDay())) {
                $date = now()->startOfDay();
            }
            $d = AdminDocument::create([
                'reference' => AdminDocument::nextReference(), 'status' => AdminDocument::REQUESTED, 'doc_date' => $date->toDateString(), 'department' => $src->department, 'currency' => $src->currency,
                'beneficiary_user_id' => $by->id, 'beneficiary_name' => $by->name, 'include_charity' => $src->include_charity, 'notes' => $src->notes, 'source' => 'duplicate', 'duplicated_from_id' => $src->id, 'user_id' => $by->id,
            ]);
            foreach ($src->lines()->get() as $l) {
                $d->lines()->create(['admin_item_id' => $l->admin_item_id, 'description' => $l->description, 'beneficiary' => $l->beneficiary, 'beneficiary_user_id' => $l->beneficiary_user_id, 'category' => $l->category, 'staff_id' => $l->staff_id, 'amount' => $l->amount, 'sort_order' => $l->sort_order]);
            }
            if ($src->include_charity) {
                foreach ($src->charityLines()->get() as $c) {
                    // the wording follows the new date's month; the amounts stay as they were on the source
                    $desc = $c->admin_charity_person_id ? self::charityDescription($c->name, $date) : $c->description;
                    $d->charityLines()->create(['admin_charity_person_id' => $c->admin_charity_person_id, 'name' => $c->name, 'description' => $desc, 'amount' => $c->amount, 'monthly_amount' => $c->monthly_amount, 'sort_order' => $c->sort_order]);
                }
            }

            return $d->recalculate();
        });
    }
}
