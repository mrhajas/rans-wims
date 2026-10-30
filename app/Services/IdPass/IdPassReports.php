<?php

namespace App\Services\IdPass;

use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\IdPassEvent;
use App\Models\IdPassPrint;
use Carbon\Carbon;

/** The RANS ID reports (registers, active, expired, suspended and revoked, issuance, print history, by issue date, by expiry date, gate passes by vehicle and by authorisation). Each returns the shape the WIMS report page prints. */
class IdPassReports
{
    public const LIMIT = 3000;

    public static function types(): array
    {
        return [
            'staff_register' => ['title' => __('Staff ID Register'), 'about' => __('Every Staff ID with its WIMS staff reference, status and dates.'), 'kinds' => ['staff']],
            'compound_register' => ['title' => __('Compound Worker ID Register'), 'about' => __('Every Compound Worker ID.'), 'kinds' => ['compound']],
            'aluminium_register' => ['title' => __('Aluminium Worker Register'), 'about' => __('The Aluminium workers who have a Compound Worker ID.'), 'kinds' => ['compound'], 'category' => 'aluminium'],
            'carpenter_register' => ['title' => __('Carpenter Register'), 'about' => __('The Carpenters who have a Compound Worker ID.'), 'kinds' => ['compound'], 'category' => 'carpenter'],
            'visitor_register' => ['title' => __('Visitor Pass Register'), 'about' => __('Every Visitor Pass with who is visited and its validity.'), 'kinds' => ['visitor']],
            'gate_register' => ['title' => __('Gate Pass Register'), 'about' => __('Every Gate Pass with vehicle, authorisation and approval.'), 'kinds' => ['gate']],
            'active' => ['title' => __('Active IDs Report'), 'about' => __('IDs and passes that are valid today.'), 'status' => 'Active'],
            'expired' => ['title' => __('Expired IDs Report'), 'about' => __('IDs and passes whose validity has ended.'), 'status' => 'Expired'],
            'suspended_revoked' => ['title' => __('Suspended and Revoked IDs Report'), 'about' => __('IDs and passes that were suspended, revoked, replaced or rejected, with the reason.'), 'statuses' => ['Suspended', 'Revoked', 'Replaced', 'Rejected']],
            'issuance' => ['title' => __('ID Issuance History'), 'about' => __('When every ID was issued, by whom, and what has happened to it since.'), 'history' => 'issued'],
            'prints' => ['title' => __('Print and Export History'), 'about' => __('Every print and PDF export: what, who and when.'), 'history' => 'prints'],
            'by_issue_date' => ['title' => __('IDs by Issue Date'), 'about' => __('IDs and passes issued in the period, newest first, with a count per month.'), 'order' => 'issued', 'group' => 'issued'],
            'by_expiry_date' => ['title' => __('IDs by Expiry Date'), 'about' => __('Active IDs and passes in the order they expire.'), 'status' => 'Valid', 'order' => 'expires', 'group' => 'expires'],
            'gate_by_vehicle' => ['title' => __('Gate Passes by Vehicle'), 'about' => __('Gate Passes that name a vehicle, by registration number.'), 'kinds' => ['gate'], 'vehicle' => true],
            'gate_by_authorisation' => ['title' => __('Gate Passes by Authorisation Type'), 'about' => __('Gate Passes grouped by what they authorise.'), 'kinds' => ['gate'], 'authorisation' => true],
        ];
    }

    private static function when(?string $d): string
    {
        return $d ? Carbon::parse($d)->format('d M Y') : '';
    }

