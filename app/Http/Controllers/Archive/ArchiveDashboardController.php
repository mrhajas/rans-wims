<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ArchiveFile;
use App\Models\ArchiveRecord;
use App\Models\Warehouse;
use App\Services\ArchiveStats;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ArchiveDashboardController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** The page itself is light; the figures arrive from stats() so the cards can show loading skeletons. */
    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-archive'), 403);

        return Inertia::render('Archive/Dashboard', [
            'options' => [
                'categories' => ArchiveRecord::CATEGORIES,
                'sources' => ArchiveRecord::SOURCES,
                'fileTypes' => ArchiveFile::TYPES,
                'projects' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'status'])->map(fn ($w) => ['label' => $w->name, 'value' => $w->id, 'status' => $w->status])->all(),
            ],
            'can' => [
                'create' => $request->user()->can('create-archive'),
                'storage' => $request->user()->can('view-archive-storage'),
                'access' => $request->user()->can('view-archive-access-logs'),
                'confidential' => $request->user()->can('read-archive-confidential'),
                'tree' => $request->user()->can('read-archive-tree'),
            ],
        ]);
    }

    public function stats(Request $request)
    {
        abort_unless($request->user()->can('read-archive'), 403);

        $stats = ArchiveStats::forUser($request->user(), $request->only(ArchiveStats::FILTERS));

        // Storage figures are sensitive: only sent to users allowed to see them.
        if (! $request->user()->can('view-archive-storage')) {
            foreach (['original_bytes', 'storage_bytes', 'saved_bytes', 'package_bytes'] as $k) {
                unset($stats['totals'][$k]);
            }
            $stats['file_types'] = array_map(fn ($t) => ['type' => $t['type'], 'files' => $t['files']], $stats['file_types']);
            $stats['categories'] = array_map(fn ($c) => ['label' => $c['label'], 'count' => $c['count'], 'files' => $c['files']], $stats['categories']);
            $stats['sources'] = array_map(fn ($c) => ['label' => $c['label'], 'count' => $c['count'], 'files' => $c['files']], $stats['sources']);
        }

        return response()->json($stats);
    }
}
