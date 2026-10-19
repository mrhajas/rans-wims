<?php

namespace App\Services\SmartBrief;

use App\Models\User;
use App\Support\ActivityModules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns what WIMS ALREADY records into one list of events for a person and a period. It does not log anything itself.
 *
 * Sources (all read-only): activity_log (record events and workflow steps with the person as causer), login_logs (sign-in / failed sign-in),
 * the sign-out rows of activity_log, download_print_logs (reports and documents), and the module history tables that carry who did what:
 * material_request_status_logs, daily_site_report_logs, material_reallocation_logs, issue_return_logs, digital_attendance_events, task_comments.
 *
 * Event: id, at, source, kind (login | login_failed | logout | created | updated | submitted | approved | rejected | deleted | restored |
 * returned | completed | comment | report | clock | viewed | other), section (timeline | sessions | approvals | changes), module_key, module,
 * title, record, subject_type, subject_id, line (a child row such as an item line), auto (written by a model event, not a workflow step),
 * result ('done'; only 'failed' for failed sign-ins), note, changes, meta.
 *
 * Honest limits (also shown to the reader): only COMPLETED actions are recorded, so failed or rolled-back operations do not appear (failed
 * sign-ins are the exception); page views are not recorded; a session that simply expired has no sign-out row.
 */
class ActivityCollector
{
    /** History tables are read through their own adapter below, so their model-event copies in activity_log are skipped. */
    private const COVERED_BY_ADAPTER = ['MaterialRequestStatusLog', 'DailySiteReportLog', 'MaterialReallocationLog', 'IssueReturnLog', 'DigitalAttendanceEvent'];

    private const HIDDEN_FIELDS = '/password|secret|token|recovery|remember|api_key|private|otp|pin_code/i';

    private const LINE_NAME = '/(Item|Items|Line|Lines|Trail|Detail|Details|Recipient|Recipients|Assignee|Assignees|Attachment|Attachments|Channel|Photo|Photos)$/';

    public function collect(User $subject, Period $period, array $settings): array
    {
        $limit = (int) ($settings['timeline_limit'] ?? 500);
        $events = collect();
        $truncated = false;

        [$activity, $onAccount, $cut] = $this->fromActivityLog($subject, $period, $settings, $limit);
        $truncated = $cut;
        $events = $events->merge($activity)
            ->merge($this->fromLogins($subject, $period))
            ->merge($this->fromDownloads($subject, $period))
            ->merge($this->fromMaterialRequests($subject, $period))
            ->merge($this->fromSiteReports($subject, $period))
            ->merge($this->fromReallocations($subject, $period))
            ->merge($this->fromIssueReturn($subject, $period))
            ->merge($this->fromAttendance($subject, $period))
            ->merge($this->fromTaskComments($subject, $period));

        $events = $this->dedupe($events)->sortBy('at')->values();

        return ['events' => $events, 'truncated' => $truncated, 'on_account' => $onAccount];
    }

    // ----------------------------------------------------------------------------------------------------------------------------- activity_log