    /** @return array the report array of the WIMS print page */
    public static function build(string $type, array $f, array $kinds, ?string $format = null): array
    {
        $t = self::types()[$type] ?? abort(404);
        $kinds = isset($t['kinds']) ? array_values(array_intersect($t['kinds'], $kinds)) : $kinds;
        $today = Carbon::today(config('app.timezone'));
        $filters = [];
        if (! empty($f['from'])) {
            $filters[__('From')] = self::when($f['from']);
        }
        if (! empty($f['to'])) {
            $filters[__('To')] = self::when($f['to']);
        }
        if (! empty($f['category_id'])) {
            $filters[__('Category')] = (string) IdPassCategory::query()->whereKey((int) $f['category_id'])->value('name');
        }
        if (! empty($f['q'])) {
            $filters[__('Search')] = $f['q'];
        }
        $qf = IdPassQuery::filters(['q' => $f['q'] ?? '', 'category_id' => $f['category_id'] ?? '', 'status' => $t['status'] ?? ($f['status'] ?? ''), 'issued_from' => $f['from'] ?? '', 'issued_to' => $f['to'] ?? '']);
        if (($t['history'] ?? '') === 'prints') {
            return self::prints($t, $f, $filters, $kinds);
        }
        $q = IdPassCard::query()->with(['category:id,name', 'staff:id,staff_number', 'createdBy:id,name']);
        IdPassQuery::apply($q, $qf, $kinds);
        if (! empty($t['category'])) {
            $q->whereHas('category', fn ($c) => $c->where('key', $t['category']));
        }
        if (! empty($t['statuses'])) {
            $q->whereIn('status', $t['statuses']);
        }
        if (! empty($t['vehicle'])) {
            $q->whereNotNull('vehicle_registration')->where('vehicle_registration', '<>', '');
        }
        $order = $t['order'] ?? (isset($t['vehicle']) ? 'vehicle' : (isset($t['authorisation']) ? 'authorisation' : 'number'));
        match ($order) {
            'issued' => $q->orderByDesc('issued_at')->orderByDesc('id'), 'expires' => $q->orderBy('expires_at')->orderBy('id'), 'vehicle' => $q->orderBy('vehicle_registration')->orderBy('id'),
            'authorisation' => $q->orderBy('authorisation_type')->orderBy('id'), default => $q->orderBy('kind')->orderBy('category_id')->orderBy('number'),
        };
        $cards = $q->limit(self::LIMIT + 1)->get();
        $truncated = $cards->count() > self::LIMIT;
        $cards = $cards->take(self::LIMIT);
        $reasons = ! empty($t['statuses']);
        $events = ($t['history'] ?? '') === 'issued' ? IdPassEvent::query()->whereIn('card_id', $cards->pluck('id'))->whereNotIn('event', ['issued'])->orderBy('id')->get()->groupBy('card_id') : collect();
        $rows = [];
        foreach ($cards as $c) {
            $row = ['number' => $c->number, 'name' => NameFitter::display((string) $c->full_name), 'category' => optional($c->category)->name, 'title' => (string) $c->title, 'status' => __($c->effective_status),
                'issued' => $c->issued_at->format('d M Y'), 'expires' => $c->expires_at ? $c->expires_at->format('d M Y') : '', 'staff_ref' => (string) optional($c->staff)->staff_number, 'vehicle' => (string) $c->vehicle_registration,
                'authorisation' => (string) $c->authorisation_type, 'host' => (string) $c->host_name, 'purpose' => (string) $c->purpose, 'printed' => (int) $c->print_count, 'last_printed' => $c->last_printed_at ? $c->last_printed_at->timezone(config('app.timezone'))->format('d M Y H:i') : '',
                'reason' => (string) ($c->status_reason ?: $c->decision_reason), 'by' => (string) optional($c->createdBy)->name,
                'since' => $events->has($c->id) ? $events[$c->id]->map(fn ($e) => $e->event . ' ' . $e->created_at->format('d M Y'))->implode('; ') : ''];
            $rows[] = $row;
        }
        $cols = self::columns($type, $kinds, $reasons);
        $sections = [];
        if (! empty($t['group'])) {
            $by = $cards->groupBy(fn ($c) => $t['group'] === 'issued' ? $c->issued_at->format('M Y') : ($c->expires_at ? $c->expires_at->format('M Y') : __('No expiry')));
            $sections[] = ['title' => $t['group'] === 'issued' ? __('Issued per month') : __('Expiring per month'), 'placement' => 'before', 'columns' => [['key' => 'm', 'label' => __('Month'), 'align' => 'left'], ['key' => 'n', 'label' => __('IDs'), 'align' => 'right']],
                'rows' => $by->map(fn ($g, $m) => ['m' => $m, 'n' => $g->count()])->values()->all()];
        }
        if (! empty($t['authorisation'])) {
            $sections[] = ['title' => __('Count per authorisation type'), 'placement' => 'before', 'columns' => [['key' => 'm', 'label' => __('Authorisation type'), 'align' => 'left'], ['key' => 'n', 'label' => __('Passes'), 'align' => 'right']],
                'rows' => $cards->groupBy(fn ($c) => $c->authorisation_type ?: __('Not stated'))->map(fn ($g, $m) => ['m' => $m, 'n' => $g->count()])->values()->all()];
        }
        $counts = collect($cards)->groupBy(fn ($c) => $c->effective_status)->map->count();
        $r = ['type' => 'id_passes', 'title' => $t['title'], 'reference' => 'IDR-' . now()->format('Ymd-His'), 'subtitle' => $t['about'], 'table_title' => $t['title'], 'filters' => $filters + [__('Kinds') => collect($kinds)->map(fn ($k) => __(IdPassKinds::get($k)['label']))->implode(', ')],
            'tiles' => [['label' => __('IDs'), 'value' => $cards->count()], ['label' => __('Active'), 'value' => (int) ($counts['Active'] ?? 0)], ['label' => __('Expired'), 'value' => (int) ($counts['Expired'] ?? 0)], ['label' => __('Suspended or revoked'), 'value' => (int) ($counts['Suspended'] ?? 0) + (int) ($counts['Revoked'] ?? 0)]],
            'columns' => $cols, 'rows' => $rows, 'raw' => $rows, 'total_rows' => count($rows), 'truncated' => $truncated, 'sections' => $sections,
            'notes' => [__('"Expired" is an ID whose expiry date has passed. A revoked, replaced or rejected ID is never valid again.'), __('The WIMS staff reference (STF) and the RANS ID number are two different things.')]];
        log_activity('RANS ID report generated', ['report' => $type, 'rows' => count($rows), 'format' => $format ?: 'screen', 'filters' => $f], null, 'RANS ID & Pass Management');

        return $r;
    }

