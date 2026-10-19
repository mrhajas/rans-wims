<?php

namespace App\Console\Commands;

use App\Models\StaffContract;
use Illuminate\Console\Command;

/**
 * Tells the right people when a staff contract is about to end: at 60, 30, 14, 7 and 1 days, on the day it ends, and once it has run out.
 * Who is told (and how) is set in the the Staff Notification page ("Staff contract ending"); until then Super Admin and Admin get an app notification.
 */
class StaffContractsRemind extends Command
{
    protected $signature = 'staff-contracts:remind {--dry : only list what would be sent}';

    protected $description = 'Remind about staff contracts that are ending soon or have ended';

    private const STEPS = [60, 30, 14, 7, 1, 0];

    public function handle(): int
    {
        $sent = 0;
        StaffContract::query()->with('staff:id,name,staff_number,status')->where('status', StaffContract::STATUS_ACTIVE)->whereNotNull('end_date')
            ->whereHas('staff', fn ($q) => $q->whereIn('status', ['Active', 'Retired Active']))->get()
            ->each(function (StaffContract $c) use (&$sent) {
                $left = $c->daysLeft();
                $step = collect(self::STEPS)->filter(fn ($s) => $left <= $s)->min();
                if ($step === null || ($c->last_reminder_days !== null && $c->last_reminder_days <= $step)) {
                    return;
                }
                $name = optional($c->staff)->name ?: __('A staff member');
                $when = $left < 0 ? __('ended :n day(s) ago', ['n' => abs($left)]) : ($left === 0 ? __('ends today') : __('ends in :n day(s)', ['n' => $left]));
                $message = $name.' ('.optional($c->staff)->staff_number.'): '.__('contract').' '.$c->contract_number.' '.$when.' ('.$c->end_date->format('d M Y').').';
                if ($this->option('dry')) {
                    $this->line($message);

                    return;
                }
                $url = route('staffs.show', $c->staff_id).'?tab=contracts';
                // Staff workflow page (Who Gets Notified / Messages)
                \App\Services\Workflow\WorkflowMessenger::dispatch('staff', 'contract_ending', $c, null, [
                    'account_id' => optional($c->staff)->account_id ?? 1, 'title' => $left < 0 ? __('Staff contract ended') : __('Staff contract ending'), 'message' => $message, 'url' => $url,
                    'details' => [__('Staff') => $name, __('Contract') => $c->contract_number, __('Type') => $c->type, __('Ends') => $c->end_date->format('d M Y')],
                    'values' => ['staff' => $name, 'contract' => (string) $c->contract_number, 'ends' => $c->end_date->format('d M Y')],
                    'dedupe' => $c->id . '|' . $step,
                ]);
                $c->forceFill(['last_reminder_days' => $step])->saveQuietly();
                $sent++;
            });
        $this->info($sent.' reminder(s) '.($this->option('dry') ? 'would be sent' : 'sent').'.');

        return self::SUCCESS;
    }
}
