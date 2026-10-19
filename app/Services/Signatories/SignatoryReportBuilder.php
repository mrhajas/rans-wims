<?php

namespace App\Services\Signatories;

use App\Models\Signatory;
use App\Models\SigningMapping;
use App\Models\User;
use App\Services\SmartBrief\Period;
use Illuminate\Support\Facades\DB;

/** The Signatories reports. Every report is built here once and shown the same way on screen, in print, in PDF and as CSV. No picture data is ever part of a report. */
class SignatoryReportBuilder
{
    public const TYPES = [
        'registered' => ['label' => 'Registered Signatories', 'description' => 'Everyone registered as a signatory with status, who verified and when.', 'ability' => 'view-signatories'],
        'missing' => ['label' => 'Missing Signatures', 'description' => 'Signatures a report needed and could not show, and the active users with no registered signature.', 'ability' => 'view-signatories'],
        'status' => ['label' => 'Signature Status', 'description' => 'How many signatures are active, waiting, suspended or revoked.', 'ability' => 'view-signatories'],
        'versions' => ['label' => 'Signature Version History', 'description' => 'Every version of every signature: who added, confirmed and verified it, and when it was in use.', 'ability' => 'view-signature-history'],
        'roles' => ['label' => 'Signing Role Assignments', 'description' => 'Where each signing role is used: documents and qualifying actions.', 'ability' => 'view-signatories'],
        'documents' => ['label' => 'Document Signing Configuration', 'description' => 'For each document: roles, qualifying action, when shown and what happens when a signature is missing.', 'ability' => 'view-signatories'],
        'activity' => ['label' => 'Signature Activity and Audit', 'description' => 'Registrations, verifications, changes, previews and refusals, with who and when.', 'ability' => 'view-signatory-audit'],
    ];

