<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `accounts.name` column still held the vendor starter-kit's
     * placeholder company name, "Tecdiary WIMS" — left over from the
     * original template and never updated to the real company name. Every
     * PDF/print view already works around this with a display-time
     * preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', ...) rather than
     * fixing the source, which is why some newer views (built after that
     * workaround became the copy-paste convention) forgot to include it —
     * fixing the actual data removes the need for that pattern everywhere
     * it's used, present and future.
     *
     * Only ever touches a row whose name is literally "Tecdiary WIMS"
     * (case-insensitive) — never blindly overwrites every account's name.
     */
    public function up(): void
    {
        DB::table('accounts')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['tecdiary wims'])
            ->update(['name' => 'RANS WIMS']);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->where('name', 'RANS WIMS')
            ->update(['name' => 'Tecdiary WIMS']);
    }
};
