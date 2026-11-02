<?php

namespace Tests\Unit;

use App\Support\SystemHealth\Collector;
use App\Support\SystemHealth\ReportBuilder;
use PHPUnit\Framework\TestCase;

// The System Health report may only carry facts about the system: nothing personal or secret may leave in an error line.
class SystemHealthScrubTest extends TestCase
{
    public function test_secrets_and_personal_data_are_removed_from_error_text(): void
    {
        $s = ReportBuilder::scrub('Failed for bob@example.com token=abc123 password: hunter2 (Connection: mysql, SQL: insert into users values (\'secret\')) {"userId":1}');
        $this->assertStringNotContainsString('bob@example.com', $s);
        $this->assertStringNotContainsString('abc123', $s);
        $this->assertStringNotContainsString('hunter2', $s);
        $this->assertStringNotContainsString('insert into', $s);
        $this->assertStringNotContainsString('userId', $s);
    }

    public function test_long_numbers_ids_and_length_are_limited(): void
    {
        $s = ReportBuilder::scrub('Card 1234567890123 id ABCDEF0123456789ABCDEF0123456789 ' . str_repeat('x', 400));
        $this->assertStringNotContainsString('1234567890123', $s);
        $this->assertStringNotContainsString('ABCDEF0123456789ABCDEF0123456789', $s);
        $this->assertLessThanOrEqual(240, mb_strlen($s));
    }

    public function test_the_five_ratings_follow_the_score(): void
    {
        $this->assertSame('excellent', Collector::rate(95));
        $this->assertSame('very_good', Collector::rate(85));
        $this->assertSame('good', Collector::rate(70));
        $this->assertSame('fair', Collector::rate(50));
        $this->assertSame('bad', Collector::rate(20));
    }
}
