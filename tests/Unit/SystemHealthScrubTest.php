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

    /** @dataProvider secrets */
    public function test_adversarial_secrets_never_survive(string $line, array $mustNotContain): void
    {
        $out = ReportBuilder::scrub($line);
        foreach ($mustNotContain as $bad) {
            $this->assertStringNotContainsString($bad, $out, $line);
        }
    }

    public static function secrets(): array
    {
        return [
            'underscore names' => ['access_token=abc123secret DB_PASSWORD=hunter2 client_secret=zzz999', ['abc123secret', 'hunter2', 'zzz999']],
            'bearer header' => ['Authorization: Bearer abc.def.ghi-jkl', ['abc.def.ghi-jkl']],
            'jwt' => ['seen eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.SflKxwRJSMeKKF2QT4fw', ['eyJhbGciOiJIUzI1NiJ9', 'SflKxwRJSMeKKF2QT4fw']],
            'password is' => ['the password is hunter2 and ok', ['hunter2']],
            'quoted key' => ['api_key: "abc def ghi" end', ['abc def ghi', 'abc', 'ghi']],
            'url credentials' => ['connect https://apikey:s3cretValue@host.example/path failed', ['s3cretValue', 'apikey']],
            'duplicate entry name' => ["Duplicate entry 'John Mwakyusa' for key 'users_name_unique'", ['John', 'Mwakyusa']],
            'phone with spaces' => ['call +255 712 345 678 now', ['712 345 678', '255 712']],
            'path outside the project' => ['failed in /Users/someone/secret-project/app/Models/User.php', ['/Users/someone', 'secret-project']],
        ];
    }
}
