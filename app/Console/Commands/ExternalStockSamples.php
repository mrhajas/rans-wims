<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Contact;
use App\Models\ExternalStockItem;
use App\Models\ExternalStockMovement;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;

/** Sample External Personnel and movements for review. Marked "SAMPLE" and written straight to the tables: no alerts are sent. --remove takes them away. */
class ExternalStockSamples extends Command
{
    protected $signature = 'external-stock:samples {--remove : remove the sample people and their movements}';

    protected $description = 'Make (or remove) clearly marked sample External Stock records';

    public function handle(): int
    {
        $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin found.');

            return self::FAILURE;
        }
        auth()->setUser($admin);
        $people = Contact::withTrashed()->where('category', Contact::EXTERNAL_CATEGORY)->where('name', 'like', 'SAMPLE %');
        if ($this->option('remove')) {
            $ids = (clone $people)->pluck('id');
            $mids = ExternalStockMovement::withTrashed()->whereIn('contact_id', $ids)->pluck('id');
            ExternalStockItem::withTrashed()->whereIn('movement_id', $mids)->forceDelete();
            ExternalStockMovement::withTrashed()->whereIn('id', $mids)->forceDelete();
            (clone $people)->forceDelete();
            $this->info('Sample External Stock removed.');

            return self::SUCCESS;
        }
        if ((clone $people)->exists()) {
            $this->warn('Sample External Stock already exists. Use --remove first to start again.');

            return self::SUCCESS;
        }
        $wh = Warehouse::ofAccount()->active()->orderBy('id')->limit(2)->get();
        $items = Item::query()->where('has_variants', 0)->where('has_serials', 0)->where('is_used', false)->orderBy('id')->limit(6)->get();
        if ($wh->isEmpty() || $items->count() < 3) {
            $this->error('Needs at least one warehouse and three simple items.');

            return self::FAILURE;
        }
        $driver = Contact::query()->where('category', 'Drivers')->first();
        $veh = Asset::vehicles()->first();
        $make = fn (string $name, string $phone, string $location) => Contact::create(['name' => $name, 'category' => Contact::EXTERNAL_CATEGORY, 'phone' => $phone, 'location' => $location, 'account_id' => $admin->account_id]);
        $a = $make('SAMPLE Juma Builders Ltd', '+255 712 100 200', 'Mikocheni, Dar es Salaam');
        $b = $make('SAMPLE Coastal Tiling Services', '+255 713 300 400', 'Kinondoni, Dar es Salaam');
        $mv = function (string $kind, Contact $c, Warehouse $w, string $date, string $status, array $lines, array $x = []) use ($admin) {
            $m = ExternalStockMovement::create($x + ['reference' => ExternalStockMovement::nextReference(), 'kind' => $kind, 'date' => $date, 'contact_id' => $c->id, 'location' => $c->location, 'warehouse_id' => $w->id, 'status' => $status, 'user_id' => $admin->id,
                'decided_by_user_id' => in_array($status, ['Approved', 'Rejected'], true) ? $admin->id : null, 'decided_at' => in_array($status, ['Approved', 'Rejected'], true) ? now() : null]);
            foreach ($lines as [$item, $qty]) {
                ExternalStockItem::create(['movement_id' => $m->id, 'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty]);
            }

            return $m;
        };
        $out = fn (Contact $c) => ['collector_name' => $c->id === $c->id ? 'Hassan Ali' : '', 'collector_phone' => '+255 755 111 222', 'collector_id_no' => 'DL-4471920', 'driver_contact_id' => $driver?->id, 'driver_name' => $driver ? null : 'Omar Khamis', 'driver_phone' => $driver ? null : '+255 754 000 111',
            'asset_id' => $veh?->id, 'vehicle_plate' => $veh ? null : 'T 482 DKX', 'vehicle_details' => $veh ? null : 'Mitsubishi Canter, white'];
        $w1 = $wh[0];
        $w2 = $wh[1] ?? $wh[0];
        $mv('opening', $a, $w1, now()->subDays(40)->toDateString(), 'Approved', [[$items[0], 200], [$items[1], 80], [$items[2], 45]], ['purpose' => 'Stock already stored when the module started']);
        $mv('opening', $b, $w2, now()->subDays(40)->toDateString(), 'Approved', [[$items[1], 120], [$items[3] ?? $items[0], 60]], ['purpose' => 'Stock already stored when the module started']);
        $mv('deposit', $a, $w1, now()->subDays(25)->toDateString(), 'Approved', [[$items[0], 100]], ['collector_name' => 'Hassan Ali', 'collector_phone' => '+255 755 111 222', 'purpose' => 'Second delivery of materials']);
        $mv('withdrawal', $a, $w1, now()->subDays(18)->toDateString(), 'Approved', [[$items[0], 120], [$items[1], 30]], $out($a) + ['purpose' => 'For the Mikocheni site']);
        $mv('withdrawal', $b, $w2, now()->subDays(9)->toDateString(), 'Approved', [[$items[1], 40]], $out($b) + ['purpose' => 'Tiling works, Block B']);
        $mv('withdrawal', $a, $w1, now()->subDays(2)->toDateString(), 'Pending Approval', [[$items[2], 15]], $out($a) + ['purpose' => 'Waiting for approval']);
        $mv('deposit', $b, $w2, now()->subDay()->toDateString(), 'Pending Approval', [[$items[3] ?? $items[0], 25]], ['collector_name' => 'Said Mwinyi', 'collector_phone' => '+255 777 222 333']);
        $mv('withdrawal', $b, $w2, now()->subDays(5)->toDateString(), 'Rejected', [[$items[1], 500]], $out($b) + ['decision_remarks' => 'More than the person holds', 'purpose' => 'Rejected example']);
        $this->info('Samples ready: 2 people, 8 movements.');

        return self::SUCCESS;
    }
}
