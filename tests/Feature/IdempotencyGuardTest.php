<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Support\IdempotencyGuard;
use App\Support\IdempotentReplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// The double-submit guard on its own (in-memory database, only its own table): the first form submission wins, a retry gets the first result, nothing is created twice.
class IdempotencyGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('idempotency_keys');
        (require base_path('database/migrations/2026_12_30_100000_create_idempotency_keys_table.php'))->up();
    }

    private function req(?string $key, int $userId = 1, array $extra = []): Request
    {
        $r = Request::create('/x', 'POST', ['request_key' => $key] + $extra);
        $r->setUserResolver(fn () => (object) ['id' => $userId, 'account_id' => 1]);

        return $r;
    }

    private function key(?string $key, array $payload, int $userId = 1): ?array
    {
        return IdempotencyGuard::fromRequest($this->req($key, $userId), 'test.scope', $payload);
    }

    /** One create as the controllers do it: claim first, create, finish, all in one transaction. */
    private function create(?array $key, array &$created): void
    {
        DB::transaction(function () use ($key, &$created) {
            IdempotencyGuard::claim($key);
            $created[] = 'record';
            IdempotencyGuard::finish($key, new class { public function getKey() { return 77; } });
        });
    }

    public function test_no_key_means_no_guard_and_creation_is_unchanged(): void
    {
        $this->assertNull($this->key(null, ['a' => 1]));
        $this->assertNull($this->key('', ['a' => 1]));
        $created = [];
        $this->create(null, $created);
        $this->create(null, $created);
        $this->assertCount(2, $created);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_a_malformed_key_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->key('short', ['a' => 1]);
    }

    public function test_the_same_form_twice_creates_one_record_and_replays_the_first(): void
    {
        $created = [];
        $this->create($this->key('abcdefghijklmnop1234', ['a' => 1]), $created);
        $this->assertCount(1, $created);
        $this->assertSame('done', IdempotencyKey::first()->status);

        // a plain retry is recognised before anything is prepared
        $done = IdempotencyGuard::peek($this->key('abcdefghijklmnop1234', ['a' => 1]));
        $this->assertNotNull($done);

        // and a twin that got past that look is stopped inside its transaction, creating nothing
        try {
            $this->create($this->key('abcdefghijklmnop1234', ['a' => 1]), $created);
            $this->fail('the second submission must be a replay');
        } catch (IdempotentReplay $e) {
            $this->assertSame(77, $e->recordId);
        }
        $this->assertCount(1, $created);
        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_the_same_key_with_different_data_is_refused(): void
    {
        $created = [];
        $this->create($this->key('abcdefghijklmnop1234', ['a' => 1]), $created);
        $this->expectException(ValidationException::class);
        $this->create($this->key('abcdefghijklmnop1234', ['a' => 2]), $created);
    }

    public function test_the_same_data_in_another_order_and_without_files_is_the_same_form(): void
    {
        $a = IdempotencyGuard::hash(['x' => 1, 'items' => [['p' => 'a', 'amount' => '5.00']], 'attachments' => ['file'], 'confirm_duplicate' => false]);
        $b = IdempotencyGuard::hash(['items' => [['amount' => '5.00', 'p' => 'a']], 'x' => '1', 'confirm_duplicate' => true]);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, IdempotencyGuard::hash(['x' => 1, 'items' => [['p' => 'a', 'amount' => '6.00']]]));
    }

    public function test_another_person_with_the_same_key_does_not_collide(): void
    {
        $created = [];
        $this->create($this->key('abcdefghijklmnop1234', ['a' => 1], 1), $created);
        $this->create($this->key('abcdefghijklmnop1234', ['a' => 1], 2), $created);
        $this->assertCount(2, $created);
        $this->assertSame(2, IdempotencyKey::count());
    }

    public function test_a_failed_creation_leaves_no_key_so_the_retry_can_create(): void
    {
        $key = $this->key('abcdefghijklmnop1234', ['a' => 1]);
        try {
            DB::transaction(function () use ($key) {
                IdempotencyGuard::claim($key);
                throw new \RuntimeException('creation failed');
            });
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertSame(0, IdempotencyKey::count());
        $created = [];
        $this->create($key, $created);
        $this->assertCount(1, $created);
    }
}
