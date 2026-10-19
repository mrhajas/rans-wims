<?php

use App\Models\Item;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-time backfill so the item catalog is uniformly uppercase, matching
     * the new Item::setNameAttribute() mutator that uppercases every name
     * going forward (create, update, and import — not just this form).
     */
    public function up(): void
    {
        Item::withTrashed()->withoutGlobalScope('account')->orderBy('id')->chunk(200, function ($items) {
            foreach ($items as $item) {
                $upper = mb_strtoupper($item->name);
                if ($item->name !== $upper) {
                    $item->newQuery()->whereKey($item->id)->update(['name' => $upper]);
                }
            }
        });
    }

    public function down(): void
    {
        // Uppercasing is not reversible (original casing isn't recorded).
    }
};
