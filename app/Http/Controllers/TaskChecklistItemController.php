<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use Illuminate\Http\Request;

class TaskChecklistItemController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function store(Request $request, Task $task)
    {
        $this->authorize('manageChecklist', $task);

        $data = $request->validate(['title' => 'required|string|max:200']);

        $maxPosition = (int) $task->checklistItems()->max('position');

        TaskChecklistItem::create([
            'task_id' => $task->id,
            'title' => $data['title'],
            'position' => $maxPosition + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        log_activity(__('Checklist item ":item" added to :title.', ['item' => $data['title'], 'title' => $task->title]), [], $task, 'Task Manager');

        \App\Services\TaskWorkflowNotifier::fire('checklist_changed', $task, $request->user(), __('Task Checklist Changed'), 'Checklist item "' . $data['title'] . '" was added to ' . $task->title . ' by ' . $request->user()->name . '.');

        return back()->with('message', __('Checklist item added.'));
    }

    public function update(Request $request, Task $task, TaskChecklistItem $checklist_item)
    {
        $this->authorize('manageChecklist', $task);
        abort_unless((int) $checklist_item->task_id === (int) $task->id, 404);

        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'is_done' => 'sometimes|boolean',
        ]);

        $wasDone = $checklist_item->is_done;
        $checklist_item->update($data);

        if (array_key_exists('is_done', $data) && $data['is_done'] !== $wasDone) {
            log_activity(
                __(':item marked as :state on :title.', [
                    'item' => $checklist_item->title,
                    'state' => $checklist_item->is_done ? __('done') : __('not done'),
                    'title' => $task->title,
                ]),
                [],
                $task,
                'Task Manager'
            );
        }

        \App\Services\TaskWorkflowNotifier::fire('checklist_changed', $task, $request->user(), __('Task Checklist Changed'), 'Checklist item "' . $checklist_item->title . '" of ' . $task->title . (array_key_exists('is_done', $data) && $data['is_done'] !== $wasDone ? ($checklist_item->is_done ? ' was ticked' : ' was un-ticked') : ' was edited') . ' by ' . $request->user()->name . '.');

        return back()->with('message', __('Checklist item updated.'));
    }

    public function destroy(Request $request, Task $task, TaskChecklistItem $checklist_item)
    {
        $this->authorize('manageChecklist', $task);
        abort_unless((int) $checklist_item->task_id === (int) $task->id, 404);

        $title = $checklist_item->title;
        $checklist_item->delete();

        log_activity(__('Checklist item ":item" removed from :title.', ['item' => $title, 'title' => $task->title]), [], $task, 'Task Manager');

        \App\Services\TaskWorkflowNotifier::fire('checklist_changed', $task, $request->user(), __('Task Checklist Changed'), 'Checklist item "' . $title . '" was removed from ' . $task->title . ' by ' . $request->user()->name . '.');

        return back()->with('message', __('Checklist item removed.'));
    }
}
