<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\TransportLoadingAttendance;
use App\Models\TransportLoadingOpeningBalance;
use App\Models\TransportLoadingPayment;
use App\Models\User;
use App\Services\Transport\LoadingLedger;
use App\Services\Transport\LoadingVoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sample Loading & Unloading data: a fortnight of attendance for Masoud Sijuinani (the first sample worker) AND a roster of
 * extra personnel, with custom daily rates, direct payments, vouchers at every stage (paid, approved, submitted, draft), a bulk-
 * generated set (one separate voucher per person) and some people with nothing paid yet. Everything is marked SAMPLE (contact
 * details, attendance / payment notes, voucher internal notes) so `--remove` deletes exactly the samples and nothing else.
 */
class SeedLoadingSamples extends Command
{
    protected $signature = 'wims:seed-loading-samples {--worker=Masoud Sijuinani : Name of the first worker (a contact in the Loading & UnLoading category)} {--remove : Delete the sample rows again}';

    protected $description = 'Add (or remove) sample Loading & Unloading personnel, attendance, payments and vouchers';

    private const MARK = 'SAMPLE';

    private int $accountId = 1;

    /** date => [status, rate or null for the standard rate] (Masoud) */
    private const MASOUD_DAYS = [
        '2026-09-24' => ['Present', null], '2026-09-25' => ['Present', null], '2026-09-26' => ['Present', 20000],
        '2026-09-27' => ['Absent', null], '2026-09-28' => ['Present', null], '2026-09-29' => ['Present', 12500],
        '2026-09-30' => ['Present', null], '2026-10-01' => ['Absent', null], '2026-10-02' => ['Present', null],
        '2026-10-03' => ['Present', 25000], '2026-10-04' => ['Present', null], '2026-10-05' => ['Present', 10000],
        '2026-10-06' => ['Absent', null], '2026-10-07' => ['Present', null],
    ];

    /** date => [amount, method, reference] (Masoud, direct payments) */
    private const MASOUD_PAYMENTS = [
        '2026-09-30' => [60000, 'Cash', null],
        '2026-10-05' => [45000, 'Mobile money', 'SAMPLE-MM-0001'],
    ];

    /** name => [amount, as at]: previous balances brought forward (a minus amount = money already taken in advance) */
    private const OPENINGS = [
        'Masoud Sijuinani' => [80000, '2026-09-23'],
        'Juma Haji Ali' => [45000, '2026-09-23'],
        'Yussuf Nassor Abeid' => [120000, '2026-09-23'],
        'Said Makame Haji' => [-20000, '2026-09-23'],
    ];

    /** name => [phone, scenario] */
    private const ROSTER = [
        'Juma Haji Ali' => ['0710 100 101', 'direct'],          // part paid in cash
        'Khamis Omar Said' => ['0710 100 102', 'voucher-paid'],  // a voucher fully processed and paid
        'Salim Rashid Mohamed' => ['0710 100 103', 'approved'],  // voucher approved / authorised, not paid yet
        'Hamad Mussa Khamis' => ['0710 100 104', 'submitted'],   // voucher submitted for approval
        'Ali Bakari Juma' => ['0710 100 105', 'bulk'],           // part of the bulk-generated vouchers
        'Mwinyi Hassan Ali' => ['0710 100 106', 'bulk'],
        'Rashid Abdalla Suleiman' => ['0710 100 107', 'bulk'],
        'Said Makame Haji' => ['0710 100 108', 'mixed'],         // cash + a paid voucher + a draft voucher
        'Yussuf Nassor Abeid' => ['0710 100 109', 'unpaid'],     // nothing paid at all
    ];

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        $by = User::role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        $userId = $by?->id ?? 1;
        $this->accountId = (int) ($by?->account_id ?? 1);
        $standard = LoadingLedger::rate();
        $stats = ['contacts' => 0, 'days' => 0, 'payments' => 0, 'vouchers' => 0, 'openings' => 0];

        // ---- the first worker (an existing contact; created if missing)
        $masoud = Contact::query()->where('name', $this->option('worker'))->first();
        if (! $masoud) {
            $masoud = $this->contact($this->option('worker'), '0710 100 100', $stats);
        }
        $this->opening($masoud, $stats);
        $this->attendance($masoud, $this->daysFor(0, true), $standard, $stats);
        foreach (self::MASOUD_PAYMENTS as $date => [$amount, $method, $ref]) {
            $this->directPayment($masoud, $amount, $date, $method, $ref, $stats);
        }

