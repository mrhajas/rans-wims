<?php

namespace Tests\Feature;

use App\Support\Performance\RequestStats;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestStatsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wims-rt-test-' . getmypid();
        @mkdir($this->dir, 0775, true);
        RequestStats::$dirOverride = $this->dir;
        Schema::dropIfExists('perf_request_stats');
        Schema::create('perf_request_stats', function (Blueprint $t) {
            $t->id(); $t->timestamp('bucket')->nullable(); $t->string('kind', 8); $t->string('module', 60); $t->unsignedInteger('n')->default(0); $t->unsignedBigInteger('sum_ms')->default(0);
            $t->unsignedInteger('max_ms')->default(0); $t->unsignedInteger('errors')->default(0); $t->unsignedInteger('client_errors')->default(0); $t->unsignedBigInteger('sum_queries')->default(0);
            for ($i = 0; $i < 10; $i++) {
                $t->unsignedInteger('h' . $i)->default(0);
            }
            $t->unique(['bucket', 'kind', 'module']);
        });

        $p = new \ReflectionProperty(\App\Support\SchemaCache::class, 'memo');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        RequestStats::$dirOverride = null;
        Schema::dropIfExists('perf_request_stats');
        parent::tearDown();
    }

    private function lines(array $rows): void
    {
        $out = '';
        foreach ($rows as [$ms, $status, $kind, $module]) {
            $out .= time() . "\t$ms\t$status\t$kind\t$module\t5\n";
        }
        file_put_contents(RequestStats::file(), $out, FILE_APPEND);
    }

    private function age(): void
    {
        foreach (glob($this->dir . '/batch-*.log') as $f) {
            touch($f, time() - 60); // a batch still being written is left alone for 20 seconds
        }
    }

    public function test_bars_and_percentiles_read_the_histogram(): void
    {
        $this->assertSame(0, RequestStats::bar(10));
        $this->assertSame(2, RequestStats::bar(200));
        $this->assertSame(9, RequestStats::bar(99999));
        // 90 fast answers (under 50 ms) and 10 slow ones (1.5 to 3 s): typical is fast, the slowest 5% is in the slow bar
        $counts = [90, 0, 0, 0, 0, 0, 10, 0, 0, 0];
        $this->assertLessThanOrEqual(50, RequestStats::percentile($counts, 50));
        $p95 = RequestStats::percentile($counts, 95);
        $this->assertGreaterThan(1500, $p95);
        $this->assertLessThanOrEqual(3000, $p95);
        $this->assertNull(RequestStats::percentile(array_fill(0, 10, 0), 95));
    }

    public function test_the_recorded_lines_are_added_up_per_hour_kind_and_module_and_added_to_on_the_next_run(): void
    {
        $this->lines([[120, 200, 'page', 'items'], [180, 200, 'page', 'items'], [4000, 500, 'page', 'items'], [30, 200, 'api', 'alerts'], [45, 403, 'api', 'alerts']]);
        $this->assertSame(0, RequestStats::aggregate(), 'a file that was just written is left for later');
        $this->age();
        $this->assertSame(5, RequestStats::aggregate());

        $items = DB::table('perf_request_stats')->where('module', 'items')->first();
        $this->assertSame(3, (int) $items->n);
        $this->assertSame(4300, (int) $items->sum_ms);
        $this->assertSame(4000, (int) $items->max_ms);
        $this->assertSame(1, (int) $items->errors);
        $this->assertSame(15, (int) $items->sum_queries);
        $this->assertSame(1, (int) DB::table('perf_request_stats')->where('module', 'alerts')->value('client_errors'));

        // the same hour again: added to, not replaced
        $this->lines([[100, 200, 'page', 'items']]);
        RequestStats::aggregate();
        $this->age();
        RequestStats::aggregate();
        $this->assertSame(4, (int) DB::table('perf_request_stats')->where('module', 'items')->value('n'));
        $this->assertSame(1, DB::table('perf_request_stats')->where('module', 'items')->count());
    }

    public function test_the_summary_gives_typical_and_slowest_times_and_the_slowest_modules(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [60 + $i, 200, 'page', 'items'];
            $rows[] = [2500, 200, 'page', 'warehouses'];
        }
        $this->lines($rows);
        RequestStats::aggregate();
        $this->age();
        RequestStats::aggregate();
        $s = RequestStats::summary(24);

        $this->assertTrue($s['available']);
        $this->assertSame(40, $s['pages']['n']);
        $this->assertSame(0, $s['api']['n']);
        $this->assertSame('warehouses', $s['worst'][0]['module'], 'the module with the slowest 95th percentile comes first');
        $this->assertGreaterThan($s['worst'][1]['p95'], $s['worst'][0]['p95']);
        $this->assertSame(2500, $s['worst'][0]['max']);
    }
}
