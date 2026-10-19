<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Models\StaffContract;
use App\Models\StaffHistory;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sample contracts for the staff who have none yet: a spread of the situations the module handles (permanent, a fixed term that is
 * about to end, one ending within days, one already overdue, a renewal with its earlier contract kept as history, a probation, and a
 * stopped contract in the history). Everything is marked SAMPLE so `--remove` deletes exactly the samples.
 */
class SeedStaffContractSamples extends Command
{
    protected $signature = 'wims:seed-staff-contract-samples {--remove : Delete the sample contracts (and their history) instead}';

    protected $description = 'Add (or remove) sample contracts for staff that have no contract yet';

    private const MARK = 'SAMPLE';

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }
        $by = User::role('Super Admin')->where('is_active', true)->where('username', 'not like', 'tempdebug%')->orderBy('id')->first();
        $staff = Staff::query()->orderBy('id')->get()->filter(fn (Staff $s) => ! StaffContract::where('staff_id', $s->id)->exists())->values();
        if ($staff->isEmpty()) {
            $this->info('Every staff member already has a contract; nothing added.');

            return self::SUCCESS;
        }

        $today = today();
        // one scenario per person, in turn
        $scenarios = [
            'permanent', 'ending_soon', 'ending_urgent', 'renewed', 'overdue', 'probation', 'stopped_then_new',
        ];
        $made = 0;
        foreach ($staff as $i => $s) {
            $scenario = $scenarios[$i % count($scenarios)];
            $salary = (float) $s->basic_salary ?: 800000;
            $position = $s->position;
            $c = fn (array $a) => StaffContract::create($a + [
                'contract_number' => StaffContract::nextNumber(), 'staff_id' => $s->id, 'position' => $position, 'basic_salary' => $salary,
                'notice_period_days' => 30, 'status' => StaffContract::STATUS_ACTIVE, 'notes' => self::MARK.': sample contract', 'created_by_user_id' => $by?->id,
            ]);
            $log = fn (string $event, string $title, array $o = []) => StaffHistory::record($s, $event, $title, $o + ['user_id' => $by?->id, 'details' => ['sample' => true]]);

            switch ($scenario) {
                case 'permanent':
                    $k = $c(['type' => 'Permanent', 'start_date' => $today->copy()->subYears(3)->toDateString(), 'end_date' => null, 'signed_date' => $today->copy()->subYears(3)->subDays(5)->toDateString(), 'probation_months' => 6]);
                    $log('contract_added', __('Contract :n added (Permanent)', ['n' => $k->contract_number]), ['contract_id' => $k->id, 'occurred_on' => $k->start_date->toDateString()]);
                    break;
                case 'ending_soon':
                    $k = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(10)->toDateString(), 'end_date' => $today->copy()->addDays(34)->toDateString(), 'signed_date' => $today->copy()->subMonths(10)->subDays(3)->toDateString(), 'probation_months' => 3]);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $k->contract_number]), ['contract_id' => $k->id, 'occurred_on' => $k->start_date->toDateString()]);
                    break;
                case 'ending_urgent':
                    $k = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(11)->subDays(7)->toDateString(), 'end_date' => $today->copy()->addDays(6)->toDateString(), 'signed_date' => $today->copy()->subMonths(11)->subDays(10)->toDateString()]);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $k->contract_number]), ['contract_id' => $k->id, 'occurred_on' => $k->start_date->toDateString()]);
                    break;
                case 'renewed':
                    $old = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(18)->toDateString(), 'end_date' => $today->copy()->subMonths(6)->subDay()->toDateString(), 'status' => StaffContract::STATUS_RENEWED, 'basic_salary' => round($salary * 0.9, -3)]);
                    $new = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(6)->toDateString(), 'end_date' => $today->copy()->addMonths(18)->toDateString(), 'renewed_from_id' => $old->id, 'notes' => self::MARK.': renewal with a better salary']);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $old->contract_number]), ['contract_id' => $old->id, 'occurred_on' => $old->start_date->toDateString()]);
                    $log('contract_renewed', __('Contract :n renewed as :m', ['n' => $old->contract_number, 'm' => $new->contract_number]), ['contract_id' => $new->id, 'occurred_on' => $new->start_date->toDateString()]);
                    break;
                case 'overdue':
                    $k = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(12)->subDays(12)->toDateString(), 'end_date' => $today->copy()->subDays(12)->toDateString(), 'signed_date' => $today->copy()->subMonths(12)->subDays(15)->toDateString()]);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $k->contract_number]), ['contract_id' => $k->id, 'occurred_on' => $k->start_date->toDateString()]);
                    break;
                case 'probation':
                    $k = $c(['type' => 'Probation', 'start_date' => $today->copy()->subMonths(1)->toDateString(), 'end_date' => $today->copy()->addMonths(2)->addDays(18)->toDateString(), 'probation_months' => 3, 'notice_period_days' => 7]);
                    $log('contract_added', __('Contract :n added (Probation)', ['n' => $k->contract_number]), ['contract_id' => $k->id, 'occurred_on' => $k->start_date->toDateString()]);
                    break;
                case 'stopped_then_new':
                    $old = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(20)->toDateString(), 'end_date' => $today->copy()->subMonths(4)->toDateString(), 'status' => StaffContract::STATUS_ENDED,
                        'stopped_reason_type' => 'Mutual agreement', 'stopped_reason' => self::MARK.': ended early by agreement; rehired on better terms.', 'stopped_on' => $today->copy()->subMonths(8)->toDateString(), 'stopped_by_user_id' => $by?->id]);
                    $new = $c(['type' => 'Fixed term', 'start_date' => $today->copy()->subMonths(4)->addDay()->toDateString(), 'end_date' => $today->copy()->addMonths(8)->toDateString(), 'notes' => self::MARK.': new contract after rehire']);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $old->contract_number]), ['contract_id' => $old->id, 'occurred_on' => $old->start_date->toDateString()]);
                    $log('contract_stopped', __('Contract :n stopped: :r', ['n' => $old->contract_number, 'r' => 'Mutual agreement']), ['contract_id' => $old->id, 'reason_type' => 'Mutual agreement', 'reason' => self::MARK.': ended early by agreement; rehired on better terms.', 'occurred_on' => $old->stopped_on->toDateString()]);
                    $log('contract_added', __('Contract :n added (Fixed term)', ['n' => $new->contract_number]), ['contract_id' => $new->id, 'occurred_on' => $new->start_date->toDateString()]);
                    break;
            }
            $made++;
        }
        $this->info("Added sample contracts for {$made} staff member(s).");

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $ids = StaffContract::query()->where('notes', 'like', self::MARK.'%')->orWhere('stopped_reason', 'like', self::MARK.'%')->pluck('id');
        $history = StaffHistory::query()->where('details', 'like', '%"sample":true%')->delete();
        $n = StaffContract::query()->whereIn('id', $ids)->orderByDesc('id')->get()->each(fn ($c) => $c->forceDelete())->count();
        $this->info("Removed {$n} sample contract(s) and {$history} history entr".($history === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