    private const CAP = 2000;

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function availability(User $u): array
    {
        return collect(self::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t['label'], 'description' => $t['description'], 'allowed' => $u->can($t['ability'])])->values()->all();
    }

    public function build(User $viewer, string $type, Period $period, array $filters = []): array
    {
        $acc = (int) $viewer->account_id;
        $report = match ($type) {
            'registered' => $this->registered($acc, $filters),
            'missing' => $this->missing($acc, $period),
            'status' => $this->status($acc),
            'versions' => $this->versions($acc, $period),
            'roles' => $this->roles($acc),
            'documents' => $this->documents($acc),
            'activity' => $this->activity($acc, $period),
        };
        $report += ['type' => $type, 'title' => self::TYPES[$type]['label'], 'filters' => [], 'tiles' => [], 'notes' => [], 'sections' => []];
        $report['filters'] = array_merge(in_array($type, ['missing', 'versions', 'activity'], true) ? ['Period' => $period->label] : [], $report['filters']);
        $report['reference'] = 'SIG-' . strtoupper(substr($type, 0, 4)) . '-' . now()->format('Ymd') . '-' . str_pad((string) $viewer->id, 3, '0', STR_PAD_LEFT);
        $report['total_rows'] = count($report['rows']);
        $report['truncated'] = $report['total_rows'] > self::CAP;
        $report['rows'] = array_slice($report['rows'], 0, self::CAP);
        $report['notes'][] = 'Signature pictures are never part of a report. Statuses and dates come from the Signatories records; a signature is only used for an action that was really recorded.';

        return $report;
    }

    public static function landscape(array $r): bool
    {
        return count($r['columns']) > 5;
    }

    private function col(string $key, string $label, string $align = 'left'): array
    {
        return ['key' => $key, 'label' => $label, 'align' => $align];
    }

    private function name(?int $id): string
    {
        return $id ? (string) User::query()->withTrashed()->where('id', $id)->value('name') : '';
    }

    private function dt($v): string
    {
        return $v ? substr((string) $v, 0, 16) : '';
    }

    private function statusText(string $s): string
    {
        return ['active' => 'Active', 'pending_verification' => 'Pending verification', 'suspended' => 'Suspended', 'revoked' => 'Revoked', 'replaced' => 'Replaced', 'rejected' => 'Rejected'][$s] ?? $s;
    }

    private function registered(int $acc, array $f): array
    {
        $rows = DB::table('signatories as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('staffs as st', 'st.id', '=', 's.staff_id')
            ->where('s.account_id', $acc)->orderBy('u.name')->get(['s.*', 'u.name', 'u.username', 'st.position', 'st.staff_number']);
        $out = $rows->map(fn ($r) => [
            'name' => $r->name, 'username' => $r->username, 'staff_no' => $r->staff_number, 'position' => $r->position, 'status' => $this->statusText($r->status),
            'registered' => $this->dt($r->registered_at), 'verified' => $this->dt($r->verified_at), 'verified_by' => $this->name($r->verified_by), 'updated' => $this->dt($r->updated_at),
        ])->all();

        return ['subtitle' => 'Everyone registered as a signatory', 'columns' => [$this->col('name', 'Name'), $this->col('username', 'Username'), $this->col('staff_no', 'Staff no'), $this->col('position', 'Position'), $this->col('status', 'Status'), $this->col('registered', 'Registered'), $this->col('verified', 'Verified'), $this->col('verified_by', 'Verified by'), $this->col('updated', 'Last updated')],
            'rows' => $out, 'tiles' => [['label' => 'Registered', 'value' => $rows->count()], ['label' => 'Active', 'value' => $rows->where('status', 'active')->count()], ['label' => 'Pending', 'value' => $rows->where('status', 'pending_verification')->count()], ['label' => 'Suspended or revoked', 'value' => $rows->whereIn('status', ['suspended', 'revoked'])->count()]], 'table_title' => 'Registered signatories'];
    }

    private function missing(int $acc, Period $p): array
    {
        $rows = DB::table('signature_missing')->where('account_id', $acc)->whereBetween('seen_at', $p->sql())->orderByDesc('seen_at')->get();
        $out = $rows->map(fn ($r) => [
            'seen' => $this->dt($r->seen_at), 'document' => (SignatureResolver::adapters()[$r->document_type] ?? null) ? (SignatureResolver::adapters()[$r->document_type])::label() : $r->document_type, 'record' => $r->record_id,
            'role' => ucwords(str_replace('_', ' ', $r->signing_role_key)), 'person' => $this->name($r->user_id), 'reason' => $r->detail ?: $r->reason, 'required' => $r->blocked ? 'Required' : 'Optional',
        ])->all();
        $without = DB::table('users')->where('account_id', $acc)->where('is_active', true)->whereNull('deleted_at')->whereNotIn('id', DB::table('signatories')->where('account_id', $acc)->pluck('user_id'))->orderBy('name')->get(['id', 'name', 'username']);

        return ['subtitle' => 'Signatures that could not be shown', 'columns' => [$this->col('seen', 'Seen'), $this->col('document', 'Document'), $this->col('record', 'Record id'), $this->col('role', 'Signing role'), $this->col('person', 'Person'), $this->col('reason', 'Why'), $this->col('required', 'Required')],
            'rows' => $out, 'tiles' => [['label' => 'Missing in the period', 'value' => count($out)], ['label' => 'Users without a signature', 'value' => $without->count()]], 'table_title' => 'Missing signatures',
            'sections' => [['type' => 'table', 'title' => 'Active users with no registered signature', 'placement' => 'after', 'columns' => [$this->col('name', 'Name'), $this->col('username', 'Username')], 'rows' => $without->map(fn ($u) => ['name' => $u->name, 'username' => $u->username])->all(), 'empty' => 'Every active user has a registered signature.']]];
    }

    private function status(int $acc): array
    {
        $by = DB::table('signatories')->where('account_id', $acc)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $vers = DB::table('signature_versions as v')->join('signatories as s', 's.id', '=', 'v.signatory_id')->where('s.account_id', $acc)->selectRaw('v.status, count(*) c')->groupBy('v.status')->pluck('c', 'v.status');
        $rows = [];
        foreach (['active', 'pending_verification', 'suspended', 'revoked'] as $s) {
            $rows[] = ['what' => 'Signatories', 'status' => $this->statusText($s), 'count' => (int) ($by[$s] ?? 0)];
        }
        foreach (['active', 'pending_verification', 'replaced', 'rejected', 'suspended', 'revoked'] as $s) {
            $rows[] = ['what' => 'Signature versions', 'status' => $this->statusText($s), 'count' => (int) ($vers[$s] ?? 0)];
        }

        return ['subtitle' => 'Counts by status', 'columns' => [$this->col('what', 'Kind'), $this->col('status', 'Status'), $this->col('count', 'Count', 'right')], 'rows' => $rows, 'table_title' => 'Signature status', 'tiles' => [['label' => 'Signatories', 'value' => (int) $by->sum()], ['label' => 'Active', 'value' => (int) ($by['active'] ?? 0)]]];
    }

    private function versions(int $acc, Period $p): array
    {
        $rows = DB::table('signature_versions as v')->join('signatories as s', 's.id', '=', 'v.signatory_id')->join('users as u', 'u.id', '=', 's.user_id')->where('s.account_id', $acc)
            ->whereBetween('v.uploaded_at', $p->sql())->orderBy('u.name')->orderByDesc('v.version_no')->get(['v.*', 'u.name']);
        $out = $rows->map(fn ($v) => [
            'person' => $v->name, 'version' => $v->version_no, 'status' => $this->statusText($v->status), 'method' => $v->method, 'added' => $this->dt($v->uploaded_at), 'added_by' => $this->name($v->uploaded_by),
            'confirmed' => $v->consent_at ? $this->dt($v->consent_at) . ' ' . ($v->consent_by == null ? '' : ($v->consent_by == $v->uploaded_by ? '(by the signatory)' : '(by ' . $this->name($v->consent_by) . ')')) : 'Not confirmed',
            'verified' => $v->verified_at ? $this->dt($v->verified_at) . ' by ' . $this->name($v->verified_by) : '', 'in_use' => $v->activated_at ? $this->dt($v->activated_at) . ($v->ended_at ? ' to ' . $this->dt($v->ended_at) : ' to now') : '', 'note' => $v->ended_reason,
        ])->all();

        return ['subtitle' => 'Versions added in the period', 'columns' => [$this->col('person', 'Signatory'), $this->col('version', 'Ver', 'right'), $this->col('status', 'Status'), $this->col('method', 'Method'), $this->col('added', 'Added'), $this->col('added_by', 'Added by'), $this->col('confirmed', 'Confirmed by signatory'), $this->col('verified', 'Verified'), $this->col('in_use', 'In use'), $this->col('note', 'Note')],
            'rows' => $out, 'tiles' => [['label' => 'Versions in the period', 'value' => count($out)]], 'table_title' => 'Signature versions'];
    }

    private function roles(int $acc): array
    {
        $rows = SigningMapping::query()->with('role')->where('account_id', $acc)->orderBy('signing_role_id')->orderBy('document_type')->get();
        $out = $rows->map(fn ($m) => [
            'role' => optional($m->role)->label, 'document' => (SignatureResolver::adapters()[$m->document_type] ?? null) ? (SignatureResolver::adapters()[$m->document_type])::label() : $m->document_type,
            'action' => (SignatureResolver::adapters()[$m->document_type] ?? null) ? ((SignatureResolver::adapters()[$m->document_type])::actions()[$m->workflow_action] ?? $m->workflow_action) : $m->workflow_action, 'in_use' => $m->is_active ? 'Yes' : 'No',
        ])->all();

        return ['subtitle' => 'Where each signing role is used', 'columns' => [$this->col('role', 'Signing role'), $this->col('document', 'Document'), $this->col('action', 'Qualifying action'), $this->col('in_use', 'In use')], 'rows' => $out, 'table_title' => 'Signing role assignments', 'tiles' => [['label' => 'Signing lines', 'value' => count($out)]]];
    }

    private function documents(int $acc): array
    {
        $rows = SigningMapping::query()->with('role')->where('account_id', $acc)->orderBy('document_type')->orderBy('slot')->get();
        $when = ['after' => 'After the action', 'always' => 'Always', 'final' => 'Only when final'];
        $miss = ['blank' => 'Blank line', 'flag' => 'Blank line and flag', 'block' => 'Block finalising'];
        $out = $rows->map(function ($m) use ($when, $miss) {
            $cls = SignatureResolver::adapters()[$m->document_type] ?? null;

            return ['document' => $cls ? $cls::label() : $m->document_type, 'order' => $m->slot, 'role' => optional($m->role)->label, 'action' => $cls ? ($cls::actions()[$m->workflow_action] ?? $m->workflow_action) : $m->workflow_action,
                'required' => $m->is_required ? 'Required' : 'Optional', 'shown' => $when[$m->show_when] ?? $m->show_when, 'missing' => $miss[$m->missing_behaviour] ?? $m->missing_behaviour, 'blocks' => $m->blocks_final ? 'Yes' : 'No', 'in_use' => $m->is_active ? 'Yes' : 'No'];
        })->all();

        return ['subtitle' => 'Document signing configuration', 'columns' => [$this->col('document', 'Document'), $this->col('order', 'Order', 'right'), $this->col('role', 'Signing role'), $this->col('action', 'Qualifying action'), $this->col('required', 'Required'), $this->col('shown', 'Shown'), $this->col('missing', 'If missing'), $this->col('blocks', 'Blocks finalising'), $this->col('in_use', 'In use')],
            'rows' => $out, 'table_title' => 'Document signing configuration', 'tiles' => [['label' => 'Documents set up', 'value' => $rows->pluck('document_type')->unique()->count()], ['label' => 'Signing lines', 'value' => count($out)]]];
    }

    private function activity(int $acc, Period $p): array
    {
        $rows = DB::table('activity_log')->where('log_name', 'Signatories')->where('account_id', $acc)->whereBetween('created_at', $p->sql())->orderByDesc('id')->limit(self::CAP + 1)->get();
        $out = $rows->map(function ($a) {
            $props = json_decode($a->properties ?: '{}', true) ?: [];
            $props = $props['attributes'] ?? $props;
            $subject = isset($props['subject_user_id']) ? $this->name((int) $props['subject_user_id']) : '';
            $detail = collect(['version' => 'version', 'reason' => 'reason', 'role' => 'role', 'document' => 'document', 'method' => 'method'])->map(fn ($k, $l) => isset($props[$k]) && is_scalar($props[$k]) ? $l . ': ' . $props[$k] : null)->filter()->implode(', ');

            return ['at' => $this->dt($a->created_at), 'by' => $this->name($a->causer_id), 'what' => $a->description, 'about' => $subject, 'detail' => $detail];
        })->all();

        return ['subtitle' => 'Signatories audit log', 'columns' => [$this->col('at', 'When'), $this->col('by', 'Done by'), $this->col('what', 'What'), $this->col('about', 'About'), $this->col('detail', 'Detail')], 'rows' => $out, 'tiles' => [['label' => 'Events in the period', 'value' => count($out)]], 'table_title' => 'Audit log'];
    }
}
