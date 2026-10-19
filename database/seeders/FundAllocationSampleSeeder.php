<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\FundAccount;
use App\Models\FundAllocation;
use App\Models\FundAllocationBalance;
use App\Models\FundAllocationItem;
use App\Models\FundAllocationItemLine;
use App\Models\FundCategory;
use App\Models\FundPaymentMethod;
use App\Models\PaymentVoucher;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\NumberToWords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * DEMO DATA for the Funds Allocation module. RESETS every Funds Allocation sheet (and the payment vouchers that were
 * generated from them) and then creates 7 sample sheets in different statuses, with split payments and beneficiaries
 * taken from Contacts, Users and Projects. Run manually only:
 *   php artisan db:seed --class='Database\Seeders\FundAllocationSampleSeeder'
 */
class FundAllocationSampleSeeder extends Seeder
{
    private ?User $sa = null;

    private array $contacts = [];

    private array $users = [];

    private array $projects = [];

    private $methods;

    private $cats;

    public function run(): void
    {
        $this->sa = User::role('Super Admin')->orderBy('id')->first() ?? User::orderBy('id')->first();
        Auth::login($this->sa);

        DB::transaction(function () {
            $this->reset();
            $this->loadLookups();
            $this->accounts();

            $this->excelSheet();
            $this->sheet('Site labour and materials - week 40', '2026-10-01', FundAllocation::STATUS_IN_PROGRESS, 'sheet2', ['voucher' => 3, 'paid' => 'partial']);
            $this->sheet('Directors and admin allowances - September', '2026-09-28', FundAllocation::STATUS_CLEARED, 'sheet3', ['voucher' => 'all', 'paid' => 'all', 'authorised' => true]);
            $this->sheet('Subcontractor payments - October week 2', '2026-10-03', FundAllocation::STATUS_COMPLETED, 'sheet4', ['voucher' => 2, 'paid' => 'all']);
            $this->sheet('Pemba site petty cash', '2026-10-04', FundAllocation::STATUS_PENDING, 'sheet5', []);
            $this->sheet('Equipment hire and fuel', '2026-10-04', FundAllocation::STATUS_DRAFT, 'sheet6', []);
            $this->sheet('Office renovation advance', '2026-09-30', FundAllocation::STATUS_REJECTED, 'sheet7', ['reason' => 'The request exceeds the available cash. Split it into two payments and resubmit.']);
        });

        $this->command?->info('Funds Allocation demo data created.');
    }

    private function reset(): void
    {
        $ids = FundAllocation::withTrashed()->pluck('id');
        $itemIds = FundAllocationItem::whereIn('fund_allocation_id', $ids)->pluck('id');
        $pvIds = FundAllocationItem::whereIn('fund_allocation_id', $ids)->whereNotNull('payment_voucher_id')->pluck('payment_voucher_id')
            ->merge(PaymentVoucher::withTrashed()->where('remarks', 'like', 'Funds Allocation FA-%')->pluck('id'))->unique();

        FundAllocationItemLine::whereIn('fund_allocation_item_id', $itemIds)->withTrashed()->forceDelete();
        FundAllocationItem::whereIn('fund_allocation_id', $ids)->forceDelete();
        FundAllocationBalance::whereIn('fund_allocation_id', $ids)->withTrashed()->forceDelete();
        DB::table('activity_log')->where('subject_type', FundAllocation::class)->delete();
        DB::table('notifications')->where('data', 'like', '%fund_allocation%')->delete();
        FundAllocation::withTrashed()->forceDelete();

        foreach (PaymentVoucher::withTrashed()->whereIn('id', $pvIds)->get() as $pv) {
            $pv->items()->forceDelete();
            DB::table('activity_log')->where('subject_type', PaymentVoucher::class)->where('subject_id', $pv->id)->delete();
            $pv->forceDelete();
        }
    }

    private function loadLookups(): void
    {
        $this->contacts = Contact::orderBy('id')->get()->all();
        $this->users = User::where('is_active', true)->orderBy('id')->get()->all();
        $this->projects = Warehouse::where('active', 1)->orderBy('id')->get()->all();
        $this->methods = FundPaymentMethod::all()->keyBy('short_label');
        $this->cats = FundCategory::all()->keyBy(fn ($c) => strtoupper($c->name));
    }

