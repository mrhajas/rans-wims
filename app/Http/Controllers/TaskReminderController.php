<?php

namespace App\Http\Controllers;

use App\Models\TaskReminder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Tecdiary\Laravel\Attachments\Attachment;

class TaskReminderController extends Controller
{
    public function index(Request $request)
    {
        $reminders = TaskReminder::with(['attachments', 'user:id,name'])
            ->orderByRaw('is_completed asc')
            ->orderBy('due_at')
            ->get();

        return Inertia::render('TaskReminder/Index', [
            'reminders' => $reminders,
            'emailEnabled' => (bool) $request->user()->task_reminders_email_enabled,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateReminder($request);

        $reminder = TaskReminder::create($data);

        if ($request->hasFile('attachments')) {
            $reminder->saveAttachments($request->file('attachments'));
        }

        return back()->with('message', __('Reminder created.'));
    }

    public function update(Request $request, TaskReminder $task_reminder)
    {
        $data = $this->validateReminder($request);

        if ($data['due_at'] !== $task_reminder->due_at->format('Y-m-d H:i')) {
            $data['notified_at'] = null;
        }

        $task_reminder->update($data);

        if ($request->hasFile('attachments')) {
            $task_reminder->saveAttachments($request->file('attachments'));
        }

        return back()->with('message', __('Reminder updated.'));
    }

    public function toggleComplete(TaskReminder $task_reminder)
    {
        $task_reminder->update([
            'is_completed' => ! $task_reminder->is_completed,
            'completed_at' => $task_reminder->is_completed ? null : now(),
        ]);

        return back()->with('message', $task_reminder->is_completed ? __('Reminder completed.') : __('Reminder reopened.'));
    }

    public function destroy(TaskReminder $task_reminder)
    {
        $task_reminder->delete();

        return back()->with('message', __('Reminder deleted.'));
    }

    public function restore(TaskReminder $task_reminder)
    {
        $task_reminder->restore();

        return back()->with('message', __('Reminder restored.'));
    }

    public function downloadAttachment(TaskReminder $task_reminder, Attachment $attachment)
    {
        if ((int) $attachment->model_id !== (int) $task_reminder->id || $attachment->model_type !== TaskReminder::class) {
            abort(404);
        }

        return Storage::disk($attachment->disk)->download($attachment->filepath, $attachment->filename);
    }

    public function print(Request $request)
    {
        return view('task-reminders-print', $this->printData($request, true));
    }

    public function pdf(Request $request)
    {
        $pdf = Pdf::loadView('task-reminders-print', $this->printData($request, false));

        return $pdf->download('task-reminders-' . now()->format('Ymd-His') . '.pdf');
    }

    protected function printData(Request $request, bool $autoPrint): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = TaskReminder::with('user:id,name')->orderBy('due_at');

        if (! empty($data['from'])) {
            $query->whereDate('due_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('due_at', '<=', $data['to']);
        }

        return [
            'reminders' => $query->get(),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'autoPrint' => $autoPrint,
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
        ];
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['email_enabled' => 'required|boolean']);

        $user = $request->user();
        $user->update(['task_reminders_email_enabled' => $data['email_enabled']]);

        return response()->json(['email_enabled' => (bool) $user->fresh()->task_reminders_email_enabled]);
    }

    protected function validateReminder(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'notes' => 'nullable|string|max:2000',
            'due_date' => 'required|date',
            'due_time' => 'required|date_format:H:i',
            'priority' => 'required|in:low,normal,high,urgent',
            'category' => 'nullable|string|max:60',
            'attachments' => 'nullable|array|max:1',
            'attachments.*' => 'file|max:5120|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,zip,txt,csv',
        ]);

        return [
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'due_at' => $data['due_date'] . ' ' . $data['due_time'],
            'priority' => $data['priority'],
            'category' => $data['category'] ?? null,
        ];
    }
}
