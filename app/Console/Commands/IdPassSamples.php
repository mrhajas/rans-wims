<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\IdPassPrint;
use App\Models\Staff;
use App\Models\User;
use App\Services\IdPass\CardService;
use App\Services\IdPass\IdPassNotifier;
use App\Services\IdPass\PassAssignments;
use App\Services\IdPass\PhotoBackground;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample RANS IDs to look at and try: 5 Staff IDs, 5 Aluminium and 5 Carpenter worker IDs, 8 Visitor Passes (3 of them with a visitor, 1 taken back) and 6 Gate Passes of people,
 * in different states (active, soon to expire, expired, suspended, revoked, rejected). Nobody is told: the alerts are switched off while this runs.
 * The photographs are drawn pictures, on the photo background colour of each category. Cards carry the note SAMPLE; the contacts made for them say SAMPLE in their details.
 *
 *   php artisan id-passes:samples            add the samples (nothing happens if they exist)
 *   php artisan id-passes:samples --remove   delete them again, with their history, and give the numbers back
 */
class IdPassSamples extends Command
{
    protected $signature = 'id-passes:samples {--remove : Delete the sample IDs instead of creating them}';

    protected $description = 'Create (or remove) sample RANS IDs of every kind';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        $admin = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tmp%')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No Super Admin user found.');

            return self::FAILURE;
        }
        Auth::setUser($admin);
        IdPassNotifier::$muted = true;
        if ($this->option('remove')) {
            return $this->remove();
        }
        if (IdPassCard::withTrashed()->where('notes', self::MARK)->exists()) {
            $this->info('The samples are already there. Use --remove to delete them first.');

            return self::SUCCESS;
        }
        $cats = IdPassCategory::query()->get()->keyBy('key');
        foreach (['staff', 'aluminium', 'carpenter', 'visitor', 'gate'] as $k) {
            if (! isset($cats[$k])) {
                $this->error("The category '$k' does not exist.");

                return self::FAILURE;
            }
        }
        $approver = User::withoutGlobalScopes()->role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tmp%')->where('id', '!=', $admin->id)->first() ?: $admin;
        $today = Carbon::today(config('app.timezone'));

        // Staff IDs: staff members who are working, have no ID yet and no photograph of their own on file (the real IDs are issued by the office)
        $taken = IdPassCard::query()->where('kind', 'staff')->whereNotNull('staff_id')->pluck('staff_id');
        $staff = Staff::query()->whereIn('status', ['Active', 'Retired Active'])->where(fn ($q) => $q->whereNull('photo')->orWhere('photo', ''))->whereNotIn('id', $taken)->orderBy('id')->limit(5)->get();
        $plan = [[-300, 65], [-120, null], [-700, 18], [-60, null], [-800, -20]];
        foreach ($staff as $i => $s) {
            $card = $this->issue($cats['staff'], ['staff_id' => $s->id, 'issued_at' => $today->copy()->addDays($plan[$i][0])->toDateString(), 'expires_at' => $plan[$i][1] === null ? null : $today->copy()->addDays($plan[$i][1])->toDateString()], $i, $admin);
            if ($i === 3) {
                CardService::suspend($card, $admin, 'Sample: staff member on long leave');
            }
        }

        $this->workers($cats['aluminium'], ['Juma Haji Mussa', 'Said Omar Khamis', 'Hamad Faki Juma', 'Rashid Ali Suleiman', 'Khamis Mbarouk Haji'], 'Aluminium', 10, $admin, $today);
        $this->workers($cats['carpenter'], ['Mohammed Abdalla Vuai', 'Ame Silima Kombo', 'Haji Makame Bakari', 'Ali Juma Gharib', 'Yussuf Nassor Ameir'], 'Carpenter', 20, $admin, $today);

        // Visitor Passes: a stock of numbered blank passes, three of them with visitors, one given out and taken back
        $pool = CardService::issuePool($cats['visitor'], 8, $admin);
        IdPassCard::query()->whereIn('id', array_map(fn ($c) => $c->id, $pool))->update(['notes' => self::MARK]);
        $holders = [['name' => 'Fatma Haji Said', 'company' => 'Zan Logistics', 'phone' => '+255777100201', 'host' => 'Reception'], ['name' => 'Peter Mwakyusa', 'company' => 'Kilimo Supplies', 'phone' => '+255754100202', 'host' => 'Store Manager'], ['name' => 'Hassan Ali Mzee', 'company' => 'ZECO', 'phone' => '+255773100203', 'host' => 'Project Manager']];
        foreach ($holders as $n => $h) {
            PassAssignments::assign($pool[$n], $h, null, $admin, self::MARK);
        }
        PassAssignments::assign($pool[3], ['name' => 'Joyce Mushi', 'company' => 'Bank of Zanzibar', 'phone' => '+255712100204', 'host' => 'Accounts'], null, $admin, self::MARK);
        PassAssignments::release($pool[3], $admin, 'Checked out');

        // Gate Passes of people (they wait for approval: some are approved, one is revoked, one rejected, one has expired)
        $gate = [['Abdallah Haji Ali', 'Delivery driver', 'Delivers cement and steel to the stores', 'Vendor access', 'GP-ZNZ 4471'], ['Salma Juma Kheir', 'Cleaner (contractor)', 'Cleans the offices every morning', 'Contractor staff', null], ['Mussa Omar Faki', 'Security officer', 'Night guard on the east gate', 'Security', null],
            ['Daniel Kimaro', 'Surveyor', 'Site survey for the Mwanakwerekwe project', 'Consultant', 'T 553 DKM'], ['Zainab Hamad Rajab', 'Caterer', 'Serves lunch at the staff canteen', 'Vendor access', 'Z 2210 AB'], ['Omar Said Mkubwa', 'Plant operator', 'Operates the loader for the quarry', 'Contractor staff', 'Z 812 OS']];
        foreach ($gate as $i => [$name, $title, $purpose, $type, $vehicle]) {
            $c = Contact::create(['name' => $name, 'category' => 'Gate Pass', 'phone' => '+25574' . str_pad((string) (1000100 + $i), 7, '0'), 'details' => self::MARK . ' - ' . $title]);
            $card = $this->issue($cats['gate'], ['contact_id' => $c->id, 'title' => $title, 'purpose' => $purpose, 'authorisation_type' => $type, 'vehicle_registration' => $vehicle, 'issued_at' => $today->copy()->addDays(-30 + $i * 4)->toDateString(), 'expires_at' => $today->copy()->addDays($i === 4 ? -3 : 150 - $i * 10)->toDateString()], 30 + $i, $admin);
            if ($i <= 4) {
                $card = CardService::approve($card->fresh(), $approver, 'Sample approval');
            }
            if ($i === 2) {
                CardService::revoke($card->fresh(), $admin, 'Sample: left the company');
            }
            if ($i === 5) {
                CardService::reject($card->fresh(), $approver, 'Sample: no vehicle permit');
            }
        }

        // a little print history, so Print History and the reports have something to show
        foreach (IdPassCard::query()->where('notes', self::MARK)->where('status', IdPassCard::ACTIVE)->whereNotNull('photo_path')->limit(8)->get() as $n => $c) {
            IdPassPrint::create(['card_id' => $c->id, 'user_id' => $admin->id, 'number' => $c->number, 'full_name' => $c->full_name, 'category_name' => optional($c->category)->name, 'card_status' => $c->status, 'action' => $n % 2 ? 'pdf' : 'print', 'layout' => 'card', 'format' => 'pdf', 'copies' => 1, 'outcome' => 'Generated', 'created_at' => now()->subDays($n)]);
            CardService::printed($c);
        }
        $this->info(IdPassCard::query()->where('notes', self::MARK)->count() . ' sample IDs made: ' . IdPassCard::query()->where('notes', self::MARK)->selectRaw('kind, COUNT(*) n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n, $k) => "$k $n")->implode(', '));

        return self::SUCCESS;
    }

    private function workers(IdPassCategory $cat, array $names, string $trade, int $offset, User $admin, Carbon $today): void
    {
        foreach ($names as $i => $name) {
            $c = Contact::create(['name' => $name, 'category' => $trade, 'phone' => '+25577' . str_pad((string) (2000100 + $offset * 10 + $i), 7, '0'), 'details' => self::MARK . ' - ' . $trade . ' worker']);
            $card = $this->issue($cat, ['contact_id' => $c->id, 'issued_at' => $today->copy()->addDays(-200 + $i * 30)->toDateString(), 'expires_at' => $today->copy()->addDays($i === 4 ? -10 : 165 + $i * 20)->toDateString()], $offset + $i, $admin);
            if ($i === 3) {
                CardService::suspend($card, $admin, 'Sample: off site for two weeks');
            }
        }
    }

    /** Issues one sample card with a drawn photograph on the colour of its category. */
    private function issue(IdPassCategory $cat, array $in, int $seed, User $admin): IdPassCard
    {
        $card = CardService::issue($cat, $in, $this->photo($cat, $seed), $admin);
        $card->forceFill(['notes' => self::MARK])->save();

        return $card;
    }

    /** A drawn head-and-shoulders picture: the cut-out on transparency, and the card picture with the category colour behind it. */
    private function photo(IdPassCategory $cat, int $seed): array
    {
        $w = 1600;
        $h = 2000;
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        $skins = [[141, 85, 36], [198, 134, 66], [224, 172, 105], [110, 68, 34], [170, 110, 60], [90, 56, 30]];
        $hairs = [[20, 16, 14], [38, 28, 20], [18, 18, 22]];
        $skin = $skins[$seed % count($skins)];
        $hair = $hairs[$seed % count($hairs)];
        $kind = $cat->kind === 'staff' ? 'suit' : ($cat->key === 'carpenter' ? 'apron' : ($cat->kind === 'gate' ? 'polo' : 'vest'));
        $col = fn ($c) => imagecolorallocate($im, $c[0], $c[1], $c[2]);
        $cx = (int) ($w / 2);
        $cloth = ['suit' => [[24, 36, 64], [28, 44, 80], [40, 40, 44]][$seed % 3], 'vest' => [255, 133, 27], 'apron' => [120, 84, 48], 'polo' => [[30, 110, 90], [70, 90, 160]][$seed % 2]][$kind];
        imagefilledellipse($im, $cx, $h + 150, 1500, 1500, $col($cloth));
        if ($kind === 'suit') {
            imagefilledpolygon($im, [$cx - 150, 1440, $cx + 150, 1440, $cx, 1800], 3, $col([245, 245, 248]));
            imagefilledpolygon($im, [$cx - 40, 1500, $cx + 40, 1500, $cx + 30, 1900, $cx - 30, 1900], 4, $col([[150, 20, 40], [20, 60, 140], [30, 100, 60]][$seed % 3]));
        } elseif ($kind === 'vest') {
            imagefilledrectangle($im, $cx - 190, 1500, $cx - 140, $h, $col([235, 235, 220]));
            imagefilledrectangle($im, $cx + 140, 1500, $cx + 190, $h, $col([235, 235, 220]));
        }
        imagefilledrectangle($im, $cx - 120, 1180, $cx + 120, 1520, $col([max(0, $skin[0] - 14), max(0, $skin[1] - 12), max(0, $skin[2] - 8)]));
        imagefilledellipse($im, $cx, 820, 640, 840, $col($skin));
        imagefilledellipse($im, $cx - 322, 840, 70, 150, $col($skin));
        imagefilledellipse($im, $cx + 322, 840, 70, 150, $col($skin));
        if ($kind === 'vest' || $kind === 'apron') {
            imagefilledellipse($im, $cx, 560, 700, 520, $col($kind === 'vest' ? [250, 250, 250] : [255, 200, 40]));
            imagefilledrectangle($im, $cx - 410, 560, $cx + 410, 600, $col($kind === 'vest' ? [235, 235, 235] : [240, 180, 20]));
        } else {
            imagefilledarc($im, $cx, 730, 680, 720, 180, 360, $col($hair), IMG_ARC_PIE);
            if ($seed % 2) {
                imagefilledarc($im, $cx, 960, 600, 420, 0, 180, $col($hair), IMG_ARC_PIE);
            }
        }
        $dark = $col([36, 26, 22]);
        imagefilledellipse($im, $cx - 120, 790, 58, 34, $dark);
        imagefilledellipse($im, $cx + 120, 790, 58, 34, $dark);
        imagesetthickness($im, 14);
        imagearc($im, $cx, 960, 190, 110, 20, 160, $dark);
        $small = imagecreatetruecolor(800, 1000);
        imagesavealpha($small, true);
        imagealphablending($small, false);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $im, 0, 0, 0, 0, 800, 1000, $w, $h);
        ob_start();
        imagepng($small, null, 6);
        $png = ob_get_clean();
        $bg = $cat->photo_background ?: '#e5e7eb';
        $dir = 'idpass/photos/' . now()->format('Y/m') . '/sample-' . Str::random(16);
        Storage::disk('local')->put($dir . '/cutout.png', $png);
        Storage::disk('local')->put($dir . '/card.jpg', PhotoBackground::composite($png, $bg));

        return ['path' => $dir . '/card.jpg', 'original' => null, 'cutout' => $dir . '/cutout.png', 'bg' => strtolower($bg), 'width' => 800, 'height' => 1000, 'low' => false];
    }

    private function remove(): int
    {
        $ids = IdPassCard::withTrashed()->where('notes', self::MARK)->pluck('id');
        $n = $ids->count();
        $dirs = IdPassCard::withTrashed()->whereIn('id', $ids)->pluck('photo_path')->filter()->map(fn ($p) => dirname($p))->unique();
        DB::transaction(function () use ($ids) {
            IdPassCard::withTrashed()->whereIn('id', $ids)->update(['replaced_by_id' => null, 'replaces_id' => null]);
            DB::table('idpass_events')->whereIn('card_id', $ids)->delete();
            DB::table('idpass_assignments')->whereIn('card_id', $ids)->delete();
            DB::table('idpass_prints')->whereIn('card_id', $ids)->delete();
            DB::table('idpass_numbers')->whereIn('card_id', $ids)->delete();
            DB::table('idpass_cards')->whereIn('id', $ids)->delete();
            Contact::withoutGlobalScopes()->withTrashed()->where('details', 'like', self::MARK . '%')->whereIn('category', ['Aluminium', 'Carpenter', 'Gate Pass'])->forceDelete();
            // the numbers go back: the next number is one after the highest that is still used
            foreach (IdPassCategory::query()->get() as $c) {
                $max = (int) DB::table('idpass_numbers')->where('category_id', $c->id)->max('seq_no');
                $c->update(['next_number' => max(1, $max + 1)]);
            }
        });
        foreach ($dirs as $dir) {
            if (str_contains($dir, 'sample-')) {
                Storage::disk('local')->deleteDirectory($dir);
            }
        }
        $this->info("$n sample IDs removed.");

        return self::SUCCESS;
    }
}