    private function accounts(): void
    {
        foreach ([
            ['Cash Balance TZS', 'cash', 'TZS', null, 1],
            ['Cash Balance USD', 'cash', 'USD', null, 2],
            ['Bank TZS Balance - 8000', 'bank', 'TZS', '8000', 3],
            ['Bank TZS Balance - 8001', 'bank', 'TZS', '8001', 4],
            ['Bank TZS Balance - 7000', 'bank', 'TZS', '7000', 5],
            ['Bank USD Balance - 4000', 'bank', 'USD', '4000', 6],
        ] as [$name, $type, $cur, $no, $o]) {
            FundAccount::firstOrCreate(['name' => $name], ['type' => $type, 'currency' => $cur, 'account_number' => $no, 'is_active' => true, 'sort_order' => $o * 10, 'created_by_user_id' => $this->sa->id]);
        }
    }

    /** Beneficiary by kind: [contact_id, beneficiary_user_id, beneficiary_warehouse_id, name]. */
    private function who(int $i, ?string $hint = null): array
    {
        if ($hint) {
            $h = mb_strtoupper(trim($hint));
            foreach ($this->contacts as $c) {
                if (mb_strtoupper($c->name) === $h) {
                    return [$c->id, null, null, $c->name];
                }
            }
            foreach ($this->users as $u) {
                if (mb_strtoupper($u->name) === $h) {
                    return [null, $u->id, null, $u->name];
                }
            }
        }
        $k = $i % 4;
        if ($k === 3 && $this->projects) {
            $w = $this->projects[($i * 3) % count($this->projects)];

            return [null, null, $w->id, $w->name];
        }
        if ($k === 2 && $this->users) {
            $u = $this->users[($i * 5) % count($this->users)];

            return [null, $u->id, null, $u->name];
        }
        $c = $this->contacts[($i * 7) % count($this->contacts)];

        return [$c->id, null, null, $c->name];
    }

    private function create(string $title, string $date, string $status, array $extra = []): FundAllocation
    {
        $post = in_array($status, [FundAllocation::STATUS_APPROVED, FundAllocation::STATUS_IN_PROGRESS, FundAllocation::STATUS_COMPLETED, FundAllocation::STATUS_CLEARED], true);
        $a = FundAllocation::create([
            'reference' => FundAllocation::nextReference(),
            'allocation_date' => $date,
            'title' => $title,
            'notes' => null,
            'status' => $status,
            'prepared_by_user_id' => $this->sa->id,
            'submitted_at' => $status === FundAllocation::STATUS_DRAFT ? null : now()->subDays(2),
            'approved_by_user_id' => $post ? $this->sa->id : null,
            'approved_at' => $post ? now()->subDay() : null,
            'assigned_to_user_id' => $post ? $this->sa->id : null,
        ] + $extra);
        log_activity(__('Allocation sheet created.'), ['reference' => $a->reference], $a, 'Funds Allocation');
        if ($status !== FundAllocation::STATUS_DRAFT) {
            log_activity(__('Allocation sheet submitted for approval.'), ['reference' => $a->reference], $a, 'Funds Allocation');
        }
        if ($post) {
            log_activity(__('Allocation approved.'), ['reference' => $a->reference, 'payer' => $this->sa->name], $a, 'Funds Allocation');
        }

        return $a;
    }

    private function balances(FundAllocation $a, array $rows): void
    {
        foreach ($rows as $i => [$name, $cur, $amount, $rate]) {
            $acc = FundAccount::where('name', $name)->first();
            FundAllocationBalance::create(['fund_allocation_id' => $a->id, 'fund_account_id' => $acc->id, 'label' => $name, 'currency' => $cur, 'amount' => $amount, 'exchange_rate' => $rate, 'sort_order' => $i]);
        }
    }

