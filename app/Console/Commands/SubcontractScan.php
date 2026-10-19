<?php

namespace App\Console\Commands;

use App\Models\SubcontractAgreement as A;
use App\Services\Subcontracts\SubcontractNotifier;
use App\Services\Subcontracts\SubcontractSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/** Daily: agreements that end soon (or are late) and items that have waited longer than the settings allow. Each alert is sent once a day at most. */
class SubcontractScan extends Command
{
    protected $signature = 'subcontracts:scan';

    protected $description = 'Alert about subcontract agreements that end soon and approvals that wait too long';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subcontract_agreements')) {
            return self::SUCCESS;
        }
        $s = SubcontractSettings::get();
        $n = 0;
        $soon = A::withoutGlobalScopes()->whereIn('status', [A::APPROVED, A::ACTIVE])->whereNotNull('expected_completion')->whereDate('expected_completion', '<=', now()->addDays((int) $s['completion_alert_days']))->get();
        foreach ($soon as $a) {
            $key = 'subcontract-due-' . $a->id . '-' . now()->toDateString();
            if (Cache::add($key, 1, now()->addDay())) {
                $late = $a->expected_completion->lt(now()->startOfDay());
                SubcontractNotifier::fire('completion_due', 'agreement', $a, null, $late ? __(':r is past its expected completion date (:d).', ['r' => $a->reference, 'd' => $a->expected_completion->toDateString()]) : __(':r ends on :d.', ['r' => $a->reference, 'd' => $a->expected_completion->toDateString()]));
                $n++;
            }
        }
        $cut = now()->subHours((int) $s['review_alert_hours']);
        foreach (DB::table('subcontract_payment_requests')->whereNull('deleted_at')->whereIn('status', ['Submitted', 'Under Review'])->where('updated_at', '<', $cut)->get() as $r) {
            $key = 'subcontract-wait-' . $r->id . '-' . now()->toDateString();
            if (Cache::add($key, 1, now()->addDay())) {
                SubcontractNotifier::fire('request_review', 'request', \App\Models\SubcontractPaymentRequest::withoutGlobalScopes()->find($r->id), null, __('Waiting since :d', ['d' => substr((string) $r->updated_at, 0, 16)]));
                $n++;
            }
        }
        $this->info("Subcontract alerts sent: $n");

        return self::SUCCESS;
    }
}
