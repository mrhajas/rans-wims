<?php

namespace App\Http\Controllers;

use App\Events\TaskCommented;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\Request;

// Flat, append-only comment list — no edit/delete, matching CaseComment's
// established convention in this app.
class TaskCommentController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, Task $task)
    {
        $this->authorize('comment', $task);

        $data = $request->validate(['body' => 'required|string|max:3000']);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        log_activity(__('Comment added to :title.', ['title' => $task->title]), [], $task, 'Task Manager');

        event(new TaskCommented($task, $comment, $request->user()->id));

        return back()->with('message', __('Comment added.'));
    }
}
