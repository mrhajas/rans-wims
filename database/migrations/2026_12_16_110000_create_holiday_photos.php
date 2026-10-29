<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Holiday Greetings: the photo behind the hero of a holiday's email. The WIMS photos are files in the project; a row here is a Super Admin's own photo for a template (or "no photo"). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holiday_photos')) {
            Schema::create('holiday_photos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('theme', 40);
                $t->string('path', 255)->nullable();                 // under storage/app/holiday-photos
                $t->string('original_name', 190)->nullable();
                $t->boolean('disabled')->default(false);              // true = this template has no photo
                $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['account_id', 'theme'], 'holiday_photos_unique');
            });
        }
        Permission::query()->firstOrCreate(['name' => 'manage-holiday-photos', 'guard_name' => 'web']);
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo('manage-holiday-photos'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_photos');
        Permission::query()->where('name', 'manage-holiday-photos')->delete();
    }
};
