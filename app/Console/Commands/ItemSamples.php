<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Support\ReferenceLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sample consumable items for review (and for trying "Convert to Used" on the Items page). Named "SAMPLE ..." so they are easy to find; --remove takes them away. */
class ItemSamples extends Command
{
    protected $signature = 'items:samples {--remove : remove the sample items again}';

    protected $description = 'Make (or remove) clearly marked sample consumable items';

    private const MARK = 'Sample item for review.';

    private const ROWS = [
        ['SAMPLE CEMENT 50KG BAG', 'Bag', 'Painting', 18500, 40], ['SAMPLE STEEL BAR Y12 12M', 'Pcs', 'Electrical', 32000, 100], ['SAMPLE PVC PIPE 4 INCH 6M', 'Pcs', 'Plumbing', 24500, 30],
        ['SAMPLE WHITE EMULSION PAINT 20L', 'Pcs', 'Painting', 145000, 10], ['SAMPLE WALL TILE 30X60 CARTON', 'Box', 'Tiles', 56000, 25], ['SAMPLE ELECTRICAL CABLE 2.5MM ROLL', 'Rolls', 'Electrical', 98000, 8],
        ['SAMPLE SAFETY GLOVES PAIR', 'Pcs', 'Electrical', 3500, 50], ['SAMPLE BINDING WIRE 1KG', 'Pcs', 'Electrical', 3200, 60], ['SAMPLE ANGLE GRINDER DISC 4 INCH', 'Pcs', 'Electrical', 2800, 80], ['SAMPLE NAILS 3 INCH 1KG', 'Pcs', 'Electrical', 4200, 40],
    ];

    public function handle(): int
    {
        $admin = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin found.');

            return self::FAILURE;
        }
        auth()->setUser($admin);
        if ($this->option('remove')) {
            $n = 0;
            Item::withTrashed()->where('details', self::MARK)->get()->each(function ($i) use (&$n) {
                if ($i->delP() !== false) {
                    $n++;
                }
            });
            $this->info("$n sample item(s) removed.");

            return self::SUCCESS;
        }
        if (Item::where('details', self::MARK)->exists()) {
            $this->warn('Sample items already exist. Use --remove first to start again.');

            return self::SUCCESS;
        }
        $made = 0;
        foreach (self::ROWS as [$name, $unit, $cat, $price, $alert]) {
            $unitId = Unit::query()->where('name', $unit)->value('id') ?? Unit::query()->value('id');
            $catId = Category::query()->where('name', $cat)->whereNull('parent_id')->value('id');
            ReferenceLock::run('items', function () use ($name, $unitId, $catId, $price, $alert, &$made) {
                $max = (int) DB::table('items')->selectRaw('MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as m')->where('code', 'like', 'RSI-%')->value('m');
                $item = Item::create(['name' => $name, 'code' => 'RSI-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT), 'symbology' => 'code128', 'price' => $price, 'unit_id' => $unitId, 'alert_quantity' => $alert, 'track_quantity' => 1, 'track_weight' => 0, 'has_serials' => 0, 'has_variants' => 0, 'is_used' => false, 'details' => self::MARK]);
                $item->addVariations()->saveRelations(['category_id' => $catId, 'child_category_id' => null]);
                $made++;
            });
        }
        $this->info("$made sample consumable items created.");

        return self::SUCCESS;
    }
}