    private static function columns(string $type, array $kinds, bool $reasons): array
    {
        $c = fn ($k, $l, $a = 'left') => ['key' => $k, 'label' => $l, 'align' => $a];
        $cols = [$c('number', __('ID number')), $c('name', __('Name')), $c('category', __('Category'))];
        if (in_array('staff', $kinds, true) || $type === 'active') {
            $cols[] = $c('staff_ref', __('WIMS staff ref'));
        }
        $cols[] = $c('title', __('Title / work'));
        if (in_array('gate', $kinds, true) || in_array($type, ['active', 'expired'], true)) {
            $cols[] = $c('vehicle', __('Vehicle'));
        }
        if (in_array($type, ['gate_register', 'gate_by_authorisation', 'gate_by_vehicle'], true)) {
            $cols[] = $c('authorisation', __('Authorisation'));
            $cols[] = $c('purpose', __('Reason for access'));
        }
        if ($type === 'visitor_register') {
            $cols[] = $c('host', __('Person visited'));
        }
        array_push($cols, $c('status', __('Status')), $c('issued', __('Issued')), $c('expires', __('Expires')));
        if ($reasons) {
            $cols[] = $c('reason', __('Reason'));
        }
        if ($type === 'issuance') {
            $cols[] = $c('by', __('Issued by'));
            $cols[] = $c('since', __('Since then'));
        } else {
            $cols[] = $c('printed', __('Prints'), 'right');
        }

        return $cols;
    }

    private static function prints(array $t, array $f, array $filters, array $kinds): array
    {
        $rows = IdPassPrint::query()->with(['user:id,name', 'batch:id,reference', 'card:id,kind'])->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', '%' . $v . '%')->orWhere('full_name', 'like', '%' . $v . '%')))->latest('id')->limit(self::LIMIT + 1)->get()
            ->filter(fn ($p) => ! $p->card || in_array($p->card->kind, $kinds, true));
        $truncated = $rows->count() > self::LIMIT;
        $tz = config('app.timezone');
        $out = $rows->take(self::LIMIT)->map(fn ($p) => ['when' => $p->created_at->timezone($tz)->format('d M Y H:i'), 'number' => (string) $p->number, 'name' => (string) $p->full_name, 'category' => (string) $p->category_name, 'action' => __(IdPassPrint::ACTIONS[$p->action] ?? $p->action),
            'layout' => __(IdPassPrint::LAYOUTS[$p->layout] ?? $p->layout), 'copies' => (int) $p->copies, 'outcome' => $p->outcome, 'by' => (string) optional($p->user)->name, 'batch' => (string) optional($p->batch)->reference])->values()->all();
        $c = fn ($k, $l, $a = 'left') => ['key' => $k, 'label' => $l, 'align' => $a];
        $r = ['type' => 'id_passes', 'title' => $t['title'], 'reference' => 'IDR-' . now()->format('Ymd-His'), 'subtitle' => $t['about'], 'table_title' => $t['title'], 'filters' => $filters, 'tiles' => [['label' => __('Print and export events'), 'value' => count($out)]],
            'columns' => [$c('when', __('When')), $c('number', __('ID number')), $c('name', __('Name')), $c('category', __('Category')), $c('action', __('Action')), $c('layout', __('Layout')), $c('copies', __('Copies'), 'right'), $c('outcome', __('Result')), $c('by', __('By')), $c('batch', __('Batch'))],
            'rows' => $out, 'raw' => $out, 'total_rows' => count($out), 'truncated' => $truncated, 'sections' => [],
            'notes' => [__('"Initiated" means WIMS made the PDF and opened it for printing; "Generated" means a PDF file was made. WIMS cannot know whether paper came out of a printer.')]];
        log_activity('RANS ID report generated', ['report' => 'prints', 'rows' => count($out)], null, 'RANS ID & Pass Management');

        return $r;
    }
}