        // ---- the extra personnel
        $bulk = [];
        $i = 1;
        foreach (self::ROSTER as $name => [$phone, $scenario]) {
            $c = $this->contact($name, $phone, $stats);
            $this->opening($c, $stats);
            $this->attendance($c, $this->daysFor($i, false), $standard, $stats);
            $i++;
            if (LoadingLedger::availableOf($c->id) <= 0 || TransportLoadingPayment::where('contact_id', $c->id)->where('notes', self::MARK)->exists()) {
                continue; // already seeded
            }
            switch ($scenario) {
                case 'direct':
                    $this->directPayment($c, round(LoadingLedger::availableOf($c->id) * 0.5, -3), '2026-10-04', 'Cash', null, $stats);
                    break;
                case 'voucher-paid':
                    $this->voucher($c, round(LoadingLedger::availableOf($c->id) * 0.7, -3), 'paid', $userId, $stats);
                    break;
                case 'approved':
                    $this->voucher($c, LoadingLedger::availableOf($c->id), 'approved', $userId, $stats);
                    break;
                case 'submitted':
                    $this->voucher($c, round(LoadingLedger::availableOf($c->id) * 0.6, -3), 'submitted', $userId, $stats);
                    break;
                case 'bulk':
                    $bulk[] = $c;
                    break;
                case 'mixed':
                    $this->directPayment($c, 30000, '2026-10-01', 'Mobile money', 'SAMPLE-MM-0002', $stats);
                    $this->voucher($c, 40000, 'paid', $userId, $stats);
                    $this->voucher($c, round(LoadingLedger::availableOf($c->id) * 0.5, -3), 'draft', $userId, $stats);
                    break;
            }
        }

        // ---- bulk generation: one separate voucher per person, each with its own amount (the first takes the whole balance)
        if ($bulk) {
            DB::transaction(function () use ($bulk, $userId, &$stats) {
                foreach ($bulk as $n => $c) {
                    $available = LoadingLedger::availableOf($c->id);
                    $amount = $n === 0 ? $available : round($available * 0.8, -3);
                    LoadingVoucherService::create($c, $amount, 'bulk payment', $userId, 'amount', self::MARK);
                    $stats['vouchers']++;
                }
            });
        }

        $this->info("Added {$stats['contacts']} contact(s), {$stats['days']} day(s), {$stats['payments']} direct payment(s), {$stats['vouchers']} voucher(s) and {$stats['openings']} previous balance(s).");
        $names = array_merge([$masoud->name], array_keys(self::ROSTER));
        $rows = collect(LoadingLedger::summary())->filter(fn ($r) => in_array($r['name'], $names, true))
            ->map(fn ($r) => [$r['name'], $r['days'], number_format($r['earned']), number_format($r['paid']), number_format($r['pending']), number_format($r['balance'])])->all();
        $this->table(['Personnel', 'Days', 'Earned', 'Paid', 'On voucher', 'Balance'], $rows);

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $vouchers = PaymentVoucher::withTrashed()->where('internal_notes', self::MARK)->pluck('id');
        $payments = TransportLoadingPayment::withTrashed()->where('notes', self::MARK)->pluck('id');
        DB::table('payment_voucher_items')->whereIn('payment_voucher_id', $vouchers)->delete();
        TransportLoadingPayment::withTrashed()->whereIn('id', $payments)->forceDelete();
        PaymentVoucher::withTrashed()->whereIn('id', $vouchers)->forceDelete();
        $openings = TransportLoadingOpeningBalance::where('notes', self::MARK)->delete();
        $days = TransportLoadingAttendance::where('notes', self::MARK)->delete();
        // sample contacts go only when nothing real is left on them
        $gone = 0;
        foreach (Contact::withTrashed()->where('details', self::MARK)->get() as $c) {
            if (! TransportLoadingAttendance::where('contact_id', $c->id)->exists() && ! TransportLoadingPayment::withTrashed()->where('contact_id', $c->id)->exists() && ! TransportLoadingOpeningBalance::where('contact_id', $c->id)->exists()) {
                $c->forceDelete();
                $gone++;
            }
        }
        $this->info("Removed {$days} sample day(s), {$payments->count()} payment(s), {$vouchers->count()} voucher(s), {$openings} previous balance(s) and {$gone} sample contact(s).");

