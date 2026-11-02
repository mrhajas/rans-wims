<?php

namespace App\Http\Controllers;

use App\Models\BackgroundTask;
use App\Services\BackgroundTasks\TaskService;
use App\Support\BackgroundTasks\Registry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Starting a background report, watching it, and fetching the finished file. Every answer is limited to the person's own tasks (a Super Admin may also look at the
 * whole account); a task of someone else is answered as "not found", never "not allowed", so its existence is not revealed.
 */
class BackgroundTaskController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (! TaskService::enabled()) {
            return response()->json(['message' => __('Background tasks are switched off. The report is made on its own page.'), 'disabled' => true], 409);
        }
        $data = $request->validate(['kind' => 'required|string|max:60', 'params' => 'nullable|array']);
        if (strlen((string) json_encode($data['params'] ?? [])) > 4000) {
            return response()->json(['message' => __('The choices for this report are too long.')], 422);
        }
        $kind = Registry::get($data['kind']);
        if (! $kind) {
            return response()->json(['message' => __('This kind of report cannot be made in the background.')], 422);
        }
        abort_unless($kind->allowed($user), 403);
        if ($refusal = $kind->startRefusal($request)) {
            return response()->json(['message' => $refusal, 'locked' => true], 423);
        }
        $params = $kind->params($request);
        [$task, $new] = TaskService::start($user, $kind, $params);

        return response()->json([
            'task' => $task->toToast(),
            'already_running' => ! $new,
            'message' => $new ? __('Started. You can keep working; you will be told when it is ready.') : __('This report is already being prepared.'),
        ], $new ? 201 : 200);
    }

    /** The person's running tasks and the finished ones they have not closed yet. Polled by the toast only while something is running. */
    public function active(Request $request)
    {
        $user = $request->user();
        $query = BackgroundTask::query()->where('account_id', $user->account_id);
        if (! ($request->boolean('all') && $user->hasRole('Super Admin'))) {
            $query->where('user_id', $user->id);
        }
        $tasks = $query->where(function ($w) {
            $w->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])
                ->orWhere(fn ($f) => $f->whereIn('status', [BackgroundTask::DONE, BackgroundTask::FAILED])->whereNull('dismissed_at')->where('finished_at', '>=', now()->subDay()));
        })->orderBy('id')->limit(8)->get();

        // a task still waiting after a few seconds had no process started for it: try once more (the scheduler's sweep is the safety net after that)
        foreach ($tasks as $t) {
            if ($t->status === BackgroundTask::QUEUED && $t->created_at->lt(now()->subSeconds(15)) && Cache::add('background-task-kick-' . $t->id, 1, 30)) {
                TaskService::spawn($t->id);
            }
        }

        return response()->json(['tasks' => $tasks->map->toToast()->values(), 'enabled' => TaskService::enabled()]);
    }

    public function dismiss(Request $request, int $id)
    {
        $task = $this->mine($request, $id);
        if (! $task->isActive()) {
            $task->forceFill(['dismissed_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, int $id)
    {
        $task = $this->mine($request, $id);
        $kind = Registry::get($task->kind);
        abort_unless($kind && $task->status === BackgroundTask::DONE && $task->result_path && Storage::disk('local')->exists($task->result_path), 404);
        // the same extra step as the page it came from (Login Logs ask for the password again)
        if ($class = $kind->downloadMiddleware()) {
            $stop = app($class)->handle($request, fn () => null);
            if ($stop instanceof \Symfony\Component\HttpFoundation\Response) {
                return $stop;
            }
        }
        abort_unless($kind->allowed($request->user()), 403);
        TaskService::note('Background task file downloaded', ['kind' => $task->kind, 'task' => $task->id], $task);

        return Storage::disk('local')->download($task->result_path, $task->result_name ?: ('report-' . $task->id . '.pdf'), ['Content-Type' => $task->result_mime ?: 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    /** A task of this account that the person owns (a Super Admin may take any of the account). Anything else is "not found". */
    private function mine(Request $request, int $id): BackgroundTask
    {
        $user = $request->user();
        $task = BackgroundTask::query()->where('account_id', $user->account_id)->find($id);
        abort_unless($task && ((int) $task->user_id === (int) $user->id || $user->hasRole('Super Admin')), 404);

        return $task;
    }
}
