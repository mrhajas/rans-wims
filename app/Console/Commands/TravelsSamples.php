<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\PaymentVoucher;
use App\Models\Staff;
use App\Models\Travel;
use App\Models\TravelAgentOpening;
use App\Models\TravelAgentPayment;
use App\Models\TravelItem;
use App\Models\TravelTraveller;
use App\Models\User;
use App\Services\Travels\TravelVoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sample ticket agents, travels (every status, local and international, with a visa), an opening balance and voucher payments, so the module can be looked at. Everything is marked SAMPLE and `--remove` takes it all away. */
class TravelsSamples extends Command
{
    protected $signature = 'travels:samples {--remove : Remove the sample data instead of creating it}';

    protected $description = 'Create (or remove) sample Travels data for review';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        return $this->option('remove') ? $this->remove() : $this->create();
    }

    private function remove(): int
    {
        $agents = Contact::query()->where('name', 'like', '% (SAMPLE)')->pluck('id');
        $travels = Travel::withTrashed()->where('notes', self::MARK)->pluck('id');
        $pay = TravelAgentPayment::withTrashed()->whereIn('contact_id', $agents)->get();
        DB::transaction(function () use ($agents, $travels, $pay) {
            $pvIds = $pay->pluck('payment_voucher_id')->filter()->all();
            DB::table('payment_voucher_items')->whereIn('payment_voucher_id', $pvIds)->delete();
            PaymentVoucher::withTrashed()->whereIn('id', $pvIds)->forceDelete();
            TravelAgentPayment::withTrashed()->whereIn('contact_id', $agents)->forceDelete();
            TravelAgentOpening::withTrashed()->whereIn('contact_id', $agents)->forceDelete();
            TravelTraveller::withTrashed()->whereIn('travel_id', $travels)->forceDelete();
            TravelItem::withTrashed()->whereIn('travel_id', $travels)->forceDelete();
            Travel::withTrashed()->whereIn('id', $travels)->forceDelete();
            Contact::withTrashed()->whereIn('id', $agents)->forceDelete();
        });
        $this->info('Sample Travels data removed (' . $travels->count() . ' travels, ' . $agents->count() . ' agents, ' . $pay->count() . ' payments).');

        return self::SUCCESS;
    }

    private function create(): int
    {
        if (Travel::where('notes', self::MARK)->exists()) {
            $this->warn('Sample data already exists. Run with --remove first to start again.');

            return self::FAILURE;
        }
        $user = User::role('Super Admin')->orderBy('id')->first() ?: User::orderBy('id')->first();
        auth()->setUser($user);
        $acct = (int) $user->account_id;
        $zan = Contact::create(['name' => 'Zanzibar Travel Agency (SAMPLE)', 'category' => 'Ticket Agent', 'phone' => '+255 777 000 111', 'account_id' => $acct]);
        $dar = Contact::create(['name' => 'Dar Air Tickets (SAMPLE)', 'category' => 'Ticket Agent', 'phone' => '+255 777 000 222', 'account_id' => $acct]);
        $visa = Contact::create(['name' => 'Gulf Visa Services (SAMPLE)', 'category' => 'Visa Agent', 'phone' => '+255 777 000 333', 'account_id' => $acct]);
        $staff = Staff::query()->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'Active'))->orderBy('id')->limit(3)->get();
        $name = fn ($i, $d) => ($staff[$i] ?? null) ? ['traveller_type' => 'staff', 'staff_id' => $staff[$i]->id, 'user_id' => $staff[$i]->user_id, 'name' => $staff[$i]->name, 'phone' => $staff[$i]->mobile_no] : ['traveller_type' => 'other', 'name' => $d];
        TravelAgentOpening::create(['contact_id' => $zan->id, 'currency' => 'USD', 'amount' => 2500, 'as_of_date' => '2026-09-30', 'notes' => self::MARK . ': owed before Travels started', 'user_id' => $user->id, 'account_id' => $acct]);
        TravelAgentOpening::create(['contact_id' => $dar->id, 'currency' => 'TZS', 'amount' => 1800000, 'as_of_date' => '2026-09-01', 'notes' => self::MARK, 'user_id' => $user->id, 'account_id' => $acct]);

        $trip = function (array $t, array $people, string $status, array $items = []) use ($user, $acct) {
            $ticket = array_sum(array_column($people, 'ticket_amount'));
            $v = array_sum(array_column($people, 'visa_amount'));
            $travel = Travel::create($t + ['reference' => Travel::nextReference(), 'status' => $status, 'ticket_amount' => $ticket, 'visa_amount' => $v, 'total_amount' => $ticket + $v, 'notes' => self::MARK, 'user_id' => $user->id, 'account_id' => $acct,
                'scope' => Travel::scopeOfCountry($t['to_country'] ?? null), 'has_visa' => $v > 0]);
            foreach ($people as $i => $p) {
                TravelTraveller::create($p + ['travel_id' => $travel->id, 'sort_order' => $i]);
            }
            foreach ($items as $i => $it) {
                $rate = ($it['currency'] ?? 'TZS') === 'TZS' ? 1 : (float) $it['exchange_rate'];
                TravelItem::create($it + ['travel_id' => $travel->id, 'exchange_rate' => $rate, 'amount_tzs' => TravelItem::toTzs((float) $it['amount'], $rate), 'sort_order' => $i]);
            }
            if ($status === Travel::APPROVED) {
                $travel->forceFill(['decided_by_user_id' => $user->id, 'decided_at' => now()])->save();
            }
            if ($status === Travel::REJECTED) {
                $travel->forceFill(['decided_by_user_id' => $user->id, 'decided_at' => now(), 'decision_remarks' => 'Not budgeted (sample)'])->save();
            }
            if ($status === Travel::CANCELLED) {
                $travel->forceFill(['cancelled_by_user_id' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => 'Meeting moved online (sample)'])->save();
            }

            return $travel;
        };
        $now = now();
        $trip(['trip_type' => 'return', 'travel_type' => 'Conference', 'purpose' => 'Africa Construction Expo', 'from_place' => 'Zanzibar', 'from_country' => 'Tanzania', 'to_place' => 'Dubai', 'to_country' => 'United Arab Emirates', 'depart_date' => $now->copy()->addDays(12)->toDateString(), 'return_date' => $now->copy()->addDays(19)->toDateString(),
            'booking_date' => $now->copy()->subDays(5)->toDateString(), 'airline' => 'Emirates', 'flight_number' => 'EK 726', 'return_airline' => 'Emirates', 'return_flight_number' => 'EK 727', 'booking_reference' => 'X7K2LM', 'ticket_agent_contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600, 'visa_type' => 'Business', 'visa_country' => 'United Arab Emirates', 'visa_agent_contact_id' => $visa->id],
            [$name(0, 'Asha Juma') + ['ticket_amount' => 1150, 'visa_amount' => 120, 'ticket_number' => '176-2201110001'], $name(1, 'Omar Ali') + ['ticket_amount' => 1150, 'visa_amount' => 120, 'ticket_number' => '176-2201110002']], Travel::APPROVED, [
            ['category' => 'Hotel', 'description' => '6 nights, Dubai (booked by the agent)', 'contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600, 'amount' => 900],
            ['category' => 'Car rental', 'description' => 'Airport transfers and 3 days car', 'contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600, 'amount' => 210],
            ['category' => 'Food and drinks', 'description' => 'Per diem', 'contact_id' => null, 'currency' => 'USD', 'exchange_rate' => 2650, 'amount' => 360],
            ['category' => 'Leisure', 'description' => 'Desert safari', 'contact_id' => null, 'currency' => 'AED', 'exchange_rate' => 710, 'amount' => 450],
        ]);
        $trip(['trip_type' => 'one_way', 'travel_type' => 'Training', 'purpose' => 'Safety course', 'from_place' => 'Dar es Salaam', 'from_country' => 'Tanzania', 'to_place' => 'Muscat', 'to_country' => 'Oman', 'depart_date' => $now->copy()->addDays(30)->toDateString(), 'booking_date' => $now->copy()->subDays(2)->toDateString(),
            'airline' => 'Oman Air', 'flight_number' => 'WY 705', 'ticket_agent_contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600, 'visa_agent_contact_id' => $visa->id, 'visa_type' => 'Tourist'], [$name(2, 'Hassan Said') + ['ticket_amount' => 640, 'visa_amount' => 60]], Travel::APPROVED, [
            ['category' => 'Accommodation', 'description' => '4 nights', 'contact_id' => null, 'currency' => 'TZS', 'amount' => 780000],
        ]);
        $trip(['trip_type' => 'return', 'travel_type' => 'Site visit', 'purpose' => 'Project inspection', 'from_place' => 'Dar es Salaam', 'from_country' => 'Tanzania', 'to_place' => 'Dodoma', 'to_country' => 'Tanzania', 'depart_date' => $now->copy()->subDays(20)->toDateString(), 'return_date' => $now->copy()->subDays(18)->toDateString(),
            'booking_date' => $now->copy()->subDays(25)->toDateString(), 'airline' => 'Air Tanzania', 'flight_number' => 'TC 210', 'ticket_agent_contact_id' => $dar->id, 'currency' => 'TZS'], [$name(0, 'Asha Juma') + ['ticket_amount' => 380000, 'visa_amount' => 0]], Travel::APPROVED);
        $trip(['trip_type' => 'return', 'travel_type' => 'Meeting', 'purpose' => 'Supplier meeting', 'from_place' => 'Zanzibar', 'from_country' => 'Tanzania', 'to_place' => 'London', 'to_country' => 'United Kingdom', 'depart_date' => $now->copy()->addDays(45)->toDateString(), 'return_date' => $now->copy()->addDays(52)->toDateString(), 'booking_date' => $now->toDateString(),
            'airline' => 'Qatar Airways', 'flight_number' => 'QR 1480', 'ticket_agent_contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600, 'visa_agent_contact_id' => $visa->id, 'visa_type' => 'Standard Visitor'], [$name(1, 'Omar Ali') + ['ticket_amount' => 1480, 'visa_amount' => 190]], Travel::PENDING);
        $trip(['trip_type' => 'return', 'travel_type' => 'Training', 'to_place' => 'Nairobi', 'to_country' => 'Kenya', 'from_place' => 'Dar es Salaam', 'depart_date' => $now->copy()->addDays(10)->toDateString(), 'return_date' => $now->copy()->addDays(14)->toDateString(), 'booking_date' => $now->toDateString(), 'ticket_agent_contact_id' => $dar->id, 'currency' => 'TZS'],
            [$name(2, 'Hassan Said') + ['ticket_amount' => 520000, 'visa_amount' => 0]], Travel::REJECTED);
        $trip(['trip_type' => 'return', 'travel_type' => 'Meeting', 'to_place' => 'Cairo', 'to_country' => 'Egypt', 'from_place' => 'Dar es Salaam', 'depart_date' => $now->copy()->addDays(20)->toDateString(), 'return_date' => $now->copy()->addDays(24)->toDateString(), 'booking_date' => $now->toDateString(), 'ticket_agent_contact_id' => $zan->id, 'currency' => 'USD', 'exchange_rate' => 2600],
            [$name(0, 'Asha Juma') + ['ticket_amount' => 900, 'visa_amount' => 0]], Travel::CANCELLED);

        // payments, always through a Payment Voucher: one paid, one waiting, one released
        $make = function (float $amount, string $note) use ($zan, $user) {
            $p = DB::transaction(fn () => TravelVoucherService::create($zan, 'USD', $amount, 2600, $note, (int) $user->id));
            $p->voucher->forceFill(['internal_notes' => self::MARK])->save();

            return $p;
        };
        $paid = $make(1000, 'Part payment of the Dubai tickets');
        $paid->voucher->forceFill(['status' => PaymentVoucher::STATUS_AUTHORISED, 'approved_by_user_id' => $user->id, 'approved_at' => now()->subDays(3), 'authorised_by_user_id' => $user->id, 'authorised_at' => now()->subDays(2), 'paid_at' => now()->subDay(), 'payment_method' => 'Bank transfer', 'payment_reference' => 'TT-SAMPLE-1', 'paid_by_user_id' => $user->id])->save();
        DB::transaction(fn () => TravelVoucherService::markPaid($paid->voucher->fresh()));
        $make(500, 'Waiting for approval (sample)');
        $released = $make(300, 'Wrong agent (sample)');
        $released->voucher->forceFill(['status' => PaymentVoucher::STATUS_REJECTED])->save();
        DB::transaction(fn () => TravelVoucherService::release($released->voucher->fresh(), 'Voucher rejected.'));
        $this->info('Sample Travels data created: 3 agents, 6 travels (approved, waiting, rejected, cancelled; local and international), 2 opening balances and 3 voucher payments (paid, waiting, released). Remove it with: php artisan travels:samples --remove');

        return self::SUCCESS;
    }
}
