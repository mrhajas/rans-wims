<?php

namespace Tests\Feature;

use App\Http\Controllers\BackgroundTaskController;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\BackgroundTasks\TaskService;
use App\Services\FeatureManager;
use App\Support\BackgroundTasks\Kinds\LoginLogsPdf;
use App\Support\BackgroundTasks\Registry;
use App\Support\BackgroundTasks\TaskKind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** A report kind the tests control. */
class FakeReportKind extends TaskKind
{
    public static bool $allowed = true;

    public static bool $explode = false;

    public function key(): string
    {
        return 'fake-report';
    }

    public function label(array $params): string
    {
        return 'Fake report';
    }

    public function allowed(User $user): bool
    {
        return self::$allowed;
    }

    public function params(Request $request): array
    {
        return (array) $request->input('params', []);
    }

    public function build(array $params, User $user): array
    {
        if (self::$explode) {
            throw new \RuntimeException('SQLSTATE[HY000] secret table name');
        }

        return ['content' => '%PDF-1.4 fake', 'name' => 'fake.pdf', 'mime' => 'application/pdf'];
    }
}

/** A person whose roles the test decides (the real role tables are not part of these tests). */
class BgTestUser extends User
{
    protected $table = 'users';

    public bool $superAdmin = false;

    public function hasRole($roles, ?string $guard = null): bool
    {
        return $this->superAdmin;
    }
}