        return self::SUCCESS;
    }

    /** A sample previous balance for people listed in OPENINGS, only when they have none yet. */
    private function opening(Contact $c, array &$stats): void
    {
        if (! isset(self::OPENINGS[$c->name]) || TransportLoadingOpeningBalance::where('contact_id', $c->id)->exists()) {
            return;
        }
        [$amount, $asAt] = self::OPENINGS[$c->name];
        TransportLoadingOpeningBalance::create(['contact_id' => $c->id, 'amount' => $amount, 'as_of_date' => $asAt, 'notes' => self::MARK]);
        $stats['openings']++;
    }

    private function contact(string $name, string $phone, array &$stats): Contact
    {
        $existing = Contact::query()->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $stats['contacts']++;

        return Contact::create(['name' => $name, 'category' => Contact::LOADING_CATEGORY, 'phone' => $phone, 'details' => self::MARK, 'account_id' => $this->accountId]);
    }

    /** Deterministic fortnight per person: a few absences and a few days at another rate. */
    private function daysFor(int $seed, bool $masoud): array
    {
        if ($masoud) {
            return self::MASOUD_DAYS;
        }
        mt_srand(900 + $seed);
        $days = [];
        for ($d = 24; $d <= 37; $d++) { // 24 Sep .. 7 Oct
            $date = $d <= 30 ? sprintf('2026-09-%02d', $d) : sprintf('2026-10-%02d', $d - 30);
            $roll = mt_rand(1, 100);
            $days[$date] = $roll <= 18 ? ['Absent', null] : ($roll <= 36 ? ['Present', [12000, 17500, 20000, 22500][mt_rand(0, 3)]] : ['Present', null]);
        }

        return $days;
    }

    private function attendance(Contact $c, array $days, float $standard, array &$stats): void
    {
        foreach ($days as $date => [$status, $rate]) {
            if (TransportLoadingAttendance::where('contact_id', $c->id)->whereDate('work_date', $date)->exists()) {
                continue; // never touch a day that already has attendance
            }
            $dayRate = $rate ?? $standard;
            TransportLoadingAttendance::create([
                'contact_id' => $c->id, 'work_date' => $date, 'status' => $status, 'daily_rate' => $dayRate, 'standard_rate' => $standard,
                'amount' => $status === 'Present' ? $dayRate : 0, 'notes' => self::MARK,
            ]);
            $stats['days']++;
        }
    }

    private function directPayment(Contact $c, float $amount, string $date, string $method, ?string $ref, array &$stats): void
    {
        if ($amount <= 0 || TransportLoadingPayment::where('contact_id', $c->id)->where('notes', self::MARK)->whereDate('payment_date', $date)->where('status', 'Paid')->exists()) {
            return;
        }
        TransportLoadingPayment::create(['contact_id' => $c->id, 'amount' => $amount, 'status' => TransportLoadingPayment::PAID, 'payment_date' => $date, 'method' => $method, 'reference' => $ref, 'notes' => self::MARK]);
        $stats['payments']++;
    }

    /** A voucher taken to the wanted stage by hand (the same fields the approval screens set). */
    private function voucher(Contact $c, float $amount, string $stage, int $userId, array &$stats): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::transaction(function () use ($c, $amount, $stage, $userId) {
            $v = LoadingVoucherService::create($c, $amount, null, $userId, 'amount', self::MARK);
            $set = [];
            if (in_array($stage, ['submitted', 'approved', 'paid'], true)) {
                $set['status'] = PaymentVoucher::STATUS_SUBMITTED;
            }
            if (in_array($stage, ['approved', 'paid'], true)) {
                $set = ['status' => PaymentVoucher::STATUS_AUTHORISED, 'approved_by_user_id' => $userId, 'approved_at' => now()->subDay(), 'authorised_by_user_id' => $userId, 'authorised_at' => now()->subDay()];
            }
            if ($stage === 'paid') {
                $set += ['paid_at' => now()->subHours(3), 'payment_reference' => 'SAMPLE-BANK-' . $v->id, 'payment_method' => 'Bank transfer', 'paid_by_user_id' => $userId];
                TransportLoadingPayment::where('payment_voucher_id', $v->id)->update([
                    'status' => TransportLoadingPayment::PAID, 'payment_date' => now()->toDateString(), 'method' => 'Bank transfer', 'reference' => 'SAMPLE-BANK-' . $v->id, 'paid_by_user_id' => $userId,
                ]);
            }
            if ($set) {
                $v->update($set);
            }
        });
        $stats['vouchers']++;
    }
}
