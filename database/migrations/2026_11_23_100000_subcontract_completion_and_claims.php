<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontract Completion and Contract Payment Requests (claims).
 *
 *  1. subcontract_agreements: who completed an agreement and who verified the completion.
 *  2. subcontract_claims / subcontract_claim_lines: ONE request to pay a subcontractor for several completed agreements, decided line by line. Approving a line creates
 *     the ordinary per-agreement payment request (subcontract_payment_requests.claim_line_id), so the voucher / ledger / report pipeline is unchanged.
 *  3. subcontract_payments.claim_id / claim_line_id and payment_vouchers.subcontract_claim_id: ONE Payment Voucher can now carry the payments of several lines, so the old
 *     unique index on subcontract_payments.payment_voucher_id (one payment, one voucher) is replaced by a plain index plus two composite unique indexes that still stop the
 *     same request or the same claim line being put on a voucher twice.
 *
 * Safe to run twice: every table, column and index is checked first.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'subcontract_payments_payment_voucher_id_unique';

    public function up(): void
    {
        if (Schema::hasTable('subcontract_agreements')) {
            foreach (['completed_by_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('completed_by_user_id')->nullable(),
                'completed_at' => fn (Blueprint $t) => $t->timestamp('completed_at')->nullable(),
                'completion_note' => fn (Blueprint $t) => $t->text('completion_note')->nullable(),
                'completion_verified_by_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('completion_verified_by_user_id')->nullable(),
                'completion_verified_at' => fn (Blueprint $t) => $t->timestamp('completion_verified_at')->nullable(),
                'completion_verify_note' => fn (Blueprint $t) => $t->text('completion_verify_note')->nullable()] as $col => $add) {
                if (! Schema::hasColumn('subcontract_agreements', $col)) {
                    Schema::table('subcontract_agreements', $add);
                }
            }
        }

        if (! Schema::hasTable('subcontract_claims')) {
            Schema::create('subcontract_claims', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('account_id')->nullable()->index();
                $t->string('reference', 40)->unique();                  // SCL-2026-0001
                $t->unsignedBigInteger('contact_id')->index();          // the subcontractor
                $t->unsignedBigInteger('warehouse_id')->nullable()->index(); // the project chosen as a filter
                $t->string('currency', 3)->default('TZS');              // one currency per claim
                $t->date('request_date');
                $t->string('status', 20)->default('Draft')->index();    // worked out from its lines (SubcontractClaims::recalc), never typed
                $t->decimal('requested_total', 16, 2)->default(0);
                $t->decimal('approved_total', 16, 2)->default(0);
                $t->text('description')->nullable();
                $t->unsignedBigInteger('requested_by_user_id')->nullable();
                $t->unsignedBigInteger('submitted_by_user_id')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->json('extra_attributes')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'status'], 'sc_claim_contact_status');
            });
        }

        if (! Schema::hasTable('subcontract_claim_lines')) {
            Schema::create('subcontract_claim_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('claim_id')->index();
                $t->unsignedBigInteger('agreement_id')->index();
                $t->decimal('requested_amount', 16, 2);
                $t->decimal('approved_amount', 16, 2)->nullable();
                $t->string('status', 12)->default('Draft')->index();    // Draft Pending Approved Rejected Returned Deferred Cancelled
                $t->text('decision_reason')->nullable();
                $t->unsignedBigInteger('decided_by_user_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->unsignedBigInteger('request_id')->nullable()->index(); // the per-agreement payment request created when the line is approved
                $t->boolean('override')->default(false);
                $t->text('override_reason')->nullable();
                $t->unsignedBigInteger('override_by_user_id')->nullable();
                $t->json('snapshot')->nullable();                       // revised value, eligible, paid, pending, approved-unpaid when it was requested
                $t->text('note')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['claim_id', 'agreement_id'], 'sc_claim_line_unique');
                $t->index(['agreement_id', 'status'], 'sc_claim_line_agr_status');
            });
        }

        $this->column('subcontract_payment_requests', 'claim_line_id', fn (Blueprint $t) => $t->unsignedBigInteger('claim_line_id')->nullable()->index());
        $this->column('subcontract_payments', 'claim_id', fn (Blueprint $t) => $t->unsignedBigInteger('claim_id')->nullable()->index());
        $this->column('subcontract_payments', 'claim_line_id', fn (Blueprint $t) => $t->unsignedBigInteger('claim_line_id')->nullable()->index());
        $this->column('payment_vouchers', 'subcontract_claim_id', fn (Blueprint $t) => $t->unsignedBigInteger('subcontract_claim_id')->nullable()->index());

        if (Schema::hasTable('subcontract_payments')) {
            if ($this->hasIndex('subcontract_payments', self::OLD_UNIQUE)) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
            }
            if (! $this->hasIndex('subcontract_payments', 'sc_pay_voucher')) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->index('payment_voucher_id', 'sc_pay_voucher'));
            }
            if (! $this->hasIndex('subcontract_payments', 'sc_pay_voucher_request')) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->unique(['payment_voucher_id', 'request_id'], 'sc_pay_voucher_request'));
            }
            if (! $this->hasIndex('subcontract_payments', 'sc_pay_voucher_line')) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->unique(['payment_voucher_id', 'claim_line_id'], 'sc_pay_voucher_line'));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subcontract_payments')) {
            foreach (['sc_pay_voucher_line', 'sc_pay_voucher_request'] as $u) {
                if ($this->hasIndex('subcontract_payments', $u)) {
                    Schema::table('subcontract_payments', fn (Blueprint $t) => $t->dropUnique($u));
                }
            }
            if ($this->hasIndex('subcontract_payments', 'sc_pay_voucher')) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->dropIndex('sc_pay_voucher'));
            }
            // the old rule can only come back when no voucher carries two payments
            $shared = DB::table('subcontract_payments')->whereNotNull('payment_voucher_id')->groupBy('payment_voucher_id')->havingRaw('COUNT(*) > 1')->exists();
            if (! $shared && ! $this->hasIndex('subcontract_payments', self::OLD_UNIQUE)) {
                Schema::table('subcontract_payments', fn (Blueprint $t) => $t->unique('payment_voucher_id', self::OLD_UNIQUE));
            }
        }
        foreach ([['payment_vouchers', 'subcontract_claim_id'], ['subcontract_payments', 'claim_line_id'], ['subcontract_payments', 'claim_id'], ['subcontract_payment_requests', 'claim_line_id']] as [$table, $col]) {
            if (Schema::hasColumn($table, $col)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        Schema::dropIfExists('subcontract_claim_lines');
        Schema::dropIfExists('subcontract_claims');
        foreach (['completion_verify_note', 'completion_verified_at', 'completion_verified_by_user_id', 'completion_note', 'completed_at', 'completed_by_user_id'] as $col) {
            if (Schema::hasColumn('subcontract_agreements', $col)) {
                Schema::table('subcontract_agreements', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }

    private function column(string $table, string $col, \Closure $add): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $col)) {
            Schema::table($table, $add);
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::connection()->getDatabaseName())->where('table_name', $table)->where('index_name', $name)->exists();
    }
};
