<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\User;
use App\Services\Signatories\Documents\GoodsReceivedNoteDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A person whose Super Admin role and approve ability the test decides. */
class CaTestUser extends User
{
    protected $table = 'users';

    public bool $superAdmin = false;

    public bool $mayApprove = false;

    public function hasRole($roles, ?string $guard = null): bool
    {
        return $this->superAdmin;
    }

    public function can($abilities, $arguments = []): bool
    {
        return $abilities === 'approve-checkins' && $this->mayApprove;
    }
}

// A check-in is Draft / Pending / Approved / Rejected, only Approved moves stock (draft = 0), approvers are Super Admin or a role granted
// "approve-checkins", GRN numbers follow each other, and a check-in from before approvals is approved by the system.
class CheckinApprovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('checkins');
        Schema::create('checkins', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->nullable();
            $t->boolean('draft')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('approved_by_role')->nullable();
            $t->unsignedBigInteger('rejected_by')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('grn_number')->nullable();
            $t->unsignedBigInteger('grn_generated_by')->nullable();
            $t->timestamp('grn_generated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function make(array $attrs): Checkin
    {
        $id = DB::table('checkins')->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);

        return Checkin::withoutGlobalScopes()->find($id);
    }

    public function test_the_four_statuses_come_from_draft_submitted_and_rejected(): void
    {
        $this->assertSame('Draft', $this->make(['draft' => 1])->status);
        $this->assertSame('Pending', $this->make(['draft' => 1, 'submitted_at' => now()])->status);
        $this->assertSame('Rejected', $this->make(['draft' => 1, 'submitted_at' => now(), 'rejected_at' => now()])->status);
        $this->assertSame('Approved', $this->make(['draft' => 0])->status);
        $this->assertTrue($this->make(['draft' => 1, 'submitted_at' => now()])->isPending());
    }

    public function test_the_status_scope_matches_the_status_attribute(): void
    {
        $this->make(['draft' => 1]);
        $this->make(['draft' => 1, 'submitted_at' => now()]);
        $this->make(['draft' => 1, 'submitted_at' => now()]);
        $this->make(['draft' => 1, 'submitted_at' => now(), 'rejected_at' => now()]);
        $this->make(['draft' => 0]);
        $this->make(['draft' => 0]);

        foreach (['Draft' => 1, 'Pending' => 2, 'Rejected' => 1, 'Approved' => 2] as $status => $count) {
            $rows = Checkin::withoutGlobalScopes()->status($status)->get();
            $this->assertCount($count, $rows, $status);
            $this->assertTrue($rows->every(fn ($c) => $c->status === $status), $status);
        }
    }

    public function test_a_check_in_from_before_approvals_is_approved_by_the_system(): void
    {
        $old = $this->make(['draft' => 0, 'approved_by_role' => Checkin::SYSTEM_APPROVER]);
        $this->assertTrue($old->approvedBySystem());

        $byPerson = $this->make(['draft' => 0, 'approved_by' => 5, 'approved_by_role' => 'Super Admin']);
        $this->assertFalse($byPerson->approvedBySystem());

        $this->assertFalse($this->make(['draft' => 1, 'submitted_at' => now()])->approvedBySystem());
    }

    public function test_only_a_super_admin_or_a_role_with_the_ability_may_approve(): void
    {
        $none = new CaTestUser();
        $super = new CaTestUser();
        $super->superAdmin = true;
        $granted = new CaTestUser();
        $granted->mayApprove = true;

        $this->assertFalse(Checkin::userCanApprove($none));
        $this->assertTrue(Checkin::userCanApprove($super));
        $this->assertTrue(Checkin::userCanApprove($granted));
        $this->assertFalse(Checkin::userCanApprove(null));
    }

    public function test_grn_numbers_follow_each_other_and_the_approval_ability_is_in_the_roles_screen(): void
    {
        $this->assertSame('GRN-0001', Checkin::nextGrnNumber());
        $this->make(['draft' => 0, 'grn_number' => 'GRN-0007']);
        $this->make(['draft' => 0, 'grn_number' => 'GRN-0012']);
        $this->assertSame('GRN-0013', Checkin::nextGrnNumber());

        $this->assertContains('approve-checkins', \App\Support\PermissionRegistry::allPermissionNames());
    }

    public function test_the_grn_signatures_say_who_did_each_step_and_name_the_system_when_nobody_did(): void
    {
        $doc = new GoodsReceivedNoteDocument();
        $this->assertSame('grn', $doc::key());

        $system = $this->make(['draft' => 0, 'user_id' => 3, 'approved_by_role' => 'WIMS System', 'approved_at' => now(), 'grn_number' => 'GRN-0001', 'grn_generated_at' => now()]);
        $h = $doc->history($system);
        $this->assertSame(3, $h['prepared']['user_id']);
        $this->assertNull($h['approved']['user_id']);
        $this->assertSame('WIMS Admin', $h['approved']['name']);
        $this->assertSame('WIMS Admin', $h['generated']['name']);
        $this->assertSame('final', $doc->state($system));

        $person = $this->make(['draft' => 0, 'user_id' => 3, 'approved_by' => 9, 'approved_at' => now(), 'grn_number' => 'GRN-0002', 'grn_generated_by' => 9, 'grn_generated_at' => now()]);
        $h = $doc->history($person);
        $this->assertSame(9, $h['approved']['user_id']);
        $this->assertSame(9, $h['generated']['user_id']);

        $pending = $this->make(['draft' => 1, 'submitted_at' => now(), 'user_id' => 3]);
        $this->assertArrayNotHasKey('approved', $doc->history($pending));
        $this->assertSame('in_progress', $doc->state($pending));
    }
}