    private function fromActivityLog(User $subject, Period $p, array $settings, int $limit): array
    {
        $excluded = array_values(array_unique(array_merge((array) ($settings['excluded_log_names'] ?? []), self::COVERED_BY_ADAPTER)));
        $base = DB::table('activity_log')->where('account_id', $subject->account_id)->whereBetween('created_at', $p->sql());

        $rows = (clone $base)->where('causer_id', $subject->id)->where('causer_type', User::class)
            ->whereNotIn('log_name', $excluded)->where('description', 'not like', '%logged in%')
            ->orderBy('created_at')->limit($limit + 1)
            ->get(['id', 'log_name', 'description', 'event', 'subject_type', 'subject_id', 'properties', 'created_at']);
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // things done on this person's account by an administrator who was impersonating them: not their own actions, only counted
        $onAccount = (clone $base)->where('causer_id', '!=', $subject->id)->where('properties', 'like', '%"effective_user_id":' . (int) $subject->id . '%')->count();

        $events = collect();
        foreach ($rows as $r) {
            $props = [];
            if (is_string($r->properties) && $r->properties !== '' && $r->properties !== '[]') {
                $props = json_decode($r->properties, true) ?: [];
            }
            $impersonation = $props['impersonation'] ?? null;
            $auto = $r->event !== null && $r->event !== '';
            [$kind, $line] = $this->classify((string) $r->description, $auto ? (string) $r->event : null, (string) $r->log_name);
            $moduleKey = ActivityModules::keyFor($r->log_name);
            $isAuth = $kind === 'logout' || str_contains(strtolower((string) $r->description), 'impersonation');
            if ($kind === 'logout') {
                $moduleKey = 'auth';
            }
            $record = $this->recordLabel($props);
            $changes = ($kind === 'updated' && $auto) ? $this->changes($props) : null;
            // a model "updated" row that records no changed field (a bookkeeping save such as a last-seen stamp) is not something the person did
            if ($kind === 'updated' && $auto && (empty($props['attributes']) || ! is_array($props['attributes']))) {
                continue;
            }
            $moduleLabel = $moduleKey === 'other' ? Str::headline((string) $r->log_name) : Modules::label($moduleKey);

            $events->push([
                'id' => 'a:' . $r->id, 'at' => $r->created_at, 'source' => 'activity_log', 'kind' => $kind,
                'section' => $kind === 'logout' ? 'sessions' : (in_array($kind, ['approved', 'rejected', 'submitted', 'returned'], true) ? 'approvals' : ($changes ? 'changes' : 'timeline')),
                'module_key' => $moduleKey, 'module' => $moduleLabel,
                'title' => $this->title((string) $r->description, $auto, (string) $r->log_name, $kind),
                'record' => $record, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
                'line' => $line, 'auto' => $auto, 'result' => 'done',
                'note' => $impersonation ? 'while acting as ' . ($impersonation['effective_user_name'] ?? 'another user') : null,
                'changes' => $changes && $kind === 'updated' ? $changes : null,
                'meta' => $isAuth ? ['administrative' => true] : [],
            ]);
        }

        return [$events, $onAccount, $truncated];
    }

    /** [kind, isLine] from the wording (never infers an approval from a submission, or the other way round). */
    private function classify(string $description, ?string $event, string $logName): array
    {
        $d = strtolower($description);
        $line = $event !== null && preg_match(self::LINE_NAME, $logName) === 1;
        $kind = match (true) {
            str_contains($d, 'logged out') => 'logout',
            (bool) preg_match('/\b(rejected|declined|disapproved)\b/', $d) => 'rejected',
            (bool) preg_match('/\b(approved|authori[sz]ed)\b/', $d) => 'approved',
            (bool) preg_match('/\b(submitted|sent for (approval|review)|submit)\b/', $d) => 'submitted',
            (bool) preg_match('/\b(returned|sent back|request(ed)? changes)\b/', $d) => 'returned',
            $event === 'deleted' || (bool) preg_match('/\b(deleted|removed|cancel(l)?ed|voided)\b/', $d) => 'deleted',
            $event === 'restored' || str_contains($d, 'restored') => 'restored',
            $event === 'created' || (bool) preg_match('/\b(created|added|recorded|registered|generated)\b/', $d) => 'created',
            $event === 'updated' || (bool) preg_match('/\b(updated|edited|changed|corrected|set|saved|reset|enabled|disabled)\b/', $d) => 'updated',
            (bool) preg_match('/\b(paid|completed|issued|received|dispatched|delivered|closed)\b/', $d) => 'completed',
            default => 'other',
        };

        return [$kind, $line];
    }

    private function title(string $description, bool $auto, string $logName, string $kind): string
    {
        $t = trim(rtrim(trim($description), '.'));
        if ($auto) {
            $name = trim(preg_replace('/(?<!^)(?=[A-Z])/', ' ', $logName));
            $verb = ['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted', 'restored' => 'restored'][$kind] ?? $kind;

            return ucfirst($name . ' ' . $verb);
        }

        return $t === '' ? ucfirst($kind) : ucfirst($t);
    }

    private function recordLabel(array $props): ?string
    {
        foreach ([$props['attributes'] ?? [], $props] as $bag) {
            if (! is_array($bag)) {
                continue;
            }
            foreach (['reference', 'number', 'contract_number', 'pv_number', 'voucher_number', 'staff_number', 'name', 'title', 'code'] as $k) {
                if (isset($bag[$k]) && is_scalar($bag[$k]) && trim((string) $bag[$k]) !== '') {
                    return Str::limit(trim((string) $bag[$k]), 60);
                }
            }
        }

        return null;
    }

