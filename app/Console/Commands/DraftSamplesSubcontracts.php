<?php

namespace App\Console\Commands;

use App\Models\ComplianceRecord;
use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractClaim as C;
use App\Models\SubcontractPaymentRequest as R;
use App\Models\SubcontractVariation as V;
use App\Models\Warehouse;
use App\Services\Subcontracts\SubcontractClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draft SAMPLE records for trying "remove the draft status and approve": 2 agreements, 2 variations, 2 payment requests, 2 claims (with a line each) and 1 compliance
 * renewal that is started but not submitted. Everything is written straight into the tables (no controller, no event), so no notification, SMS or email is sent.
 * Every sample carries the word SAMPLE in its title or description, and --fresh removes exactly those. Parents the drafts need (an Active agreement, Completed and
 * verified agreements for the claims, a compliance record) are SAMPLE records too. Running it again without --fresh creates nothing twice.
 */
class DraftSamplesSubcontracts extends Command
{
    protected $signature = 'draft-samples:subcontracts {--fresh : remove the previous samples first}';

    protected $description = 'Create SAMPLE draft subcontract records and a started compliance renewal to try "remove draft and approve"';

    private const TAG = 'SAMPLE';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->wipe();
        } elseif (A::withoutGlobalScopes()->where('title', 'like', self::TAG . '%')->exists()) {
            $this->info('The samples already exist. Use --fresh to replace them.');
            $this->report();

            return self::SUCCESS;
        }

        $contact = Contact::withoutGlobalScopes()->where('category', 'SubContractors')->orderBy('id')->first();
        $second = Contact::withoutGlobalScopes()->where('category', 'SubContractors')->where('id', '<>', optional($contact)->id)->orderBy('id')->first() ?: $contact;
        $site = Warehouse::withoutGlobalScopes()->whereNotIn('id', Warehouse::withoutGlobalScopes()->mainWarehouses()->pluck('id'))->orderBy('id')->first();
        if (! $contact || ! $site) {
            $this->error('No subcontractor or project warehouse found to attach the samples to.');

            return self::FAILURE;
        }
        if (class_exists(SubcontractClaims::class) && SubcontractClaims::requiresEvidence()) {
            $this->warn('Claims need evidence documents on the agreement (setting). The sample claims can be created, but submitting them will ask for a document.');
        }

        DB::transaction(function () use ($contact, $second, $site) {
            $now = now();
            $base = ['account_id' => 1, 'warehouse_id' => $site->id, 'currency' => 'TZS', 'user_id' => 1, 'source' => 'manual', 'agreement_type' => 'lump_sum', 'remarks' => self::TAG . ' record for trying "remove draft and approve". Safe to delete.'];

            // two draft agreements
            foreach ([['SAMPLE draft agreement: roof repairs', 18000000], ['SAMPLE draft agreement: boundary wall', 42500000]] as $i => [$title, $amount]) {
                $this->agreement($base, $title, $amount, A::DRAFT, $i === 0 ? $contact : $second);
            }

            // an Active agreement the drafted variations and requests hang on
            $active = $this->agreement($base, 'SAMPLE active agreement (for the draft variations and requests)', 60000000, A::ACTIVE, $contact, [
                'submitted_by_user_id' => 1, 'submitted_at' => $now, 'approved_by_user_id' => 1, 'approved_at' => $now, 'start_date' => $now->toDateString(),
            ]);

            foreach ([['SAMPLE draft variation: extra drainage', 'increase', 3500000], ['SAMPLE draft variation: omitted paving', 'reduction', 1200000]] as [$title, $dir, $amount]) {
                $v = new V();
                $v->forceFill(['reference' => V::nextReference(), 'account_id' => 1, 'agreement_id' => $active->id, 'title' => $title, 'reason' => self::TAG . ' variation for trying "remove draft and approve".',
                    'direction' => $dir, 'amount' => $amount, 'status' => V::DRAFT, 'requested_by_user_id' => 1])->save();
            }

            foreach ([['SAMPLE draft payment request: first stage of the work', 6500000], ['SAMPLE draft payment request: second stage of the work', 4000000]] as [$text, $amount]) {
                $r = new R();
                $r->forceFill(['reference' => R::nextReference(), 'account_id' => 1, 'agreement_id' => $active->id, 'contact_id' => $active->contact_id, 'warehouse_id' => $site->id, 'request_date' => $now->toDateString(),
                    'currency' => 'TZS', 'requested_amount' => $amount, 'kind' => 'work', 'description' => $text, 'status' => R::DRAFT, 'requested_by_user_id' => 1])->save();
            }

            // each draft claim has one line on its own Completed and verified agreement, so it can be submitted
            foreach ([['SAMPLE completed agreement (for a draft claim) A', 5000000, $contact, 'SAMPLE draft claim: completed works A'], ['SAMPLE completed agreement (for a draft claim) B', 3200000, $second, 'SAMPLE draft claim: completed works B']] as [$title, $amount, $who, $text]) {
                $done = $this->agreement($base, $title, $amount, A::COMPLETED, $who, [
                    'submitted_by_user_id' => 1, 'submitted_at' => $now, 'approved_by_user_id' => 1, 'approved_at' => $now, 'start_date' => $now->copy()->subMonths(3)->toDateString(),
                    'actual_completion' => $now->toDateString(), 'completed_by_user_id' => 1, 'completed_at' => $now, 'completion_note' => self::TAG,
                    'completion_verified_by_user_id' => 1, 'completion_verified_at' => $now, 'completion_verify_note' => self::TAG,
                ]);
                $c = new C();
                $c->forceFill(['reference' => C::nextReference(), 'account_id' => 1, 'contact_id' => $done->contact_id, 'warehouse_id' => $site->id, 'currency' => 'TZS', 'request_date' => $now->toDateString(),
                    'status' => C::DRAFT, 'requested_total' => $amount, 'approved_total' => 0, 'description' => $text, 'requested_by_user_id' => 1])->save();
                DB::table('subcontract_claim_lines')->insert(['claim_id' => $c->id, 'agreement_id' => $done->id, 'requested_amount' => $amount, 'status' => 'Draft', 'note' => self::TAG, 'created_at' => $now, 'updated_at' => $now]);
            }

            $this->complianceRenewal();
        });

        $this->info('Draft samples created.');
        $this->report();

        return self::SUCCESS;
    }

    private function agreement(array $base, string $title, int $amount, string $status, $contact, array $more = []): A
    {
        $a = new A();
        $a->forceFill($base + ['reference' => A::nextReference(), 'contact_id' => $contact->id, 'title' => $title, 'scope' => self::TAG . ' scope: ' . $title, 'original_amount' => $amount, 'status' => $status] + $more)->save();

        return $a;
    }

    /** A copy of an existing compliance record (so every required field is real), titled SAMPLE, with a renewal that was started and not submitted. */
    private function complianceRenewal(): void
    {
        $source = ComplianceRecord::withoutGlobalScopes()->where('title', 'not like', self::TAG . '%')->whereNotNull('expiry_date')->orderBy('id')->first();
        if (! $source) {
            $this->warn('No compliance record to copy, so no sample renewal was made.');

            return;
        }
        $max = (int) DB::table('compliance_records')->selectRaw("MAX(CAST(SUBSTRING(compliance_number, 5) AS UNSIGNED)) m")->where('compliance_number', 'like', 'CMP-%')->value('m');
        $attrs = collect($source->getAttributes())->except(['id', 'created_at', 'updated_at', 'deleted_at', 'photo'])->all();
        $rec = new ComplianceRecord();
        $rec->forceFill($attrs)->forceFill(['compliance_number' => 'CMP-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT), 'title' => 'SAMPLE renewal in progress (' . $source->title . ')',
            'description' => self::TAG . ' record for trying "remove draft and approve" on a renewal. Safe to delete.', 'status' => 'Renewal in Progress'])->save();
        DB::table('compliance_renewal_history')->insert(['compliance_record_id' => $rec->id, 'previous_issue_date' => $rec->issue_date, 'previous_expiry_date' => $rec->expiry_date,
            'renewal_started_at' => now(), 'status' => 'Renewal in Progress', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Removes exactly the SAMPLE records (and what hangs on them). */
    private function wipe(): void
    {
        DB::transaction(function () {
            $agreementIds = DB::table('subcontract_agreements')->where('title', 'like', self::TAG . '%')->pluck('id');
            $claimIds = DB::table('subcontract_claims')->where('description', 'like', self::TAG . '%')->pluck('id');
            DB::table('subcontract_claim_lines')->whereIn('claim_id', $claimIds)->orWhereIn('agreement_id', $agreementIds)->delete();
            DB::table('subcontract_claims')->whereIn('id', $claimIds)->delete();
            DB::table('subcontract_payment_requests')->where(fn ($q) => $q->where('description', 'like', self::TAG . '%')->orWhereIn('agreement_id', $agreementIds))->delete();
            DB::table('subcontract_variations')->where(fn ($q) => $q->where('title', 'like', self::TAG . '%')->orWhereIn('agreement_id', $agreementIds))->delete();
            DB::table('subcontract_agreements')->whereIn('id', $agreementIds)->delete();

            $recordIds = DB::table('compliance_records')->where('title', 'like', self::TAG . '%')->pluck('id');
            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                if ($name !== 'compliance_records' && Schema::hasColumn($name, 'compliance_record_id')) {
                    DB::table($name)->whereIn('compliance_record_id', $recordIds)->delete();
                }
            }
            DB::table('compliance_records')->whereIn('id', $recordIds)->delete();
        });
        $this->info('Previous samples removed.');
    }

    private function report(): void
    {
        $this->table(['Kind', 'Reference', 'Status', 'Title / description'], collect()
            ->merge(DB::table('subcontract_agreements')->where('title', 'like', self::TAG . '%')->get(['reference', 'status', 'title'])->map(fn ($x) => ['Agreement', $x->reference, $x->status, $x->title]))
            ->merge(DB::table('subcontract_variations')->where('title', 'like', self::TAG . '%')->get(['reference', 'status', 'title'])->map(fn ($x) => ['Variation', $x->reference, $x->status, $x->title]))
            ->merge(DB::table('subcontract_payment_requests')->where('description', 'like', self::TAG . '%')->get(['reference', 'status', 'description'])->map(fn ($x) => ['Payment request', $x->reference, $x->status, $x->description]))
            ->merge(DB::table('subcontract_claims')->where('description', 'like', self::TAG . '%')->get(['reference', 'status', 'description'])->map(fn ($x) => ['Claim', $x->reference, $x->status, $x->description]))
            ->merge(DB::table('compliance_records')->where('title', 'like', self::TAG . '%')->get(['compliance_number', 'status', 'title'])->map(fn ($x) => ['Compliance renewal', $x->compliance_number, $x->status, $x->title]))
            ->all());
    }
}