    private function excelSheet(): void
    {
        $a = $this->create('Funds Allocation in Progress (sample)', '2026-10-02', FundAllocation::STATUS_APPROVED);
        $this->balances($a, [['Cash Balance TZS', 'TZS', 0, 1], ['Cash Balance USD', 'USD', 18700, 2650], ['Bank TZS Balance - 8000', 'TZS', 0, 1], ['Bank TZS Balance - 8001', 'TZS', 0, 1], ['Bank TZS Balance - 7000', 'TZS', 57000000, 1], ['Bank USD Balance - 4000', 'USD', 0, 2650]]);
        $n = 0;
        foreach ((new self())->rows() as $i => [$category, $description, $beneficiary, $request, $allocation, $tt, $cash]) {
            [$cid, $uid, $wid, $name] = $this->who($i, $beneficiary);
            $item = FundAllocationItem::create([
                'fund_allocation_id' => $a->id, 'fund_category_id' => $this->cats[$category]->id, 'sort_order' => ++$n,
                'description' => $description, 'beneficiary' => $name, 'contact_id' => $cid, 'beneficiary_user_id' => $uid, 'beneficiary_warehouse_id' => $wid,
                'request_amount' => $request, 'allocated_amount' => $allocation,
            ]);
            foreach (['TT' => $tt, 'CASH' => $cash] as $short => $amount) {
                if ($amount > 0) {
                    FundAllocationItemLine::create(['fund_allocation_item_id' => $item->id, 'fund_payment_method_id' => $this->methods[$short]->id, 'amount' => $amount]);
                }
            }
        }
    }

    /** Items for the generated sheets: [category, description, request, allocation, [method => amount]] */
    private function itemsFor(string $key): array
    {
        return match ($key) {
            'sheet2' => [
                ['GENERAL LABOUR', 'Mbweni site labour - week 40', 6200000, 5000000, ['TT' => 2000000, 'CASH' => 3000000]],
                ['GENERAL LABOUR', 'Kwamtipura masons and helpers', 4800000, 4000000, ['CASH' => 4000000]],
                ['SUPPLIERS', 'Cement and sand delivery', 18500000, 12000000, ['TT' => 9000000, 'CASH' => 2000000, 'MOBILE' => 1000000]],
                ['PROCUREMENT', 'Petty transport Unguja', 1500000, 1500000, ['CASH' => 1500000]],
                ['ADMINISTRATION', 'Generator diesel', 1444480, 1444480, ['CASH' => 1444480]],
                ['SITE SECURITY', 'Security guards - September', 6320000, 6320000, ['TT' => 6320000]],
                ['PRIVATE', 'Residence materials top-up', 2700000, 1800000, ['CASH' => 1000000, 'MOBILE' => 800000]],
                ['LABOUR CONTRACTED WORKS', 'Fundi plumbing contract', 3000000, 3000000, ['TT' => 1500000, 'CASH' => 1500000]],
            ],
            'sheet3' => [
                ['DIRECTOR ALLOWANCE', 'Monthly allowance - director 1', 1000000, 1000000, ['TT' => 1000000]],
                ['DIRECTOR ALLOWANCE', 'Monthly allowance - director 2', 2000000, 2000000, ['CASH' => 2000000]],
                ['ADMINISTRATION', 'Office rent', 3500000, 3500000, ['TT' => 3500000]],
                ['ADMINISTRATION', 'Internet and phones', 900000, 900000, ['MOBILE' => 400000, 'TT' => 500000]],
                ['PRIVATE', 'Family support', 750000, 750000, ['CASH' => 750000]],
            ],
            'sheet4' => [
                ['SUB-CONTRACTED WORKS', 'Aluminium works - stage 2', 25000000, 20000000, ['TT' => 15000000, 'CASH' => 5000000]],
                ['SUB-CONTRACTED WORKS', 'Electrical first fix', 12000000, 9000000, ['TT' => 9000000]],
                ['SUB-CONTRACTED WORKS', 'Painting - block A', 8000000, 4500000, ['CASH' => 2500000, 'MOBILE' => 2000000]],
                ['SUPPLIERS', 'Tiles - second delivery', 7600000, 7600000, ['TT' => 7600000]],
            ],
            'sheet5' => [
                ['PROCUREMENT', 'Pemba travelling', 900000, 900000, ['CASH' => 900000]],
                ['GENERAL LABOUR', 'Psychiatric Pemba labour', 3500000, 3000000, ['TT' => 1500000, 'CASH' => 1500000]],
                ['ADMINISTRATION', 'Pemba office running costs', 650000, 650000, ['MOBILE' => 650000]],
            ],
            'sheet6' => [
                ['LABOUR CONTRACTED WORKS', 'Excavator hire - 3 days', 4500000, 0, []],
                ['PROCUREMENT', 'Fuel for equipment', 1200000, 0, []],
            ],
            'sheet7' => [
                ['SUB-CONTRACTED WORKS', 'Office renovation advance', 30000000, 30000000, ['TT' => 20000000, 'CASH' => 10000000]],
            ],
        };
    }