    /** [{field, from, to}] for a model update, without bookkeeping columns and never secrets. */
    private function changes(array $props): ?array
    {
        $new = $props['attributes'] ?? null;
        $old = $props['old'] ?? [];
        if (! is_array($new) || $new === []) {
            return null;
        }
        $out = [];
        foreach ($new as $field => $value) {
            if (in_array($field, ['updated_at', 'created_at', 'deleted_at', 'hash'], true)) {
                continue;
            }
            $hidden = preg_match(self::HIDDEN_FIELDS, (string) $field) === 1;
            $fmt = fn ($v) => $hidden ? '••••' : (is_array($v) ? Str::limit(json_encode($v), 80) : (is_bool($v) ? ($v ? 'yes' : 'no') : Str::limit((string) ($v ?? ''), 80)));
            $out[] = ['field' => Str::headline((string) $field), 'from' => $fmt($old[$field] ?? null), 'to' => $fmt($value)];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out ?: null;
    }

    // ------------------------------------------------------------------------------------------------------------------------------- sign-ins

    private function fromLogins(User $subject, Period $p): Collection
    {
        $rows = DB::table('login_logs')->whereBetween('attempted_at', $p->sql())
            ->where(function ($q) use ($subject) {
                $q->where('user_id', $subject->id)->orWhere(fn ($w) => $w->whereNull('user_id')->whereRaw('LOWER(username) = ?', [strtolower((string) $subject->username)]));
            })->orderBy('attempted_at')->limit(500)
            ->get(['id', 'status', 'attempted_at', 'ip_address', 'device', 'os', 'browser', 'location', 'error_message']);

        return $rows->map(fn ($r) => [
            'id' => 'l:' . $r->id, 'at' => $r->attempted_at, 'source' => 'login_logs',
            'kind' => $r->status === 'success' ? 'login' : 'login_failed', 'section' => 'sessions',
            'module_key' => 'auth', 'module' => Modules::label('auth'),
            'title' => $r->status === 'success' ? 'Signed in to WIMS' : 'Failed sign-in attempt',
            'record' => null, 'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false,
            'result' => $r->status === 'success' ? 'done' : 'failed', 'note' => null, 'changes' => null,
            'meta' => ['device' => implode(' on ', array_filter([trim((string) $r->browser), trim((string) $r->os)], fn ($v) => $v !== '' && strcasecmp($v, 'Unknown') !== 0)), 'ip' => $r->ip_address, 'location' => $r->location, 'sensitive' => $r->status !== 'success'],
        ]);
    }

    // -------------------------------------------------------------------------------------------------------------------- reports / downloads

    private function fromDownloads(User $subject, Period $p): Collection
    {
        $rows = DB::table('download_print_logs')->where('user_id', $subject->id)->whereBetween('performed_at', $p->sql())
            ->orderBy('performed_at')->limit(300)->get(['id', 'action_type', 'module', 'route_name', 'file_name', 'performed_at']);

        return $rows->map(function ($r) {
            $key = ActivityModules::keyFor($r->module);
            $what = $r->file_name ?: Str::headline(str_replace(['.', '-'], ' ', (string) $r->route_name));

            return [
                'id' => 'd:' . $r->id, 'at' => $r->performed_at, 'source' => 'download_print_logs', 'kind' => 'report', 'section' => 'timeline',
                'module_key' => $key, 'module' => Modules::label($key),
                'title' => ($r->action_type === 'print' ? 'Printed ' : 'Downloaded ') . $what,
                'record' => null, 'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false, 'result' => 'done',
                'note' => null, 'changes' => null, 'meta' => ['action' => $r->action_type],
            ];
        });
    }

    // ---------------------------------------------------------------------------------------------------------------------- module histories

    private function fromMaterialRequests(User $subject, Period $p): Collection
    {
        $map = ['Pending Approval' => ['submitted', 'Submitted for approval'], 'Approved' => ['approved', 'Approved'], 'Rejected' => ['rejected', 'Rejected'],
            'Partially Issued' => ['completed', 'Partially issued'], 'Issued' => ['completed', 'Issued'], 'Cleared' => ['completed', 'Cleared'],
            'Cleared by' => ['completed', 'Cleared'], 'Closed' => ['completed', 'Closed'], 'Update Procedure' => ['updated', 'Updated the procedure']];
        $rows = DB::table('material_request_status_logs as l')->leftJoin('material_requests as m', 'm.id', '=', 'l.material_request_id')
            ->where('l.user_id', $subject->id)->whereBetween('l.created_at', $p->sql())->whereNull('l.deleted_at')
            ->orderBy('l.created_at')->limit(300)->get(['l.id', 'l.status', 'l.remarks', 'l.created_at', 'l.material_request_id', 'm.reference']);

        return $rows->map(function ($r) use ($map) {
            [$kind, $text] = $map[$r->status] ?? ['other', $r->status];

            return [
                'id' => 'mr:' . $r->id, 'at' => $r->created_at, 'source' => 'material_request_status_logs', 'kind' => $kind,
                'section' => in_array($kind, ['approved', 'rejected', 'submitted'], true) ? 'approvals' : 'timeline',
                'module_key' => 'material_requests', 'module' => Modules::label('material_requests'),
                'title' => 'Material Request: ' . $text, 'record' => $r->reference, 'subject_type' => \App\Models\MaterialRequest::class, 'subject_id' => $r->material_request_id,
                'line' => false, 'auto' => false, 'result' => 'done', 'note' => $r->remarks ? Str::limit($r->remarks, 120) : null, 'changes' => null, 'meta' => [],
            ];
        });
    }

    private function fromSiteReports(User $subject, Period $p): Collection
    {
        $map = ['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted', 'printed' => 'report', 'downloaded' => 'report', 'viewed' => 'viewed', 'archived' => 'other', 'auto_archived' => 'other'];
        $rows = DB::table('daily_site_report_logs')->where('user_id', $subject->id)->whereBetween('created_at', $p->sql())->whereNull('deleted_at')
            ->orderBy('created_at')->limit(300)->get(['id', 'daily_site_report_id', 'action', 'remarks', 'created_at']);

        return $rows->map(fn ($r) => [
            'id' => 'dsr:' . $r->id, 'at' => $r->created_at, 'source' => 'daily_site_report_logs', 'kind' => $map[$r->action] ?? 'other', 'section' => 'timeline',
            'module_key' => 'daily_site_reports', 'module' => Modules::label('daily_site_reports'), 'title' => 'Daily Site Report ' . str_replace('_', ' ', $r->action),
            'record' => '#' . $r->daily_site_report_id, 'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false, 'result' => 'done',
            'note' => $r->remarks ? Str::limit($r->remarks, 120) : null, 'changes' => null, 'meta' => [],
        ]);
    }

    private function fromReallocations(User $subject, Period $p): Collection
    {
        $map = ['created' => 'created', 'submitted' => 'submitted', 'updated' => 'updated', 'cleared' => 'approved', 'final_approved' => 'approved', 'request_changes' => 'returned',
            'allocated' => 'completed', 'dispatched' => 'completed', 'received' => 'completed', 'printed' => 'report'];
        $rows = DB::table('material_reallocation_logs')->where('user_id', $subject->id)->whereBetween('created_at', $p->sql())->whereNull('deleted_at')
            ->orderBy('created_at')->limit(300)->get(['id', 'material_reallocation_id', 'action', 'remarks', 'created_at']);

        return $rows->map(function ($r) use ($map) {
            $kind = $map[$r->action] ?? 'other';

            return [
                'id' => 'mre:' . $r->id, 'at' => $r->created_at, 'source' => 'material_reallocation_logs', 'kind' => $kind,
                'section' => in_array($kind, ['approved', 'submitted', 'returned'], true) ? 'approvals' : 'timeline',
                'module_key' => 'material_reallocation', 'module' => Modules::label('material_reallocation'),
                'title' => 'Material Reallocation: ' . str_replace('_', ' ', $r->action), 'record' => '#' . $r->material_reallocation_id,
                'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false, 'result' => 'done',
                'note' => $r->remarks ? Str::limit($r->remarks, 120) : null, 'changes' => null, 'meta' => [],
            ];
        });
    }

    private function fromIssueReturn(User $subject, Period $p): Collection
    {
        $rows = DB::table('issue_return_logs')->where('user_id', $subject->id)->whereBetween('created_at', $p->sql())
            ->orderBy('created_at')->limit(200)->get(['id', 'action', 'details', 'created_at']);

        return $rows->map(fn ($r) => [
            'id' => 'ir:' . $r->id, 'at' => $r->created_at, 'source' => 'issue_return_logs', 'kind' => $r->action === 'Print' ? 'report' : 'created', 'section' => 'timeline',
            'module_key' => 'issue_return', 'module' => Modules::label('issue_return'), 'title' => 'Issue & Return: ' . $r->action,
            'record' => null, 'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false, 'result' => 'done',
            'note' => null, 'changes' => null, 'meta' => [],
        ]);
    }

    private function fromAttendance(User $subject, Period $p): Collection
    {
        $rows = DB::table('digital_attendance_events')->where('user_id', $subject->id)->whereIn('event_type', ['CLOCK_IN', 'CLOCK_OUT'])
            ->where(fn ($q) => $q->whereNull('actor_id')->orWhere('actor_id', $subject->id))->whereBetween('created_at', $p->sql())->whereNull('deleted_at')
            ->orderBy('created_at')->limit(100)->get(['id', 'event_type', 'created_at']);

        return $rows->map(fn ($r) => [
            'id' => 'att:' . $r->id, 'at' => $r->created_at, 'source' => 'digital_attendance_events', 'kind' => 'clock', 'section' => 'timeline',
            'module_key' => 'digital_attendance', 'module' => Modules::label('digital_attendance'),
            'title' => $r->event_type === 'CLOCK_IN' ? 'Clocked in' : 'Clocked out', 'record' => null, 'subject_type' => null, 'subject_id' => null,
            'line' => false, 'auto' => false, 'result' => 'done', 'note' => null, 'changes' => null, 'meta' => [],
        ]);
    }

    private function fromTaskComments(User $subject, Period $p): Collection
    {
        $rows = DB::table('task_comments as c')->leftJoin('tasks as t', 't.id', '=', 'c.task_id')->where('c.user_id', $subject->id)
            ->whereBetween('c.created_at', $p->sql())->whereNull('c.deleted_at')->orderBy('c.created_at')->limit(200)
            ->get(['c.id', 'c.created_at', 'c.task_id', 't.title']);

        return $rows->map(fn ($r) => [
            'id' => 'tc:' . $r->id, 'at' => $r->created_at, 'source' => 'task_comments', 'kind' => 'comment', 'section' => 'timeline',
            'module_key' => 'task_manager', 'module' => Modules::label('task_manager'), 'title' => 'Commented on a task',
            'record' => $r->title ? Str::limit($r->title, 60) : null, 'subject_type' => null, 'subject_id' => null, 'line' => false, 'auto' => false,
            'result' => 'done', 'note' => null, 'changes' => null, 'meta' => [],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------------------------ cleaning

    /**
     * The same action is often written twice: by the model event and by an explicit "X Created" row (and by a module history row). Keep the
     * more meaningful one: within 5 seconds, an explicit / history event of the same module and kind replaces ONE matching automatic
     * record event; an automatic "updated" copy of a workflow step (history rows) is dropped the same way.
     */
    private function dedupe(Collection $events): Collection
    {
        $explicit = $events->filter(fn ($e) => ! $e['auto'] && ! $e['line'] && in_array($e['source'], ['activity_log', 'material_request_status_logs', 'daily_site_report_logs', 'material_reallocation_logs', 'issue_return_logs'], true))->values();
        $drop = [];
        foreach ($explicit as $x) {
            $xt = strtotime($x['at']);
            foreach ($events as $a) {
                if (! $a['auto'] || $a['line'] || isset($drop[$a['id']]) || $a['module_key'] !== $x['module_key']) {
                    continue;
                }
                $sameKind = $a['kind'] === $x['kind'] || ($x['source'] !== 'activity_log' && $a['kind'] === 'updated');
                if ($sameKind && abs(strtotime($a['at']) - $xt) <= 5 && ($x['subject_id'] === null || $a['subject_id'] === null || ($a['subject_type'] === $x['subject_type'] && (string) $a['subject_id'] === (string) $x['subject_id']) || $x['source'] !== 'activity_log')) {
                    $drop[$a['id']] = true;
                    break;
                }
            }
        }

        return $events->reject(fn ($e) => isset($drop[$e['id']]));
    }
}
