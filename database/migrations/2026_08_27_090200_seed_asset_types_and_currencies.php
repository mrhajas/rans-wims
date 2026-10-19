<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = [
        'Sedan', 'Pickup', 'Truck', 'Bus', 'Excavator', 'Bulldozer', 'Crane', 'Generator', 'Compressor',
        'Concrete Mixer', 'Desktop Computer', 'Laptop', 'Printer', 'Server', 'Network Switch', 'Air Conditioner',
        'Office Desk', 'Office Chair', 'Cabinet', 'Land', 'Building', 'Warehouse', 'Water Pump', 'Welding Machine',
        'Scaffolding', 'Power Tool', 'Radio / Communication Set', 'Other',
    ];

    private const CURRENCIES = ['TZS', 'USD', 'EUR', 'GBP', 'KES', 'UGX'];

    public function up(): void
    {
        foreach (self::TYPES as $text) {
            DB::table('asset_types')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach (self::CURRENCIES as $text) {
            DB::table('asset_currencies')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('asset_types')->whereIn('text', self::TYPES)->delete();
        DB::table('asset_currencies')->whereIn('text', self::CURRENCIES)->delete();
    }
};
