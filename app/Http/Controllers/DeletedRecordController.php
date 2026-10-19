<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Checkin;
use App\Models\Checkout;
use App\Models\Item;
use App\Models\SuperAdjustment;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageIssue;
use App\Http\Resources\DeletedRecordCollection;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeletedRecordController extends Controller
{
    private array $types = ['checkins', 'checkouts', 'transfers', 'adjustments', 'super-adjustments', 'items', 'work-packages', 'work-package-issues'];

    /**
     * Authorizable::callAction() would auto-derive read-deleted (unregistered
     * — only the lumped manage-deleted-records exists), 403ing Admin before
     * it ever reaches this class's own hasRole(['Super Admin','Admin'])
     * checks below (Admin gets no Gate::before bypass the way Super Admin does).
     */
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        $type = in_array($request->input('type'), $this->types, true) ? $request->input('type') : 'checkins';
        $search = $request->input('search');
        $deletedBy = $request->input('deleted_by');

        $records = new DeletedRecordCollection(
            $this->query($type, $search, $deletedBy)
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($model) => $this->transform($type, $model))
        );

        return Inertia::render('Deleted/Index', [
            'type'    => $type,
            'filters' => ['search' => $search, 'deleted_by' => $deletedBy ? (int) $deletedBy : null],
            'users'   => User::ofAccount()->orderBy('name')->get(['id', 'name']),
            'counts'  => [
                'checkins'    => Checkin::onlyTrashed()->withoutGlobalScope('mine')->count(),
                'checkouts'   => Checkout::onlyTrashed()->withoutGlobalScope('mine')->count(),
                'transfers'   => Transfer::onlyTrashed()->withoutGlobalScope('mine')->count(),
                'adjustments' => Adjustment::onlyTrashed()->withoutGlobalScope('mine')->count(),
                'super-adjustments' => SuperAdjustment::onlyTrashed()->withoutGlobalScope('mine')->count(),
                'items'       => Item::onlyTrashed()->count(),
                'work-packages' => WorkPackage::onlyTrashed()->count(),
                'work-package-issues' => WorkPackageIssue::onlyTrashed()->count(),
            ],
            'records' => $records,
        ]);
    }
    
    public function print(Request $request)
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        return view('deleted-records-print', $this->viewData($request, true));
    }
    
    public function pdf(Request $request)
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        $pdf = Pdf::loadView('deleted-records-print', $this->viewData($request, false))
            ->setPaper('a4', 'portrait');

        return $pdf->download('deleted-records-' . now()->format('Ymd-His') . '.pdf');
    }

    private function query(string $type, ?string $search, $deletedBy = null)
    {
        // Every type's search now matches the reference/title AND the
        // related names actually shown in the Details column (contact,
        // warehouse, creator) — previously only the reference/code matched,
        // so searching "Ministry of Education" or a warehouse name found
        // nothing even though that's exactly what's displayed on screen.
        $query = match ($type) {
            'checkins' => Checkin::onlyTrashed()->withoutGlobalScope('mine')
                ->with(['contact:id,name', 'warehouse:id,name', 'user:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('contact', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('user', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'checkouts' => Checkout::onlyTrashed()->withoutGlobalScope('mine')
                ->with(['contact:id,name', 'warehouse:id,name', 'user:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('contact', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('user', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'transfers' => Transfer::onlyTrashed()->withoutGlobalScope('mine')
                ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('fromWarehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('toWarehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('user', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'adjustments' => Adjustment::onlyTrashed()->withoutGlobalScope('mine')
                ->with(['warehouse:id,name', 'user:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('user', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'super-adjustments' => SuperAdjustment::onlyTrashed()->withoutGlobalScope('mine')
                ->with(['warehouse:id,name', 'user:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('user', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'items' => Item::onlyTrashed()
                ->with(['deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'work-packages' => WorkPackage::onlyTrashed()
                ->with(['warehouse:id,name', 'creator:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhere('title', 'like', "%{$s}%")
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('creator', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
            'work-package-issues' => WorkPackageIssue::onlyTrashed()
                ->with(['workPackage:id,reference,title', 'creator:id,name', 'deletedBy:id,name'])
                ->when($search, fn ($q, $s) => $q->where('reference', 'like', "%{$s}%")
                    ->orWhere('title', 'like', "%{$s}%")
                    ->orWhereHas('workPackage', fn ($w) => $w->where('reference', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%"))
                    ->orWhereHas('creator', fn ($w) => $w->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('deletedBy', fn ($w) => $w->where('name', 'like', "%{$s}%"))),
        };

        return $query
            ->when($deletedBy, fn ($q, $id) => $q->where('deleted_by', $id))
            ->orderByDesc('deleted_at');
    }

    private function transform(string $type, $m): array
    {
        $base = [
            'id'         => $m->id,
            'deleted_at' => $m->deleted_at,
            'deleted_by' => optional($m->deletedBy)->name,
        ];

        return match ($type) {
            'items' => array_merge($base, [
                'primary'    => $m->code,
                'name'       => $m->name,
                'created_by' => null,
                'relations'  => [],
            ]),
            'transfers' => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => null,
                'created_by' => optional($m->user)->name,
                'relations'  => [
                    ['label' => 'From', 'value' => optional($m->fromWarehouse)->name],
                    ['label' => 'To', 'value' => optional($m->toWarehouse)->name],
                ],
            ]),
            'adjustments' => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => null,
                'created_by' => optional($m->user)->name,
                'relations'  => [
                    ['label' => 'Warehouse', 'value' => optional($m->warehouse)->name],
                ],
            ]),
            'super-adjustments' => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => null,
                'created_by' => optional($m->user)->name,
                'relations'  => [
                    ['label' => 'Warehouse', 'value' => optional($m->warehouse)->name],
                    ['label' => 'Status', 'value' => $m->status],
                ],
            ]),
            'work-packages' => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => $m->title,
                'created_by' => optional($m->creator)->name,
                'relations'  => [
                    ['label' => 'Project', 'value' => optional($m->warehouse)->name],
                    ['label' => 'Status', 'value' => $m->status],
                ],
            ]),
            'work-package-issues' => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => $m->title,
                'created_by' => optional($m->creator)->name,
                'relations'  => [
                    ['label' => 'Work Package', 'value' => optional($m->workPackage)->reference],
                    ['label' => 'Status', 'value' => $m->status],
                ],
            ]),
            default => array_merge($base, [
                'primary'    => $m->reference,
                'name'       => null,
                'created_by' => optional($m->user)->name,
                'relations'  => [
                    ['label' => 'Warehouse', 'value' => optional($m->warehouse)->name],
                    ['label' => 'Contact', 'value' => optional($m->contact)->name],
                ],
            ]),
        };
    }
    
    private function viewData(Request $request, bool $autoPrint): array
    {
        $type = in_array($request->input('type'), $this->types, true) ? $request->input('type') : 'checkins';
        $search = $request->input('search');
        $deletedBy = $request->input('deleted_by');

        $rows = $this->query($type, $search, $deletedBy)
            ->limit(3000)
            ->get()
            ->map(fn ($model) => $this->transform($type, $model))
            ->values()
            ->all();

        return [
            'title' => 'Deleted Records',
            'type' => $type,
            'filters' => [
                'search' => $search,
                'deleted_by' => $deletedBy,
            ],
            'columns' => $type === 'items'
                ? ['Item', 'Details', 'Deleted By', 'Deleted At']
                : ['Reference', 'Details', 'Deleted By', 'Deleted At'],
            'rows' => array_map(function (array $row) {
                $details = collect($row['relations'] ?? [])
                    ->map(fn ($relation) => $relation['label'] . ': ' . ($relation['value'] ?: '—'))
                    ->values()
                    ->all();

                if (! empty($row['created_by'])) {
                    $details[] = 'By: ' . $row['created_by'];
                }

                return [
                    $row['primary'] ?? '—',
                    implode(' | ', $details),
                    $row['deleted_by'] ?? '—',
                    optional($row['deleted_at'] ?? null)->format('Y-m-d H:i:s') ?? '—',
                ];
            }, $rows),
            'companyName' => optional($request->user()->account ?? null)->name,
            'generatedBy' => optional($request->user())->name,
            'generatedAt' => now(),
            'autoPrint' => $autoPrint,
            'isPdf' => ! $autoPrint,
        ];
    }
}
