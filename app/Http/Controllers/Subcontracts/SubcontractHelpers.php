<?php

namespace App\Http\Controllers\Subcontracts;

use App\Models\Contact;
use App\Models\SubcontractAgreement;
use App\Models\Warehouse;
use App\Traits\HasActivityHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** What every Subcontract controller shares: ability checks done by name, who may see money, attachments (secure, logged) and the project / contact option lists. */
trait SubcontractHelpers
{
    use HasActivityHistory;

    public const FILE_TYPES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt';

    protected function need(string ...$abilities): void
    {
        $u = auth()->user();
        abort_unless($u && collect($abilities)->contains(fn ($a) => $u->can($a)), 403);
    }

    protected function can(string $ability): bool
    {
        return (bool) optional(auth()->user())->can($ability);
    }

    /** Who may see contract values, payments and balances (server side: a person without it gets no figures in the page). */
    protected function money(): bool
    {
        return $this->can('view-financials-subcontracts');
    }

    /** A figure for the page, or null when the viewer may not see money. */
    protected function m($v)
    {
        return $this->money() && $v !== null ? (string) $v : null;
    }

    protected function visibleAgreements()
    {
        return SubcontractAgreement::query()->visibleTo(auth()->user());
    }

    /** A list parameter where a text is expected (?search[]=x) is never a valid filter: it is dropped before the page reads its filters, so the page shows the unfiltered list instead of an error. */
    protected function scalarQuery(Request $request): void
    {
        foreach (array_keys(array_filter($request->query(), 'is_array')) as $k) {
            $request->query->remove($k);
        }
    }

    protected function agreementOrFail(int $id): SubcontractAgreement
    {
        return $this->visibleAgreements()->findOrFail($id);
    }

    protected function subcontractors()
    {
        return Contact::query()->where('category', 'SubContractors')->orderBy('name')->get(['id', 'name', 'phone', 'email', 'location'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->phone ? ' (' . $c->phone . ')' : '')]);
    }

    protected function projects()
    {
        $ids = accessible_warehouse_ids();

        return Warehouse::ofAccount()->active()->visible()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'status' => $w->status]);
    }

    protected function filesOf($model)
    {
        if (! $this->can('view-documents-subcontracts')) {
            return [];
        }

        return $model->attachments()->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'size' => (int) $a->filesize, 'mime' => $a->filetype, 'at' => optional($a->created_at)->toDateTimeString()])->values();
    }

    protected function saveFiles($model, Request $request, string $field = 'attachments'): int
    {
        $n = 0;
        foreach ((array) $request->file($field, []) as $f) {
            if ($f && $f->isValid()) {
                $model->attach($f, ['title' => mb_substr($f->getClientOriginalName(), 0, 90), 'disk' => 'local', 'group' => 'subcontracts']);
                $n++;
            }
        }

        return $n;
    }

    protected function fileRules(): array
    {
        return ['attachments' => 'nullable|array|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES];
    }

    protected function downloadFile($model, int $file, string $label)
    {
        $this->need('view-documents-subcontracts');
        $a = $model->attachments()->whereKey($file)->first();
        abort_unless($a, 404);
        $inline = request()->boolean('inline');
        log_activity($label . ' ' . $model->reference . ' document ' . ($inline ? 'viewed' : 'downloaded'), ['file' => $a->title], $model, 'Subcontracts');
        if ($inline && in_array(strtolower(pathinfo($a->title, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $mime = strtolower(pathinfo($a->title, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : ($a->filetype ?: 'application/octet-stream');

            return response()->file(Storage::disk($a->disk ?: 'local')->path($a->filepath), ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . addslashes($a->title) . '"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"]);
        }

        return Storage::disk($a->disk ?: 'local')->download($a->filepath, $a->title, ['X-Content-Type-Options' => 'nosniff']);
    }

    protected function removeFileOf($model, int $file, string $label): void
    {
        $a = $model->attachments()->whereKey($file)->first();
        abort_unless($a, 404);
        $title = $a->title;
        $a->delete();
        log_activity($label . ' ' . $model->reference . ' document removed', ['file' => $title], $model, 'Subcontracts');
    }

    /** A person may not decide what they created or submitted (a Super Admin may, and it is recorded by the log). */
    protected function selfDecision(?int $ownerId): bool
    {
        $u = auth()->user();

        return $ownerId && (int) $ownerId === (int) $u->id && ! $u->hasRole('Super Admin');
    }

    /** The change history of a record for its page: who did what and when. The logged properties (amounts, before / after values) stay in the audit report, they are not sent to the browser. */
    protected function safeHistory(string $modelClass, int $modelId): array
    {
        return array_map(fn ($h) => \Illuminate\Support\Arr::except($h, ['properties']), $this->activityHistory($modelClass, $modelId));
    }
}
