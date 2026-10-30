<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\IdPassCard;
use App\Models\IdPassPrint;
use App\Services\FeatureManager;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassQuery;
use App\Services\IdPass\IdPassSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The overview: one card per kind with its figures, what expires soon, what was issued and printed lately. Only the kinds the person may open are counted and shown. */
class DashboardController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->module();
        $u = $request->user();
        $kinds = IdPassKinds::readable($u);
        $today = Carbon::today(config('app.timezone'));
        $cards = [];
        foreach ($kinds as $k) {
            $i = IdPassKinds::get($k);
            $cards[] = ['kind' => $k, 'label' => __($i['label']), 'singular' => __($i['singular']), 'icon' => $i['icon'], 'tone' => $i['tone'], 'url' => route('id-passes.' . $i['route'] . '.index'), 'create_url' => IdPassKinds::can($k, 'create') ? route('id-passes.' . $i['route'] . '.create') : null,
                'counts' => IdPassQuery::counts([$k])];
        }
        $all = IdPassQuery::counts($kinds);
        $soon = IdPassCard::query()->whereIn('kind', $kinds ?: ['-'])->where('status', IdPassCard::ACTIVE)->whereNotNull('expires_at')->whereBetween('expires_at', [$today->toDateString(), $today->copy()->addDays(max(1, IdPassSettings::noticeDays() ?: 30))->toDateString()])->count();
        $recent = IdPassCard::query()->with('category')->whereIn('kind', $kinds ?: ['-'])->latest('id')->limit(8)->get()->map(fn ($c) => IdPassQuery::row($c) + ['url' => route('id-passes.' . IdPassKinds::get($c->kind)['route'] . '.show', ['card' => $c->id])]);
        $canHistory = $u->can('read-id-print-history') && FeatureManager::canAccess('id_print_centre', $u);
        $prints = $canHistory ? IdPassPrint::query()->with('user:id,name')->latest('id')->limit(8)->get()->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'name' => $p->full_name, 'category' => $p->category_name, 'action' => $p->action, 'layout' => $p->layout, 'by' => optional($p->user)->name,
            'at' => $p->created_at->timezone(config('app.timezone'))->toIso8601String(), 'copies' => $p->copies]) : [];
        $links = [
            'print_centre' => $u->can('access-id-print-centre') && FeatureManager::canAccess('id_print_centre', $u) ? route('id-passes.print-centre.index') : null,
            'history' => $canHistory ? route('id-passes.print-centre.history') : null,
            'reports' => $u->can('read-id-reports') && FeatureManager::canAccess('id_reports', $u) ? route('id-passes.reports.index') : null,
            'settings' => $u->canAny(['manage-id-settings', 'manage-id-templates', 'manage-id-numbering', 'manage-id-qr-settings']) && FeatureManager::canAccess('id_settings', $u) ? route('id-passes.settings.index') : null,
            'alerts' => $u->can('manage-id-notifications') ? route('id-passes.workflow') : null,
        ];

        return Inertia::render('IdPasses/Dashboard', ['cards' => $cards, 'totals' => $all, 'soon' => $soon, 'noticeDays' => IdPassSettings::noticeDays(), 'recent' => $recent, 'prints' => $prints, 'links' => $links]);
    }
}
