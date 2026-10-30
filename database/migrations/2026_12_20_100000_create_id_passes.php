<?php

use App\Services\FeatureManager;
use App\Support\PermissionRegistry;
use App\Support\SystemFeatureRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RANS ID & Pass Management: Staff IDs, Compound Worker IDs, Visitor Passes and Gate Passes of people, one card engine for all four.
 *
 *  idpass_categories  the configurable categories (numbering, colours, icon, validity, rules); four built-in kinds: staff | compound | visitor | gate
 *  idpass_numbers     every number ever given out: never recycled, never edited (unique per category and sequence)
 *  idpass_cards       the issued IDs and passes (a person is linked, never copied into a second staff or contact list)
 *  idpass_events      the immutable life of a card: issued, approved, renewed, suspended, revoked, replaced ...
 *  idpass_prints      every print or PDF export (what was done, who did it, when, how many cards)
 *  idpass_batches     a batch of cards printed or exported together (the file of a large one is made in the background)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('idpass_categories')) {
            Schema::create('idpass_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('kind', 12)->index();                       // staff | compound | visitor | gate
                $t->string('key', 40);
                $t->string('name', 80);
                $t->string('label', 40)->nullable();                   // the big word on a pass card ("Visitor Pass"); empty = the person's name and title are shown
                $t->string('prefix', 12)->default('');
                $t->unsignedTinyInteger('digits')->default(3);
                $t->unsignedInteger('next_number')->default(1);
                $t->string('band_color', 9)->default('#1565c0');
                $t->string('arrow_color', 9)->default('#fff200');
                $t->string('icon', 40)->nullable();                    // a file of resources/idpass/img/icons, shown on the front of a compound worker ID
                $t->json('contact_categories')->nullable();            // compound: the Contacts categories that can be given this ID
                $t->unsignedSmallInteger('validity_value')->nullable();
                $t->string('validity_unit', 8)->default('years');      // days | months | years
                $t->boolean('requires_approval')->default(false);
                $t->boolean('photo_required')->default(true);
                $t->boolean('vehicle_required')->default(false);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['account_id', 'key']);
            });
        }
        if (! Schema::hasTable('idpass_cards')) {
            Schema::create('idpass_cards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('kind', 12)->index();
                $t->unsignedBigInteger('category_id')->index();
                $t->string('number', 40);                              // what is printed: RS001
                $t->unsignedInteger('seq_no');
                $t->string('token', 48)->unique();                     // what the QR code holds: unpredictable, never an id
                $t->string('status', 20)->default('Active')->index();  // Pending Approval | Active | Suspended | Revoked | Rejected | Replaced
                // who the card is for: a staff member or a contact is linked (never copied), a visitor or anyone else is written on the card itself
                $t->unsignedBigInteger('staff_id')->nullable()->index();
                $t->unsignedBigInteger('contact_id')->nullable()->index();
                $t->unsignedBigInteger('visitor_id')->nullable()->index();
                $t->string('full_name', 190);                          // as printed (a snapshot: the card reads the same later)
                $t->string('title', 120)->nullable();                 // designation / work category / "Visitor" / purpose line
                $t->string('phone', 60)->nullable();
                $t->string('company', 150)->nullable();
                $t->string('photo_path', 255)->nullable();             // the cropped photograph used on the card (private disk)
                $t->string('photo_original', 255)->nullable();         // what was uploaded
                $t->date('issued_at');
                $t->date('expires_at')->nullable()->index();
                $t->string('host_name', 150)->nullable();              // visitor: who is visited
                $t->string('purpose', 255)->nullable();
                $t->string('authorisation_type', 80)->nullable();      // gate: parking, supplier delivery ...
                $t->string('vehicle_registration', 40)->nullable()->index();
                $t->string('vehicle_description', 150)->nullable();
                $t->text('restrictions')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('replaces_id')->nullable();
                $t->unsignedBigInteger('replaced_by_id')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('approved_by_user_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('decision_reason', 500)->nullable();
                $t->unsignedBigInteger('status_by_user_id')->nullable();
                $t->timestamp('status_at')->nullable();
                $t->string('status_reason', 500)->nullable();
                $t->unsignedInteger('print_count')->default(0);
                $t->timestamp('last_printed_at')->nullable();
                $t->timestamp('expiry_notified_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['category_id', 'number'], 'idpass_cards_cat_number');
                $t->unique(['category_id', 'seq_no'], 'idpass_cards_cat_seq');
            });
        }
        if (! Schema::hasTable('idpass_numbers')) {
            Schema::create('idpass_numbers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('category_id')->index();
                $t->unsignedInteger('seq_no');
                $t->string('number', 40);
                $t->unsignedBigInteger('card_id')->nullable()->index();
                $t->boolean('manual')->default(false);
                $t->unsignedBigInteger('assigned_by_user_id')->nullable();
                $t->timestamp('assigned_at')->useCurrent();
                $t->unique(['category_id', 'seq_no'], 'idpass_numbers_cat_seq');
                $t->unique(['category_id', 'number'], 'idpass_numbers_cat_number');
            });
        }
        if (! Schema::hasTable('idpass_events')) {
            Schema::create('idpass_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('card_id')->index();
                $t->string('event', 30)->index();
                $t->string('from_status', 20)->nullable();
                $t->string('to_status', 20)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('ip', 45)->nullable();
                $t->json('detail')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('idpass_batches')) {
            Schema::create('idpass_batches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 30)->unique();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('action', 12);                              // print | pdf
                $t->string('layout', 20);                              // card | a4_side | a4_duplex | report
                $t->json('filters')->nullable();
                $t->json('card_ids')->nullable();
                $t->unsignedInteger('cards')->default(0);
                $t->unsignedInteger('sheets')->default(0);
                $t->string('status', 12)->default('Queued')->index();  // Queued | Running | Done | Failed
                $t->unsignedTinyInteger('progress')->default(0);
                $t->string('file_path', 255)->nullable();
                $t->text('error')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('idpass_prints')) {
            Schema::create('idpass_prints', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->unsignedBigInteger('card_id')->nullable()->index();
                $t->unsignedBigInteger('batch_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('number', 40)->nullable();                  // snapshots: the history reads the same if the card changes later
                $t->string('full_name', 190)->nullable();
                $t->string('category_name', 80)->nullable();
                $t->string('card_status', 20)->nullable();
                $t->string('action', 14)->index();                     // print | pdf | batch_print | batch_pdf
                $t->string('layout', 20);                              // card | a4_side | a4_duplex | report
                $t->string('format', 10)->default('pdf');
                $t->unsignedInteger('copies')->default(1);
                $t->string('outcome', 12)->default('Generated');       // Initiated | Generated | Failed
                $t->string('message', 255)->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }

        // the starting categories, one set per account: the four kinds, Aluminium and Carpenter for compound workers
        $accounts = Schema::hasTable('accounts') ? DB::table('accounts')->pluck('id')->all() : [];
        $accounts = $accounts ?: [1];
        $seed = [
            ['staff', 'staff', 'Staff', null, 'RS', 3, '#1565c0', '#fff200', null, null, 2, 'years', false, true, false, 1],
            ['compound', 'aluminium', 'Aluminium', null, 'AL', 3, '#1d310a', '#94e050', 'aluminium', ['Aluminium'], 1, 'years', false, true, false, 2],
            ['compound', 'carpenter', 'Carpenter', null, 'CP', 3, '#735801', '#f4e5b4', 'hammer', ['Carpenter'], 1, 'years', false, true, false, 3],
            ['visitor', 'visitor', 'Visitor', 'Visitor Pass', 'V', 3, '#3d0563', '#ffb8fd', null, null, 1, 'days', false, false, false, 4],
            ['gate', 'gate', 'Gate Pass', 'Gate Pass', 'GP', 3, '#e69801', '#eabb60', null, null, 6, 'months', true, false, false, 5],
        ];
        foreach ($accounts as $aid) {
            foreach ($seed as [$kind, $key, $name, $label, $prefix, $digits, $band, $arrow, $icon, $cc, $vv, $vu, $appr, $photo, $veh, $sort]) {
                if (DB::table('idpass_categories')->where('account_id', $aid)->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('idpass_categories')->insert(['account_id' => $aid, 'kind' => $kind, 'key' => $key, 'name' => $name, 'label' => $label, 'prefix' => $prefix, 'digits' => $digits, 'next_number' => 1,
                    'band_color' => $band, 'arrow_color' => $arrow, 'icon' => $icon, 'contact_categories' => $cc ? json_encode($cc) : null, 'validity_value' => $vv, 'validity_unit' => $vu,
                    'requires_approval' => $appr, 'photo_required' => $photo, 'vehicle_required' => $veh, 'is_active' => true, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $names = [];
        foreach (['id_passes', 'id_staff', 'id_compound', 'id_visitor', 'id_gate', 'id_print_centre', 'id_settings'] as $m) {
            $names = array_merge($names, array_keys(PermissionRegistry::modules()[$m]['abilities'] ?? []));
        }
        foreach ($names as $n) {
            Permission::query()->firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        Role::query()->where('name', 'Super Admin')->get()->each(fn ($r) => $r->givePermissionTo(Permission::query()->whereIn('name', $names)->get()->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Schema::hasTable('system_features')) {
            foreach (['id_passes', 'id_staff', 'id_compound', 'id_visitor', 'id_gate', 'id_print_centre', 'id_batch_print', 'id_pdf_export', 'id_settings', 'id_reports', 'id_verify'] as $key) {
                $f = SystemFeatureRegistry::features()[$key] ?? null;
                if (! $f) {
                    continue;
                }
                $exists = DB::table('system_features')->where('key', $key)->exists();
                DB::table('system_features')->updateOrInsert(['key' => $key], [
                    'name' => $f['name'], 'description' => $f['description'], 'module' => $f['module'], 'icon' => $f['icon'],
                    'route_prefixes' => json_encode($f['route_prefixes']), 'depends_on' => $f['depends_on'] ?? null, 'updated_at' => now(),
                ] + ($exists ? [] : ['sort_order' => (int) DB::table('system_features')->max('sort_order') + 1, 'created_at' => now()]));
                DB::table('system_features')->where('key', $key)->whereNull('deleted_at')->update(['is_enabled' => DB::raw('COALESCE(is_enabled, 1)')]);
            }
            FeatureManager::flushCache();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idpass_prints');
        Schema::dropIfExists('idpass_batches');
        Schema::dropIfExists('idpass_events');
        Schema::dropIfExists('idpass_numbers');
        Schema::dropIfExists('idpass_cards');
        Schema::dropIfExists('idpass_categories');
    }
};
