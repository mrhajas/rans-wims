<?php

namespace App\Console\Commands;

use App\Models\Checkin;
use App\Models\CheckinItem;
use App\Models\Checkout;
use App\Models\CheckoutItem;
use App\Models\Stock;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sample Transfers, one Check-out and one Check-in saved as DRAFTS, to try the Draft / Final switch and the "remove the draft status and approve" prompt:
 *  - DRAFT-SMP-TX-1  draft from a main warehouse, every item in stock: the prompt offers to remove the draft and approve it
 *  - DRAFT-SMP-TX-2  draft from a main warehouse, one item asks for far more than the warehouse holds: the approval is blocked and the item is shown to fix
 *  - DRAFT-SMP-TX-3  draft from a project warehouse (no approval needed): the Draft / Final switch only
 *  - AWAIT-SMP-TX-4  NOT a draft: saved as final from a main warehouse and waiting for approval (the normal approve button; WIMS keeps it without stock effect until approved)
 * Nothing is stocked or moved, nobody is told. Everything is marked SAMPLE so --fresh removes exactly these.
 */
class DraftSamplesTransfers extends Command
{
    protected $signature = 'draft-samples:transfers {--fresh : Remove the previous samples first}';

    protected $description = 'Add sample draft Transfers (valid and failing), a Check-out and a Check-in to try Draft/Final and approving a draft';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->remove();
        }
        $by = User::role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        if (! $by) {
            $this->error('No Super Admin found.');

            return self::FAILURE;
        }
        $main = Warehouse::withoutGlobalScopes()->mainWarehouses()->whereNull('deleted_at')->orderBy('id')->get();
        $projects = Warehouse::withoutGlobalScopes()->whereNull('deleted_at')->where('status', '!=', 'Main Warehouse')->orderBy('id')->get();
        // a main warehouse that really holds a few items
        $from = $main->first(fn ($w) => Stock::withoutGlobalScopes()->where('warehouse_id', $w->id)->where('quantity', '>', 50)->count() >= 3);
        if (! $from || $projects->count() < 2) {
            $this->error('Need a main warehouse with stock and two project warehouses.');

            return self::FAILURE;
        }
        $stock = Stock::withoutGlobalScopes()->with('item')->where('warehouse_id', $from->id)->where('quantity', '>', 50)->whereNotNull('item_id')->orderByDesc('quantity')->limit(12)->get()
            ->filter(fn ($s) => $s->item && ! $s->variation_id)->values();
        if ($stock->count() < 4) {
            $this->error('The main warehouse holds too few plain items to build the samples.');

            return self::FAILURE;
        }

        $contact = \App\Models\Contact::withoutGlobalScopes()->orderBy('id')->value('id');
        $made = [];
        $make = function (string $ref, Warehouse $src, Warehouse $dst, bool $draft, string $approval, array $lines, string $note) use ($by, &$made) {
            if (Transfer::withoutGlobalScopes()->withTrashed()->where('reference', $ref)->exists()) {
                return;
            }
            $t = Transfer::create([
                'date' => now()->subDays(2)->format('Y-m-d'), 'reference' => $ref, 'draft' => $draft, 'approval_status' => $approval,
                'from_warehouse_id' => $src->id, 'to_warehouse_id' => $dst->id, 'user_id' => $by->id, 'account_id' => $by->account_id,
                'details' => self::MARK . ': ' . $note,
            ]);
            foreach ($lines as [$item, $qty]) {
                TransferItem::create(['transfer_id' => $t->id, 'item_id' => $item->id, 'quantity' => $qty, 'unit_id' => $item->unit_id, 'account_id' => $by->account_id, 'draft' => $draft, 'from_warehouse_id' => $src->id, 'to_warehouse_id' => $dst->id]);
            }
            $made[] = $ref;
        };

        $make('DRAFT-SMP-TX-1', $from, $projects[0], true, Transfer::APPROVAL_NOT_REQUIRED, [[$stock[0]->item, 2], [$stock[1]->item, 5], [$stock[2]->item, 3]], 'draft from a main warehouse, ready to approve');
        $make('DRAFT-SMP-TX-2', $from, $projects[1], true, Transfer::APPROVAL_NOT_REQUIRED, [[$stock[0]->item, 2], [$stock[1]->item, 9999999], [$stock[2]->item, 4]], 'draft from a main warehouse, one item asks for more than the warehouse holds');
        $make('DRAFT-SMP-TX-3', $projects[0], $projects[1], true, Transfer::APPROVAL_NOT_REQUIRED, [[$stock[3]->item, 1]], 'draft from a project warehouse');
        $make('AWAIT-SMP-TX-4', $from, $projects[0], true, Transfer::APPROVAL_PENDING, [[$stock[1]->item, 2], [$stock[3]->item, 2]], 'final from a main warehouse, waiting for approval');

        // a Check-out and a Check-in saved as drafts
        if (! Checkout::withoutGlobalScopes()->withTrashed()->where('reference', 'DRAFT-SMP-CO-1')->exists()) {
            $co = Checkout::create(['date' => now()->format('Y-m-d'), 'reference' => 'DRAFT-SMP-CO-1', 'draft' => true, 'contact_id' => $contact, 'warehouse_id' => $projects[0]->id, 'user_id' => $by->id, 'account_id' => $by->account_id, 'details' => self::MARK . ': check-out saved as a draft']);
            CheckoutItem::create(['checkout_id' => $co->id, 'item_id' => $stock[0]->item->id, 'quantity' => 1, 'unit_id' => $stock[0]->item->unit_id, 'account_id' => $by->account_id, 'draft' => true, 'warehouse_id' => $projects[0]->id]);
            $made[] = 'DRAFT-SMP-CO-1';
        }
        if (! Checkin::withoutGlobalScopes()->withTrashed()->where('reference', 'DRAFT-SMP-CI-1')->exists()) {
            $ci = Checkin::create(['date' => now()->format('Y-m-d'), 'reference' => 'DRAFT-SMP-CI-1', 'draft' => true, 'contact_id' => $contact, 'warehouse_id' => $projects[0]->id, 'user_id' => $by->id, 'account_id' => $by->account_id, 'details' => self::MARK . ': check-in saved as a draft']);
            CheckinItem::create(['checkin_id' => $ci->id, 'item_id' => $stock[0]->item->id, 'quantity' => 1, 'unit_id' => $stock[0]->item->unit_id, 'account_id' => $by->account_id, 'draft' => true, 'warehouse_id' => $projects[0]->id]);
            $made[] = 'DRAFT-SMP-CI-1';
        }

        $this->info($made ? 'Added: ' . implode(', ', $made) : 'The samples are already there (use --fresh to rebuild them).');

        return self::SUCCESS;
    }

    private function remove(): void
    {
        DB::transaction(function () {
            foreach (Transfer::withoutGlobalScopes()->withTrashed()->where(fn ($q) => $q->where('reference', 'like', 'DRAFT-SMP-TX-%')->orWhere('reference', 'like', 'AWAIT-SMP-TX-%')->orWhere('reference', 'like', 'APPROVED-SMP-TX-%'))->get() as $t) {
                TransferItem::withoutGlobalScopes()->withTrashed()->where('transfer_id', $t->id)->forceDelete();
                $t->forceDelete();
            }
            foreach (Checkout::withoutGlobalScopes()->withTrashed()->where('reference', 'like', 'DRAFT-SMP-CO-%')->get() as $c) {
                CheckoutItem::withoutGlobalScopes()->withTrashed()->where('checkout_id', $c->id)->forceDelete();
                $c->forceDelete();
            }
            foreach (Checkin::withoutGlobalScopes()->withTrashed()->where('reference', 'like', 'DRAFT-SMP-CI-%')->get() as $c) {
                CheckinItem::withoutGlobalScopes()->withTrashed()->where('checkin_id', $c->id)->forceDelete();
                $c->forceDelete();
            }
        });
        $this->line('Previous draft samples removed.');
    }
}
