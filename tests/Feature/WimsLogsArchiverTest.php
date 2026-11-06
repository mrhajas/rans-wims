<?php

namespace Tests\Feature;

use App\Models\WimsLogArchive;
use App\Models\WimsLogRun;
use App\Support\WimsLogs\ArchiveStore;
use App\Support\WimsLogs\Archiver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// WIMS Logs on its own (in-memory database, only the tables it needs): old records move into a verified archive, records still being worked on stay, every archived record
// reads back exactly, a damaged archive is noticed before anything is removed, and an archive can be put back.
class WimsLogsArchiverTest extends TestCase
{
    protected function setUp(): void
    {
        // the tests write their archives to a folder of their own and never touch storage/app/wims-logs
        ArchiveStore::$rootOverride = sys_get_temp_dir() . '/wims-logs-test-' . getmypid();
        parent::setUp();
        foreach (['wims_log_archives', 'wims_log_runs', 'slow_logs', 'wims_connect_logs'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('wims_log_runs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('account_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('user_name')->nullable();
            $t->string('trigger', 12)->default('manual'); $t->string('status', 12)->default('running'); $t->json('sources')->nullable(); $t->json('options')->nullable();
            $t->json('before')->nullable(); $t->json('after')->nullable(); $t->json('speed_before')->nullable(); $t->json('speed_after')->nullable();
            $t->decimal('speed_before_ms', 10, 2)->nullable(); $t->decimal('speed_after_ms', 10, 2)->nullable();
            $t->unsignedBigInteger('rows_archived')->default(0); $t->unsignedBigInteger('original_bytes')->default(0); $t->unsignedBigInteger('stored_bytes')->default(0);
            $t->bigInteger('db_freed_bytes')->default(0); $t->unsignedInteger('seconds')->default(0); $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamps();
        });
        Schema::create('wims_log_archives', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('run_id'); $t->string('source', 30); $t->string('table_name', 64); $t->unsignedBigInteger('rows_n')->default(0);
            $t->unsignedBigInteger('original_bytes')->default(0); $t->unsignedBigInteger('compressed_bytes')->default(0); $t->unsignedBigInteger('stored_bytes')->default(0);
            $t->timestamp('date_from')->nullable(); $t->timestamp('date_to')->nullable(); $t->string('path')->nullable(); $t->string('sha256', 64)->nullable();
            $t->boolean('verified')->default(false); $t->unsignedBigInteger('skipped_n')->default(0); $t->unsignedBigInteger('restored_n')->default(0); $t->timestamp('restored_at')->nullable();
            $t->boolean('closed')->default(false); $t->text('error')->nullable(); $t->timestamps();
        });
        Schema::create('slow_logs', function (Blueprint $t) {
            $t->id(); $t->string('kind'); $t->text('label')->nullable(); $t->string('fingerprint')->nullable(); $t->unsignedInteger('duration_ms')->default(0); $t->string('path')->nullable();
            $t->string('method', 10)->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedInteger('queries')->default(0); $t->decimal('memory_mb', 8, 1)->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('wims_connect_logs', function (Blueprint $t) {
            $t->id(); $t->string('event_key')->nullable(); $t->string('channel')->nullable(); $t->string('address')->nullable(); $t->string('subject')->nullable(); $t->text('message')->nullable();
            $t->longText('body_html')->nullable(); $t->string('status')->default('SENT'); $t->timestamp('created_at')->nullable();
        });
        $this->cleanFiles();
        $this->forgetSchemaMemo();
    }

    // SchemaCache remembers "this table exists" for the whole process, also the answer "no" it gave in an earlier test before these tables were made
    private function forgetSchemaMemo(): void
    {
        $p = new \ReflectionProperty(\App\Support\SchemaCache::class, 'memo');
        $p->setAccessible(true);
        $p->setValue(null, []);
        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->cleanFiles();
        ArchiveStore::$rootOverride = null;
        $this->forgetSchemaMemo();
        parent::tearDown();
    }

    private function cleanFiles(): void
    {
        $root = ArchiveStore::root();
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($root);
        }
    }

    private function slow(int $n, string $when): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('slow_logs')->insert(['kind' => 'request', 'label' => 'GET /items ünï ' . $i, 'fingerprint' => md5((string) $i), 'duration_ms' => 1600 + $i, 'path' => '/items', 'method' => 'GET', 'queries' => $i, 'memory_mb' => 43.0, 'created_at' => $when]);
        }
    }

    public function test_old_records_are_archived_verified_and_removed_and_recent_ones_stay(): void
    {
        $this->slow(30, now()->subDays(20)->toDateTimeString()); // older than the 7 live days
        $this->slow(5, now()->subDays(1)->toDateTimeString());   // recent

        $run = Archiver::run('manual', null, ['slow']);

        $this->assertSame('done', $run->status);
        $this->assertSame(30, (int) $run->rows_archived);
        $this->assertSame(5, DB::table('slow_logs')->count(), 'only the recent records stay live');
        $arc = WimsLogArchive::first();
        $this->assertTrue($arc->verified);
        $this->assertTrue($arc->closed);
        $this->assertSame(30, (int) $arc->rows_n);
        $this->assertGreaterThan(0, $arc->compressed_bytes);
        $this->assertLessThan($arc->original_bytes, $arc->compressed_bytes, 'the records are smaller compressed');
        $this->assertSame(64, strlen((string) $arc->sha256));
        $this->assertFileExists(ArchiveStore::bin((string) $arc->path));
        $this->assertFileExists(ArchiveStore::idxGz((string) $arc->path));
        $this->assertFileDoesNotExist(ArchiveStore::idx((string) $arc->path), 'the plain index is replaced by the compressed one');
    }

    public function test_every_archived_record_reads_back_exactly_and_can_be_searched_and_exported(): void
    {
        $this->slow(12, now()->subDays(30)->toDateTimeString());
        $before = DB::table('slow_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        Archiver::run('manual', null, ['slow']);
        $rel = (string) WimsLogArchive::first()->path;

        foreach ($before as $row) {
            $this->assertSame($row, ArchiveStore::record($rel, (string) $row['id']), 'record ' . $row['id'] . ' is exactly what it was');
        }
        $page = ArchiveStore::page($rel, 1, 5);
        $this->assertSame(12, $page['total']);
        $this->assertCount(5, $page['rows']);
        $this->assertSame(1, ArchiveStore::page($rel, 1, 50, '1607 ms')['total'], 'the one-line description is searchable');
        $this->assertSame(0, ArchiveStore::page($rel, 1, 50, 'nothing like this')['total']);
        $this->assertStringContainsString('ünï', (string) ArchiveStore::record($rel, '1')['label'], 'accented text is kept as it was');
        $this->assertCount(12, iterator_to_array(ArchiveStore::export($rel), false));
        $this->assertNull(ArchiveStore::record($rel, '99999'));
    }

    public function test_messages_still_being_worked_on_are_never_moved(): void
    {
        $old = now()->subDays(40)->toDateTimeString();
        foreach (['SENT', 'DELIVERED', 'FAILED', 'RETRYING', 'QUEUED'] as $status) {
            DB::table('wims_connect_logs')->insert(['event_key' => 'x', 'channel' => 'email', 'address' => 'a@b.c', 'subject' => 'S ' . $status, 'body_html' => str_repeat('<p>hello</p>', 200), 'status' => $status, 'created_at' => $old]);
        }

        $run = Archiver::run('manual', null, ['delivery']);

        $this->assertSame(2, (int) $run->rows_archived);
        $this->assertEqualsCanonicalizing(['FAILED', 'RETRYING', 'QUEUED'], DB::table('wims_connect_logs')->pluck('status')->all());
    }

    public function test_a_damaged_archive_is_noticed_before_anything_is_removed(): void
    {
        $this->slow(3, now()->subDays(30)->toDateTimeString());
        $rows = DB::table('slow_logs')->get()->map(fn ($r) => (array) $r)->all();

        $w = ArchiveStore::append('slow/run-99', $rows, 'id', 'created_at', fn ($r) => 'x');
        // one byte of the file is damaged
        $h = fopen(ArchiveStore::bin('slow/run-99'), 'r+b');
        fseek($h, (int) $w['entries'][1]['p'] + 3);
        fwrite($h, "\xff");
        fclose($h);

        $this->expectException(\RuntimeException::class);
        ArchiveStore::verify('slow/run-99', $w['entries'], $w['hashes']);
    }

    public function test_an_archive_can_be_put_back_exactly(): void
    {
        $this->slow(8, now()->subDays(30)->toDateTimeString());
        $before = DB::table('slow_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $run = Archiver::run('manual', null, ['slow']);
        $this->assertSame(0, DB::table('slow_logs')->count());

        $this->artisan('wims-logs:restore', ['archive' => (string) WimsLogArchive::first()->id])->assertSuccessful();

        $this->assertSame($before, DB::table('slow_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(8, (int) WimsLogArchive::first()->restored_n);
        $this->assertSame('done', $run->status);
    }

    public function test_only_one_run_at_a_time_and_a_forgotten_run_stops_blocking(): void
    {
        $first = Archiver::begin('manual', null, ['slow']);
        try {
            Archiver::begin('manual', null, ['slow']);
            $this->fail('a second run must not start while one is in progress');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('in progress', $e->getMessage());
        }

        DB::table('wims_log_runs')->where('id', $first->id)->update(['updated_at' => now()->subHours(5)]);
        $second = Archiver::begin('manual', null, ['slow']);

        $this->assertSame('failed', WimsLogRun::find($first->id)->status);
        $this->assertSame('running', $second->status);
    }

    public function test_nothing_is_archived_when_nothing_is_old_enough(): void
    {
        $this->slow(4, now()->subDays(1)->toDateTimeString());

        $run = Archiver::run('manual', null, ['slow']);

        $this->assertSame(0, (int) $run->rows_archived);
        $this->assertSame(4, DB::table('slow_logs')->count());
        $this->assertSame(0, WimsLogArchive::count(), 'an archive with no record is not kept');
        $this->assertFileDoesNotExist(ArchiveStore::bin('slow/run-' . $run->id));
    }

    public function test_a_run_by_hand_can_use_fewer_live_days_without_changing_the_saved_setting(): void
    {
        $this->slow(6, now()->subDays(3)->toDateTimeString()); // newer than the 7 saved days, older than 2
        $this->slow(4, now()->subHours(5)->toDateTimeString());

        $run = Archiver::run('manual', null, ['slow'], null, ['slow' => 2]);

        $this->assertSame(6, (int) $run->rows_archived, 'the days chosen for this run decide what is old enough');
        $this->assertSame(4, DB::table('slow_logs')->count());
        $this->assertSame(2, (int) ($run->options['keep_days']['slow'] ?? 0), 'the days used are written on the run');
        $this->assertSame(7, \App\Support\WimsLogs\WimsLogSettings::keepDays('slow'), 'the saved setting is untouched');
    }

    public function test_a_window_of_the_archived_records_can_be_read_with_and_without_a_search(): void
    {
        $this->slow(12, now()->subDays(20)->toDateTimeString());
        Archiver::run('manual', null, ['slow']);
        $rel = (string) WimsLogArchive::first()->path;

        $page = ArchiveStore::slice($rel, 5, 4, null, 12);
        $this->assertCount(4, $page['rows']);
        $this->assertSame(12, $page['total']);

        $found = ArchiveStore::slice($rel, 0, 50, '1605 ms');
        $this->assertSame(1, $found['total'], 'the search looks in the one-line description');
        $this->assertCount(1, $found['rows']);
        $this->assertSame([], ArchiveStore::slice($rel, 0, 50, 'no such thing')['rows']);
    }

    public function test_system_logs_the_activity_history_are_archived_and_read_back(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::create('activity_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('log_name')->nullable();
            $t->text('description');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('causer_type')->nullable();
            $t->unsignedBigInteger('causer_id')->nullable();
            $t->longText('properties')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        $this->forgetSchemaMemo();
        for ($i = 0; $i < 12; $i++) {
            DB::table('activity_log')->insert(['log_name' => 'Item', 'description' => 'Item ünï ' . $i . ' was updated', 'subject_type' => 'App\\Models\\Item', 'subject_id' => $i, 'causer_id' => 7, 'properties' => json_encode(['attributes' => ['name' => 'X' . $i]]), 'created_at' => now()->subDays(400)->toDateTimeString()]);
        }
        DB::table('activity_log')->insert(['log_name' => 'Item', 'description' => 'recent', 'created_at' => now()->subDays(3)->toDateTimeString()]);

        $run = Archiver::run('manual', null, ['system']);

        $this->assertSame(12, (int) $run->rows_archived, 'only what is older than the 365 days is moved');
        $this->assertSame(1, DB::table('activity_log')->count());
        $rel = (string) WimsLogArchive::first()->path;
        $e = iterator_to_array(ArchiveStore::lines($rel))[0];
        $this->assertStringContainsString('Item', $e['s']);
        $this->assertStringContainsString('user 7', $e['s']);
        $row = ArchiveStore::record($rel, (string) $e['i']);
        $this->assertSame('Item', $row['log_name']);
        $this->assertStringContainsString('attributes', (string) $row['properties'], 'the whole record is kept, not only its description');
        Schema::dropIfExists('activity_log');
    }
}