    private function sheet(string $title, string $date, string $status, string $key, array $opt): void
    {
        $extra = isset($opt['reason']) ? ['rejection_reason' => $opt['reason']] : [];
        $a = $this->create($title, $date, $status, $extra);
        $this->balances($a, [['Cash Balance TZS', 'TZS', 22000000, 1], ['Bank TZS Balance - 7000', 'TZS', 40000000, 1]]);

        $items = [];
        foreach ($this->itemsFor($key) as $i => [$category, $description, $request, $allocation, $split]) {
            [$cid, $uid, $wid, $name] = $this->who($i + crc32($key) % 11);
            $item = FundAllocationItem::create([
                'fund_allocation_id' => $a->id, 'fund_category_id' => $this->cats[$category]->id, 'sort_order' => $i + 1,
                'description' => $description, 'beneficiary' => $name, 'contact_id' => $cid, 'beneficiary_user_id' => $uid, 'beneficiary_warehouse_id' => $wid,
                'request_amount' => $request, 'allocated_amount' => $allocation,
            ]);
            $lines = [];
            foreach ($split as $short => $amount) {
                $lines[] = FundAllocationItemLine::create(['fund_allocation_item_id' => $item->id, 'fund_payment_method_id' => $this->methods[$short]->id, 'amount' => $amount]);
            }
            $items[] = [$item, $lines];
        }

        $paid = $opt['paid'] ?? null;
        if ($paid) {
            $lastLine = null;
            foreach ($items as $n => [$item, $lines]) {
                $tick = $paid === 'all' || $n % 2 === 0;
                foreach ($lines as $k => $line) {
                    // "partial": the first item is only half paid so the sheet stays In Progress
                    if ($paid === 'partial' && $n === 0 && $k > 0) {
                        continue;
                    }
                    if ($tick) {
                        $line->update(['is_paid' => true, 'paid_at' => now()->subHours(5 + $n), 'paid_by_user_id' => $this->sa->id]);
                        $lastLine = $line;
                    }
                }
                $item->refresh();
                if ($item->isFullyPaid()) {
                    $item->forceFill(['paid_at' => now()->subHours(5 + $n)])->save();
                }
            }
            log_activity(__('Payments ticked by the payer.'), ['reference' => $a->reference], $a, 'Funds Allocation');
            if ($paid === 'all') {
                $a->update(['completed_at' => now()->subHours(4), 'completed_by_user_id' => $this->sa->id]);
            }
        }

        if (! empty($opt['voucher'])) {
            $ready = collect($items)->filter(fn ($p) => $p[0]->fresh()->isFullyPaid())->values();
            $take = $opt['voucher'] === 'all' ? $ready : $ready->take((int) $opt['voucher']);
            foreach ($take as $n => [$item]) {
                $this->voucher($a, $item->fresh(), $n, ! empty($opt['authorised']));
            }
        }

        if ($status === FundAllocation::STATUS_CLEARED) {
            $a->update(['cleared_by_user_id' => $this->sa->id, 'cleared_at' => now()->subHours(2)]);
            log_activity(__('Allocation sheet closed and cleared.'), ['reference' => $a->reference], $a, 'Funds Allocation');
        }
    }