// Background Tasks on their own (in-memory database with only the tables they need): the same job cannot be started twice at once, a task can be claimed only once,
// permissions are asked again when it runs, nobody else can see or fetch a person's file, stuck tasks are freed, and switching the feature off returns to the old way.
class BackgroundTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['background_tasks', 'users', 'settings', 'system_features', 'accounts', 'roles', 'model_has_roles'] as $t) {
            Schema::dropIfExists($t);
        }
        (require base_path('database/migrations/2026_12_30_100000_create_background_tasks_table.php'))->up();
        Schema::create('accounts', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('accounts')->insert(['id' => 1, 'name' => 'Test']);
        Schema::create('roles', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name')->default('web');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('model_has_roles', function ($t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('account_id')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('settings', function ($t) {
            $t->id();
            $t->string('tec_key');
            $t->text('tec_value')->nullable();
            $t->unsignedBigInteger('account_id')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('system_features', function ($t) {
            $t->id();
            $t->string('key');
            $t->boolean('is_enabled')->default(true);
            $t->string('depends_on')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('system_features')->insert(['key' => 'background_tasks', 'is_enabled' => 1]);
        FeatureManager::flushCache();
        Storage::fake('local');
        FakeReportKind::$allowed = true;
        FakeReportKind::$explode = false;
        Registry::$override = ['fake-report' => new FakeReportKind()];
    }

    protected function tearDown(): void
    {
        Registry::$override = null;
        FeatureManager::flushCache();
        parent::tearDown();
    }

    private function person(int $id, bool $superAdmin = false): BgTestUser
    {
        DB::table('users')->updateOrInsert(['id' => $id], ['name' => 'Person ' . $id, 'account_id' => 1, 'is_active' => 1]);
        $u = BgTestUser::query()->withoutGlobalScopes()->find($id);
        $u->superAdmin = $superAdmin;

        return $u;
    }

    private function request(User $user, array $data = []): Request
    {
        $r = Request::create('/x', 'GET', $data);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    public function test_the_same_job_cannot_be_started_twice_at_once(): void
    {
        $ana = $this->person(1);
        [$first, $firstNew] = TaskService::start($ana, new FakeReportKind(), ['a' => 1]);
        [$second, $secondNew] = TaskService::start($ana, new FakeReportKind(), ['a' => 1]);

        $this->assertTrue($firstNew);
        $this->assertFalse($secondNew);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BackgroundTask::query()->count());

        // another choice is another job; another person has their own
        [$other] = TaskService::start($ana, new FakeReportKind(), ['a' => 2]);
        [$theirs, $theirsNew] = TaskService::start($this->person(2), new FakeReportKind(), ['a' => 1]);
        $this->assertNotSame($first->id, $other->id);
        $this->assertTrue($theirsNew);
        $this->assertNotSame($first->id, $theirs->id);
    }

    public function test_the_database_itself_refuses_a_second_active_lock(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);
        $this->expectException(\Illuminate\Database\QueryException::class);
        BackgroundTask::query()->create(['account_id' => 1, 'user_id' => 1, 'kind' => 'fake-report', 'label' => 'x', 'subject_key' => 'x', 'status' => 'queued', 'active_lock' => $task->getRawOriginal('active_lock')]);
    }

    public function test_a_task_can_be_claimed_only_once(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);

        $this->assertTrue(TaskService::claim($task->id));
        $this->assertFalse(TaskService::claim($task->id), 'a second process must not get the same task');
        $this->assertNull(TaskService::run($task->id), 'a task already running is not run again');
    }

    public function test_a_run_finishes_frees_the_lock_and_keeps_the_file_private(): void
    {
        $ana = $this->person(1);
        [$task] = TaskService::start($ana, new FakeReportKind(), []);

        $this->assertSame(BackgroundTask::DONE, TaskService::run($task->id));
        $task->refresh();
        $this->assertNull($task->getRawOriginal('active_lock'));
        $this->assertSame('done', $task->status);
        Storage::disk('local')->assertExists($task->result_path);
        $this->assertStringStartsWith('background-tasks/1/', $task->result_path);
        $this->assertArrayNotHasKey('result_path', $task->toToast(), 'the browser is never told where the file is');
        $this->assertArrayNotHasKey('active_lock', $task->toToast());

        // the job can be asked for again now that it is finished
        [$again, $new] = TaskService::start($ana, new FakeReportKind(), []);
        $this->assertTrue($new);
        $this->assertNotSame($task->id, $again->id);
    }

    public function test_permission_is_asked_again_when_the_task_runs(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);
        FakeReportKind::$allowed = false; // the permission was taken away after the task was queued

        $this->assertSame(BackgroundTask::FAILED, TaskService::run($task->id));
        $task->refresh();
        $this->assertNull($task->result_path);
        $this->assertNull($task->getRawOriginal('active_lock'));
        $this->assertStringContainsString('permission', $task->error);
    }

    public function test_a_person_who_can_no_longer_sign_in_gets_no_report(): void
    {
        $ana = $this->person(1);
        [$task] = TaskService::start($ana, new FakeReportKind(), []);
        DB::table('users')->where('id', 1)->update(['is_active' => 0]);

        $this->assertSame(BackgroundTask::FAILED, TaskService::run($task->id));
        $this->assertNull($task->refresh()->result_path);
    }

    public function test_internal_error_text_is_never_shown_to_the_person(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);
        FakeReportKind::$explode = true;

        $this->assertSame(BackgroundTask::FAILED, TaskService::run($task->id));
        $task->refresh();
        $this->assertStringNotContainsString('SQLSTATE', $task->error);
        $this->assertStringNotContainsString('secret', $task->error);
        $this->assertNull($task->getRawOriginal('active_lock'));
    }

    public function test_nobody_else_can_list_or_fetch_a_persons_file(): void
    {
        $ana = $this->person(1);
        $ben = $this->person(2);
        $admin = $this->person(3, true);
        [$task] = TaskService::start($ana, new FakeReportKind(), []);
        TaskService::run($task->id);
        $controller = app(BackgroundTaskController::class);

        $mine = $controller->active($this->request($ana))->getData(true)['tasks'];
        $theirs = $controller->active($this->request($ben))->getData(true)['tasks'];
        $this->assertCount(1, $mine);
        $this->assertCount(0, $theirs);
        $this->assertCount(0, $controller->active($this->request($admin))->getData(true)['tasks'], 'a Super Admin sees their own unless they ask for the whole account');
        $this->assertCount(1, $controller->active($this->request($admin, ['all' => 1]))->getData(true)['tasks']);

        try {
            $controller->download($this->request($ben), $task->id);
            $this->fail('another person must not get the file');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode(), 'answered as "not found", so its existence is not revealed');
        }
        try {
            $controller->dismiss($this->request($ben), $task->id);
            $this->fail('another person must not close it');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(200, $controller->download($this->request($ana), $task->id)->getStatusCode());
        $this->assertSame(200, $controller->download($this->request($admin), $task->id)->getStatusCode());
    }

    public function test_the_file_is_refused_when_the_permission_has_since_gone(): void
    {
        $ana = $this->person(1);
        [$task] = TaskService::start($ana, new FakeReportKind(), []);
        TaskService::run($task->id);
        FakeReportKind::$allowed = false;

        $this->expectException(HttpException::class);
        app(BackgroundTaskController::class)->download($this->request($ana), $task->id);
    }

    public function test_a_stuck_task_is_failed_and_its_lock_freed(): void
    {
        $ana = $this->person(1);
        [$task] = TaskService::start($ana, new FakeReportKind(), []);
        TaskService::claim($task->id);
        DB::table('background_tasks')->where('id', $task->id)->update(['last_heartbeat_at' => now()->subMinutes(TaskService::RUNNING_STUCK_MINUTES + 5)]);

        $result = TaskService::sweep();
        $task->refresh();
        $this->assertSame(1, $result['failed_stuck']);
        $this->assertSame('failed', $task->status);
        $this->assertNull($task->getRawOriginal('active_lock'));

        [, $new] = TaskService::start($ana, new FakeReportKind(), []);
        $this->assertTrue($new, 'after the sweep the same job can be started again');
    }

    public function test_a_task_nobody_started_is_run_by_the_sweep_and_an_old_one_is_failed(): void
    {
        $ana = $this->person(1);
        [$waiting] = TaskService::start($ana, new FakeReportKind(), ['n' => 1]);
        [$forgotten] = TaskService::start($ana, new FakeReportKind(), ['n' => 2]);
        DB::table('background_tasks')->where('id', $waiting->id)->update(['created_at' => now()->subMinute()]);
        DB::table('background_tasks')->where('id', $forgotten->id)->update(['created_at' => now()->subMinutes(TaskService::QUEUED_GIVE_UP_MINUTES + 1)]);

        TaskService::sweep();

        $this->assertSame('done', $waiting->refresh()->status, 'when no process could be started, the scheduler still does the work');
        $this->assertSame('failed', $forgotten->refresh()->status);
        $this->assertNull($forgotten->getRawOriginal('active_lock'));
    }

    public function test_old_files_are_removed_after_a_week(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);
        TaskService::run($task->id);
        $task->refresh();
        DB::table('background_tasks')->where('id', $task->id)->update(['finished_at' => now()->subDays(TaskService::KEEP_FILE_DAYS + 1)]);

        $this->assertSame(1, TaskService::sweep()['files_removed']);
        Storage::disk('local')->assertMissing($task->result_path);
        $this->assertNull($task->refresh()->result_path);
    }

    public function test_switched_off_nothing_starts_and_the_old_way_is_used(): void
    {
        $this->assertTrue(TaskService::enabled());
        DB::table('system_features')->where('key', 'background_tasks')->update(['is_enabled' => 0]);
        FeatureManager::flushCache();

        $this->assertFalse(TaskService::enabled());
        $ana = $this->person(1);
        $response = app(BackgroundTaskController::class)->store($this->request($ana, ['kind' => 'fake-report']));
        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['disabled'], 'the page is told to make the report the usual way');
        $this->assertSame(0, BackgroundTask::query()->count());
    }

    public function test_a_task_queued_before_the_switch_was_turned_off_does_not_run(): void
    {
        [$task] = TaskService::start($this->person(1), new FakeReportKind(), []);
        DB::table('system_features')->where('key', 'background_tasks')->update(['is_enabled' => 0]);
        FeatureManager::flushCache();

        $this->assertSame(BackgroundTask::FAILED, TaskService::run($task->id));
        $this->assertNull($task->refresh()->result_path);
    }

    public function test_the_choices_are_checked_and_only_known_ones_are_kept(): void
    {
        $kind = new LoginLogsPdf();
        $clean = $kind->params($this->request($this->person(1), ['params' => ['search' => 'abc', 'sort' => 'username', 'sort_direction' => 'asc', 'evil' => 'x', 'start_date' => '2026-01-02', 'role' => '']]));
        $this->assertEquals(['search' => 'abc', 'sort' => 'username', 'sort_direction' => 'asc', 'start_date' => '2026-01-02'], $clean);

        $this->expectException(ValidationException::class);
        $kind->params($this->request($this->person(1), ['params' => ['sort' => 'password; drop table users']]));
    }

    public function test_the_same_choices_are_the_same_job_whatever_their_order(): void
    {
        $kind = new LoginLogsPdf();
        $this->assertSame($kind->subject(['b' => 2, 'a' => 1]), $kind->subject(['a' => 1, 'b' => 2]));
        $this->assertNotSame($kind->subject(['a' => 1]), $kind->subject(['a' => 2]));
    }
}