    private function voucher(FundAllocation $a, FundAllocationItem $item, int $n, bool $authorised): void
    {
        $total = round((float) $item->lines()->sum('amount'), 2);
        $payee = ['payee_type' => PaymentVoucher::PAYEE_TYPE_EXTERNAL, 'payee_contact_id' => $item->contact_id, 'staff_id' => null, 'payee_name' => (string) $item->beneficiary, 'warehouse_id' => $item->warehouse_id ?: $item->beneficiary_warehouse_id];
        if ($item->beneficiary_user_id) {
            $staff = optional(User::find($item->beneficiary_user_id))->staff;
            if ($staff && $staff->status === 'Active') {
                $payee['payee_type'] = PaymentVoucher::PAYEE_TYPE_STAFF;
                $payee['staff_id'] = $staff->id;
            }
        }
        $year = now()->format('Y');
        $max = (int) DB::table('payment_vouchers')->selectRaw('MAX(CAST(SUBSTRING(pv_number, '.(strlen("PV-{$year}-") + 1).') AS UNSIGNED)) as m')->where('pv_number', 'like', "PV-{$year}-%")->value('m');
        $status = $authorised ? PaymentVoucher::STATUS_AUTHORISED : ($n % 2 === 0 ? PaymentVoucher::STATUS_SUBMITTED : PaymentVoucher::STATUS_APPROVED);
        $pv = PaymentVoucher::create($payee + [
            'pv_number' => "PV-{$year}-".str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT),
            'type' => mb_substr((string) optional($item->category)->name ?: 'Administration', 0, 100),
            'voucher_date' => now()->toDateString(),
            'total_amount' => $total,
            'amount_in_words' => NumberToWords::convert($total),
            'status' => $status,
            'prepared_by_user_id' => $this->sa->id,
            'approved_by_user_id' => in_array($status, [PaymentVoucher::STATUS_APPROVED, PaymentVoucher::STATUS_AUTHORISED], true) ? $this->sa->id : null,
            'approved_at' => in_array($status, [PaymentVoucher::STATUS_APPROVED, PaymentVoucher::STATUS_AUTHORISED], true) ? now()->subHour() : null,
            'authorised_by_user_id' => $status === PaymentVoucher::STATUS_AUTHORISED ? $this->sa->id : null,
            'authorised_at' => $status === PaymentVoucher::STATUS_AUTHORISED ? now()->subMinutes(30) : null,
            'remarks' => 'Funds Allocation '.$a->reference.' - '.$item->description,
        ]);
        $pv->items()->create(['line_number' => 1, 'particulars' => PaymentVoucher::PARTICULARS_PREFIX.mb_substr($item->description.' - Funds Allocation '.$a->reference, 0, 255), 'amount' => $total]);
        $item->update(['payment_voucher_id' => $pv->id]);
    }

    /** [category, description, beneficiary, request, allocation, TT, CASH] */
    public function rows(): array
    {
        $A = 'ADMINISTRATION';
        $B = 'PROCUREMENT';
        $C = 'CASH PROCUREMENT';
        $D = 'GENERAL LABOUR';
        $E = 'LABOUR CONTRACTED WORKS';
        $F = 'SUB-CONTRACTED WORKS';
        $G = 'SITE SECURITY';
        $H = 'PRIVATE';
        $I = 'SUPPLIERS';
        $J = 'DIRECTOR ALLOWANCE';

        $rows = [
            [$A, 'ADMIN UNGUJA', 'SALIM HAROUB', 6199000, 6199000, 0, 6199000],
            [$A, 'ADMIN PEMBA', 'TT-PBA ACC', 320000, 320000, 320000, 0],
            [$A, 'MBWENI SHUWENA ZECO ELECTRIC BILL', 'MASOUD JUMA', 13430000, 0, 0, 0],
            [$A, 'ZSSF JULY 2026 Contribution', 'ZSSF', 0, 0, 0, 0],
            [$A, 'SOCIAL COPERATE RESPONSIBILITY (ZANZIBAR SPICE BOYS BRAZIL TRIP)', 'BALOZI ABDULSAMAD', 5400000, 5400000, 5400000, 0],
            [$A, 'ZANZIBAR OUTREACH PROGRAM (ZOP) Annual contribution', 'ZOP', 1500000, 1500000, 1500000, 0],
            [$A, 'DIESAL FOR GENERATOR', 'PREMIER', 1444480, 1444480, 0, 1444480],
            [$A, 'Salary Unguja JUNIOR JULY 2026, SITE & MASJID AUG 2026', 'ARAFA', 38647000, 0, 0, 0],
            [$A, 'Salary Pemba JULY 2026', 'TT-PBA ACC', 3353500, 0, 0, 0],
            [$A, 'SDL AUG', 'TRA', 411690, 0, 0, 0],
            [$A, 'PAYEE AUG', 'ARAFA', 440854, 0, 0, 0],

            [$B, 'PROC-PETTY TRANSPORT UNGUJA', 'SALIM HAROUB', 10640000, 5605000, 0, 5605000],
            [$B, 'Proc-Petty transport Pemba', 'TT-PBA ACC', 0, 0, 0, 0],
            [$B, 'TRAVELLING PEMBA', 'SUBRA', 0, 0, 0, 0],
            [$B, 'MPENDAE HEALTH CENTRE HOSPITAL SIGNAGE', 'SAID RASHID', 8000000, 0, 0, 0],

            [$C, 'SAID MASOUD-SEVERALS', 'SAID MASOUD TT', 67453200, 0, 0, 0],
            [$C, 'ANIL-SEVERALS', 'ANIL-CASH', 16711700, 0, 0, 0],
            [$C, 'LELA', 'LELA-CASH', 12629350, 0, 0, 0],
            [$C, 'NASSIR A.SULTAN', 'NASSIR TT', 8930000, 0, 0, 0],
            [$C, 'NANJIM-MARINE', 'NANJIM', 11330000, 0, 0, 0],
            [$C, 'MASJID JAMII ZINJIBAR MONTHLY CLEANINING ITEMS JULY', 'RAMLA ISSA', 0, 0, 0, 0],
            [$C, 'AISHA SULTAN RAK', 'AISHA SULTAN', 0, 0, 0, 0],
            [$C, 'Mr NASSOR RESIDENCE K/SAMAKI - ROPE - 1 ROLL', 'KHAMIS UMEME', 0, 0, 0, 0],
            [$C, 'PREMIER TOWER - FABRICATION OF SPYGOT(VINGANISHI)', 'SALIM ALI OMAR', 0, 0, 0, 0],

            [$D, 'MOMBASA ZSSF', 'ARAFA', 0, 0, 0, 0],
            [$D, 'MPENDAE', 'ARAFA', 4076200, 2000000, 0, 2000000],
            [$D, 'K/CHEKUNDU', 'ARAFA', 220000, 220000, 0, 220000],
            [$D, 'PREMEIR TOWER', 'ARAFA', 5660000, 2000000, 0, 2000000],
            [$D, 'MBWENI SHUWENA', 'ARAFA', 1360000, 1360000, 0, 1360000],
            [$D, 'PSYCHIATRIC PEMBA', 'TT-PBA ACC', 0, 0, 0, 0],
            [$D, 'MAINATENANCE PUBLIC - JAAMIU ZINJIBAR', 'ARAFA', 361000, 361000, 0, 361000],
            [$D, 'KOMBENI RANS HQ', 'ARAFA', 0, 0, 0, 0],
            [$D, 'BALOZI CHUKWANI', 'ARAFA', 5000000, 5000000, 0, 5000000],
            [$D, 'BALOZI AL-SINAWI BUYU', 'ARAFA', 6571600, 3500000, 0, 3500000],
            [$D, 'MASJID ITIQAMA TUNGUU', 'ARAFA', 1735000, 1735000, 0, 1735000],
            [$D, 'MWERA-STAFF HOUSE', 'ARAFA', 0, 0, 0, 0],
            [$D, 'MAALIM HAROUN RESIDENTIAL - MBWENI', 'ARAFA', 9369000, 3500000, 0, 3500000],
            [$D, 'KARAMA RESIDENCE KISAUNI', 'ARAFA', 9776072, 6000000, 0, 6000000],
            [$D, 'KWAMTIPURA SECONDARY SCHOOL', 'ARAFA', 5428600, 2000000, 0, 2000000],
            [$D, 'KISAKASAKA ZEC', 'ARAFA', 7490000, 2000000, 0, 2000000],

            [$E, 'MR.MASOUD JUMA UMEME', 'ARAFA', 1250000, 1250000, 0, 1250000],
            [$E, 'FUNDI HAFIDH BOMBA', 'ARAFA', 5450000, 3000000, 0, 3000000],
            [$E, 'FUNDI SAID + HAMAD BOMBA', 'ARAFA', 0, 0, 0, 0],
            [$E, 'FUNDI MAJIDI RANGI', 'ARAFA', 0, 0, 0, 0],
            [$E, 'FUNDI HATIBU RANGI', 'ARAFA', 0, 0, 0, 0],
            [$E, 'FUNDI MAULID RANGI', 'ARAFA', 0, 0, 0, 0],
            [$E, 'FUNDI OMAR CORNICE', 'ARAFA', 0, 0, 0, 0],
            [$E, 'SALIM A OMAR -SCAFOLDING', 'ARAFA', 76500, 76500, 0, 76500],
            [$E, 'KHAMIS GRILL WORKS', 'KHAMIS', 0, 0, 0, 0],
            [$E, 'SURVEYORS', 'MR.JOROGE', 0, 0, 0, 0],
        ];

        foreach ([
            ['MWALIMU KHAMIS TERRAZO', null, 417326240], ['BORA', null, 43540000], ['ARAS', null, 391650000], ['YASIN AND SONS', null, 31400000],
            ['HITESH', null, 233328777], ['EDDY KV', null, 5150000], ['PRINCE INTERNATIONAL METAL WORKS', null, 170360000], ['KHAMIS GRILLS', null, 7228900],
            ['GOPI ENTERPRISES', null, 59448000], ['ELECTROTHERMAL', null, 3100000], ['YUSRA METAL WORKS', null, 40000000], ['HOME FURNITURE-SAID RASHID', 'SAID TT', 13958000],
            ['OZTI EA CO LTD US$ @ 2,470', null, 31616000], ['DERM ELEVATOR', null, 39848030], ['FIRST PILLING', null, 205091231], ['AC TECHNOLOGY', null, 20770550],
            ['EXTRION', null, 53357500], ['ELECTROGAS AND LABS LTD', null, 251257710],
        ] as [$d, $b, $r]) {
            $rows[] = [$F, $d, $b, $r, 0, 0, 0];
        }

        $rows[] = [$G, '2TECH AUG', 'TT', 6320000, 0, 0, 0];
        $rows[] = [$G, 'ZNZ MODIFYING AUG', 'CASH', 2800000, 0, 0, 0];

        $rows[] = [$H, 'ALI NASSOR MBWENI', 'ARAFA', 0, 0, 0, 0];
        $rows[] = [$H, 'MR NASSOR PLOT K/SAMAKI', 'ARAFA', 0, 0, 0, 0];
        $rows[] = [$H, 'CHUKWANI FAHMI', 'ARAFA', 0, 0, 0, 0];
        $rows[] = [$H, 'ORANGE BUILDING', 'ARAFA', 0, 0, 0, 0];
        $rows[] = [$H, 'MR.HUMOUD CHUKWANI', 'ARAFA', 70000, 70000, 0, 70000];
        $rows[] = [$H, 'MR.NASSOR K.SAMAKI', 'ARAFA', 3727000, 727000, 0, 727000];

        foreach ([
            ['SUB SAHARA ALL', 1233652500], ['SALEH ALL', 66430000], ['ZINAMOLA', 140922500], ['ALHADAD', 166682000], ['NANJIM', 190300435], ['MASINGINI', 16665000],
            ['EVERGREEN', 201317350], ['SAID SEIF ALI', 48600000], ['OMAR HAMAD PEMBA', 4020000], ['MOHD AMOUR ABDALLAH', 347220000], ['MOHD KHAMIS', 25000000],
            ['MKUBWA BILAL', 2135100], ['MCL', 168913985], ['KIBOKO', 178056497], ['AFRIZANIA', 700], ['PATEL', 52423700], ['HOKOKAI', 31698800],
            ['MANSOUR NASSOR', 77725000], ['SHAKIR', 29617000], ['SAZUNA', 107125000], ['ENG.MBAROUK MASOUD', 16640000], ['RFB-DODOMA ELECTRICAL', 58217769],
            ['RFB-LIFT SUBCONTRACTOR', 51348530], ['KIGOROFANI HARDWARE STORE', 41038000],
        ] as [$d, $r]) {
            $rows[] = [$I, $d, null, $r, 0, 0, 0];
        }

        $rows[] = [$J, 'MR.NASSOR MISKIRY', null, 1000000, 1000000, 0, 1000000];
        $rows[] = [$J, 'MR.ALI NASSOR MISKIRY', null, 2000000, 2000000, 0, 2000000];
        $rows[] = [$J, 'MR.YUNUS A.GAARE', null, 0, 0, 0, 0];
        $rows[] = [$J, 'ASHA NASSOR MISKIRY', null, 0, 0, 0, 0];

        return $rows;
    }
}
