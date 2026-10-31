<?php

use App\Http\Controllers;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
| Public knowledge base ("WIMS Show"): readable by anyone, no login. It deliberately skips the Inertia, maintenance-lock,
| impersonation and download-log middleware, since it is plain server-rendered HTML.
*/
Route::prefix('wimshow')->name('wimshow.')
    ->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\SystemLockdown::class,
        \App\Http\Middleware\EnforceLicence::class,
        \App\Http\Middleware\ImpersonationGuard::class,
        \App\Http\Middleware\LogDownloadPrintActions::class,
        \App\Http\Middleware\Language::class,
    ])
    ->group(function () {
        Route::get('/', [Controllers\WimshowController::class, 'index'])->name('index');
        Route::get('/search.json', [Controllers\WimshowController::class, 'search'])->name('search');
        Route::get('/{slug}', [Controllers\WimshowController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('show');
    });

// The route table for the browser (route('name') in the pages), a plain cacheable script: no Inertia, no licence or lock-down redirects, so the browser and any cache can keep it for a year per version
Route::get('ziggy.js', fn () => response(\App\Support\ZiggyScript::body(), 200, ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'public, max-age=31536000, immutable']))
    ->withoutMiddleware([
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\SystemLockdown::class,
        \App\Http\Middleware\EnforceLicence::class,
        \App\Http\Middleware\ImpersonationGuard::class,
        \App\Http\Middleware\LogDownloadPrintActions::class,
        \App\Http\Middleware\Language::class,
    ])->name('ziggy.script');

// Simulation WIMS accounts, managed from PRODUCTION by Super Admins (never reachable from inside the simulation)
Route::middleware(['auth:sanctum', 'system.lockdown', 'feature.gate', 'not.simulation'])->prefix('simulation')->name('simulation.')->group(function () {
    $sa = Controllers\Simulation\SimulationAccountsController::class;
    Route::get('accounts', [$sa, 'index'])->name('accounts.index');
    Route::post('accounts', [$sa, 'store'])->middleware('throttle:20,1,simulation.accounts.store')->name('accounts.store');
    Route::put('accounts/{id}', [$sa, 'update'])->whereNumber('id')->middleware('throttle:30,1,simulation.accounts.update')->name('accounts.update');
    Route::put('accounts/{id}/access', [$sa, 'access'])->whereNumber('id')->middleware('throttle:60,1,simulation.accounts.access')->name('accounts.access');
    Route::post('accounts/{id}/active', [$sa, 'active'])->whereNumber('id')->middleware('throttle:60,1,simulation.accounts.active')->name('accounts.active');
    Route::post('accounts/{id}/password', [$sa, 'password'])->whereNumber('id')->middleware('throttle:20,1,simulation.accounts.password')->name('accounts.password');
    Route::delete('accounts/{id}', [$sa, 'revoke'])->whereNumber('id')->middleware('throttle:20,1,simulation.accounts.revoke')->name('accounts.revoke');
});

// Inside Simulation WIMS: the Import Centre, imported/modified/synthetic records, scenarios and checkpoints (answer 404 in production)
Route::middleware(['auth:sanctum', 'system.lockdown', 'only.simulation'])->prefix('simulation')->name('simulation.')->group(function () {
    $im = Controllers\Simulation\ImportController::class;
    Route::get('import', [$im, 'index'])->name('import.index');
    Route::get('import/search', [$im, 'search'])->middleware('throttle:120,1,simulation.import.search')->name('import.search');
    Route::get('import/preview', [$im, 'preview'])->middleware('throttle:120,1,simulation.import.preview')->name('import.preview');
    Route::post('import/plan', [$im, 'plan'])->middleware('throttle:60,1,simulation.import.plan')->name('import.plan');
    Route::post('import/run', [$im, 'run'])->middleware('throttle:20,1,simulation.import.run')->name('import.run');
    Route::post('import/{id}/retry', [$im, 'retry'])->whereNumber('id')->middleware('throttle:20,1,simulation.import.retry')->name('import.retry');
    Route::get('import/template/{kind}', [$im, 'template'])->name('import.template');
    Route::post('import/file/check', [$im, 'fileCheck'])->middleware('throttle:30,1,simulation.import.file-check')->name('import.file-check');
    Route::post('import/file/run', [$im, 'fileRun'])->middleware('throttle:10,1,simulation.import.file-run')->name('import.file-run');
    $pr = Controllers\Simulation\ProvenanceController::class;
    Route::get('provenance', [$pr, 'index'])->name('provenance.index');
    Route::get('provenance/records', [$pr, 'records'])->middleware('throttle:120,1,simulation.provenance.records')->name('provenance.records');
    Route::get('provenance/compare', [$pr, 'compare'])->middleware('throttle:120,1,simulation.provenance.compare')->name('provenance.compare');
    Route::post('provenance/restore', [$pr, 'restore'])->middleware('throttle:20,1,simulation.provenance.restore')->name('provenance.restore');
    $sc = Controllers\Simulation\ScenarioController::class;
    Route::get('scenarios', [$sc, 'index'])->name('scenarios.index');
    Route::post('scenarios', [$sc, 'store'])->middleware('throttle:6,1,simulation.scenarios.store')->name('scenarios.store');
    Route::post('scenarios/{id}/switch', [$sc, 'switch'])->whereNumber('id')->middleware('throttle:6,1,simulation.scenarios.switch')->name('scenarios.switch');
    Route::post('scenarios/{id}/checkpoints', [$sc, 'checkpoint'])->whereNumber('id')->middleware('throttle:10,1,simulation.scenarios.checkpoint')->name('scenarios.checkpoint');
    Route::post('scenarios/checkpoints/{checkpoint}/restore', [$sc, 'restore'])->whereNumber('checkpoint')->middleware('throttle:6,1,simulation.scenarios.restore')->name('scenarios.restore');
    Route::delete('scenarios/checkpoints/{checkpoint}', [$sc, 'deleteCheckpoint'])->whereNumber('checkpoint')->middleware('throttle:10,1,simulation.scenarios.cp-delete')->name('scenarios.cp-delete');
    Route::post('scenarios/reset', [$sc, 'reset'])->middleware('throttle:6,1,simulation.scenarios.reset')->name('scenarios.reset');
    Route::post('scenarios/baseline', [$sc, 'baseline'])->middleware('throttle:6,1,simulation.scenarios.baseline')->name('scenarios.baseline');
    Route::post('scenarios/{id}/archive', [$sc, 'archive'])->whereNumber('id')->name('scenarios.archive');
    Route::delete('scenarios/{id}', [$sc, 'destroy'])->whereNumber('id')->middleware('throttle:6,1,simulation.scenarios.destroy')->name('scenarios.destroy');
    Route::get('scenarios/compare', [$sc, 'compare'])->middleware('throttle:30,1,simulation.scenarios.compare')->name('scenarios.compare');
});

// The hidden login-page choice (see Auth\EnvironmentChoiceController). Answers 404 unless the simulation is switched on, like any unknown address.
Route::middleware(['throttle:20,1,wims.ctx'])->prefix('auth')->group(function () {
    Route::post('ctx', [Controllers\Auth\EnvironmentChoiceController::class, 'choose'])->name('ctx.choose');
    Route::delete('ctx', [Controllers\Auth\EnvironmentChoiceController::class, 'cancel'])->name('ctx.cancel');
});

// RANS ID & Pass: the page a scanned QR code opens (public and rate limited; what it shows depends on who is looking and on the ID settings)
Route::get('id-verify/{token}', [Controllers\IdPasses\VerifyController::class, 'show'])->where('token', '[A-Za-z0-9]{20,64}')->middleware(['web', 'throttle:60,1,id-passes.verify'])->name('id-passes.verify.show');

Route::middleware(['auth:sanctum', 'system.lockdown', 'feature.gate', 'log.download.print', 'reject.offline.mutations'])->group(function () {
    Route::redirect('/dashboard', '/');

    // Every module's Notification page (Who Gets Notified / SMS & Email messages). Defined first so these literal paths come
    // before the modules' own {record} wildcard routes.
    foreach (\App\Support\Workflow\WorkflowModules::routes() as $workflowModule => $wf) {
        Route::get($wf['path'], [Controllers\WorkflowSettingsController::class, 'index'])->defaults('module', $workflowModule)->name($wf['name']);
        $wfUpdate = Route::put($wf['path'], [Controllers\WorkflowSettingsController::class, 'update'])->defaults('module', $workflowModule)->name($wf['update']);
        if (str_starts_with($wf['update'], 'acct.')) {
            $wfUpdate->middleware('throttle:20,1,acct.workflow.update'); // Accounts Tracking: who is told about money events
        }
    }

    Route::get('/language/{language}', [Controllers\AjaxController::class, 'language']);
    Route::get('/', [Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::post('/presence/heartbeat', [Controllers\DashboardController::class, 'heartbeat'])->name('presence.heartbeat');
    Route::get('/dashboard/online-users', [Controllers\DashboardController::class, 'onlineUsers'])->name('dashboard.online-users');
    // WIMS Smart Brief (every route is authorised on the server in its controller; Global Feature Control gates the smart-brief.* prefixes)
    Route::prefix('smart-brief')->name('smart-brief.')->group(function () {
        $sb = Controllers\SmartBrief\SmartBriefController::class;
        Route::get('/', [$sb, 'home'])->name('home');
        Route::get('mybrief', [$sb, 'my'])->name('my.index');
        Route::redirect('my', '/smart-brief/mybrief'); // the earlier address, kept so bookmarks still work
        Route::get('mybrief/data', [$sb, 'myData'])->name('my.data');
        Route::post('mybrief/clear', [$sb, 'clear'])->name('my.clear');
        Route::get('management', [$sb, 'management'])->name('management.index');
        Route::get('management/users', [$sb, 'users'])->name('management.users');
        Route::get('management/user/{user}', [$sb, 'userBrief'])->whereNumber('user')->name('management.user');
        Route::get('team/overview', [$sb, 'overview'])->name('team.overview');
        Route::get('settings', [Controllers\SmartBrief\SmartBriefSettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [Controllers\SmartBrief\SmartBriefSettingsController::class, 'update'])->name('settings.update');
        // reports page + print / PDF / CSV (feature prefixes: smart-brief.reports. and smart-brief.export.)
        Route::get('reports', [Controllers\SmartBrief\SmartBriefReportController::class, 'index'])->name('reports.index');
        Route::get('export/print', [Controllers\SmartBrief\SmartBriefReportController::class, 'print'])->name('export.print');
        Route::get('export/pdf', [Controllers\SmartBrief\SmartBriefReportController::class, 'pdf'])->name('export.pdf');
        Route::get('export/csv', [Controllers\SmartBrief\SmartBriefReportController::class, 'csv'])->name('export.csv');
    });

    // WIMS Weather & Site Risk (every route is authorised on the server in its controllers; Global Feature Control gates the weather.* prefixes)
    Route::prefix('weather')->name('weather.')->group(function () {
        $wx = Controllers\Weather\WeatherController::class;
        Route::get('/', [$wx, 'index'])->name('overview');
        Route::get('projects/{warehouse}/card', [$wx, 'card'])->whereNumber('warehouse')->name('card');
        Route::post('projects/{warehouse}/refresh', [$wx, 'refresh'])->whereNumber('warehouse')->middleware('throttle:30,1,weather.refresh')->name('refresh');
        Route::get('projects/{warehouse}/geocode', [$wx, 'geocode'])->whereNumber('warehouse')->middleware('throttle:20,1,weather.locations.geocode')->name('locations.geocode');
        Route::put('projects/{warehouse}/location', [$wx, 'location'])->whereNumber('warehouse')->middleware('throttle:30,1,weather.locations.update')->name('locations.update');
        $ob = Controllers\Weather\WeatherObservationController::class;
        Route::get('observations', [$ob, 'index'])->name('observations.index');
        Route::post('observations', [$ob, 'store'])->middleware('throttle:60,1,weather.observations.store')->name('observations.store');
        Route::put('observations/{observation}', [$ob, 'update'])->whereNumber('observation')->name('observations.update');
        Route::delete('observations/{observation}', [$ob, 'destroy'])->whereNumber('observation')->name('observations.destroy');
        $wr = Controllers\Weather\WeatherWarningController::class;
        Route::get('warnings', [$wr, 'index'])->name('warnings.index');
        Route::post('warnings/{warning}/acknowledge', [$wr, 'acknowledge'])->whereNumber('warning')->middleware('throttle:60,1,weather.warnings.acknowledge')->name('warnings.acknowledge');
        $rp = Controllers\Weather\WeatherReportController::class;
        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('reports/print', [$rp, 'print'])->name('reports.print');
        Route::get('reports/pdf', [$rp, 'pdf'])->name('reports.pdf');
        Route::get('reports/csv', [$rp, 'csv'])->name('reports.csv');
        $st = Controllers\Weather\WeatherSettingsController::class;
        Route::get('settings', [$st, 'index'])->name('settings.index');
        Route::put('settings', [$st, 'update'])->middleware('throttle:20,1,weather.settings.update')->name('settings.update');
        Route::put('settings/key', [$st, 'replaceKey'])->middleware('throttle:6,1,weather.settings.key')->name('settings.key');
        Route::delete('settings/key', [$st, 'removeKey'])->middleware('throttle:6,1,weather.settings.key.remove')->name('settings.key.remove');
        Route::post('settings/test', [$st, 'test'])->middleware('throttle:6,1,weather.settings.test')->name('settings.test');
        Route::put('settings/rules/{rule}', [$st, 'updateRule'])->whereNumber('rule')->middleware('throttle:30,1,weather.settings.rules.update')->name('settings.rules.update');
        Route::post('settings/rules/{rule}/review', [$st, 'reviewRule'])->whereNumber('rule')->name('settings.rules.review');
        Route::post('settings/rules/{rule}/restore', [$st, 'restoreRule'])->whereNumber('rule')->name('settings.rules.restore');
    });

    // Accounts Tracking (every route is authorised on the server in its controller; Global Feature Control gates the acct.* prefixes)
    Route::prefix('accounts-tracking')->name('acct.')->group(function () {
        $ns = 'App\\Http\\Controllers\\Accounts\\';
        $dash = $ns . 'DashboardController'; $acc = $ns . 'AccountController'; $txn = $ns . 'TransactionController'; $trf = $ns . 'TransferController'; $apr = $ns . 'ApprovalController';
        $cur = $ns . 'CurrencyController'; $rec = $ns . 'ReconciliationController'; $alr = $ns . 'AlertController'; $rep = $ns . 'ReportController'; $aud = $ns . 'AuditController'; $set = $ns . 'SettingsController'; $fnd = $ns . 'FundsController';
        Route::get('/', [$dash, 'index'])->name('dashboard');

        Route::get('accounts', [$acc, 'index'])->name('accounts.index');
        Route::get('accounts/create', [$acc, 'create'])->name('accounts.create');
        Route::post('accounts', [$acc, 'store'])->middleware('throttle:30,1,acct.accounts.store')->name('accounts.store');
        Route::post('accounts/link-funds', [$acc, 'linkFunds'])->middleware('throttle:6,1,acct.accounts.link')->name('accounts.link-funds');
        Route::get('accounts/{id}', [$acc, 'show'])->whereNumber('id')->name('accounts.show');
        Route::get('accounts/{id}/edit', [$acc, 'edit'])->whereNumber('id')->name('accounts.edit');
        Route::put('accounts/{id}', [$acc, 'update'])->whereNumber('id')->middleware('throttle:30,1,acct.accounts.update')->name('accounts.update');
        Route::post('accounts/{id}/status', [$acc, 'status'])->whereNumber('id')->middleware('throttle:20,1,acct.accounts.status')->name('accounts.status');
        Route::post('accounts/{id}/repair', [$acc, 'repair'])->whereNumber('id')->middleware('throttle:10,1,acct.accounts.repair')->name('accounts.repair');

        Route::get('transactions', [$txn, 'index'])->name('transactions.index');
        Route::get('transactions/create', [$txn, 'create'])->name('transactions.create');
        Route::post('transactions', [$txn, 'store'])->middleware('throttle:60,1,acct.transactions.store')->name('transactions.store');
        Route::get('transactions/{id}', [$txn, 'show'])->whereNumber('id')->name('transactions.show');
        Route::get('transactions/{id}/edit', [$txn, 'edit'])->whereNumber('id')->name('transactions.edit');
        Route::put('transactions/{id}', [$txn, 'update'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.update')->name('transactions.update');
        Route::post('transactions/{id}/submit', [$txn, 'submit'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.submit')->name('transactions.submit');
        Route::post('transactions/{id}/approve', [$txn, 'approve'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.approve')->name('transactions.approve');
        Route::post('transactions/{id}/reject', [$txn, 'reject'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.reject')->name('transactions.reject');
        Route::post('transactions/{id}/cancel', [$txn, 'cancel'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.cancel')->name('transactions.cancel');
        Route::post('transactions/{id}/post', [$txn, 'post'])->whereNumber('id')->middleware('throttle:60,1,acct.transactions.post')->name('transactions.post');
        Route::post('transactions/{id}/reverse', [$txn, 'reverse'])->whereNumber('id')->middleware('throttle:20,1,acct.transactions.reverse')->name('transactions.reverse');
        Route::post('transactions/{id}/attachments', [$txn, 'attach'])->whereNumber('id')->middleware('throttle:30,1,acct.transactions.attach')->name('transactions.attach');
        Route::get('transactions/{id}/attachments/{file}', [$txn, 'download'])->whereNumber(['id', 'file'])->middleware('throttle:120,1,acct.transactions.download')->name('transactions.download');
        Route::delete('transactions/{id}/attachments/{file}', [$txn, 'removeFile'])->whereNumber(['id', 'file'])->middleware('throttle:30,1,acct.transactions.remove-file')->name('transactions.remove-file');

        Route::get('transfers', [$trf, 'index'])->name('transfers.index');
        Route::get('transfers/create', [$trf, 'create'])->name('transfers.create');
        Route::get('transfers/rate', [$trf, 'rate'])->middleware('throttle:120,1,acct.transfers.rate')->name('transfers.rate');
        Route::post('transfers', [$trf, 'store'])->middleware('throttle:60,1,acct.transfers.store')->name('transfers.store');
        Route::get('transfers/{id}', [$trf, 'show'])->whereNumber('id')->name('transfers.show');
        Route::get('transfers/{id}/edit', [$trf, 'edit'])->whereNumber('id')->name('transfers.edit');
        Route::put('transfers/{id}', [$trf, 'update'])->whereNumber('id')->middleware('throttle:60,1,acct.transfers.update')->name('transfers.update');
        foreach (['submit', 'approve', 'reject', 'cancel', 'post', 'reverse'] as $act) {
            Route::post("transfers/{id}/$act", [$trf, $act])->whereNumber('id')->middleware("throttle:60,1,acct.transfers.$act")->name("transfers.$act");
        }

        Route::get('approvals', [$apr, 'index'])->name('approvals.index');

        Route::get('currencies', [$cur, 'index'])->name('currencies.index');
        Route::post('currencies', [$cur, 'storeCurrency'])->middleware('throttle:20,1,acct.currencies.store')->name('currencies.store');
        Route::put('currencies/{id}', [$cur, 'updateCurrency'])->whereNumber('id')->middleware('throttle:30,1,acct.currencies.update')->name('currencies.update');
        Route::post('currencies/{id}/reporting', [$cur, 'setReporting'])->whereNumber('id')->middleware(['throttle:10,1,acct.currencies.reporting', 'password.confirm'])->name('currencies.reporting');
        Route::post('rates', [$cur, 'storeRate'])->middleware('throttle:60,1,acct.rates.store')->name('rates.store');
        Route::post('rates/{id}/approve', [$cur, 'approveRate'])->whereNumber('id')->middleware('throttle:60,1,acct.rates.approve')->name('rates.approve');
        Route::post('rates/{id}/reject', [$cur, 'rejectRate'])->whereNumber('id')->middleware('throttle:60,1,acct.rates.reject')->name('rates.reject');

        Route::get('reconciliations', [$rec, 'index'])->name('reconciliations.index');
        Route::get('reconciliations/create', [$rec, 'create'])->name('reconciliations.create');
        Route::get('reconciliations/expected', [$rec, 'expected'])->middleware('throttle:120,1,acct.reconciliations.expected')->name('reconciliations.expected');
        Route::post('reconciliations', [$rec, 'store'])->middleware('throttle:30,1,acct.reconciliations.store')->name('reconciliations.store');
        Route::get('reconciliations/{id}', [$rec, 'show'])->whereNumber('id')->name('reconciliations.show');
        Route::put('reconciliations/{id}/explain', [$rec, 'explain'])->whereNumber('id')->middleware('throttle:60,1,acct.reconciliations.explain')->name('reconciliations.explain');
        Route::post('reconciliations/{id}/import', [$rec, 'import'])->whereNumber('id')->middleware('throttle:20,1,acct.reconciliations.import')->name('reconciliations.import');
        Route::post('reconciliations/{id}/items', [$rec, 'addItem'])->whereNumber('id')->middleware('throttle:120,1,acct.reconciliations.items')->name('reconciliations.items');
        Route::post('reconciliations/{id}/match', [$rec, 'match'])->whereNumber('id')->middleware('throttle:30,1,acct.reconciliations.match')->name('reconciliations.match');
        Route::post('reconciliations/{id}/items/{item}', [$rec, 'matchItem'])->whereNumber(['id', 'item'])->middleware('throttle:240,1,acct.reconciliations.match-item')->name('reconciliations.match-item');
        foreach (['submit', 'approve', 'reject'] as $act) {
            Route::post("reconciliations/{id}/$act", [$rec, $act])->whereNumber('id')->middleware("throttle:30,1,acct.reconciliations.$act")->name("reconciliations.$act");
        }

        Route::get('alerts', [$alr, 'index'])->name('alerts.index');
        Route::post('alerts/read-all', [$alr, 'readAll'])->middleware('throttle:30,1,acct.alerts.read-all')->name('alerts.read-all');
        foreach (['read', 'acknowledge', 'assign', 'resolve', 'escalate'] as $act) {
            Route::post("alerts/{id}/$act", [$alr, $act === 'read' ? 'read' : $act])->whereNumber('id')->middleware("throttle:120,1,acct.alerts.$act")->name("alerts.$act");
        }

        Route::get('reports', [$rep, 'index'])->name('reports.index');
        Route::get('reports/print', [$rep, 'print'])->middleware('throttle:30,1,acct.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rep, 'pdf'])->middleware('throttle:20,1,acct.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rep, 'csv'])->middleware('throttle:20,1,acct.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rep, 'xlsx'])->middleware('throttle:20,1,acct.reports.xlsx')->name('reports.xlsx');

        Route::get('audit', [$aud, 'index'])->name('audit.index');
        Route::post('audit/verify', [$aud, 'verify'])->middleware('throttle:10,1,acct.audit.verify')->name('audit.verify');

        Route::get('settings', [$set, 'index'])->name('settings.index');
        Route::put('settings', [$set, 'save'])->middleware('throttle:30,1,acct.settings.save')->name('settings.save');
        Route::put('settings/controls', [$set, 'controls'])->middleware(['throttle:10,1,acct.settings.controls', 'password.confirm'])->name('settings.controls');
        Route::post('settings/rules', [$set, 'saveRule'])->middleware('throttle:30,1,acct.settings.rules.store')->name('settings.rules.store');
        Route::put('settings/rules/{id}', [$set, 'saveRule'])->whereNumber('id')->middleware('throttle:30,1,acct.settings.rules.update')->name('settings.rules.update');
        Route::delete('settings/rules/{id}', [$set, 'deleteRule'])->whereNumber('id')->middleware('throttle:30,1,acct.settings.rules.delete')->name('settings.rules.delete');
        Route::post('settings/categories', [$set, 'saveCategory'])->middleware('throttle:30,1,acct.settings.categories.store')->name('settings.categories.store');
        Route::put('settings/categories/{id}', [$set, 'saveCategory'])->whereNumber('id')->middleware('throttle:30,1,acct.settings.categories.update')->name('settings.categories.update');

        Route::get('funds', [$fnd, 'index'])->name('funds.index');
        Route::get('funds/{id}', [$fnd, 'show'])->whereNumber('id')->name('funds.show');
        Route::post('funds/{id}/lines/{line}/post', [$fnd, 'postLine'])->whereNumber(['id', 'line'])->middleware('throttle:60,1,acct.funds.post-line')->name('funds.post-line');
    });

    // WIMS Signatories (every route is authorised in its controller; Global Feature Control gates the signatories.* prefixes)
    Route::prefix('signatories')->name('signatories.')->group(function () {
        $sg = Controllers\Signatories\SignatoryController::class;
        Route::get('/', [$sg, 'index'])->name('index');
        Route::get('my', [$sg, 'mine'])->name('mine');
        Route::get('s/{signatory}', [$sg, 'show'])->whereNumber('signatory')->name('show');
        Route::get('register/{user}', [$sg, 'register'])->whereNumber('user')->name('register.user');
        Route::post('register/preview', [$sg, 'preview'])->middleware('throttle:30,1,signatories.preview')->name('register.preview');
        Route::post('register/confirm', [$sg, 'confirm'])->middleware('throttle:20,1,signatories.confirm')->name('register.confirm');
        Route::post('versions/{version}/consent', [$sg, 'consent'])->whereNumber('version')->name('versions.consent');
        Route::post('versions/{version}/use', [$sg, 'useVersion'])->whereNumber('version')->middleware('throttle:20,1,signatories.use')->name('versions.use');
        Route::post('versions/{version}/verify', [$sg, 'verify'])->whereNumber('version')->middleware('throttle:20,1,signatories.verify')->name('versions.verify');
        Route::post('versions/{version}/reject', [$sg, 'reject'])->whereNumber('version')->name('versions.reject');
        Route::post('s/{signatory}/suspend', [$sg, 'suspend'])->whereNumber('signatory')->name('status.suspend');
        Route::post('s/{signatory}/reinstate', [$sg, 'reinstate'])->whereNumber('signatory')->name('status.reinstate');
        Route::post('s/{signatory}/revoke', [$sg, 'revoke'])->whereNumber('signatory')->name('status.revoke');
        Route::get('versions/{version}/image/{kind}', [$sg, 'image'])->whereNumber('version')->middleware('throttle:120,1,signatories.image')->name('image');

        $rl = Controllers\Signatories\SigningRoleController::class;
        Route::get('roles', [$rl, 'index'])->name('roles.index');
        Route::post('roles', [$rl, 'store'])->name('roles.store');
        Route::put('roles/{role}', [$rl, 'update'])->whereNumber('role')->name('roles.update');
        Route::delete('roles/{role}', [$rl, 'destroy'])->whereNumber('role')->name('roles.destroy');

        $mp = Controllers\Signatories\SigningMappingController::class;
        Route::get('mappings', [$mp, 'index'])->name('mappings.index');
        Route::post('mappings', [$mp, 'store'])->name('mappings.store');
        Route::put('mappings/{mapping}', [$mp, 'update'])->whereNumber('mapping')->name('mappings.update');
        Route::delete('mappings/{mapping}', [$mp, 'destroy'])->whereNumber('mapping')->name('mappings.destroy');
        Route::post('mappings/reset/{type}', [$mp, 'reset'])->where('type', '[a-z_]+')->name('mappings.reset');

        $rp = Controllers\Signatories\SignatoryReportController::class;
        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('export/print', [$rp, 'print'])->name('export.print');
        Route::get('export/pdf', [$rp, 'pdf'])->name('export.pdf');
        Route::get('export/csv', [$rp, 'csv'])->name('export.csv');

        $st = Controllers\Signatories\SignatorySettingsController::class;
        Route::get('settings', [$st, 'index'])->name('settings.index');
        Route::put('settings', [$st, 'update'])->name('settings.update');
    });

    // Responsive Audit (Super Admin tool, permission-checked in the controller; Global Feature Control gates the responsive-audit.* prefix)
    Route::prefix('responsive-audit')->name('responsive-audit.')->group(function () {
        $ra = Controllers\ResponsiveAudit\ResponsiveAuditController::class;
        Route::get('/', [$ra, 'index'])->name('index');
        Route::post('runs', [$ra, 'store'])->name('runs.store');
        Route::get('runs/{run}', [$ra, 'show'])->whereNumber('run')->name('runs.show');
        Route::get('runs/{run}/print', [$ra, 'print'])->whereNumber('run')->name('runs.print');
        Route::get('runs/{run}/csv', [$ra, 'csv'])->whereNumber('run')->name('runs.csv');
        Route::put('settings', [$ra, 'settings'])->name('settings.update');
    });

    Route::put('/dashboard/cards', [Controllers\DashboardController::class, 'updateCards'])->name('dashboard.cards.update');
    Route::delete('/dashboard/cards', [Controllers\DashboardController::class, 'resetCards'])->name('dashboard.cards.reset');
    Route::post('/dashboard/shortcut-card-order', [Controllers\DashboardController::class, 'updateShortcutCardOrder'])->name('dashboard.shortcut-card-order.update');
    Route::get('/dashboard/warehouse-stock-value/print', [Controllers\DashboardController::class, 'warehouseStockValuePrint'])
        ->name('dashboard.warehouse_stock_value_print');
    Route::get('/dashboard/warehouse-stock-value/pdf', [Controllers\DashboardController::class, 'warehouseStockValuePdf'])
        ->name('dashboard.warehouse_stock_value_pdf');
    Route::get('/dashboard/warehouse-stock-value/{warehouse}/items', [Controllers\DashboardController::class, 'warehouseStockItems'])->whereNumber('warehouse')->name('dashboard.warehouse_stock_items');
    Route::get('/activity', [Controllers\DashboardController::class, 'activity'])->name('activity');

    Route::get('/alerts', [Controllers\AjaxController::class, 'alerts'])->name('alerts');
    Route::get('/alerts/{warehouse}', [Controllers\AjaxController::class, 'warehouse'])->name('alerts.list');

    Route::get('/offline/health', [Controllers\OfflineHealthController::class, 'check'])->name('offline.health');
    Route::get('/offline/sync', [Controllers\OfflineSyncController::class, 'sync'])->name('offline.sync');

    Route::get('/settings', [Controllers\SettingController::class, 'index'])->name('settings');
    Route::post('settings', [Controllers\SettingController::class, 'store'])->name('settings.store');
    Route::post('settings/system-lockdown', [Controllers\SettingController::class, 'toggleSystemLockdown'])->name('settings.system-lockdown.toggle');
    Route::post('settings/logo', [Controllers\SettingController::class, 'uploadLogo'])->name('settings.logo.upload');
    Route::put('settings/terms', [Controllers\SettingController::class, 'updateTerms'])->name('settings.terms.update');
    Route::put('settings/impersonation-expiry', [Controllers\SettingController::class, 'updateImpersonationExpiry'])->name('settings.impersonation-expiry.update');
    Route::put('settings/typography', [Controllers\SettingController::class, 'updateTypography'])->name('settings.typography.update');
    Route::delete('settings/typography', [Controllers\SettingController::class, 'resetTypography'])->name('settings.typography.reset');
    Route::put('settings/device-limit', [Controllers\SettingController::class, 'updateDeviceLimit'])->name('settings.device-limit.update');
    // the devices a person is signed in on: the held sign-in page and the sign-out actions (Profile uses the same ones)
    Route::get('device-limit', [Controllers\DeviceController::class, 'show'])->name('device-limit.show');
    Route::post('device-limit/sign-out-others', [Controllers\DeviceController::class, 'signOutOthers'])->middleware('throttle:20,1,device-limit.others')->name('device-limit.sign-out-others');
    Route::post('device-limit/sign-out-all', [Controllers\DeviceController::class, 'signOutAll'])->middleware('throttle:10,1,device-limit.all')->name('device-limit.sign-out-all');
    Route::post('device-limit/sign-out/{token}', [Controllers\DeviceController::class, 'signOut'])->where('token', '[a-f0-9]{24}')->middleware('throttle:30,1,device-limit.one')->name('device-limit.sign-out');

    // WIMS Licence (Super Admins with read-wims-licence) and the Licence Manager (Dr Hajas only, hard-coded)
    Route::get('wims-licence', [Controllers\WimsLicenceController::class, 'show'])->name('wims-licence.show');
    Route::post('wims-licence/apply', [Controllers\WimsLicenceController::class, 'apply'])->name('wims-licence.apply');
    Route::get('wims-licence/print', [Controllers\WimsLicenceController::class, 'print'])->name('wims-licence.print');
    Route::prefix('licence-manager')->name('licence-manager.')->middleware('licence.manager')->group(function () {
        Route::get('/', [Controllers\LicenceManagerController::class, 'index'])->name('index');
        Route::post('/', [Controllers\LicenceManagerController::class, 'store'])->name('store');
        Route::put('view-access', [Controllers\LicenceManagerController::class, 'viewAccessUpdate'])->name('view-access');
        Route::put('{licence}', [Controllers\LicenceManagerController::class, 'update'])->name('update');
        Route::delete('{licence}', [Controllers\LicenceManagerController::class, 'destroy'])->name('destroy');
        Route::post('{licence}/activate', [Controllers\LicenceManagerController::class, 'activate'])->name('activate');
        Route::post('{licence}/deactivate', [Controllers\LicenceManagerController::class, 'deactivate'])->name('deactivate');
        Route::post('{licence}/reactivate', [Controllers\LicenceManagerController::class, 'reactivate'])->name('reactivate');
        Route::get('{licence}/file', [Controllers\LicenceManagerController::class, 'download'])->name('download');
        Route::get('{licence}/print', [Controllers\LicenceManagerController::class, 'print'])->name('print');
    });

    Route::get('terms', [Controllers\TermsController::class, 'show'])->name('terms.show');
    Route::get('terms/print', [Controllers\TermsController::class, 'print'])->name('terms.print');
    Route::get('terms/pdf', [Controllers\TermsController::class, 'pdf'])->name('terms.pdf');

    Route::resource('task-reminders', Controllers\TaskReminderController::class)
        ->parameters(['task-reminders' => 'task_reminder'])
        ->only(['index', 'store', 'update', 'destroy']);
    Route::put('task-reminders/{task_reminder}/toggle-complete', [Controllers\TaskReminderController::class, 'toggleComplete'])
        ->name('task-reminders.toggle-complete');
    Route::put('task-reminders/{task_reminder}/restore', [Controllers\TaskReminderController::class, 'restore'])
        ->withTrashed()
        ->name('task-reminders.restore');
    Route::get('task-reminders/{task_reminder}/attachments/{attachment}/download', [Controllers\TaskReminderController::class, 'downloadAttachment'])
        ->name('task-reminders.attachments.download');
    Route::put('task-reminders-preferences', [Controllers\TaskReminderController::class, 'updatePreferences'])
        ->name('task-reminders.preferences.update');
    Route::put('notification-preferences', [Controllers\NotificationPreferenceController::class, 'updatePreferences'])
        ->name('notification-preferences.update');
    Route::put('idle-timeout', [Controllers\IdleTimeoutController::class, 'savePreference'])->name('idle-timeout.update');
    Route::post('idle/ping', [Controllers\IdleTimeoutController::class, 'ping'])->middleware('throttle:120,1')->name('idle.ping');
    Route::post('quick-menu/track', [Controllers\QuickMenuController::class, 'track'])
        ->name('quick-menu.track');
    Route::put('quick-menu-preferences', [Controllers\QuickMenuController::class, 'updatePreferences'])
        ->name('quick-menu.preferences.update');
    Route::get('task-reminders/print', [Controllers\TaskReminderController::class, 'print'])->name('task-reminders.print');
    Route::get('task-reminders/pdf', [Controllers\TaskReminderController::class, 'pdf'])->name('task-reminders.pdf');

    Route::get('feature-unavailable', [Controllers\SystemFeatureController::class, 'unavailable'])->name('feature-unavailable');
    // Settings > Performance & Data (Super Admin): retention numbers, the activity archive, slow queries, sending in the background
    Route::get('settings/performance', [Controllers\PerformanceController::class, 'index'])->name('performance.index');
    Route::put('settings/performance', [Controllers\PerformanceController::class, 'update'])->middleware('throttle:20,1,performance.update')->name('performance.update');
    Route::post('settings/performance/housekeeping', [Controllers\PerformanceController::class, 'housekeeping'])->middleware('throttle:4,1,performance.housekeeping')->name('performance.housekeeping');
    Route::get('settings/performance/archive', [Controllers\PerformanceController::class, 'archive'])->middleware('throttle:60,1,performance.archive')->name('performance.archive.list');
    Route::get('settings/performance/archive/download', [Controllers\PerformanceController::class, 'archiveDownload'])->middleware('throttle:6,1,performance.archive.download')->name('performance.archive.download');
    Route::get('settings/performance/archive/plan', [Controllers\PerformanceController::class, 'archivePlan'])->middleware('throttle:60,1,performance.archive.plan')->name('performance.archive.plan');
    Route::get('settings/performance/archive/pdf', [Controllers\PerformanceController::class, 'archivePdf'])->middleware('throttle:6,1,performance.archive.pdf')->name('performance.archive.pdf');
    Route::post('settings/performance/archive/export', [Controllers\PerformanceController::class, 'archiveExportStart'])->middleware('throttle:6,1,performance.archive.export')->name('performance.archive.export');
    Route::get('settings/performance/archive/jobs', [Controllers\PerformanceController::class, 'archiveExports'])->middleware('throttle:90,1,performance.archive.jobs')->name('performance.archive.jobs');
    Route::get('settings/performance/archive/exports/{export}/download', [Controllers\PerformanceController::class, 'archiveExportDownload'])->whereNumber('export')->middleware('throttle:10,1,performance.archive.export.download')->name('performance.archive.export.download');
    Route::delete('settings/performance/archive/exports/{export}', [Controllers\PerformanceController::class, 'archiveExportDelete'])->whereNumber('export')->name('performance.archive.export.delete');
    Route::delete('settings/performance/archive', [Controllers\PerformanceController::class, 'archiveClear'])->middleware('throttle:4,1,performance.archive.clear')->name('performance.archive.clear');
    Route::delete('settings/performance/slow', [Controllers\PerformanceController::class, 'clearSlow'])->name('performance.slow.clear');
    Route::post('settings/performance/queue/run', [Controllers\PerformanceController::class, 'queueRun'])->middleware('throttle:6,1,performance.queue.run')->name('performance.queue.run');
    Route::post('settings/performance/queue/retry', [Controllers\PerformanceController::class, 'queueRetry'])->middleware('throttle:6,1,performance.queue.retry')->name('performance.queue.retry');
    Route::delete('settings/performance/queue/failed', [Controllers\PerformanceController::class, 'queueFlush'])->middleware('throttle:4,1,performance.queue.flush')->name('performance.queue.flush');
    Route::get('system-features', [Controllers\SystemFeatureController::class, 'index'])->name('system-features.index');
    Route::put('system-features/{systemFeature}', [Controllers\SystemFeatureController::class, 'update'])->name('system-features.update');
    Route::get('system-features/reconcile', [Controllers\SystemFeatureController::class, 'reconcile'])->name('system-features.reconcile');
    Route::post('system-features/reconcile', [Controllers\SystemFeatureController::class, 'reconcileApply'])->name('system-features.reconcile.apply');

    Route::post('search/items', [Controllers\AjaxController::class, 'items'])->name('items.search');
    Route::post('scan/item', [Controllers\AjaxController::class, 'scan'])->name('items.scan');
    Route::post('scan/resolve', [Controllers\ScanResolveController::class, 'resolve'])->middleware('throttle:120,1,scan.resolve')->name('scan.resolve');
    Route::get('barcodes', [Controllers\ItemController::class, 'barcodes'])->name('items.barcodes');
    Route::get('barcodes/print', [Controllers\ItemController::class, 'barcodesPrint'])->middleware('throttle:30,1,items.barcodes.print')->name('items.barcodes.print');
    Route::post('barcodes/preferences', [Controllers\ItemController::class, 'saveBarcodePrefs'])->middleware('throttle:60,1,items.barcodes.preferences')->name('items.barcodes.preferences');
    Route::post('check/stock', [Controllers\AjaxController::class, 'checkStock'])->name('stock.check');
    Route::get('items/{item}/report/print', [Controllers\ItemReportController::class, 'print'])->whereNumber('item')->middleware('throttle:60,1,items.report.print')->name('items.report.print');
    Route::get('items/{item}/report/pdf', [Controllers\ItemReportController::class, 'pdf'])->whereNumber('item')->middleware('throttle:60,1,items.report.pdf')->name('items.report.pdf');
    Route::get('items/{item}/trail', [Controllers\ItemController::class, 'trail'])->name('items.trail');
    Route::get('items/{item}/price-history', [Controllers\ItemPriceHistoryController::class, 'index'])->name('items.price-history.index');
    Route::post('items/{item}/price-history', [Controllers\ItemPriceHistoryController::class, 'store'])->name('items.price-history.store');
    Route::patch('items/{item}/price-history/{price_history}/apply', [Controllers\ItemPriceHistoryController::class, 'applyPrice'])->name('items.price-history.apply');
    Route::put('items/{item}/price-history/{price_history}', [Controllers\ItemPriceHistoryController::class, 'update'])->name('items.price-history.update');
    Route::delete('items/{item}/price-history/{price_history}', [Controllers\ItemPriceHistoryController::class, 'destroy'])->name('items.price-history.destroy');
    Route::post('search/contacts', [Controllers\AjaxController::class, 'contacts'])->name('contacts.search');
    Route::delete('media/{media}/delete', [Controllers\AjaxController::class, 'delete'])->name('media.delete');
    Route::get('media/{media}/download', [Controllers\AjaxController::class, 'download'])->name('media.download');
    Route::delete('users/{user}/disable_2fa', [Controllers\UserController::class, 'disable2FA'])->name('users.disable.2fa');
    Route::get('users/audit-history', [Controllers\UserAuditController::class, 'index'])->name('users.audit-history');
    Route::post('users/{user}/disable-account', [Controllers\UserController::class, 'disableAccount'])->name('users.disable.account');
    Route::post('users/{user}/enable-account', [Controllers\UserController::class, 'enableAccount'])->name('users.enable.account');

    // WIMS Secure User Impersonation ("Login As User") — see App\Support\Impersonation.
    // start re-checks the SUPER ADMIN's own password inline (same
    // Hash::check pattern as UserController::disableAccount()/enableAccount()
    // above) — never the target user's password.
    Route::get('impersonation/reasons', [Controllers\ImpersonationController::class, 'reasons'])->name('impersonation.reasons');
    Route::post('users/{user}/impersonate', [Controllers\ImpersonationController::class, 'store'])->name('users.impersonate');
    Route::post('impersonation/exit', [Controllers\ImpersonationController::class, 'destroy'])->name('impersonation.exit');
    Route::get('administration/impersonation/active', [Controllers\ImpersonationController::class, 'activeIndex'])->name('impersonation.active');
    Route::post('administration/impersonation/{impersonationSession}/terminate', [Controllers\ImpersonationController::class, 'terminate'])->name('impersonation.active.terminate');
    Route::get('administration/impersonation/history', [Controllers\ImpersonationController::class, 'historyIndex'])->name('impersonation.history');
    Route::get('administration/impersonation/history/print', [Controllers\ImpersonationController::class, 'print'])->name('impersonation.history.print');
    Route::get('administration/impersonation/history/download', [Controllers\ImpersonationController::class, 'download'])->name('impersonation.history.download');
    Route::post('items/bulk-used', [Controllers\ItemController::class, 'bulkUsed'])->middleware('throttle:10,1,items.bulk-used')->name('items.bulk-used');
    Route::post('items/bulk-consumable', [Controllers\ItemController::class, 'bulkConsumable'])->middleware('throttle:10,1,items.bulk-consumable')->name('items.bulk-consumable');
    Route::post('items/{item}/create-used', [Controllers\ItemController::class, 'createUsed'])->name('items.create-used');
    Route::delete('items/{item}/photo', [Controllers\ItemController::class, 'destroyPhoto'])->name('items.deletePhoto');
    Route::delete('items/{item}/photos/{photo}', [Controllers\ItemController::class, 'destroyGalleryPhoto'])->name('items.photos.destroy');
    Route::post('items/{item}/photos/{photo}/main', [Controllers\ItemController::class, 'makeGalleryPhotoMain'])->name('items.photos.main');
    Route::get('items/{item}/bincard', [Controllers\ItemController::class, 'bincard'])->whereNumber('item')->name('items.bincard');
    Route::post('items/{item}/bincard', [Controllers\ItemController::class, 'refreshBincard'])->whereNumber('item')->middleware('throttle:20,1,items.bincard.refresh')->name('items.bincard.refresh');
    Route::delete('items/{item}/bincard', [Controllers\ItemController::class, 'removeBincard'])->whereNumber('item')->name('items.bincard.remove');
    Route::get('items/{item}/bincard/print', [Controllers\ItemController::class, 'bincardPrint'])->whereNumber('item')->middleware('throttle:30,1,items.bincard.print')->name('items.bincard.print');
    Route::get('items/{item}/bincard/pdf', [Controllers\ItemController::class, 'bincardPdf'])->whereNumber('item')->middleware('throttle:20,1,items.bincard.pdf')->name('items.bincard.pdf');
    Route::post('items/{item}/photos/{photo}/cutout', [Controllers\ItemController::class, 'cutoutPhoto'])->whereNumber('item')->middleware('throttle:30,1,items.photos.cutout')->name('items.photos.cutout');
    Route::post('items/{item}/photos/{photo}/bincard', [Controllers\ItemController::class, 'assignBincard'])->whereNumber('item')->name('items.photos.bincard');
    Route::post('items/fetch-photo-download', [Controllers\ItemController::class, 'fetchPhotoDownload'])->name('items.fetchPhotoDownload');

    // Multi Transfer: items from several warehouses to one destination (an ordinary transfer per source, all held for approval). Literal paths first.
    // QR codes: the scan address of a code, the scanner page, a record's code (JSON / PNG / printable label) and the settings
    Route::get('q/{token}', [Controllers\QrCodeController::class, 'scan'])->where('token', '[A-Za-z0-9]{8,64}')->middleware('throttle:90,1,qr.scan')->name('qr.scan');
    Route::get('qr-codes/scanner', [Controllers\QrCodeController::class, 'scanner'])->name('qr-codes.scanner');
    Route::get('qr-codes/check/{token}', [Controllers\QrCodeController::class, 'check'])->where('token', '[A-Za-z0-9]{8,64}')->middleware('throttle:120,1,qr.check')->name('qr-codes.check');
    Route::get('qr-codes/scans', [Controllers\QrCodeController::class, 'scans'])->name('qr-codes.scans');
    Route::get('qr-codes/scans/{id}/image', [Controllers\QrCodeController::class, 'scanImage'])->whereNumber('id')->middleware('throttle:600,1,qr.scans.image')->name('qr-codes.scans.image');
    Route::get('qr-codes/scans/print', [Controllers\QrCodeController::class, 'scansPrint'])->middleware('throttle:30,1,qr.scans.print')->name('qr-codes.scans.print');
    Route::get('qr-codes/scans/pdf', [Controllers\QrCodeController::class, 'scansPdf'])->middleware('throttle:30,1,qr.scans.pdf')->name('qr-codes.scans.pdf');
    Route::get('qr-codes/activation', [Controllers\QrCodeController::class, 'activation'])->name('qr-codes.activation');
    Route::put('qr-codes/activation', [Controllers\QrCodeController::class, 'activationUpdate'])->name('qr-codes.activation.update');
    Route::get('qr-codes/settings', [Controllers\QrCodeController::class, 'settings'])->name('qr-codes.settings');
    Route::put('qr-codes/settings', [Controllers\QrCodeController::class, 'update'])->name('qr-codes.settings.update');
    Route::get('qr-codes/{type}/{id}', [Controllers\QrCodeController::class, 'show'])->where('type', '[a-z_]+')->whereNumber('id')->middleware('throttle:120,1,qr.show')->name('qr-codes.show');
    Route::get('qr-codes/{type}/{id}/download', [Controllers\QrCodeController::class, 'download'])->where('type', '[a-z_]+')->whereNumber('id')->middleware('throttle:60,1,qr.download')->name('qr-codes.download');
    Route::get('qr-codes/{type}/{id}/print', [Controllers\QrCodeController::class, 'print'])->where('type', '[a-z_]+')->whereNumber('id')->middleware('throttle:60,1,qr.print')->name('qr-codes.print');
    Route::post('qr-codes/{type}/{id}/regenerate', [Controllers\QrCodeController::class, 'regenerate'])->where('type', '[a-z_]+')->whereNumber('id')->middleware('throttle:20,1,qr.regenerate')->name('qr-codes.regenerate');

    // External Stock: stock of External Personnel kept in our warehouses (approval based). Names all start "external-stock." so Global Feature Control covers them.
    Route::prefix('external-stock')->name('external-stock.')->group(function () {
        $mv = Controllers\ExternalStock\MovementController::class;
        $rp = Controllers\ExternalStock\ReportController::class;
        Route::get('/', [$mv, 'index'])->name('index');
        Route::get('create', [$mv, 'create'])->name('create');
        Route::get('availability', [$mv, 'availability'])->middleware('throttle:240,1,external-stock.availability')->name('availability');
        Route::post('/', [$mv, 'store'])->middleware('throttle:60,1,external-stock.store')->name('store');
        Route::post('people', [$mv, 'storePerson'])->middleware('throttle:60,1,external-stock.people.store')->name('people.store');
        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('reports/print', [$rp, 'print'])->middleware('throttle:30,1,external-stock.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rp, 'pdf'])->middleware('throttle:20,1,external-stock.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rp, 'csv'])->middleware('throttle:20,1,external-stock.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rp, 'xlsx'])->middleware('throttle:20,1,external-stock.reports.xlsx')->name('reports.xlsx');
        Route::get('{movement}', [$mv, 'show'])->whereNumber('movement')->name('show');
        Route::get('{movement}/edit', [$mv, 'edit'])->whereNumber('movement')->name('edit');
        Route::put('{movement}', [$mv, 'update'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.update')->name('update');
        Route::post('{movement}/approve', [$mv, 'approve'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.approve')->name('approve');
        Route::post('{movement}/reject', [$mv, 'reject'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.reject')->name('reject');
        Route::post('{movement}/cancel', [$mv, 'cancel'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.cancel')->name('cancel');
        Route::get('{movement}/print', [$mv, 'print'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.print')->name('print');
        Route::get('{movement}/pdf', [$mv, 'pdf'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.pdf')->name('pdf');
        Route::post('{movement}/files', [$mv, 'attach'])->whereNumber('movement')->middleware('throttle:60,1,external-stock.attach')->name('attach');
        Route::get('{movement}/files/{file}', [$mv, 'download'])->whereNumber(['movement', 'file'])->middleware('throttle:120,1,external-stock.download')->name('download');
        Route::delete('{movement}/files/{file}', [$mv, 'removeFile'])->whereNumber(['movement', 'file'])->middleware('throttle:60,1,external-stock.remove-file')->name('remove-file');
        Route::post('{movement}/gate-pass', [Controllers\GatePassController::class, 'storeForExternalStock'])->whereNumber('movement')->name('gate-pass.store');
        Route::post('{movement}/delivery-note', [Controllers\DeliveryNoteController::class, 'storeForExternalStock'])->whereNumber('movement')->name('delivery-note.store');
        Route::post('{movement}/transport-route', [Controllers\ExternalStock\RouteController::class, 'store'])->whereNumber('movement')->name('transport-route.store');
    });

    // Administration: the weekly document in three stages (requested, approved, authorized), the template and items, the Charity list, reports. Names all start "weekly-administration." so Global Feature Control covers them.
    Route::prefix('weekly-administration')->name('weekly-administration.')->group(function () {
        $dc = Controllers\WeeklyAdministration\DocumentController::class;
        $it = Controllers\WeeklyAdministration\ItemController::class;
        $ch = Controllers\WeeklyAdministration\CharityController::class;
        $rp = Controllers\WeeklyAdministration\ReportController::class;
        $st = Controllers\WeeklyAdministration\SettingsController::class;
        $pc = Controllers\WeeklyAdministration\PaymentController::class;
        Route::get('/', [$dc, 'index'])->name('index');
        Route::get('create', [$dc, 'create'])->name('create');
        Route::post('/', [$dc, 'store'])->middleware('throttle:60,1,weekly-administration.store')->name('store');
        Route::get('charity-pull', [$dc, 'charityPull'])->middleware('throttle:60,1,weekly-administration.charity.pull')->name('charity.pull');
        Route::get('settings', [$st, 'index'])->name('settings');
        Route::put('settings', [$st, 'update'])->middleware('throttle:30,1,weekly-administration.settings.update')->name('settings.update');
        Route::get('items', [$it, 'index'])->name('items.index');
        Route::post('items', [$it, 'store'])->middleware('throttle:60,1,weekly-administration.items.store')->name('items.store');
        Route::post('items/reorder', [$it, 'reorder'])->middleware('throttle:60,1,weekly-administration.items.reorder')->name('items.reorder');
        Route::put('items/{item}', [$it, 'update'])->whereNumber('item')->middleware('throttle:60,1,weekly-administration.items.update')->name('items.update');
        Route::delete('items/{item}', [$it, 'destroy'])->whereNumber('item')->middleware('throttle:60,1,weekly-administration.items.destroy')->name('items.destroy');
        Route::get('charity', [$ch, 'index'])->name('charity.index');
        Route::post('charity', [$ch, 'store'])->middleware('throttle:60,1,weekly-administration.charity.store')->name('charity.store');
        Route::put('charity/{person}', [$ch, 'update'])->whereNumber('person')->middleware('throttle:60,1,weekly-administration.charity.update')->name('charity.update');
        Route::delete('charity/{person}', [$ch, 'destroy'])->whereNumber('person')->middleware('throttle:60,1,weekly-administration.charity.destroy')->name('charity.destroy');
        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('reports/print', [$rp, 'print'])->middleware('throttle:30,1,weekly-administration.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rp, 'pdf'])->middleware('throttle:20,1,weekly-administration.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rp, 'csv'])->middleware('throttle:20,1,weekly-administration.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rp, 'xlsx'])->middleware('throttle:20,1,weekly-administration.reports.xlsx')->name('reports.xlsx');
        // the documents
        Route::get('{document}', [$dc, 'show'])->whereNumber('document')->name('show');
        Route::get('{document}/edit', [$dc, 'edit'])->whereNumber('document')->name('edit');
        Route::put('{document}', [$dc, 'update'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.update')->name('update');
        Route::post('{document}/approve', [$dc, 'approve'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.approve')->name('approve');
        Route::post('{document}/authorize', [$dc, 'authorizeIt'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.authorize')->name('authorize');
        Route::post('{document}/reject', [$dc, 'reject'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.reject')->name('reject');
        Route::post('{document}/cancel', [$dc, 'cancel'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.cancel')->name('cancel');
        Route::post('{document}/payments', [$pc, 'store'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.payments.store')->name('payments.store');
        Route::post('{document}/payments/voucher', [$pc, 'voucher'])->whereNumber('document')->middleware('throttle:30,1,weekly-administration.payments.voucher')->name('payments.voucher');
        Route::post('{document}/payments/vouchers', [$pc, 'vouchers'])->whereNumber('document')->middleware('throttle:20,1,weekly-administration.payments.vouchers')->name('payments.vouchers');
        Route::post('payments/{payment}/reverse', [$pc, 'reverse'])->whereNumber('payment')->middleware('throttle:30,1,weekly-administration.payments.reverse')->name('payments.reverse');
        Route::post('{document}/duplicate', [$dc, 'duplicate'])->whereNumber('document')->middleware('throttle:10,1,weekly-administration.duplicate')->name('duplicate');
        Route::post('{document}/send', [$dc, 'send'])->whereNumber('document')->middleware('throttle:20,1,weekly-administration.send')->name('send');
        Route::get('{document}/print', [$dc, 'print'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.print')->name('print');
        Route::get('{document}/pdf', [$dc, 'pdfDownload'])->whereNumber('document')->middleware('throttle:60,1,weekly-administration.pdf')->name('pdf');
    });

    // Travels: the travels of staff and users (approval based), ticket agents and what they are owed, reports. Names all start "travels." so Global Feature Control covers them.
    Route::prefix('travels')->name('travels.')->group(function () {
        $tr = Controllers\Travels\TravelController::class;
        $ag = Controllers\Travels\AgentController::class;
        $rp = Controllers\Travels\ReportController::class;
        $st = Controllers\Travels\SettingsController::class;
        Route::get('/', [$tr, 'index'])->name('index');
        Route::get('create', [$tr, 'create'])->name('create');
        Route::post('/', [$tr, 'store'])->middleware('throttle:60,1,travels.store')->name('store');
        Route::get('settings', [$st, 'index'])->name('settings');
        Route::put('settings', [$st, 'update'])->middleware('throttle:30,1,travels.settings.update')->name('settings.update');
        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('reports/print', [$rp, 'print'])->middleware('throttle:30,1,travels.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rp, 'pdf'])->middleware('throttle:20,1,travels.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rp, 'csv'])->middleware('throttle:20,1,travels.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rp, 'xlsx'])->middleware('throttle:20,1,travels.reports.xlsx')->name('reports.xlsx');
        // travel packages: ready-made travels with their amounts, their history, and the Travel Request made from one
        $pk = Controllers\Travels\PackageController::class;
        Route::get('packages', [$pk, 'index'])->name('packages.index');
        Route::post('packages', [$pk, 'store'])->middleware('throttle:60,1,travels.packages.store')->name('packages.store');
        Route::put('packages/{package}', [$pk, 'update'])->whereNumber('package')->middleware('throttle:60,1,travels.packages.update')->name('packages.update');
        Route::post('packages/{package}/toggle', [$pk, 'toggle'])->whereNumber('package')->middleware('throttle:60,1,travels.packages.toggle')->name('packages.toggle');
        Route::delete('packages/{package}', [$pk, 'destroy'])->whereNumber('package')->middleware('throttle:30,1,travels.packages.destroy')->name('packages.destroy');
        Route::get('packages/{package}/history', [$pk, 'history'])->whereNumber('package')->name('packages.history');
        Route::get('packages/{package}/request', [$pk, 'requestForm'])->whereNumber('package')->name('packages.request');
        Route::post('packages/{package}/request', [$pk, 'submitRequest'])->whereNumber('package')->middleware('throttle:30,1,travels.packages.request.store')->name('packages.request.store');
        // ticket agents and visa issuers: balance, opening balance, payments, statement
        Route::get('agents', [$ag, 'index'])->name('agents.index');
        Route::get('agents/payments/{payment}', [$ag, 'paymentShow'])->whereNumber('payment')->name('agents.payments.show');
        Route::get('agents/payments/{payment}/print', [$ag, 'paymentPrint'])->whereNumber('payment')->middleware('throttle:60,1,travels.agents.payments.print')->name('agents.payments.print');
        Route::get('agents/payments/{payment}/pdf', [$ag, 'paymentPdf'])->whereNumber('payment')->middleware('throttle:60,1,travels.agents.payments.pdf')->name('agents.payments.pdf');
        Route::post('agents/payments/{payment}/files', [$ag, 'paymentAttach'])->whereNumber('payment')->middleware('throttle:60,1,travels.agents.payments.attach')->name('agents.payments.attach');
        Route::get('agents/payments/{payment}/files/{file}', [$ag, 'paymentDownload'])->whereNumber(['payment', 'file'])->middleware('throttle:120,1,travels.agents.payments.download')->name('agents.payments.download');
        Route::delete('agents/payments/{payment}/files/{file}', [$ag, 'paymentRemoveFile'])->whereNumber(['payment', 'file'])->middleware('throttle:60,1,travels.agents.payments.remove-file')->name('agents.payments.remove-file');
        Route::get('agents/{contact}', [$ag, 'show'])->whereNumber('contact')->name('agents.show');
        Route::post('agents/{contact}/opening', [$ag, 'setOpening'])->whereNumber('contact')->middleware('throttle:30,1,travels.agents.opening')->name('agents.opening.store');
        Route::delete('agents/{contact}/opening/{opening}', [$ag, 'removeOpening'])->whereNumber(['contact', 'opening'])->middleware('throttle:30,1,travels.agents.opening.destroy')->name('agents.opening.destroy');
        Route::post('agents/{contact}/payments', [$ag, 'requestPayment'])->whereNumber('contact')->middleware('throttle:30,1,travels.agents.payments.store')->name('agents.payments.store');
        // the travel records
        Route::get('{travel}', [$tr, 'show'])->whereNumber('travel')->name('show');
        Route::get('{travel}/edit', [$tr, 'edit'])->whereNumber('travel')->name('edit');
        Route::put('{travel}', [$tr, 'update'])->whereNumber('travel')->middleware('throttle:60,1,travels.update')->name('update');
        Route::post('{travel}/approve', [$tr, 'approve'])->whereNumber('travel')->middleware('throttle:60,1,travels.approve')->name('approve');
        Route::post('{travel}/reject', [$tr, 'reject'])->whereNumber('travel')->middleware('throttle:60,1,travels.reject')->name('reject');
        Route::post('{travel}/cancel', [$tr, 'cancel'])->whereNumber('travel')->middleware('throttle:60,1,travels.cancel')->name('cancel');
        Route::get('{travel}/print', [$tr, 'print'])->whereNumber('travel')->middleware('throttle:60,1,travels.print')->name('print');
        Route::get('{travel}/pdf', [$tr, 'pdf'])->whereNumber('travel')->middleware('throttle:60,1,travels.pdf')->name('pdf');
        Route::post('{travel}/files', [$tr, 'attach'])->whereNumber('travel')->middleware('throttle:60,1,travels.attach')->name('attach');
        Route::get('{travel}/files/{file}', [$tr, 'download'])->whereNumber(['travel', 'file'])->middleware('throttle:120,1,travels.download')->name('download');
        Route::delete('{travel}/files/{file}', [$tr, 'removeFile'])->whereNumber(['travel', 'file'])->middleware('throttle:60,1,travels.remove-file')->name('remove-file');
    });

    // RANS ID & Pass Management: Staff IDs, Compound Worker IDs, Visitor Passes and Gate Passes of people. Names start "id-passes." (parent), then "id-passes.staff." / ".compound." / ".visitors." / ".gates."
    // (one kind each), ".print-centre.", ".batch.", ".settings.", ".reports." and ".verify." so Global Feature Control can switch each part off. The QR page is outside this group (public, rate limited).
    Route::prefix('id-passes')->name('id-passes.')->group(function () {
        $dash = Controllers\IdPasses\DashboardController::class;
        $card = Controllers\IdPasses\CardController::class;
        $prn = Controllers\IdPasses\PrintController::class;
        $look = Controllers\IdPasses\LookupController::class;
        $pc = Controllers\IdPasses\PrintCentreController::class;
        $set = Controllers\IdPasses\SettingsController::class;
        $rep = Controllers\IdPasses\ReportController::class;
        Route::get('/', [$dash, 'index'])->name('dashboard');
        Route::get('my-id', [Controllers\IdPasses\MyIdController::class, 'show'])->middleware('throttle:120,1,id-passes.my-id')->name('my-id');
        Route::get('lookup/people', [$look, 'people'])->middleware('throttle:240,1,id-passes.lookup')->name('lookup.people');
        Route::get('lookup/staff/{staff}/photo', [$look, 'staffPhoto'])->whereNumber('staff')->middleware('throttle:240,1,id-passes.lookup.photo')->name('lookup.staff-photo');
        Route::post('lookup/preview', [$look, 'preview'])->middleware('throttle:90,1,id-passes.preview')->name('lookup.preview');
        Route::get('card.css', [$look, 'css'])->name('lookup.css');

        Route::prefix('print-centre')->name('print-centre.')->group(function () use ($pc) {
            Route::get('/', [$pc, 'index'])->name('index');
            Route::get('cards', [$pc, 'cards'])->middleware('throttle:240,1,id-passes.print-centre.cards')->name('cards');
            Route::get('faces', [$pc, 'faces'])->middleware('throttle:240,1,id-passes.print-centre.faces')->name('faces');
            Route::get('history', [$pc, 'history'])->name('history');
        });
        Route::prefix('batch')->name('batch.')->group(function () use ($pc) {
            Route::post('preview', [$pc, 'preview'])->middleware('throttle:90,1,id-passes.batch.preview')->name('preview');
            Route::post('/', [$pc, 'store'])->middleware('throttle:20,1,id-passes.batch.store')->name('store');
            Route::get('{batch}', [$pc, 'batch'])->whereNumber('batch')->middleware('throttle:240,1,id-passes.batch.show')->name('show');
            Route::get('{batch}/download', [$pc, 'download'])->whereNumber('batch')->middleware('throttle:60,1,id-passes.batch.download')->name('download');
        });
        Route::prefix('reports')->name('reports.')->group(function () use ($rep) {
            Route::get('/', [$rep, 'index'])->name('index');
            Route::get('print', [$rep, 'print'])->middleware('throttle:30,1,id-passes.reports.print')->name('print');
            Route::get('pdf', [$rep, 'pdf'])->middleware('throttle:20,1,id-passes.reports.pdf')->name('pdf');
            Route::get('csv', [$rep, 'csv'])->middleware('throttle:20,1,id-passes.reports.csv')->name('csv');
        });
        Route::prefix('settings')->name('settings.')->group(function () use ($set) {
            Route::get('/', [$set, 'index'])->name('index');
            Route::get('samples', [$set, 'samples'])->middleware('throttle:60,1,id-passes.settings.samples')->name('samples');
            Route::put('general', [$set, 'general'])->middleware('throttle:30,1,id-passes.settings.general')->name('general');
            Route::put('qr', [$set, 'qr'])->middleware('throttle:30,1,id-passes.settings.qr')->name('qr');
            Route::post('categories', [$set, 'store'])->middleware('throttle:30,1,id-passes.settings.categories.store')->name('categories.store');
            Route::put('categories/{category}', [$set, 'update'])->whereNumber('category')->middleware('throttle:60,1,id-passes.settings.categories.update')->name('categories.update');
            Route::post('categories/{category}/photo-background', [$set, 'applyPhotoBackground'])->whereNumber('category')->middleware('throttle:20,1,id-passes.settings.categories.photo-background')->name('categories.photo-background');
            Route::delete('categories/{category}', [$set, 'destroy'])->whereNumber('category')->middleware('throttle:20,1,id-passes.settings.categories.destroy')->name('categories.destroy');
        });

        // one group of routes per kind of ID: the same controllers, the kind comes from the route
        foreach (\App\Services\IdPass\IdPassKinds::ALL as $kind => $k) {
            Route::prefix($k['slug'])->name($k['route'] . '.')->group(function () use ($card, $prn, $k, $kind) {
                Route::get('/', [$card, 'index'])->defaults('kind', $kind)->name('index');
                Route::get('create', [$card, 'create'])->defaults('kind', $kind)->name('create');
                Route::post('/', [$card, 'store'])->middleware('throttle:60,1,id-passes.' . $k['route'] . '.store')->defaults('kind', $kind)->name('store');
                Route::get('{card}', [$card, 'show'])->whereNumber('card')->defaults('kind', $kind)->name('show');
                Route::put('{card}', [$card, 'update'])->whereNumber('card')->middleware('throttle:60,1,id-passes.' . $k['route'] . '.update')->defaults('kind', $kind)->name('update');
                if (in_array($kind, ['staff', 'compound'], true)) {
                    Route::get('bulk', [Controllers\IdPasses\BulkController::class, 'index'])->defaults('kind', $kind)->name('bulk');
                    Route::get('bulk/people', [Controllers\IdPasses\BulkController::class, 'people'])->middleware('throttle:240,1,id-passes.' . $k['route'] . '.bulk.people')->defaults('kind', $kind)->name('bulk.people');
                    Route::post('bulk/issue', [Controllers\IdPasses\BulkController::class, 'issue'])->middleware('throttle:120,1,id-passes.' . $k['route'] . '.bulk.issue')->defaults('kind', $kind)->name('bulk.issue');
                    Route::post('bulk/sync', [Controllers\IdPasses\BulkController::class, 'sync'])->middleware('throttle:30,1,id-passes.' . $k['route'] . '.bulk.sync')->defaults('kind', $kind)->name('bulk.sync');
                    Route::post('bulk/backgrounds', [Controllers\IdPasses\BulkController::class, 'backgrounds'])->middleware('throttle:60,1,id-passes.' . $k['route'] . '.bulk.backgrounds')->defaults('kind', $kind)->name('bulk.backgrounds');
                }
                if (in_array($kind, ['staff', 'compound', 'gate'], true)) {
                    Route::get('{card}/sync', [$card, 'syncPreview'])->whereNumber('card')->middleware('throttle:120,1,id-passes.' . $k['route'] . '.sync-preview')->defaults('kind', $kind)->name('sync-preview');
                    Route::post('{card}/sync', [$card, 'sync'])->whereNumber('card')->middleware('throttle:60,1,id-passes.' . $k['route'] . '.sync')->defaults('kind', $kind)->name('sync');
                }
                if (in_array($kind, ['visitor', 'gate'], true)) {
                    Route::post('stock', [$card, 'stock'])->middleware('throttle:120,1,id-passes.' . $k['route'] . '.stock')->defaults('kind', $kind)->name('stock');
                }
                if ($kind === 'gate') {
                    Route::post('{card}/assign', [$card, 'assign'])->whereNumber('card')->middleware('throttle:60,1,id-passes.gates.assign')->defaults('kind', $kind)->name('assign');
                }
                Route::post('{card}/photo', [$card, 'photo'])->whereNumber('card')->middleware('throttle:30,1,id-passes.' . $k['route'] . '.photo')->defaults('kind', $kind)->name('photo');
                Route::get('{card}/faces', [$card, 'faces'])->whereNumber('card')->middleware('throttle:240,1,id-passes.' . $k['route'] . '.faces')->defaults('kind', $kind)->name('faces');
                Route::get('{card}/photo-file', [$card, 'photoFile'])->whereNumber('card')->middleware('throttle:600,1,id-passes.' . $k['route'] . '.photo-file')->defaults('kind', $kind)->name('photo-file');
                foreach (['approve', 'reject', 'suspend', 'reactivate', 'revoke', 'renew', 'replace'] as $action) {
                    Route::post('{card}/' . $action, [$card, $action])->whereNumber('card')->middleware('throttle:30,1,id-passes.' . $k['route'] . '.' . $action)->defaults('kind', $kind)->name($action);
                }
                if ($kind === 'visitor') {
                    Route::post('visit/{visit}/give', [$card, 'giveToVisit'])->whereNumber('visit')->middleware('throttle:60,1,id-passes.visitors.give-visit')->defaults('kind', $kind)->name('give-visit');
                    Route::post('{card}/give', [$card, 'give'])->whereNumber('card')->middleware('throttle:60,1,id-passes.visitors.give')->defaults('kind', $kind)->name('give');
                    Route::post('{card}/take-back', [$card, 'takeBack'])->whereNumber('card')->middleware('throttle:60,1,id-passes.visitors.take-back')->defaults('kind', $kind)->name('take-back');
                }
                Route::get('{card}/print', [$prn, 'print'])->whereNumber('card')->middleware('throttle:40,1,id-passes.' . $k['route'] . '.print')->defaults('kind', $kind)->name('print');
                Route::get('{card}/pdf', [$prn, 'pdf'])->whereNumber('card')->middleware('throttle:40,1,id-passes.' . $k['route'] . '.pdf')->defaults('kind', $kind)->name('pdf');
            });
        }
    });

    // Visitor Logs: reception. Visitors (directory), visits (check-in / check-out), incoming mail & deliveries, reports, settings. Names all start "visitor-logs." so Global Feature Control covers them
    // (the Notification page is "visitor-logs.workflow", registered with the other module workflows above).
    Route::prefix('visitor-logs')->name('visitor-logs.')->group(function () {
        $dash = Controllers\VisitorLogs\DashboardController::class;
        $look = Controllers\VisitorLogs\LookupController::class;
        $vis = Controllers\VisitorLogs\VisitController::class;
        $dir = Controllers\VisitorLogs\VisitorController::class;
        $itm = Controllers\VisitorLogs\ItemController::class;
        $rep = Controllers\VisitorLogs\ReportController::class;
        $set = Controllers\VisitorLogs\SettingsController::class;
        Route::get('/', [$dash, 'index'])->name('dashboard');
        Route::post('lookup/people', [$look, 'people'])->middleware('throttle:240,1,visitor-logs.lookup.people')->name('lookup.people');
        Route::post('lookup/visitors', [$look, 'visitors'])->middleware('throttle:240,1,visitor-logs.lookup.visitors')->name('lookup.visitors');
        Route::post('lookup/contacts', [$look, 'contacts'])->middleware('throttle:240,1,visitor-logs.lookup.contacts')->name('lookup.contacts');
        Route::post('lookup/contacts/store', [$look, 'storeContact'])->middleware('throttle:60,1,visitor-logs.lookup.contacts.store')->name('lookup.contacts.store');
        // visits
        Route::get('visits', [$vis, 'index'])->name('visits.index');
        Route::get('visits/create', [$vis, 'create'])->name('visits.create');
        Route::post('visits', [$vis, 'store'])->middleware('throttle:60,1,visitor-logs.visits.store')->name('visits.store');
        Route::get('visits/{visit}', [$vis, 'show'])->whereNumber('visit')->name('visits.show');
        Route::get('visits/{visit}/edit', [$vis, 'edit'])->whereNumber('visit')->name('visits.edit');
        Route::put('visits/{visit}', [$vis, 'update'])->whereNumber('visit')->middleware('throttle:60,1,visitor-logs.visits.update')->name('visits.update');
        Route::post('visits/{visit}/checkout', [$vis, 'checkout'])->whereNumber('visit')->middleware('throttle:120,1,visitor-logs.visits.checkout')->name('visits.checkout');
        Route::post('visits/{visit}/correct', [$vis, 'correct'])->whereNumber('visit')->middleware('throttle:30,1,visitor-logs.visits.correct')->name('visits.correct');
        Route::post('visits/{visit}/cancel', [$vis, 'cancel'])->whereNumber('visit')->middleware('throttle:30,1,visitor-logs.visits.cancel')->name('visits.cancel');
        Route::post('visits/{visit}/files', [$vis, 'attach'])->whereNumber('visit')->middleware('throttle:60,1,visitor-logs.visits.attach')->name('visits.attach');
        Route::get('visits/{visit}/files/{file}', [$vis, 'download'])->whereNumber(['visit', 'file'])->middleware('throttle:120,1,visitor-logs.visits.download')->name('visits.download');
        // visitor directory
        Route::get('visitors', [$dir, 'index'])->name('visitors.index');
        Route::post('visitors', [$dir, 'store'])->middleware('throttle:60,1,visitor-logs.visitors.store')->name('visitors.store');
        Route::get('visitors/{visitor}', [$dir, 'show'])->whereNumber('visitor')->name('visitors.show');
        Route::put('visitors/{visitor}', [$dir, 'update'])->whereNumber('visitor')->middleware('throttle:60,1,visitor-logs.visitors.update')->name('visitors.update');
        Route::post('visitors/{visitor}/toggle', [$dir, 'toggle'])->whereNumber('visitor')->middleware('throttle:30,1,visitor-logs.visitors.toggle')->name('visitors.toggle');
        // incoming mail and deliveries
        Route::get('items', [$itm, 'index'])->name('items.index');
        Route::get('items/create', [$itm, 'create'])->name('items.create');
        Route::post('items', [$itm, 'store'])->middleware('throttle:60,1,visitor-logs.items.store')->name('items.store');
        Route::get('items/{item}', [$itm, 'show'])->whereNumber('item')->name('items.show');
        Route::get('items/{item}/edit', [$itm, 'edit'])->whereNumber('item')->name('items.edit');
        Route::put('items/{item}', [$itm, 'update'])->whereNumber('item')->middleware('throttle:60,1,visitor-logs.items.update')->name('items.update');
        Route::post('items/{item}/status', [$itm, 'status'])->whereNumber('item')->middleware('throttle:120,1,visitor-logs.items.status')->name('items.status');
        Route::post('items/{item}/handover', [$itm, 'handover'])->whereNumber('item')->middleware('throttle:60,1,visitor-logs.items.handover')->name('items.handover');
        Route::post('items/{item}/correct', [$itm, 'correct'])->whereNumber('item')->middleware('throttle:30,1,visitor-logs.items.correct')->name('items.correct');
        Route::post('items/{item}/files', [$itm, 'attach'])->whereNumber('item')->middleware('throttle:60,1,visitor-logs.items.attach')->name('items.attach');
        Route::get('items/{item}/files/{file}', [$itm, 'download'])->whereNumber(['item', 'file'])->middleware('throttle:120,1,visitor-logs.items.download')->name('items.download');
        // reports
        Route::get('reports', [$rep, 'index'])->name('reports.index');
        Route::get('reports/print', [$rep, 'print'])->middleware('throttle:30,1,visitor-logs.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rep, 'pdf'])->middleware('throttle:20,1,visitor-logs.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rep, 'csv'])->middleware('throttle:20,1,visitor-logs.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rep, 'xlsx'])->middleware('throttle:20,1,visitor-logs.reports.xlsx')->name('reports.xlsx');
        // settings
        Route::get('settings', [$set, 'index'])->name('settings');
        Route::put('settings', [$set, 'update'])->middleware('throttle:30,1,visitor-logs.settings.update')->name('settings.update');
        Route::post('settings/options', [$set, 'storeOption'])->middleware('throttle:60,1,visitor-logs.settings.options.store')->name('settings.options.store');
        Route::put('settings/options/{option}', [$set, 'updateOption'])->whereNumber('option')->middleware('throttle:60,1,visitor-logs.settings.options.update')->name('settings.options.update');
        Route::post('settings/options/reorder', [$set, 'reorderOptions'])->middleware('throttle:60,1,visitor-logs.settings.options.reorder')->name('settings.options.reorder');
    });

    // Holiday Greetings: the holidays of the year, the list of people who receive greetings (imported from users, staff and contacts or typed in), the email and SMS of each holiday, sending with a delivery
    // indicator, and the record of every holiday. Names all start "holiday-greetings." so Global Feature Control covers them.
    Route::prefix('holiday-greetings')->name('holiday-greetings.')->group(function () {
        $dash = Controllers\HolidayGreetings\DashboardController::class;
        $hol = Controllers\HolidayGreetings\HolidayController::class;
        $snd = Controllers\HolidayGreetings\SendController::class;
        $con = Controllers\HolidayGreetings\ContactController::class;
        $rec = Controllers\HolidayGreetings\RecordController::class;
        $set = Controllers\HolidayGreetings\SettingsController::class;
        $bun = Controllers\HolidayGreetings\BundleController::class;
        Route::get('/', [$dash, 'index'])->name('dashboard');

        Route::post('holidays', [$hol, 'store'])->middleware('throttle:30,1,holiday-greetings.holidays.store')->name('holidays.store');
        Route::get('holidays/{holiday}', [$hol, 'show'])->whereNumber('holiday')->name('holidays.show');
        Route::put('holidays/{holiday}', [$hol, 'update'])->whereNumber('holiday')->middleware('throttle:60,1,holiday-greetings.holidays.update')->name('holidays.update');
        Route::delete('holidays/{holiday}', [$hol, 'destroy'])->whereNumber('holiday')->middleware('throttle:30,1,holiday-greetings.holidays.destroy')->name('holidays.destroy');
        Route::post('holidays/{holiday}/remind', [$hol, 'remindNow'])->whereNumber('holiday')->middleware('throttle:6,1,holiday-greetings.holidays.remind')->name('holidays.remind');

        Route::post('holidays/{holiday}/preview', [$snd, 'preview'])->whereNumber('holiday')->middleware('throttle:120,1,holiday-greetings.send.preview')->name('send.preview');
        Route::put('holidays/{holiday}/message', [$snd, 'template'])->whereNumber('holiday')->middleware('throttle:60,1,holiday-greetings.send.template')->name('send.template');
        Route::post('holidays/{holiday}/message/reset', [$snd, 'reset'])->whereNumber('holiday')->middleware('throttle:30,1,holiday-greetings.send.reset')->name('send.reset');
        Route::post('holidays/{holiday}/left-out', [$snd, 'exclude'])->whereNumber('holiday')->middleware('throttle:240,1,holiday-greetings.send.exclude')->name('send.exclude');
        Route::post('holidays/{holiday}/send', [$snd, 'start'])->whereNumber('holiday')->middleware('throttle:10,1,holiday-greetings.send.start')->name('send.start');
        Route::put('holidays/{holiday}/auto-send', [$snd, 'auto'])->whereNumber('holiday')->middleware('throttle:30,1,holiday-greetings.send.auto')->name('send.auto');
        Route::post('holidays/{holiday}/retry', [$snd, 'retryFailed'])->whereNumber('holiday')->middleware('throttle:20,1,holiday-greetings.send.retry-failed')->name('send.retry-failed');
        Route::post('holidays/{holiday}/retry/{delivery}', [$snd, 'retryOne'])->whereNumber(['holiday', 'delivery'])->middleware('throttle:120,1,holiday-greetings.send.retry-one')->name('send.retry-one');

        Route::get('holidays/{holiday}/record/print', [$rec, 'print'])->whereNumber('holiday')->middleware('throttle:30,1,holiday-greetings.records.print')->name('records.print');
        Route::get('holidays/{holiday}/record/pdf', [$rec, 'pdf'])->whereNumber('holiday')->middleware('throttle:20,1,holiday-greetings.records.pdf')->name('records.pdf');
        Route::get('holidays/{holiday}/record/csv', [$rec, 'csv'])->whereNumber('holiday')->middleware('throttle:20,1,holiday-greetings.records.csv')->name('records.csv');

        Route::get('people', [$con, 'index'])->name('contacts.index');
        Route::post('people', [$con, 'store'])->middleware('throttle:60,1,holiday-greetings.contacts.store')->name('contacts.store');
        Route::put('people/{contact}', [$con, 'update'])->whereNumber('contact')->middleware('throttle:120,1,holiday-greetings.contacts.update')->name('contacts.update');
        Route::delete('people/{contact}', [$con, 'destroy'])->whereNumber('contact')->middleware('throttle:60,1,holiday-greetings.contacts.destroy')->name('contacts.destroy');
        Route::get('bundles', [$bun, 'index'])->name('bundles.index');
        Route::post('bundles', [$bun, 'store'])->middleware('throttle:30,1,holiday-greetings.bundles.store')->name('bundles.store');
        Route::put('bundles/{bundle}', [$bun, 'update'])->whereNumber('bundle')->middleware('throttle:60,1,holiday-greetings.bundles.update')->name('bundles.update');
        Route::delete('bundles/{bundle}', [$bun, 'destroy'])->whereNumber('bundle')->middleware('throttle:30,1,holiday-greetings.bundles.destroy')->name('bundles.destroy');
        Route::post('people/import/preview', [$con, 'importPreview'])->middleware('throttle:30,1,holiday-greetings.contacts.import-preview')->name('contacts.import-preview');
        Route::post('people/import', [$con, 'import'])->middleware('throttle:10,1,holiday-greetings.contacts.import')->name('contacts.import');

        Route::get('settings', [$set, 'show'])->name('settings');
        Route::put('settings', [$set, 'update'])->middleware('throttle:30,1,holiday-greetings.settings.update')->name('settings.update');
        $pho = Controllers\HolidayGreetings\PhotoController::class;
        Route::post('settings/photos/{theme}', [$pho, 'store'])->where('theme', '[a-z]+')->middleware('throttle:20,1,holiday-greetings.settings.photos.store')->name('settings.photos.store');
        Route::delete('settings/photos/{theme}', [$pho, 'reset'])->where('theme', '[a-z]+')->middleware('throttle:20,1,holiday-greetings.settings.photos.reset')->name('settings.photos.reset');
        Route::post('settings/photos/{theme}/off', [$pho, 'off'])->where('theme', '[a-z]+')->middleware('throttle:20,1,holiday-greetings.settings.photos.off')->name('settings.photos.off');
    });

    // Subcontractor Management: subcontractors (Contacts), agreements, work schedules, variations, payment requests (paid only through Payment Vouchers), statements, import, reports.
    // Names all start "subcontracts." so Global Feature Control covers them.
    Route::prefix('subcontracts')->name('subcontracts.')->group(function () {
        $d = Controllers\Subcontracts\DashboardController::class;
        $rg = Controllers\Subcontracts\RegisterController::class;
        $ag = Controllers\Subcontracts\AgreementController::class;
        $wi = Controllers\Subcontracts\WorkItemController::class;
        $va = Controllers\Subcontracts\VariationController::class;
        $rq = Controllers\Subcontracts\PaymentRequestController::class;
        $st = Controllers\Subcontracts\StatementController::class;
        $im = Controllers\Subcontracts\ImportController::class;
        $rc = Controllers\Subcontracts\ReconciliationController::class;
        $rp = Controllers\Subcontracts\ReportController::class;
        $se = Controllers\Subcontracts\SettingsController::class;

        Route::get('/', [$d, 'index'])->name('dashboard');

        Route::get('register', [$rg, 'index'])->name('register.index');
        Route::get('register/{contact}', [$rg, 'show'])->whereNumber('contact')->name('register.show');

        Route::get('statements/contact/{contact}', [$st, 'contact'])->whereNumber('contact')->name('statements.contact');
        Route::get('statements/agreement/{agreement}', [$st, 'agreement'])->whereNumber('agreement')->name('statements.agreement');
        Route::get('statements/{kind}/{id}/print', [$st, 'print'])->whereIn('kind', ['contact', 'agreement'])->whereNumber('id')->middleware('throttle:30,1,subcontracts.statements.print')->name('statements.print');
        Route::get('statements/{kind}/{id}/pdf', [$st, 'pdf'])->whereIn('kind', ['contact', 'agreement'])->whereNumber('id')->middleware('throttle:20,1,subcontracts.statements.pdf')->name('statements.pdf');
        Route::get('statements/{kind}/{id}/xlsx', [$st, 'xlsx'])->whereIn('kind', ['contact', 'agreement'])->whereNumber('id')->middleware('throttle:20,1,subcontracts.statements.xlsx')->name('statements.xlsx');

        Route::get('agreements', [$ag, 'index'])->name('agreements.index');
        Route::get('agreements/create', [$ag, 'create'])->name('agreements.create');
        Route::post('agreements', [$ag, 'store'])->middleware('throttle:60,1,subcontracts.agreements.store')->name('agreements.store');
        Route::get('agreements/{agreement}', [$ag, 'show'])->whereNumber('agreement')->name('agreements.show');
        Route::get('agreements/{agreement}/edit', [$ag, 'edit'])->whereNumber('agreement')->name('agreements.edit');
        Route::put('agreements/{agreement}', [$ag, 'update'])->whereNumber('agreement')->middleware('throttle:60,1,subcontracts.agreements.update')->name('agreements.update');
        Route::delete('agreements/{agreement}', [$ag, 'destroy'])->whereNumber('agreement')->name('agreements.destroy');
        Route::post('agreements/{agreement}/do/{action}', [$ag, 'transition'])->whereNumber('agreement')->whereIn('action', ['submit', 'review', 'approve', 'reject', 'suspend', 'activate', 'complete', 'terminate', 'archive', 'restore', 'verify-completion', 'unverify-completion'])->middleware('throttle:60,1,subcontracts.agreements.transition')->name('agreements.transition');
        Route::post('agreements/{agreement}/files', [$ag, 'attach'])->whereNumber('agreement')->middleware('throttle:60,1,subcontracts.agreements.attach')->name('agreements.attach');
        Route::get('agreements/{agreement}/files/{file}', [$ag, 'download'])->whereNumber(['agreement', 'file'])->middleware('throttle:120,1,subcontracts.agreements.download')->name('agreements.download');
        Route::delete('agreements/{agreement}/files/{file}', [$ag, 'removeFile'])->whereNumber(['agreement', 'file'])->name('agreements.remove-file');
        Route::post('agreements/{agreement}/groups', [$wi, 'storeGroup'])->whereNumber('agreement')->name('agreements.groups.store');
        Route::put('agreements/{agreement}/groups/{group}', [$wi, 'updateGroup'])->whereNumber(['agreement', 'group'])->name('agreements.groups.update');
        Route::delete('agreements/{agreement}/groups/{group}', [$wi, 'destroyGroup'])->whereNumber(['agreement', 'group'])->name('agreements.groups.destroy');
        Route::post('agreements/{agreement}/items', [$wi, 'storeItem'])->whereNumber('agreement')->middleware('throttle:240,1,subcontracts.agreements.items.store')->name('agreements.items.store');
        Route::put('agreements/{agreement}/items/{item}', [$wi, 'updateItem'])->whereNumber(['agreement', 'item'])->middleware('throttle:240,1,subcontracts.agreements.items.update')->name('agreements.items.update');
        Route::delete('agreements/{agreement}/items/{item}', [$wi, 'destroyItem'])->whereNumber(['agreement', 'item'])->name('agreements.items.destroy');
        Route::post('agreements/{agreement}/schedule-total', [$wi, 'useScheduleTotal'])->whereNumber('agreement')->name('agreements.schedule-total');
        Route::post('agreements/{agreement}/variations', [$va, 'store'])->whereNumber('agreement')->middleware('throttle:60,1,subcontracts.variations.store')->name('variations.store');

        Route::get('variations', [$va, 'index'])->name('variations.index');
        Route::get('variations/{variation}', [$va, 'show'])->whereNumber('variation')->name('variations.show');
        Route::put('variations/{variation}', [$va, 'update'])->whereNumber('variation')->name('variations.update');
        Route::delete('variations/{variation}', [$va, 'destroy'])->whereNumber('variation')->name('variations.destroy');
        Route::post('variations/{variation}/do/{action}', [$va, 'transition'])->whereNumber('variation')->whereIn('action', ['submit', 'review', 'approve', 'reject'])->middleware('throttle:60,1,subcontracts.variations.transition')->name('variations.transition');
        Route::post('variations/{variation}/files', [$va, 'attach'])->whereNumber('variation')->middleware('throttle:60,1,subcontracts.variations.attach')->name('variations.attach');
        Route::get('variations/{variation}/files/{file}', [$va, 'download'])->whereNumber(['variation', 'file'])->middleware('throttle:120,1,subcontracts.variations.download')->name('variations.download');
        Route::delete('variations/{variation}/files/{file}', [$va, 'removeFile'])->whereNumber(['variation', 'file'])->name('variations.remove-file');

        Route::get('requests', [$rq, 'index'])->name('requests.index');
        Route::get('requests/create', [$rq, 'create'])->name('requests.create');
        Route::post('requests', [$rq, 'store'])->middleware('throttle:60,1,subcontracts.requests.store')->name('requests.store');
        Route::get('requests/{payreq}', [$rq, 'show'])->whereNumber('payreq')->name('requests.show');
        Route::get('requests/{payreq}/edit', [$rq, 'edit'])->whereNumber('payreq')->name('requests.edit');
        Route::put('requests/{payreq}', [$rq, 'update'])->whereNumber('payreq')->middleware('throttle:60,1,subcontracts.requests.update')->name('requests.update');
        Route::delete('requests/{payreq}', [$rq, 'destroy'])->whereNumber('payreq')->name('requests.destroy');
        Route::post('requests/{payreq}/do/{action}', [$rq, 'transition'])->whereNumber('payreq')->whereIn('action', ['submit', 'review', 'return', 'approve', 'reject', 'cancel'])->middleware('throttle:60,1,subcontracts.requests.transition')->name('requests.transition');
        Route::post('requests/{payreq}/voucher', [$rq, 'initiateVoucher'])->whereNumber('payreq')->middleware('throttle:20,1,subcontracts.requests.voucher')->name('requests.voucher');
        Route::post('requests/{payreq}/payments/{payment}/cancel', [$rq, 'cancelPayment'])->whereNumber(['payreq', 'payment'])->name('requests.payments.cancel');
        Route::post('requests/{payreq}/files', [$rq, 'attach'])->whereNumber('payreq')->middleware('throttle:60,1,subcontracts.requests.attach')->name('requests.attach');
        Route::get('requests/{payreq}/files/{file}', [$rq, 'download'])->whereNumber(['payreq', 'file'])->middleware('throttle:120,1,subcontracts.requests.download')->name('requests.download');
        Route::delete('requests/{payreq}/files/{file}', [$rq, 'removeFile'])->whereNumber(['payreq', 'file'])->name('requests.remove-file');

        // Contract Payment Requests (claims): several completed agreements of one subcontractor, decided line by line, paid by one Payment Voucher
        $cl = Controllers\Subcontracts\ClaimController::class;
        Route::get('claims', [$cl, 'index'])->name('claims.index');
        Route::get('claims/create', [$cl, 'create'])->name('claims.create');
        Route::get('claims/eligible', [$cl, 'eligible'])->middleware('throttle:120,1,subcontracts.claims.eligible')->name('claims.eligible');
        Route::post('claims', [$cl, 'store'])->middleware('throttle:60,1,subcontracts.claims.store')->name('claims.store');
        Route::get('claims/{claim}', [$cl, 'show'])->whereNumber('claim')->name('claims.show');
        Route::get('claims/{claim}/edit', [$cl, 'edit'])->whereNumber('claim')->name('claims.edit');
        Route::put('claims/{claim}', [$cl, 'update'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.update')->name('claims.update');
        Route::delete('claims/{claim}', [$cl, 'destroy'])->whereNumber('claim')->name('claims.destroy');
        Route::post('claims/{claim}/submit', [$cl, 'submit'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.submit')->name('claims.submit');
        Route::post('claims/{claim}/cancel', [$cl, 'cancel'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.cancel')->name('claims.cancel');
        Route::post('claims/{claim}/review', [$cl, 'review'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.review')->name('claims.review');
        Route::post('claims/{claim}/decide', [$cl, 'decide'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.decide')->name('claims.decide');
        Route::post('claims/{claim}/lines/{line}/do/{action}', [$cl, 'lineDecide'])->whereNumber(['claim', 'line'])->whereIn('action', ['approve', 'reject', 'return', 'defer'])->middleware('throttle:120,1,subcontracts.claims.lines.decide')->name('claims.lines.decide');
        Route::post('claims/{claim}/lines/{line}/resubmit', [$cl, 'lineResubmit'])->whereNumber(['claim', 'line'])->middleware('throttle:60,1,subcontracts.claims.lines.resubmit')->name('claims.lines.resubmit');
        Route::post('claims/{claim}/lines/{line}/cancel', [$cl, 'lineCancel'])->whereNumber(['claim', 'line'])->middleware('throttle:60,1,subcontracts.claims.lines.cancel')->name('claims.lines.cancel');
        Route::post('claims/{claim}/lines/{line}/reverse', [$cl, 'lineReverse'])->whereNumber(['claim', 'line'])->middleware('throttle:60,1,subcontracts.claims.lines.reverse')->name('claims.lines.reverse');
        Route::post('claims/{claim}/voucher', [$cl, 'startVoucher'])->whereNumber('claim')->middleware('throttle:20,1,subcontracts.claims.voucher')->name('claims.voucher');
        Route::post('claims/{claim}/payments/cancel', [$cl, 'cancelPayments'])->whereNumber('claim')->middleware('throttle:20,1,subcontracts.claims.payments.cancel')->name('claims.payments.cancel');
        Route::post('claims/{claim}/files', [$cl, 'attach'])->whereNumber('claim')->middleware('throttle:60,1,subcontracts.claims.attach')->name('claims.attach');
        Route::get('claims/{claim}/files/{file}', [$cl, 'download'])->whereNumber(['claim', 'file'])->middleware('throttle:120,1,subcontracts.claims.download')->name('claims.download');
        Route::delete('claims/{claim}/files/{file}', [$cl, 'removeFile'])->whereNumber(['claim', 'file'])->name('claims.remove-file');
        Route::get('claims/{claim}/print', [$cl, 'print'])->whereNumber('claim')->middleware('throttle:30,1,subcontracts.claims.print')->name('claims.print');
        Route::get('claims/{claim}/pdf', [$cl, 'pdf'])->whereNumber('claim')->middleware('throttle:20,1,subcontracts.claims.pdf')->name('claims.pdf');

        Route::get('import', [$im, 'index'])->name('import.index');
        Route::post('import', [$im, 'store'])->middleware('throttle:10,1,subcontracts.import.store')->name('import.store');
        Route::get('import/{batch}', [$im, 'show'])->whereNumber('batch')->name('import.show');
        Route::put('import/{batch}/sheets/{sheet}', [$im, 'updateSheet'])->whereNumber(['batch', 'sheet'])->name('import.sheets.update');
        Route::post('import/{batch}/confirm', [$im, 'confirm'])->whereNumber('batch')->middleware('throttle:6,1,subcontracts.import.confirm')->name('import.confirm');
        Route::post('import/{batch}/revert', [$im, 'revert'])->whereNumber('batch')->middleware('throttle:6,1,subcontracts.import.revert')->name('import.revert');
        Route::get('import/{batch}/report', [$im, 'report'])->whereNumber('batch')->name('import.report');

        // Opening balances (contract and paid to date per account, previous data attached) and the reset to them
        $op = Controllers\Subcontracts\OpeningController::class;
        Route::get('opening', [$op, 'index'])->name('opening.index');
        Route::get('opening/reset-plan', [$op, 'resetPlan'])->name('opening.reset-plan');
        Route::post('opening/reset', [$op, 'reset'])->middleware('throttle:6,1,subcontracts.opening.reset')->name('opening.reset');
        Route::get('opening/{agreement}', [$op, 'edit'])->whereNumber('agreement')->name('opening.edit');
        Route::put('opening/{agreement}', [$op, 'saveOpening'])->whereNumber('agreement')->middleware('throttle:60,1,subcontracts.opening.save')->name('opening.save');
        Route::post('opening/{agreement}/files', [$op, 'attach'])->whereNumber('agreement')->middleware('throttle:30,1,subcontracts.opening.attach')->name('opening.attach');
        Route::get('opening/{agreement}/files/{file}/preview', [$op, 'preview'])->whereNumber(['agreement', 'file'])->name('opening.preview');
        Route::get('opening/{agreement}/files/{file}/download', [$op, 'download'])->whereNumber(['agreement', 'file'])->name('opening.download');
        Route::delete('opening/{agreement}/files/{file}', [$op, 'removeFile'])->whereNumber(['agreement', 'file'])->name('opening.remove');
        Route::get('reconciliation', [$rc, 'index'])->name('reconciliation.index');
        Route::get('reconciliation/{agreement}', [$rc, 'show'])->whereNumber('agreement')->name('reconciliation.show');
        Route::put('reconciliation/{agreement}', [$rc, 'note'])->whereNumber('agreement')->name('reconciliation.note');
        Route::post('reconciliation/{agreement}/approve', [$rc, 'approve'])->whereNumber('agreement')->name('reconciliation.approve');

        // Project Name Mapping: Excel project names matched to WIMS projects (drafts), then confirmed with the person's password
        $pm = Controllers\Subcontracts\ProjectMappingController::class;
        Route::get('project-mapping', [$pm, 'index'])->name('project-mapping.index');
        Route::post('project-mapping/project', [$pm, 'createProject'])->middleware('throttle:20,1,subcontracts.project-mapping.project')->name('project-mapping.project');
        Route::put('project-mapping', [$pm, 'save'])->middleware('throttle:60,1,subcontracts.project-mapping.save')->name('project-mapping.save');
        Route::post('project-mapping/upload', [$pm, 'upload'])->middleware('throttle:20,1,subcontracts.project-mapping.upload')->name('project-mapping.upload');
        Route::post('project-mapping/import', [$pm, 'import'])->middleware('throttle:20,1,subcontracts.project-mapping.import')->name('project-mapping.import');
        Route::post('project-mapping/confirm', [$pm, 'confirm'])->middleware('throttle:10,1,subcontracts.project-mapping.confirm')->name('project-mapping.confirm');
        Route::get('project-mapping/print', [$pm, 'print'])->middleware('throttle:30,1,subcontracts.project-mapping.print')->name('project-mapping.print');
        Route::get('project-mapping/pdf', [$pm, 'pdf'])->middleware('throttle:30,1,subcontracts.project-mapping.pdf')->name('project-mapping.pdf');
        Route::get('project-mapping/template', [$pm, 'template'])->middleware('throttle:20,1,subcontracts.project-mapping.template')->name('project-mapping.template');

        // Alerts: what needs attention on the subcontract accounts
        $alr = Controllers\Subcontracts\AlertController::class;
        Route::get('alerts', [$alr, 'index'])->name('alerts.index');
        Route::post('alerts/read-all', [$alr, 'readAll'])->middleware('throttle:60,1,subcontracts.alerts.read-all')->name('alerts.read-all');
        Route::post('alerts/scan', [$alr, 'scan'])->middleware('throttle:6,1,subcontracts.alerts.scan')->name('alerts.scan');
        foreach (['read', 'acknowledge', 'assign', 'resolve', 'escalate'] as $act) {
            Route::post("alerts/{id}/$act", [$alr, $act])->whereNumber('id')->middleware("throttle:120,1,subcontracts.alerts.$act")->name("alerts.$act");
        }

        Route::get('reports', [$rp, 'index'])->name('reports.index');
        Route::get('reports/print', [$rp, 'print'])->middleware('throttle:30,1,subcontracts.reports.print')->name('reports.print');
        Route::get('reports/pdf', [$rp, 'pdf'])->middleware('throttle:20,1,subcontracts.reports.pdf')->name('reports.pdf');
        Route::get('reports/csv', [$rp, 'csv'])->middleware('throttle:20,1,subcontracts.reports.csv')->name('reports.csv');
        Route::get('reports/xlsx', [$rp, 'xlsx'])->middleware('throttle:20,1,subcontracts.reports.xlsx')->name('reports.xlsx');

        Route::get('settings', [$se, 'edit'])->name('settings.edit');
        Route::put('settings', [$se, 'update'])->name('settings.update');
    });

    Route::get('multi-transfers', [Controllers\MultiTransferController::class, 'index'])->name('multi-transfers.index');
    Route::get('multi-transfers/create', [Controllers\MultiTransferController::class, 'create'])->name('multi-transfers.create');
    Route::get('multi-transfers/availability', [Controllers\MultiTransferController::class, 'availability'])->middleware('throttle:120,1,multi-transfers.availability')->name('multi-transfers.availability');
    Route::post('multi-transfers', [Controllers\MultiTransferController::class, 'store'])->name('multi-transfers.store');
    Route::get('multi-transfers/{multi_transfer}', [Controllers\MultiTransferController::class, 'show'])->whereNumber('multi_transfer')->name('multi-transfers.show');
    Route::post('multi-transfers/{multi_transfer}/approve', [Controllers\MultiTransferController::class, 'approveAll'])->whereNumber('multi_transfer')->name('multi-transfers.approve');
    Route::post('multi-transfers/{multi_transfer}/cancel', [Controllers\MultiTransferController::class, 'cancelAll'])->whereNumber('multi_transfer')->name('multi-transfers.cancel');
    Route::get('multi-transfers/{multi_transfer}/print', [Controllers\MultiTransferController::class, 'print'])->whereNumber('multi_transfer')->name('multi-transfers.print');
    Route::get('multi-transfers/{multi_transfer}/pdf', [Controllers\MultiTransferController::class, 'pdf'])->whereNumber('multi_transfer')->name('multi-transfers.pdf');
    Route::post('transfers/cleanup-warehouse', [Controllers\TransferController::class, 'cleanupWarehouse'])->name('transfers.cleanup');
    Route::post('transfers/cleanup-preview', [Controllers\TransferController::class, 'cleanupPreview'])->name('transfers.cleanup.preview');
    Route::post('transfers/cleanup-distribute', [Controllers\TransferController::class, 'cleanupDistribute'])->name('transfers.cleanup.distribute');
    Route::post('transfers/validate-password', [Controllers\TransferController::class, 'validatePassword'])->name('transfers.validate.password');
    Route::post('transfers/{transfer}/receive', [Controllers\TransferController::class, 'receive'])->name('transfers.receive');
    Route::patch('transfers/bulk-receive', [Controllers\TransferController::class, 'bulkReceive'])->name('transfers.bulk-receive');
    Route::post('transfers/{transfer}/approve', [Controllers\TransferController::class, 'approve'])->name('transfers.approve');
    Route::post('transfers/{transfer}/cancel', [Controllers\TransferController::class, 'cancel'])->name('transfers.cancel');

    Route::post('current-warehouse', [Controllers\AjaxController::class, 'setWarehouse'])->name('current.warehouse');

    Route::get('rauncher/search', [Controllers\RauncherController::class, 'search'])->name('rauncher.search');
    Route::put('rauncher/shortcut', [Controllers\RauncherController::class, 'updateShortcut'])->name('rauncher.shortcut.update');
    Route::put('scan/shortcut', [Controllers\ScanShortcutController::class, 'update'])->name('scan.shortcut.update');
    Route::put('menu/style', [Controllers\MenuStyleController::class, 'update'])->middleware('throttle:60,1,menu.style')->name('menu.style.update');

    Route::get('hover-preview', [Controllers\HoverPreviewController::class, 'preview'])->name('hover-preview.show');

    Route::get('deleted', [Controllers\DeletedRecordController::class, 'index'])->name('deleted.index');

    // Literal segments before the parameterless index route so 'history'
    // never gets swallowed by a future dynamic segment.
    Route::get('security-reminders', [Controllers\SecurityReminderController::class, 'index'])->name('security-reminders.index');
    Route::post('security-reminders/send', [Controllers\SecurityReminderController::class, 'send'])->name('security-reminders.send');
    Route::get('security-reminders/history', [Controllers\SecurityReminderController::class, 'history'])->name('security-reminders.history');
    Route::get('security-reminders/history/print', [Controllers\SecurityReminderController::class, 'print'])->name('security-reminders.history.print');
    Route::get('security-reminders/history/download', [Controllers\SecurityReminderController::class, 'download'])->name('security-reminders.history.download');

    // Project Certificates — literal/static segments before the
    // {certificate} wildcard route so 'workflow'/'print'/'download'/
    // 'dashboard' etc. never get swallowed by it.
    Route::get('project-certificates', [Controllers\ProjectCertificateController::class, 'globalIndex'])->name('project-certificates.index');
    Route::get('project-certificates/dashboard', [Controllers\ProjectCertificateController::class, 'dashboard'])->name('project-certificates.dashboard');
    Route::get('project-certificates/dashboard/print', [Controllers\ProjectCertificateController::class, 'dashboardPrint'])->name('project-certificates.dashboard.print');
    Route::get('project-certificates/dashboard/download', [Controllers\ProjectCertificateController::class, 'dashboardDownload'])->name('project-certificates.dashboard.download');
    Route::get('project-certificates/print', [Controllers\ProjectCertificateController::class, 'print'])->name('project-certificates.print');
    Route::get('project-certificates/download', [Controllers\ProjectCertificateController::class, 'download'])->name('project-certificates.download');
    Route::post('project-certificates/contacts/quick-store', [Controllers\ProjectCertificateContractController::class, 'quickStoreContact'])->name('project-certificates.contacts.quick-store');
    Route::get('project-certificates/payments', [Controllers\ProjectCertificatePaymentController::class, 'index'])->name('project-certificates.payments.index');
    Route::get('project-certificates/payments/print', [Controllers\ProjectCertificatePaymentController::class, 'print'])->name('project-certificates.payments.print');
    Route::get('project-certificates/payments/download', [Controllers\ProjectCertificatePaymentController::class, 'download'])->name('project-certificates.payments.download');
    Route::get('project-certificates/unpaid', [Controllers\ProjectCertificateController::class, 'unpaid'])->name('project-certificates.unpaid');
    Route::get('project-certificates/unpaid/print', [Controllers\ProjectCertificateController::class, 'unpaidPrint'])->name('project-certificates.unpaid.print');
    Route::get('project-certificates/unpaid/download', [Controllers\ProjectCertificateController::class, 'unpaidDownload'])->name('project-certificates.unpaid.download');
    Route::get('warehouses/{warehouse}/certificates', [Controllers\ProjectCertificateController::class, 'projectIndex'])->name('project-certificates.project.index');
    Route::get('warehouses/{warehouse}/certificates/print', [Controllers\ProjectCertificateController::class, 'projectPrint'])->name('project-certificates.project.print');
    Route::get('warehouses/{warehouse}/certificates/download', [Controllers\ProjectCertificateController::class, 'projectDownload'])->name('project-certificates.project.download');
    Route::get('warehouses/{warehouse}/certificates/payments', [Controllers\ProjectCertificatePaymentController::class, 'projectIndex'])->name('project-certificates.project.payments');
    Route::get('warehouses/{warehouse}/certificates/contract', [Controllers\ProjectCertificateContractController::class, 'edit'])->name('project-certificates.contract.edit');
    Route::post('warehouses/{warehouse}/certificates/contract', [Controllers\ProjectCertificateContractController::class, 'update'])->name('project-certificates.contract.update');
    Route::get('warehouses/{warehouse}/certificates/contract/print', [Controllers\ProjectCertificateContractController::class, 'print'])->name('project-certificates.contract.print');
    Route::get('warehouses/{warehouse}/certificates/contract/download', [Controllers\ProjectCertificateContractController::class, 'download'])->name('project-certificates.contract.download');
    Route::get('warehouses/{warehouse}/certificates/contract/attachments/{attachment}/download', [Controllers\ProjectCertificateContractController::class, 'downloadAttachment'])->name('project-certificates.contract.attachments.download');
    Route::delete('warehouses/{warehouse}/certificates/contract/attachments/{attachment}', [Controllers\ProjectCertificateContractController::class, 'destroyAttachment'])->name('project-certificates.contract.attachments.destroy');
    Route::get('warehouses/{warehouse}/certificates/contract/addendums/create', [Controllers\ProjectCertificateContractAddendumController::class, 'create'])->name('project-certificates.contract.addendums.create');
    Route::post('warehouses/{warehouse}/certificates/contract/addendums', [Controllers\ProjectCertificateContractAddendumController::class, 'store'])->name('project-certificates.contract.addendums.store');
    Route::get('warehouses/{warehouse}/certificates/contract/addendums/{addendum}', [Controllers\ProjectCertificateContractAddendumController::class, 'show'])->name('project-certificates.contract.addendums.show');
    Route::post('warehouses/{warehouse}/certificates/contract/addendums/{addendum}', [Controllers\ProjectCertificateContractAddendumController::class, 'update'])->name('project-certificates.contract.addendums.update');
    Route::post('warehouses/{warehouse}/certificates/contract/addendums/{addendum}/status', [Controllers\ProjectCertificateContractAddendumController::class, 'updateStatus'])->name('project-certificates.contract.addendums.status');
    Route::delete('warehouses/{warehouse}/certificates/contract/addendums/{addendum}', [Controllers\ProjectCertificateContractAddendumController::class, 'destroy'])->name('project-certificates.contract.addendums.destroy');
    Route::get('warehouses/{warehouse}/certificates/contract/addendums/{addendum}/print', [Controllers\ProjectCertificateContractAddendumController::class, 'print'])->name('project-certificates.contract.addendums.print');
    Route::get('warehouses/{warehouse}/certificates/contract/addendums/{addendum}/download', [Controllers\ProjectCertificateContractAddendumController::class, 'download'])->name('project-certificates.contract.addendums.download');
    Route::get('warehouses/{warehouse}/certificates/contract/addendums/{addendum}/attachments/{attachment}/download', [Controllers\ProjectCertificateContractAddendumController::class, 'downloadAttachment'])->name('project-certificates.contract.addendums.attachments.download');
    Route::delete('warehouses/{warehouse}/certificates/contract/addendums/{addendum}/attachments/{attachment}', [Controllers\ProjectCertificateContractAddendumController::class, 'destroyAttachment'])->name('project-certificates.contract.addendums.attachments.destroy');
    Route::get('warehouses/{warehouse}/certificates/create', [Controllers\ProjectCertificateController::class, 'create'])->name('project-certificates.create');
    Route::post('warehouses/{warehouse}/certificates', [Controllers\ProjectCertificateController::class, 'store'])->name('project-certificates.store');
    Route::get('project-certificates/{certificate}', [Controllers\ProjectCertificateController::class, 'show'])->name('project-certificates.show');
    Route::put('project-certificates/{certificate}', [Controllers\ProjectCertificateController::class, 'update'])->name('project-certificates.update');
    Route::put('project-certificates/{certificate}/lines', [Controllers\ProjectCertificateLineController::class, 'sync'])->name('project-certificates.lines.sync');
    Route::post('project-certificates/{certificate}/submit', [Controllers\ProjectCertificateController::class, 'submit'])->name('project-certificates.submit');
    Route::post('project-certificates/{certificate}/approve', [Controllers\ProjectCertificateController::class, 'approve'])->name('project-certificates.approve');
    Route::post('project-certificates/{certificate}/issue', [Controllers\ProjectCertificateController::class, 'issue'])->name('project-certificates.issue');
    Route::post('project-certificates/{certificate}/revise', [Controllers\ProjectCertificateController::class, 'revise'])->name('project-certificates.revise');
    Route::post('project-certificates/{certificate}/cancel', [Controllers\ProjectCertificateController::class, 'cancel'])->name('project-certificates.cancel');
    Route::get('project-certificates/{certificate}/print', [Controllers\ProjectCertificateController::class, 'printSingle'])->name('project-certificates.certificate.print');
    Route::get('project-certificates/{certificate}/download', [Controllers\ProjectCertificateController::class, 'downloadSingle'])->name('project-certificates.certificate.download');
    Route::get('project-certificates/{certificate}/attachments/{attachment}/download', [Controllers\ProjectCertificateController::class, 'downloadAttachment'])->name('project-certificates.attachments.download');
    Route::delete('project-certificates/{certificate}/attachments/{attachment}', [Controllers\ProjectCertificateController::class, 'destroyAttachment'])->name('project-certificates.attachments.destroy');
    Route::post('project-certificates/{certificate}/payments', [Controllers\ProjectCertificatePaymentController::class, 'store'])->name('project-certificates.payments.store');
    Route::put('project-certificates/{certificate}/payments/status-override', [Controllers\ProjectCertificatePaymentController::class, 'overrideStatus'])->middleware('password.confirm')->name('project-certificates.payments.status-override');
    Route::put('project-certificates/{certificate}/payments/{payment}', [Controllers\ProjectCertificatePaymentController::class, 'update'])->middleware('password.confirm')->name('project-certificates.payments.update');
    Route::get('project-certificates/{certificate}/payments/{payment}/attachments/{attachment}/download', [Controllers\ProjectCertificatePaymentController::class, 'downloadAttachment'])->name('project-certificates.payments.attachments.download');
    Route::delete('project-certificates/{certificate}/payments/{payment}/attachments/{attachment}', [Controllers\ProjectCertificatePaymentController::class, 'destroyAttachment'])->name('project-certificates.payments.attachments.destroy');

    Route::get('branch', [Controllers\BranchDashboardController::class, 'index'])->name('branch.index');
    Route::get('my-warehouses', [Controllers\WarehouseController::class, 'myWarehouses'])->name('my.warehouses');
    Route::get('my-warehouses/{warehouse}/print', [Controllers\WarehouseController::class, 'printMyWarehouses'])->name('my.warehouses.print');
    Route::get('my-warehouses/{warehouse}/pdf', [Controllers\WarehouseController::class, 'pdfMyWarehouses'])->name('my.warehouses.pdf');
    Route::get('warehouses-list/print', [Controllers\WarehouseController::class, 'listPrint'])->name('warehouses.list.print');
    Route::get('warehouses-list/pdf', [Controllers\WarehouseController::class, 'listPdf'])->name('warehouses.list.pdf');
    Route::get('warehouses-details/print', [Controllers\WarehouseController::class, 'detailsPrint'])->name('warehouses.details.print');
    Route::get('warehouses-details/pdf', [Controllers\WarehouseController::class, 'detailsPdf'])->name('warehouses.details.pdf');

    Route::post('construction-toolbox/print', [Controllers\ConstructionToolboxController::class, 'print'])->name('construction-toolbox.print');
    Route::post('construction-toolbox/pdf', [Controllers\ConstructionToolboxController::class, 'pdf'])->name('construction-toolbox.pdf');
    Route::get('warehouses/{warehouse}/print', [Controllers\WarehouseController::class, 'printWarehouse'])->name('warehouses.print');
    Route::get('warehouses/{warehouse}/pdf', [Controllers\WarehouseController::class, 'pdfWarehouse'])->name('warehouses.pdf');
    Route::get('warehouses/{warehouse}/attachments/{attachment}/download', [Controllers\WarehouseController::class, 'downloadAttachment'])
        ->name('warehouses.attachments.download');
    Route::delete('warehouses/{warehouse}/attachments/{attachment}', [Controllers\WarehouseController::class, 'destroyAttachment'])
        ->name('warehouses.attachments.destroy');
    Route::post('warehouses/{warehouse}/toggle-active', [Controllers\WarehouseController::class, 'toggleActive'])
        ->name('warehouses.toggle-active');
    Route::post('warehouses/{warehouse}/complete-project', [Controllers\WarehouseController::class, 'completeProject'])
        ->name('warehouses.complete-project');
    Route::post('warehouses/{warehouse}/reopen-project', [Controllers\WarehouseController::class, 'reopenProject'])
        ->name('warehouses.reopen-project');

    // Warehouse email subscribers & logs

    // Email Notifications matrix — the single source of truth for who receives which system email

    // WIMS Connect — external communication (WhatsApp / Meta / SMS), independent of the internal notification bell and Email Notifications above
    Route::get('wims-connect', [Controllers\WimsConnectController::class, 'index'])->name('wims-connect.index');
    // Sidebar links call route(name) with no params (see FullMainMenu.vue), so each
    // gateway gets its own named route — driver is a fixed default, not a URI segment.
    foreach (['whatsapp', 'meta', 'sms'] as $wcDriver) {
        Route::get("wims-connect/gateways/{$wcDriver}", [Controllers\WimsConnectGatewayController::class, 'show'])
            ->name("wims-connect.gateways.{$wcDriver}")
            ->defaults('driver', $wcDriver);
    }
    Route::get('wims-connect/gateways/sms/balance', [Controllers\WimsConnectGatewayController::class, 'balance'])->middleware('throttle:20,1')->name('wims-connect.gateways.sms.balance');
    Route::put('wims-connect/gateways/{driver}', [Controllers\WimsConnectGatewayController::class, 'update'])->name('wims-connect.gateways.update');
    Route::post('wims-connect/gateways/{driver}/test', [Controllers\WimsConnectGatewayController::class, 'test'])->name('wims-connect.gateways.test');

    Route::get('wims-connect/recipients', [Controllers\WimsConnectRecipientController::class, 'index'])->name('wims-connect.recipients.index');
    Route::get('wims-connect/recipients/{user}', [Controllers\WimsConnectRecipientController::class, 'show'])->name('wims-connect.recipients.show');
    Route::put('wims-connect/recipients/{user}/toggle', [Controllers\WimsConnectRecipientController::class, 'toggle'])->name('wims-connect.recipients.toggle');
    Route::post('wims-connect/recipients/enable-waiting', [Controllers\WimsConnectRecipientController::class, 'enableWaiting'])->name('wims-connect.recipients.enable-waiting');
    Route::put('wims-connect/recipients/{user}/channels', [Controllers\WimsConnectRecipientController::class, 'updateChannels'])->name('wims-connect.recipients.channels.update');


    Route::get('wims-connect/logs', [Controllers\WimsConnectLogController::class, 'index'])->name('wims-connect.logs.index');
    Route::post('wims-connect/logs/retry-all', [Controllers\WimsConnectLogController::class, 'retryAll'])->name('wims-connect.logs.retry-all');
    Route::post('wims-connect/logs/{log}/resend', [Controllers\WimsConnectLogController::class, 'resend'])->middleware('throttle:30,1')->name('wims-connect.logs.resend')->whereNumber('log');
    Route::post('wims-connect/logs/{log}/retry', [Controllers\WimsConnectLogController::class, 'retry'])->name('wims-connect.logs.retry');
    Route::get('wims-connect/logs/{log}/message', [Controllers\WimsConnectLogController::class, 'show'])->name('wims-connect.logs.show')->whereNumber('log');
    Route::get('wims-connect/logs/print', [Controllers\WimsConnectLogController::class, 'print'])->name('wims-connect.logs.print');
    Route::get('wims-connect/logs/download', [Controllers\WimsConnectLogController::class, 'download'])->name('wims-connect.logs.download');

    Route::get('wims-connect/bulk-connect', [Controllers\WimsConnectBulkConnectController::class, 'index'])->name('wims-connect.bulk-connect.index');
    Route::get('wims-connect/bulk-connect/suggest-recipients', [Controllers\WimsConnectBulkConnectController::class, 'suggestRecipients'])->name('wims-connect.bulk-connect.suggest-recipients');
    Route::get('wims-connect/bulk-connect/import-contacts', [Controllers\WimsConnectBulkConnectController::class, 'importContacts'])->name('wims-connect.bulk-connect.import-contacts');
    Route::get('wims-connect/bulk-connect/import-users', [Controllers\WimsConnectBulkConnectController::class, 'importUsers'])->name('wims-connect.bulk-connect.import-users');
    Route::get('wims-connect/bulk-connect/excel-template', [Controllers\WimsConnectBulkConnectController::class, 'downloadExcelTemplate'])->name('wims-connect.bulk-connect.excel-template');
    Route::post('wims-connect/bulk-connect/import-excel', [Controllers\WimsConnectBulkConnectController::class, 'importExcel'])->name('wims-connect.bulk-connect.import-excel');
    Route::post('wims-connect/bulk-connect/send', [Controllers\WimsConnectBulkConnectController::class, 'store'])->name('wims-connect.bulk-connect.send');
    Route::get('wims-connect/bulk-connect/history', [Controllers\WimsConnectBulkConnectController::class, 'history'])->name('wims-connect.bulk-connect.history.index');
    Route::get('wims-connect/bulk-connect/history/print', [Controllers\WimsConnectBulkConnectController::class, 'print'])->name('wims-connect.bulk-connect.history.print');
    Route::get('wims-connect/bulk-connect/history/download', [Controllers\WimsConnectBulkConnectController::class, 'download'])->name('wims-connect.bulk-connect.history.download');
    Route::post('wims-connect/bulk-connect/history/{recipient}/retry', [Controllers\WimsConnectBulkConnectController::class, 'retry'])->name('wims-connect.bulk-connect.history.retry');

    // ARIS (Adaptive RANS Intelligence System) — natural-language, read-only Q&A over the account's own data, opened as a top-bar modal
    Route::post('ai-assistant/ask', [Controllers\AiAssistantController::class, 'ask'])->name('ai-assistant.ask');
    Route::get('ai-assistant/lookup', [Controllers\AiAssistantController::class, 'lookup'])->name('ai-assistant.lookup');

    // Executive Intelligence Report subscribers & settings
    Route::get('executive-report', [Controllers\ExecutiveReportController::class, 'index'])->name('executive-report.index');
    Route::put('executive-report/settings', [Controllers\ExecutiveReportController::class, 'updateSettings'])->name('executive-report.settings.update');
    Route::put('executive-report/content', [Controllers\ExecutiveReportController::class, 'updateContent'])->name('executive-report.content.update');
    Route::get('executive-report/preview', [Controllers\ExecutiveReportController::class, 'preview'])->name('executive-report.preview');
    Route::post('executive-report/send-test', [Controllers\ExecutiveReportController::class, 'sendTest'])->name('executive-report.send-test');
    Route::post('executive-report/subscribers', [Controllers\ExecutiveReportController::class, 'store'])->name('executive-report.subscribers.store');
    Route::put('executive-report/subscribers/{executiveReportSubscriber}', [Controllers\ExecutiveReportController::class, 'update'])->name('executive-report.subscribers.update');
    Route::delete('executive-report/subscribers/{executiveReportSubscriber}', [Controllers\ExecutiveReportController::class, 'destroy'])->name('executive-report.subscribers.destroy');

    // Literal before the resource's {material_request} wildcard below.
    Route::get('material-requests/workflow', [Controllers\MaterialRequestWorkflowController::class, 'index'])->name('material-requests.workflow');
    Route::put('material-requests/workflow', [Controllers\MaterialRequestWorkflowController::class, 'update'])->name('material-requests.workflow.update');

    Route::resource('material-requests', Controllers\MaterialRequestController::class)
        ->parameters(['material-requests' => 'material_request'])
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::resource('notices', Controllers\NoticeController::class)
        ->only(['index', 'create', 'store', 'show']);
    Route::get('deleted/print', [Controllers\DeletedRecordController::class, 'print'])->name('deleted.print');
    Route::get('deleted/pdf', [Controllers\DeletedRecordController::class, 'pdf'])->name('deleted.pdf');
    Route::get('login-logs/unlock', [Controllers\LoginLogUnlockController::class, 'show'])->name('login-logs.unlock');
    Route::post('login-logs/unlock', [Controllers\LoginLogUnlockController::class, 'check'])->middleware('throttle:20,1,login-logs.unlock')->name('login-logs.unlock.check');
    // everything else in Login Logs asks for the person's own password first (App\Http\Middleware\ConfirmLoginLogsAccess)
    Route::middleware('login-logs.unlocked')->group(function () {
        Route::get('login-logs', [Controllers\LoginLogController::class, 'index'])->name('login-logs.index');
        Route::get('login-logs/export', [Controllers\LoginLogController::class, 'export'])->name('login-logs.export');
        Route::get('login-logs/print', [Controllers\LoginLogController::class, 'print'])->name('login-logs.print');
        Route::get('login-logs/pdf', [Controllers\LoginLogController::class, 'pdf'])->name('login-logs.pdf');
        Route::get('login-logs/addresses/{tab}', [Controllers\LoginLogAddressController::class, 'index'])->whereIn('tab', ['blacklist', 'whitelist'])->name('login-logs.addresses');
        Route::post('login-logs/addresses', [Controllers\LoginLogAddressController::class, 'store'])->middleware('throttle:30,1,login-logs.addresses.store')->name('login-logs.addresses.store');
        Route::delete('login-logs/addresses/{id}', [Controllers\LoginLogAddressController::class, 'destroy'])->whereNumber('id')->name('login-logs.addresses.destroy');
        Route::post('login-logs/blocks/{id}/clear', [Controllers\LoginLogAddressController::class, 'clearBlock'])->whereNumber('id')->name('login-logs.blocks.clear');
        Route::get('login-logs/{loginLog}', [Controllers\LoginLogController::class, 'show'])->name('login-logs.show');
    });
    Route::get('download-print-logs', [Controllers\DownloadPrintLogController::class, 'index'])->name('download-print-logs.index');
    Route::get('download-print-logs/print', [Controllers\DownloadPrintLogController::class, 'print'])->name('download-print-logs.print');
    Route::get('download-print-logs/pdf', [Controllers\DownloadPrintLogController::class, 'pdf'])->name('download-print-logs.pdf');
    Route::post('material-requests/force-clear', [Controllers\MaterialRequestController::class, 'forceClear'])->middleware('throttle:10,1,material-requests.force-clear')->name('material-requests.force-clear');
    Route::post('material-requests/{material_request}/create-transfers', [Controllers\MaterialRequestController::class, 'createTransfers'])->whereNumber('material_request')->middleware('throttle:20,1,material-requests.create-transfers')->name('material-requests.create-transfers');
    Route::patch('material-requests/{material_request}/status', [Controllers\MaterialRequestController::class, 'updateStatus'])
        ->name('material-requests.status');
    Route::patch('material-requests/{material_request}/procedures', [Controllers\MaterialRequestController::class, 'updateProcedures'])
        ->name('material-requests.procedures');
    Route::patch('material-requests/{material_request}/delivery', [Controllers\MaterialRequestController::class, 'confirmDelivery'])
        ->name('material-requests.delivery');
    Route::get('material-requests/{material_request}/print', [Controllers\MaterialRequestController::class, 'print'])
        ->name('material-requests.print');
    Route::get('material-requests/{material_request}/pdf', [Controllers\MaterialRequestController::class, 'pdf'])
        ->name('material-requests.pdf');

    // Material Request Reminders: send one from a request, and the page / report of every reminder and who was told (names start "material-request-reminders." for Feature Control).
    Route::prefix('material-request-reminders')->name('material-request-reminders.')->group(function () {
        $rem = Controllers\MrReminders\ReminderController::class;
        Route::get('/', [$rem, 'index'])->name('index');
        Route::get('print', [$rem, 'print'])->middleware('throttle:30,1,material-request-reminders.print')->name('print');
        Route::get('pdf', [$rem, 'pdf'])->middleware('throttle:20,1,material-request-reminders.pdf')->name('pdf');
        Route::get('csv', [$rem, 'csv'])->middleware('throttle:20,1,material-request-reminders.csv')->name('csv');
        Route::put('settings', [$rem, 'settings'])->middleware('throttle:30,1,material-request-reminders.settings')->name('settings');
        Route::get('request/{material_request}', [$rem, 'form'])->whereNumber('material_request')->middleware('throttle:120,1,material-request-reminders.form')->name('form');
        Route::post('request/{material_request}', [$rem, 'store'])->whereNumber('material_request')->middleware('throttle:20,1,material-request-reminders.store')->name('store');
    });

    Route::get('material-reallocations/reports', [Controllers\MaterialReallocationController::class, 'reports'])
        ->name('material-reallocations.reports');
    Route::resource('material-reallocations', Controllers\MaterialReallocationController::class)
        ->parameters(['material-reallocations' => 'material_reallocation'])
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::patch('material-reallocations/{material_reallocation}/submit', [Controllers\MaterialReallocationController::class, 'submit'])
        ->name('material-reallocations.submit');
    Route::patch('material-reallocations/{material_reallocation}/clear', [Controllers\MaterialReallocationController::class, 'clear'])
        ->name('material-reallocations.clear');
    Route::patch('material-reallocations/{material_reallocation}/allocate', [Controllers\MaterialReallocationController::class, 'allocate'])
        ->name('material-reallocations.allocate');
    Route::patch('material-reallocations/{material_reallocation}/dispatch', [Controllers\MaterialReallocationController::class, 'dispatchShipment'])
        ->name('material-reallocations.dispatch');
    Route::patch('material-reallocations/{material_reallocation}/receive', [Controllers\MaterialReallocationController::class, 'receive'])
        ->name('material-reallocations.receive');
    Route::patch('material-reallocations/{material_reallocation}/final-approve', [Controllers\MaterialReallocationController::class, 'finalApprove'])
        ->name('material-reallocations.final-approve');
    Route::patch('material-reallocations/{material_reallocation}/request-changes', [Controllers\MaterialReallocationController::class, 'requestChanges'])
        ->name('material-reallocations.request-changes');
    Route::patch('material-reallocations/{material_reallocation}/reject', [Controllers\MaterialReallocationController::class, 'reject'])
        ->name('material-reallocations.reject');
    Route::patch('material-reallocations/{material_reallocation}/cancel', [Controllers\MaterialReallocationController::class, 'cancel'])
        ->name('material-reallocations.cancel');
    Route::get('material-reallocations/{material_reallocation}/print', [Controllers\MaterialReallocationController::class, 'print'])
        ->name('material-reallocations.print');
    Route::get('material-reallocations/{material_reallocation}/pdf', [Controllers\MaterialReallocationController::class, 'pdf'])
        ->name('material-reallocations.pdf');

    // Supplier Records — supplier profiles (real Contacts), purchase
    // requests, quotations, purchase orders, goods receipts, invoices,
    // payment requests, and payments, backed by real tables (see the
    // 2026_08_22_0943xx migrations and app/Models/{PurchaseRequest,
    // Quotation,PurchaseOrder,GoodsReceipt,SupplierInvoice,PaymentRequest,
    // Payment}.php).
    Route::prefix('supplier-records')->name('supplier-records.')->group(function () {
        Route::get('/', [Controllers\SupplierRecords\DashboardController::class, 'index'])->name('dashboard');
        Route::get('reports', [Controllers\SupplierRecords\FinanceReportController::class, 'index'])->name('reports');
        Route::get('explorer', [Controllers\SupplierRecords\ExplorerController::class, 'index'])->name('explorer');
        Route::get('explorer/print', [Controllers\SupplierRecords\ExplorerController::class, 'print'])->name('explorer.print');
        Route::get('explorer/pdf', [Controllers\SupplierRecords\ExplorerController::class, 'pdf'])->name('explorer.pdf');
        Route::get('explorer/balances/print', [Controllers\SupplierRecords\ExplorerController::class, 'printBalances'])->name('explorer.balances.print');

        // Suppliers are real Contact records (category "Suppliers") managed
        // in the Contacts module — only a profile view and the financial/bank
        // detail editor (Contact::extra_attributes) live here.
        Route::get('suppliers/{supplier}', [Controllers\SupplierRecords\SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('suppliers/{supplier}/statement', [Controllers\SupplierRecords\StatementController::class, 'show'])->name('suppliers.statement');
        Route::get('suppliers/{supplier}/details/edit', [Controllers\SupplierRecords\SupplierController::class, 'editDetails'])->name('suppliers.details.edit');
        Route::patch('suppliers/{supplier}/details', [Controllers\SupplierRecords\SupplierController::class, 'updateDetails'])->name('suppliers.details.update');

        // Supplier Ledger — the financial master (see App\Support\SupplierRecords\Ledger).
        Route::get('suppliers/{supplier}/ledger', [Controllers\SupplierRecords\SupplierLedgerController::class, 'show'])->name('suppliers.ledger.show');
        Route::post('suppliers/{supplier}/ledger/opening-balance', [Controllers\SupplierRecords\SupplierLedgerController::class, 'storeOpeningBalance'])->name('suppliers.ledger.opening-balance.store');
        Route::patch('suppliers/{supplier}/ledger/entries/{entry}/verify', [Controllers\SupplierRecords\SupplierLedgerController::class, 'verifyOpeningBalance'])->name('suppliers.ledger.entries.verify');
        // Correcting an already-recorded Opening Balance — requires a fresh
        // password confirmation (Fortify's password.confirm, session-scoped)
        // on top of the edit-supplier-ledger permission, since it can
        // retroactively change a supplier's balance.
        Route::patch('suppliers/{supplier}/ledger/entries/{entry}/opening-balance', [Controllers\SupplierRecords\SupplierLedgerController::class, 'updateOpeningBalance'])->middleware('password.confirm')->name('suppliers.ledger.entries.opening-balance.update');
        // Same password.confirm gate as the correction above — Super Admin
        // only (checked in the controller too).
        Route::delete('suppliers/{supplier}/ledger/entries/{entry}/opening-balance', [Controllers\SupplierRecords\SupplierLedgerController::class, 'destroyOpeningBalance'])->middleware('password.confirm')->name('suppliers.ledger.entries.opening-balance.destroy');
        Route::post('suppliers/{supplier}/ledger/entries', [Controllers\SupplierRecords\SupplierLedgerController::class, 'storeManualEntry'])->name('suppliers.ledger.entries.store');
        Route::post('suppliers/{supplier}/ledger/entries/{entry}/attachments', [Controllers\SupplierRecords\SupplierLedgerController::class, 'storeAttachment'])->name('suppliers.ledger.entries.attachments.store');
        Route::get('suppliers/{supplier}/ledger/entries/{entry}/attachments/{attachment}/download', [Controllers\SupplierRecords\SupplierLedgerController::class, 'downloadAttachment'])->name('suppliers.ledger.entries.attachments.download');
        Route::delete('suppliers/{supplier}/ledger/entries/{entry}/attachments/{attachment}', [Controllers\SupplierRecords\SupplierLedgerController::class, 'destroyAttachment'])->name('suppliers.ledger.entries.attachments.destroy');

        Route::get('suppliers/{supplier}/reconciliations', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'index'])->name('suppliers.reconciliations.index');
        Route::post('suppliers/{supplier}/reconciliations', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'store'])->name('suppliers.reconciliations.store');
        Route::patch('suppliers/{supplier}/reconciliations/{reconciliation}/resolve', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'resolve'])->name('suppliers.reconciliations.resolve');
        Route::post('suppliers/{supplier}/reconciliations/{reconciliation}/attachments', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'storeAttachment'])->name('suppliers.reconciliations.attachments.store');
        Route::get('suppliers/{supplier}/reconciliations/{reconciliation}/attachments/{attachment}/download', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'downloadAttachment'])->name('suppliers.reconciliations.attachments.download');
        Route::delete('suppliers/{supplier}/reconciliations/{reconciliation}/attachments/{attachment}', [Controllers\SupplierRecords\SupplierReconciliationController::class, 'destroyAttachment'])->name('suppliers.reconciliations.attachments.destroy');

        Route::resource('purchase-requests', Controllers\SupplierRecords\PurchaseRequestController::class)->parameters(['purchase-requests' => 'purchase_request']);
        Route::patch('purchase-requests/{purchase_request}/status', [Controllers\SupplierRecords\PurchaseRequestController::class, 'updateStatus'])->name('purchase-requests.status');
        Route::get('purchase-requests/{purchase_request}/attachments/{attachment}/download', [Controllers\SupplierRecords\PurchaseRequestController::class, 'downloadAttachment'])->name('purchase-requests.attachments.download');
        Route::get('purchase-requests/{purchase_request}/print', [Controllers\SupplierRecords\PurchaseRequestController::class, 'print'])->name('purchase-requests.print');
        Route::get('purchase-requests/{purchase_request}/pdf', [Controllers\SupplierRecords\PurchaseRequestController::class, 'pdf'])->name('purchase-requests.pdf');

        Route::resource('quotations', Controllers\SupplierRecords\QuotationController::class)->parameters(['quotations' => 'quotation']);
        Route::patch('quotations/{quotation}/status', [Controllers\SupplierRecords\QuotationController::class, 'updateStatus'])->name('quotations.status');
        Route::get('quotations/{quotation}/print', [Controllers\SupplierRecords\QuotationController::class, 'print'])->name('quotations.print');
        Route::get('quotations/{quotation}/pdf', [Controllers\SupplierRecords\QuotationController::class, 'pdf'])->name('quotations.pdf');

        Route::resource('purchase-orders', Controllers\SupplierRecords\PurchaseOrderController::class)->parameters(['purchase-orders' => 'purchase_order']);
        Route::patch('purchase-orders/{purchase_order}/submit', [Controllers\SupplierRecords\PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
        Route::patch('purchase-orders/{purchase_order}/approve', [Controllers\SupplierRecords\PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::patch('purchase-orders/{purchase_order}/send', [Controllers\SupplierRecords\PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::patch('purchase-orders/{purchase_order}/acknowledge', [Controllers\SupplierRecords\PurchaseOrderController::class, 'acknowledge'])->name('purchase-orders.acknowledge');
        Route::patch('purchase-orders/{purchase_order}/reject', [Controllers\SupplierRecords\PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
        Route::patch('purchase-orders/{purchase_order}/cancel', [Controllers\SupplierRecords\PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::get('purchase-orders/{purchase_order}/print', [Controllers\SupplierRecords\PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
        Route::get('purchase-orders/{purchase_order}/pdf', [Controllers\SupplierRecords\PurchaseOrderController::class, 'pdf'])->name('purchase-orders.pdf');
        Route::get('purchase-orders/{purchase_order}/attachments/{attachment}/download', [Controllers\SupplierRecords\PurchaseOrderController::class, 'downloadAttachment'])->name('purchase-orders.attachments.download');

        Route::resource('goods-receipts', Controllers\SupplierRecords\GoodsReceiptController::class)->parameters(['goods-receipts' => 'goods_receipt']);
        Route::get('goods-receipts/{goods_receipt}/attachments/{attachment}/download', [Controllers\SupplierRecords\GoodsReceiptController::class, 'downloadAttachment'])->name('goods-receipts.attachments.download');
        Route::get('goods-receipts/{goods_receipt}/print', [Controllers\SupplierRecords\GoodsReceiptController::class, 'print'])->name('goods-receipts.print');
        Route::get('goods-receipts/{goods_receipt}/pdf', [Controllers\SupplierRecords\GoodsReceiptController::class, 'pdf'])->name('goods-receipts.pdf');

        Route::resource('supplier-invoices', Controllers\SupplierRecords\SupplierInvoiceController::class)->parameters(['supplier-invoices' => 'supplier_invoice']);
        Route::get('supplier-invoices/{supplier_invoice}/attachments/{attachment}/download', [Controllers\SupplierRecords\SupplierInvoiceController::class, 'downloadAttachment'])->name('supplier-invoices.attachments.download');
        Route::get('supplier-invoices/{supplier_invoice}/print', [Controllers\SupplierRecords\SupplierInvoiceController::class, 'print'])->name('supplier-invoices.print');
        Route::get('supplier-invoices/{supplier_invoice}/pdf', [Controllers\SupplierRecords\SupplierInvoiceController::class, 'pdf'])->name('supplier-invoices.pdf');

        Route::resource('payment-requests', Controllers\SupplierRecords\PaymentRequestController::class)->parameters(['payment-requests' => 'payment_request']);
        Route::patch('payment-requests/{payment_request}/approve', [Controllers\SupplierRecords\PaymentRequestController::class, 'approve'])->name('payment-requests.approve');
        Route::patch('payment-requests/{payment_request}/reject', [Controllers\SupplierRecords\PaymentRequestController::class, 'reject'])->name('payment-requests.reject');
        Route::get('payment-requests/{payment_request}/print', [Controllers\SupplierRecords\PaymentRequestController::class, 'print'])->name('payment-requests.print');
        Route::get('payment-requests/{payment_request}/pdf', [Controllers\SupplierRecords\PaymentRequestController::class, 'pdf'])->name('payment-requests.pdf');

        Route::resource('payments', Controllers\SupplierRecords\PaymentController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('payments/{payment}/voucher', [Controllers\SupplierRecords\PaymentController::class, 'voucher'])->name('payments.voucher');
        Route::patch('payments/{payment}/verify', [Controllers\SupplierRecords\PaymentController::class, 'verify'])->name('payments.verify');
        Route::get('payments/{payment}/attachments/{attachment}/download', [Controllers\SupplierRecords\PaymentController::class, 'downloadAttachment'])->name('payments.attachments.download');
    });

    Route::get('payment-vouchers/reports/transport-fuel', [Controllers\PaymentVoucherController::class, 'transportFuelReport'])->name('payment-vouchers.reports.transport-fuel');
    Route::get('payment-vouchers/print-list', [Controllers\PaymentVoucherController::class, 'printList'])->name('payment-vouchers.print-list');
    Route::get('payment-vouchers/pdf-list', [Controllers\PaymentVoucherController::class, 'pdfList'])->name('payment-vouchers.pdf-list');
    Route::get('payment-vouchers/search-for-fund', [Controllers\PaymentVoucherController::class, 'searchForFund'])->name('payment-vouchers.search-for-fund');
    Route::get('payment-vouchers/export', [Controllers\PaymentVoucherController::class, 'export'])->name('payment-vouchers.export');
    Route::put('payment-vouchers/rules', [Controllers\PaymentVoucherController::class, 'saveRules'])->middleware('throttle:10,1')->name('payment-vouchers.rules');
    Route::post('payment-vouchers/bulk-approve', [Controllers\PaymentVoucherController::class, 'bulkApprove'])->name('payment-vouchers.bulk-approve');
    Route::post('payment-vouchers/bulk-mark-paid', [Controllers\PaymentVoucherController::class, 'bulkMarkPaid'])->name('payment-vouchers.bulk-mark-paid');
    Route::post('payment-vouchers/bulk-approve-authorise', [Controllers\PaymentVoucherController::class, 'bulkApproveAndAuthorise'])->name('payment-vouchers.bulk-approve-authorise');
    Route::get('payment-vouchers/batches', [Controllers\PaymentVoucherBatchController::class, 'index'])->name('payment-vouchers.batches.index');
    Route::get('payment-vouchers/batches/create', [Controllers\PaymentVoucherBatchController::class, 'create'])->name('payment-vouchers.batches.create');
    Route::post('payment-vouchers/batches', [Controllers\PaymentVoucherBatchController::class, 'store'])->name('payment-vouchers.batches.store');
    // Must be registered before the {payment_voucher_batch} wildcard show
    // route below, or "print-list"/"pdf-list" would be swallowed as an id.
    Route::get('payment-vouchers/batches/print-list', [Controllers\PaymentVoucherBatchController::class, 'printList'])->name('payment-vouchers.batches.print-list');
    Route::get('payment-vouchers/batches/pdf-list', [Controllers\PaymentVoucherBatchController::class, 'pdfList'])->name('payment-vouchers.batches.pdf-list');
    // every route with a voucher / batch id is limited to the projects of the person (company-wide vouchers and their own stay open)
    Route::middleware('pv.scope')->group(function () {
        Route::get('payment-vouchers/batches/{payment_voucher_batch}', [Controllers\PaymentVoucherBatchController::class, 'show'])->name('payment-vouchers.batches.show');
        Route::get('payment-vouchers/batches/{payment_voucher_batch}/print', [Controllers\PaymentVoucherBatchController::class, 'printAll'])->name('payment-vouchers.batches.print');
        Route::get('payment-vouchers/batches/{payment_voucher_batch}/pdf', [Controllers\PaymentVoucherBatchController::class, 'pdfAll'])->name('payment-vouchers.batches.pdf');
        Route::get('payment-vouchers/batches/{payment_voucher_batch}/attachments/{attachment}/download', [Controllers\PaymentVoucherBatchController::class, 'downloadAttachment'])->name('payment-vouchers.batches.attachments.download');
        Route::resource('payment-vouchers', Controllers\PaymentVoucherController::class)->parameters(['payment-vouchers' => 'payment_voucher']);
        Route::patch('payment-vouchers/{payment_voucher}/submit', [Controllers\PaymentVoucherController::class, 'submit'])->name('payment-vouchers.submit');
        Route::patch('payment-vouchers/{payment_voucher}/approve', [Controllers\PaymentVoucherController::class, 'approve'])->name('payment-vouchers.approve');
        Route::patch('payment-vouchers/{payment_voucher}/reject-approval', [Controllers\PaymentVoucherController::class, 'rejectApproval'])->name('payment-vouchers.reject-approval');
        Route::patch('payment-vouchers/{payment_voucher}/authorise', [Controllers\PaymentVoucherController::class, 'authorise'])->name('payment-vouchers.authorise');
        Route::patch('payment-vouchers/{payment_voucher}/reject-authorisation', [Controllers\PaymentVoucherController::class, 'rejectAuthorisation'])->name('payment-vouchers.reject-authorisation');
        Route::patch('payment-vouchers/{payment_voucher}/cancel', [Controllers\PaymentVoucherController::class, 'cancel'])->name('payment-vouchers.cancel');
        Route::get('payment-vouchers/{payment_voucher}/print', [Controllers\PaymentVoucherController::class, 'print'])->name('payment-vouchers.print');
        Route::get('payment-vouchers/{payment_voucher}/pdf', [Controllers\PaymentVoucherController::class, 'pdf'])->name('payment-vouchers.pdf');
        Route::get('payment-vouchers/{payment_voucher}/attachments/{attachment}/download', [Controllers\PaymentVoucherController::class, 'downloadAttachment'])->name('payment-vouchers.attachments.download');
        Route::delete('payment-vouchers/{payment_voucher}/attachments/{attachment}', [Controllers\PaymentVoucherController::class, 'destroyAttachment'])->name('payment-vouchers.attachments.destroy');
        Route::patch('payment-vouchers/{payment_voucher}/record-payment', [Controllers\PaymentVoucherController::class, 'recordPayment'])->name('payment-vouchers.record-payment');
    });

    // Transport Routes — Vehicles reuse the Asset model (scoped to the
    // 'Vehicles' category tree), Drivers reuse Contacts (category =
    // 'Drivers'); only Transport Records and Transport Locations are new
    // tables. Custom GET routes registered before each Route::resource()
    // call, same ordering rule as Super Adjustment's routes, so the
    // resource's {asset}/{transport_record}/{transport_location} show
    // route doesn't swallow a literal segment.
    Route::get('transport-management/dashboard', [Controllers\Transport\TransportDashboardController::class, 'index'])->name('transport-management.dashboard');

    Route::get('transport-vehicles/print-list', [Controllers\Transport\TransportVehicleController::class, 'printList'])->name('transport-vehicles.print-list');
    Route::get('transport-vehicles/pdf-list', [Controllers\Transport\TransportVehicleController::class, 'pdfList'])->name('transport-vehicles.pdf-list');
    Route::get('transport-vehicles/{asset}/print', [Controllers\Transport\TransportVehicleController::class, 'print'])->name('transport-vehicles.print');
    Route::get('transport-vehicles/{asset}/pdf', [Controllers\Transport\TransportVehicleController::class, 'pdf'])->name('transport-vehicles.pdf');
    Route::get('transport-vehicles/{asset}/attachments/{attachment}/download', [Controllers\Transport\TransportVehicleController::class, 'downloadAttachment'])->name('transport-vehicles.attachments.download');
    Route::delete('transport-vehicles/{asset}/attachments/{attachment}', [Controllers\Transport\TransportVehicleController::class, 'destroyAttachment'])->name('transport-vehicles.attachments.destroy');
    // Correcting an already-set Opening Balance needs a fresh password
    // confirmation on top of the permission check (see
    // TransportVehicleController::updateOpeningBalance()) — the initial,
    // one-time set instead rides along on the ordinary store()/update()
    // vehicle save, no separate route needed.
    Route::patch('transport-vehicles/{asset}/opening-balance', [Controllers\Transport\TransportVehicleController::class, 'updateOpeningBalance'])->middleware('password.confirm')->name('transport-vehicles.opening-balance.update');
    Route::resource('transport-vehicles', Controllers\Transport\TransportVehicleController::class)->parameters(['transport-vehicles' => 'asset']);

    Route::get('transport-records/print-list', [Controllers\Transport\TransportRecordController::class, 'printList'])->name('transport-records.print-list');
    Route::get('transport-records/pdf-list', [Controllers\Transport\TransportRecordController::class, 'pdfList'])->name('transport-records.pdf-list');
    Route::get('transport-records/export', [Controllers\Transport\TransportRecordController::class, 'export'])->name('transport-records.export');
    Route::post('transport-records/create-payment-voucher', [Controllers\Transport\TransportRecordController::class, 'createPaymentVoucher'])->name('transport-records.create-payment-voucher');
    Route::patch('transport-records/bulk-verify', [Controllers\Transport\TransportRecordController::class, 'bulkVerify'])->name('transport-records.bulk-verify');
    Route::get('transport-records/{transport_record}/print', [Controllers\Transport\TransportRecordController::class, 'print'])->name('transport-records.print');
    Route::get('transport-records/{transport_record}/pdf', [Controllers\Transport\TransportRecordController::class, 'pdf'])->name('transport-records.pdf');
    Route::resource('transport-records', Controllers\Transport\TransportRecordController::class)->parameters(['transport-records' => 'transport_record']);
    Route::patch('transport-records/{transport_record}/verify', [Controllers\Transport\TransportRecordController::class, 'verify'])->name('transport-records.verify');
    Route::patch('transport-records/{transport_record}/mark-paid', [Controllers\Transport\TransportRecordController::class, 'markPaid'])->name('transport-records.mark-paid');
    // Editing a trip that already has money against it (Partially Paid or
    // Paid, and not linked to a Payment Voucher — see updatePaid()'s own
    // comment for why a voucher-linked trip is excluded) needs a fresh
    // password confirmation on top of the ordinary update permission.
    Route::patch('transport-records/{transport_record}/update-paid', [Controllers\Transport\TransportRecordController::class, 'updatePaid'])->middleware('password.confirm')->name('transport-records.update-paid');
    Route::patch('transport-records/{transport_record}/cancel', [Controllers\Transport\TransportRecordController::class, 'cancel'])->name('transport-records.cancel');
    Route::get('transport-records/{transport_record}/attachments/{attachment}/download', [Controllers\Transport\TransportRecordController::class, 'downloadAttachment'])->name('transport-records.attachments.download');
    Route::delete('transport-records/{transport_record}/attachments/{attachment}', [Controllers\Transport\TransportRecordController::class, 'destroyAttachment'])->name('transport-records.attachments.destroy');

    Route::resource('transport-locations', Controllers\Transport\TransportLocationController::class)->parameters(['transport-locations' => 'transport_location'])->except(['show']);

    Route::get('transport-reports', [Controllers\Transport\TransportReportController::class, 'index'])->name('transport-reports.index');
    Route::get('transport-reports/print', [Controllers\Transport\TransportReportController::class, 'printList'])->name('transport-reports.print');
    Route::get('transport-reports/pdf', [Controllers\Transport\TransportReportController::class, 'pdfList'])->name('transport-reports.pdf');
    Route::get('transport-reports/export', [Controllers\Transport\TransportReportController::class, 'export'])->name('transport-reports.export');

    Route::get('transport-reports/timely', [Controllers\Transport\TransportTimelyReportController::class, 'index'])->name('transport-reports.timely');
    Route::get('transport-reports/timely/print', [Controllers\Transport\TransportTimelyReportController::class, 'printList'])->name('transport-reports.timely.print');
    Route::get('transport-reports/timely/pdf', [Controllers\Transport\TransportTimelyReportController::class, 'pdfList'])->name('transport-reports.timely.pdf');
    Route::get('transport-reports/timely/export', [Controllers\Transport\TransportTimelyReportController::class, 'export'])->name('transport-reports.timely.export');

    // Loading & Unloading: daily attendance of the loading / unloading workers, with their earnings, payments and balance.
    Route::get('transport-loading', [Controllers\Transport\TransportLoadingController::class, 'index'])->name('transport-loading.index');
    // Loading & Unloading report (filters + print / PDF / Excel) and a printable statement per worker.
    Route::get('transport-loading/report', [Controllers\Transport\TransportLoadingReportController::class, 'index'])->name('transport-loading.report');
    Route::get('transport-loading/report/print', [Controllers\Transport\TransportLoadingReportController::class, 'printList'])->name('transport-loading.report.print');
    Route::get('transport-loading/report/pdf', [Controllers\Transport\TransportLoadingReportController::class, 'pdfList'])->name('transport-loading.report.pdf');
    Route::get('transport-loading/report/export', [Controllers\Transport\TransportLoadingReportController::class, 'export'])->name('transport-loading.report.export');
    Route::get('transport-loading/workers/{contact}/print', [Controllers\Transport\TransportLoadingController::class, 'printStatement'])->name('transport-loading.workers.print');
    Route::get('transport-loading/workers/{contact}/pdf', [Controllers\Transport\TransportLoadingController::class, 'pdfStatement'])->name('transport-loading.workers.pdf');
    Route::post('transport-loading/attendance', [Controllers\Transport\TransportLoadingController::class, 'storeAttendance'])->name('transport-loading.attendance.store');
    Route::delete('transport-loading/attendance/{attendance}', [Controllers\Transport\TransportLoadingController::class, 'destroyAttendance'])->name('transport-loading.attendance.destroy');
    Route::get('transport-loading/workers/{contact}', [Controllers\Transport\TransportLoadingController::class, 'show'])->name('transport-loading.show');
    Route::post('transport-loading/payments', [Controllers\Transport\TransportLoadingController::class, 'storePayment'])->name('transport-loading.payments.store');
    Route::post('transport-loading/vouchers', [Controllers\Transport\TransportLoadingController::class, 'storeVoucher'])->name('transport-loading.vouchers.store');
    Route::post('transport-loading/vouchers/bulk', [Controllers\Transport\TransportLoadingController::class, 'storeBulkVouchers'])->name('transport-loading.vouchers.bulk');
    Route::delete('transport-loading/payments/{payment}', [Controllers\Transport\TransportLoadingController::class, 'destroyPayment'])->name('transport-loading.payments.destroy');
    Route::post('transport-loading/opening', [Controllers\Transport\TransportLoadingController::class, 'storeOpening'])->name('transport-loading.opening.store');
    Route::delete('transport-loading/opening/{contact}', [Controllers\Transport\TransportLoadingController::class, 'destroyOpening'])->name('transport-loading.opening.destroy');

    // Transport Routes > Notifications (the Notification Center, limited to this module)

    Route::get('transport-settings', [Controllers\Transport\TransportSettingsController::class, 'edit'])->name('transport-settings.edit');
    Route::patch('transport-settings', [Controllers\Transport\TransportSettingsController::class, 'update'])->name('transport-settings.update');

    // Literal before the resource's {labour} wildcard below.
    Route::get('labour/print-list', [Controllers\LabourController::class, 'printList'])->name('labour.print-list');
    Route::get('labour/pdf-list', [Controllers\LabourController::class, 'pdfList'])->name('labour.pdf-list');

    Route::resource('labour', Controllers\LabourController::class);
    Route::patch('labour/{labour}/submit', [Controllers\LabourController::class, 'submit'])->name('labour.submit');
    Route::patch('labour/{labour}/status', [Controllers\LabourController::class, 'updateStatus'])->name('labour.status');
    Route::post('labour/{labour}/create-payment-voucher', [Controllers\LabourController::class, 'createPaymentVoucher'])->name('labour.create-payment-voucher');
    Route::get('labour/{labour}/print', [Controllers\LabourController::class, 'print'])->name('labour.print');
    Route::get('labour/{labour}/pdf', [Controllers\LabourController::class, 'pdf'])->name('labour.pdf');
    Route::get('labour/{labour}/attachments/{attachment}', [Controllers\LabourController::class, 'downloadAttachment'])->name('labour.attachments.download');

    // Literal before the resource's {workforce_agreement} wildcard below.
    Route::get('workforce-agreements/by-project', [Controllers\WorkforceAgreementController::class, 'byProject'])->name('workforce-agreements.by-project');
    Route::get('workforce-agreements/by-project/print', [Controllers\WorkforceAgreementController::class, 'printByProject'])->name('workforce-agreements.by-project.print');
    Route::get('workforce-agreements/by-project/pdf', [Controllers\WorkforceAgreementController::class, 'pdfByProject'])->name('workforce-agreements.by-project.pdf');
    Route::get('workforce-agreements/for-personnel', [Controllers\WorkforceAgreementController::class, 'forPersonnel'])->name('workforce-agreements.for-personnel');
    Route::get('workforce-agreements/print', [Controllers\WorkforceAgreementController::class, 'printList'])->name('workforce-agreements.print');
    Route::get('workforce-agreements/pdf', [Controllers\WorkforceAgreementController::class, 'pdfList'])->name('workforce-agreements.pdf');

    Route::resource('workforce-agreements', Controllers\WorkforceAgreementController::class)->except(['destroy']);
    Route::patch('workforce-agreements/{workforce_agreement}/cancel', [Controllers\WorkforceAgreementController::class, 'cancel'])->name('workforce-agreements.cancel');
    Route::get('workforce-agreements/{workforce_agreement}/print', [Controllers\WorkforceAgreementController::class, 'printDocument'])->name('workforce-agreements.document.print');
    Route::get('workforce-agreements/{workforce_agreement}/pdf', [Controllers\WorkforceAgreementController::class, 'pdfDocument'])->name('workforce-agreements.document.pdf');
    Route::get('workforce-agreements/{workforce_agreement}/attachments/{attachment}', [Controllers\WorkforceAgreementController::class, 'downloadAttachment'])->name('workforce-agreements.attachments.download');

    // Literal before the resource's {case_ticket} wildcard below.
    Route::get('case-tracking-analytics', [Controllers\CaseAnalyticsController::class, 'index'])->name('case-tracking.analytics');
    Route::get('case-tracking-analytics/print', [Controllers\CaseAnalyticsController::class, 'print'])->name('case-tracking.analytics.print');
    Route::get('case-tracking-analytics/pdf', [Controllers\CaseAnalyticsController::class, 'pdf'])->name('case-tracking.analytics.pdf');
    Route::get('case-tracking-settings', [Controllers\CaseSettingsController::class, 'index'])->name('case-tracking.settings');
    Route::post('case-tracking-settings/categories', [Controllers\CaseSettingsController::class, 'storeCategory'])->name('case-tracking.settings.categories.store');
    Route::patch('case-tracking-settings/categories/{case_category}', [Controllers\CaseSettingsController::class, 'updateCategory'])->name('case-tracking.settings.categories.update');
    Route::delete('case-tracking-settings/categories/{case_category}', [Controllers\CaseSettingsController::class, 'destroyCategory'])->name('case-tracking.settings.categories.destroy');
    Route::post('case-tracking-settings/priorities', [Controllers\CaseSettingsController::class, 'storePriority'])->name('case-tracking.settings.priorities.store');
    Route::patch('case-tracking-settings/priorities/{case_priority}', [Controllers\CaseSettingsController::class, 'updatePriority'])->name('case-tracking.settings.priorities.update');
    Route::delete('case-tracking-settings/priorities/{case_priority}', [Controllers\CaseSettingsController::class, 'destroyPriority'])->name('case-tracking.settings.priorities.destroy');
    Route::get('case-tracking/export', [Controllers\CaseTrackingController::class, 'export'])->name('case-tracking.export');
    Route::get('case-tracking/print-list', [Controllers\CaseTrackingController::class, 'printList'])->name('case-tracking.print-list');
    Route::get('case-tracking/pdf-list', [Controllers\CaseTrackingController::class, 'pdfList'])->name('case-tracking.pdf-list');
    // Same index() action as case-tracking.index — the sidebar's plain
    // route(sm.route) link (FullMainMenu.vue's multi-item branch) can't pin
    // a query param onto a shared route the way menuHref()'s single-item
    // branch can, so "My Cases" gets its own route name instead; the
    // controller detects it via $request->routeIs() and forces own-scoping.
    Route::get('case-tracking/mine', [Controllers\CaseTrackingController::class, 'index'])->name('case-tracking.mine');

    Route::post('case-tracking/categories/quick-create', [Controllers\CaseTrackingController::class, 'quickCreateCategory'])->name('case-tracking.categories.quick-create');
    Route::post('case-tracking/priorities/quick-create', [Controllers\CaseTrackingController::class, 'quickCreatePriority'])->name('case-tracking.priorities.quick-create');
    Route::resource('case-tracking', Controllers\CaseTrackingController::class)->parameters(['case-tracking' => 'case_ticket']);
    Route::patch('case-tracking/{case_ticket}/submit', [Controllers\CaseTrackingController::class, 'submit'])->name('case-tracking.submit');
    Route::patch('case-tracking/{case_ticket}/status', [Controllers\CaseTrackingController::class, 'updateStatus'])->name('case-tracking.status');
    Route::patch('case-tracking/{case_ticket}/assign', [Controllers\CaseTrackingController::class, 'assign'])->name('case-tracking.assign');
    Route::post('case-tracking/{case_ticket}/comments', [Controllers\CaseCommentController::class, 'store'])->name('case-tracking.comments.store');
    Route::get('case-tracking/{case_ticket}/print', [Controllers\CaseTrackingController::class, 'print'])->name('case-tracking.print');
    Route::get('case-tracking/{case_ticket}/pdf', [Controllers\CaseTrackingController::class, 'pdf'])->name('case-tracking.pdf');
    Route::get('case-tracking/{case_ticket}/attachments/{attachment}', [Controllers\CaseTrackingController::class, 'downloadAttachment'])->name('case-tracking.attachments.download');
    Route::delete('case-tracking/{case_ticket}/attachments/{attachment}', [Controllers\CaseTrackingController::class, 'destroyAttachment'])->name('case-tracking.attachments.destroy');

    Route::get('task-manager-reports', [Controllers\TaskReportController::class, 'index'])->name('task-manager.reports');
    Route::get('task-manager-reports/print', [Controllers\TaskReportController::class, 'print'])->name('task-manager.reports.print');
    Route::get('task-manager-reports/pdf', [Controllers\TaskReportController::class, 'pdf'])->name('task-manager.reports.pdf');
    Route::get('task-manager-settings', [Controllers\TaskSettingsController::class, 'index'])->name('task-manager.settings');
    Route::put('task-manager-settings/notifications', [Controllers\TaskSettingsController::class, 'updateNotifications'])->name('task-manager.settings.notifications.update');

    // Same reuse trick as case-tracking.mine above — one index() action,
    // routeIs() detects which route name was hit to decide the scope.
    Route::get('task-manager/mine', [Controllers\TaskManagerController::class, 'index'])->name('task-manager.mine');
    Route::get('task-manager/reference', [Controllers\TaskManagerController::class, 'resolveReference'])->name('task-manager.reference');
    Route::get('task-manager/references/search', [Controllers\TaskManagerController::class, 'searchReferences'])->name('task-manager.references.search');
    Route::resource('task-manager', Controllers\TaskManagerController::class)->parameters(['task-manager' => 'task'])->only(['index', 'store', 'update', 'destroy']);
    Route::get('task-manager/{task}/detail', [Controllers\TaskManagerController::class, 'detail'])->name('task-manager.detail');
    Route::patch('task-manager/{task}/move', [Controllers\TaskManagerController::class, 'move'])->name('task-manager.move');
    Route::patch('task-manager/{task}/assignees', [Controllers\TaskManagerController::class, 'syncAssignees'])->name('task-manager.assignees.sync');
    Route::get('task-manager/{task}/activity', [Controllers\TaskManagerController::class, 'activity'])->name('task-manager.activity');
    Route::get('task-manager/{task}/attachments/{attachment}', [Controllers\TaskManagerController::class, 'downloadAttachment'])->name('task-manager.attachments.download');
    Route::delete('task-manager/{task}/attachments/{attachment}', [Controllers\TaskManagerController::class, 'destroyAttachment'])->name('task-manager.attachments.destroy');
    Route::post('task-manager/{task}/comments', [Controllers\TaskCommentController::class, 'store'])->name('task-manager.comments.store');
    Route::post('task-manager/{task}/checklist-items', [Controllers\TaskChecklistItemController::class, 'store'])->name('task-manager.checklist-items.store');
    Route::patch('task-manager/{task}/checklist-items/{checklist_item}', [Controllers\TaskChecklistItemController::class, 'update'])->name('task-manager.checklist-items.update');
    Route::delete('task-manager/{task}/checklist-items/{checklist_item}', [Controllers\TaskChecklistItemController::class, 'destroy'])->name('task-manager.checklist-items.destroy');

    // URL path deliberately NOT "assets" — that literally collides with the
    // real public/assets/ Vite build directory on disk, so Apache serves
    // its own 404 for the directory before the request ever reaches
    // Laravel. Route *names* stay "assets.*" (names are just identifiers,
    // they don't touch the filesystem) — only the path segment changes.
    Route::get('company-assets/print-list', [Controllers\Asset\AssetController::class, 'printList'])->name('assets.print-list');
    Route::get('company-assets/pdf-list', [Controllers\Asset\AssetController::class, 'pdfList'])->name('assets.pdf-list');
    // Document Archive. Files are only ever served by these authorised routes (never a storage URL).
    Route::get('archive', [Controllers\Archive\ArchiveDashboardController::class, 'index'])->name('archive.dashboard');
    Route::get('archive/dashboard/stats', [Controllers\Archive\ArchiveDashboardController::class, 'stats'])->name('archive.dashboard.stats');
    Route::get('archive/records', [Controllers\Archive\ArchiveRecordController::class, 'index'])->name('archive.records.index');
    Route::get('archive/notifications', [Controllers\Archive\ArchiveNotificationController::class, 'index'])->name('archive.notifications');
    Route::put('archive/notifications', [Controllers\Archive\ArchiveNotificationController::class, 'update'])->middleware('throttle:10,1,archive.notifications.update')->name('archive.notifications.update');
    Route::post('archive/restricted/unlock', [Controllers\Archive\ArchiveRecordController::class, 'unlockRestricted'])->middleware('throttle:8,1,archive.restricted.unlock')->name('archive.restricted.unlock');
    Route::get('archive/tree', [Controllers\Archive\ArchiveTreeController::class, 'index'])->name('archive.tree');
    Route::get('archive/tree/nodes', [Controllers\Archive\ArchiveTreeController::class, 'nodes'])->name('archive.tree.nodes');
    Route::get('archive/tree/records', [Controllers\Archive\ArchiveTreeController::class, 'records'])->name('archive.tree.records');
    Route::post('archive/tree/unlock', [Controllers\Archive\ArchiveTreeController::class, 'unlock'])->middleware('throttle:8,1,archive.tree.unlock')->name('archive.tree.unlock');
    Route::get('archive/records/quick', [Controllers\Archive\ArchiveRecordController::class, 'quick'])->name('archive.records.quick');
    Route::get('archive/records/create', [Controllers\Archive\ArchiveRecordController::class, 'create'])->name('archive.records.create');
    Route::post('archive/records', [Controllers\Archive\ArchiveRecordController::class, 'store'])->name('archive.records.store');
    Route::put('archive/records/{id}/restore', [Controllers\Archive\ArchiveRecordController::class, 'restore'])->whereNumber('id')->name('archive.records.restore');
    Route::get('archive/records/{archive_record}', [Controllers\Archive\ArchiveRecordController::class, 'show'])->whereNumber('archive_record')->name('archive.records.show');
    Route::get('archive/records/{archive_record}/edit', [Controllers\Archive\ArchiveRecordController::class, 'edit'])->whereNumber('archive_record')->name('archive.records.edit');
    Route::put('archive/records/{archive_record}', [Controllers\Archive\ArchiveRecordController::class, 'update'])->whereNumber('archive_record')->name('archive.records.update');
    Route::delete('archive/records/{archive_record}', [Controllers\Archive\ArchiveRecordController::class, 'destroy'])->whereNumber('archive_record')->name('archive.records.destroy');
    Route::get('archive/records/{archive_record}/print', [Controllers\Archive\ArchiveRecordController::class, 'print'])->whereNumber('archive_record')->name('archive.records.print');
    Route::get('archive/records/{archive_record}/files', [Controllers\Archive\ArchiveFileController::class, 'index'])->whereNumber('archive_record')->name('archive.records.files.index');
    Route::post('archive/records/{archive_record}/files', [Controllers\Archive\ArchiveFileController::class, 'store'])->whereNumber('archive_record')->name('archive.records.files.store');
    Route::get('archive/records/{archive_record}/files/{archive_file}/preview', [Controllers\Archive\ArchiveFileController::class, 'preview'])->whereNumber(['archive_record', 'archive_file'])->name('archive.records.files.preview');
    Route::get('archive/records/{archive_record}/files/{archive_file}/open', [Controllers\Archive\ArchiveFileController::class, 'open'])->whereNumber(['archive_record', 'archive_file'])->name('archive.records.files.open');
    Route::get('archive/records/{archive_record}/files/{archive_file}/download', [Controllers\Archive\ArchiveFileController::class, 'download'])->whereNumber(['archive_record', 'archive_file'])->name('archive.records.files.download');
    Route::delete('archive/records/{archive_record}/files/{archive_file}', [Controllers\Archive\ArchiveFileController::class, 'destroy'])->whereNumber(['archive_record', 'archive_file'])->name('archive.records.files.destroy');

    // Site Diary. Media is only ever streamed by these authorised routes (never a Drive / storage URL).
    Route::get('site-diary/analytics', [Controllers\SiteDiary\SiteDiaryController::class, 'dashboard'])->name('site-diary.analytics');
    Route::get('site-diary/analytics/stats', [Controllers\SiteDiary\SiteDiaryController::class, 'stats'])->name('site-diary.analytics.stats');
    Route::get('site-diary/settings', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'index'])->name('site-diary.settings');
    Route::put('site-diary/settings', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'update'])->middleware('throttle:12,1,site-diary.settings.update')->name('site-diary.settings.update');
    Route::put('site-diary/settings/credentials', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'credentials'])->middleware('throttle:6,1,site-diary.settings.credentials')->name('site-diary.settings.credentials');
    Route::post('site-diary/settings/google/connect', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'connect'])->middleware('throttle:6,1,site-diary.settings.google.connect')->name('site-diary.settings.google.connect');
    Route::get('site-diary/settings/google/callback', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'callback'])->middleware('throttle:10,1,site-diary.settings.google.callback')->name('site-diary.settings.google.callback');
    Route::delete('site-diary/settings/credentials', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'disconnect'])->middleware('throttle:6,1,site-diary.settings.disconnect')->name('site-diary.settings.disconnect');
    Route::post('site-diary/settings/test', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'test'])->middleware('throttle:10,1,site-diary.settings.test')->name('site-diary.settings.test');
    Route::get('site-diary/settings/usage', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'usage'])->middleware('throttle:20,1,site-diary.settings.usage')->name('site-diary.settings.usage');
    Route::post('site-diary/settings/clear-drive', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'clearDrive'])->middleware('throttle:3,1,site-diary.settings.clear-drive')->name('site-diary.settings.clear-drive');
    Route::post('site-diary/settings/sync', [Controllers\SiteDiary\SiteDiarySettingsController::class, 'sync'])->middleware('throttle:6,1,site-diary.settings.sync')->name('site-diary.settings.sync');
    Route::get('site-diary/reports', [Controllers\SiteDiary\SiteDiaryReportController::class, 'index'])->name('site-diary.reports');
    Route::get('site-diary/reports/print', [Controllers\SiteDiary\SiteDiaryReportController::class, 'print'])->name('site-diary.reports.print');
    Route::get('site-diary/reports/pdf', [Controllers\SiteDiary\SiteDiaryReportController::class, 'pdf'])->name('site-diary.reports.pdf');
    Route::get('site-diary/reports/export', [Controllers\SiteDiary\SiteDiaryReportController::class, 'export'])->name('site-diary.reports.export');
    Route::get('site-diary/reports/gallery/print', [Controllers\SiteDiary\SiteDiaryReportController::class, 'gallery'])->name('site-diary.reports.gallery.print');
    Route::get('site-diary/reports/gallery/pdf', [Controllers\SiteDiary\SiteDiaryReportController::class, 'galleryPdf'])->name('site-diary.reports.gallery.pdf');
    Route::get('site-diary/projects/{warehouse}/summary', [Controllers\SiteDiary\SiteDiaryController::class, 'projectSummaryJson'])->whereNumber('warehouse')->name('site-diary.projects.summary');
    Route::get('site-diary', [Controllers\SiteDiary\SiteDiaryController::class, 'index'])->name('site-diary.index');
    Route::get('site-diary/create', [Controllers\SiteDiary\SiteDiaryController::class, 'create'])->name('site-diary.create');
    Route::post('site-diary', [Controllers\SiteDiary\SiteDiaryController::class, 'store'])->name('site-diary.store');
    Route::put('site-diary/{id}/restore', [Controllers\SiteDiary\SiteDiaryController::class, 'restore'])->whereNumber('id')->name('site-diary.restore');
    Route::get('site-diary/{diary}', [Controllers\SiteDiary\SiteDiaryController::class, 'show'])->whereNumber('diary')->name('site-diary.show');
    Route::get('site-diary/{diary}/edit', [Controllers\SiteDiary\SiteDiaryController::class, 'edit'])->whereNumber('diary')->name('site-diary.edit');
    Route::put('site-diary/{diary}', [Controllers\SiteDiary\SiteDiaryController::class, 'update'])->whereNumber('diary')->name('site-diary.update');
    Route::delete('site-diary/{diary}', [Controllers\SiteDiary\SiteDiaryController::class, 'destroy'])->whereNumber('diary')->name('site-diary.destroy');
    Route::post('site-diary/{diary}/uploads-complete', [Controllers\SiteDiary\SiteDiaryController::class, 'uploadsComplete'])->whereNumber('diary')->name('site-diary.uploads-complete');
    Route::post('site-diary/{diary}/media', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'store'])->whereNumber('diary')->middleware('throttle:240,1,site-diary.media.store')->name('site-diary.media.store');
    Route::delete('site-diary/{diary}/media/{media}', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'destroy'])->whereNumber(['diary', 'media'])->name('site-diary.media.destroy');
    Route::post('site-diary/{diary}/media/{media}/retry', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'retry'])->whereNumber(['diary', 'media'])->middleware('throttle:30,1,site-diary.media.retry')->name('site-diary.media.retry');
    Route::get('site-diary/{diary}/media/{media}/thumb', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'thumb'])->whereNumber(['diary', 'media'])->name('site-diary.media.thumb');
    Route::get('site-diary/{diary}/media/{media}/view', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'view'])->whereNumber(['diary', 'media'])->name('site-diary.media.view');
    Route::get('site-diary/{diary}/media/{media}/open', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'open'])->whereNumber(['diary', 'media'])->name('site-diary.media.open');
    Route::get('site-diary/{diary}/media/{media}/download', [Controllers\SiteDiary\SiteDiaryMediaController::class, 'download'])->whereNumber(['diary', 'media'])->name('site-diary.media.download');

    // Site Meetings — management's weekly meeting schedule per site, and the minutes document the site engineers prepare.
    $sm = Controllers\SiteMeetings\SiteMeetingController::class;
    $smr = Controllers\SiteMeetings\SiteMeetingReportController::class;
    $sms = Controllers\SiteMeetings\SiteMeetingSettingsController::class;
    Route::get('site-meetings', [$sm, 'index'])->name('site-meetings.index');
    Route::get('site-meetings/list', [$sm, 'list'])->name('site-meetings.list');
    Route::get('site-meetings/week-summary', [$sm, 'weekSummary'])->name('site-meetings.week-summary');
    Route::get('site-meetings/create', [$sm, 'create'])->name('site-meetings.create');
    Route::get('site-meetings/conflicts', [$sm, 'conflicts'])->middleware('throttle:120,1,site-meetings.conflicts')->name('site-meetings.conflicts');
    Route::get('site-meetings/next-number', [$sm, 'nextNumber'])->name('site-meetings.next-number');
    Route::get('site-meetings/print-week', [$sm, 'printWeek'])->name('site-meetings.print-week');
    Route::get('site-meetings/pdf-week', [$sm, 'pdfWeek'])->name('site-meetings.pdf-week');
    Route::get('site-meetings/settings', [$sms, 'show'])->name('site-meetings.settings');
    Route::put('site-meetings/settings', [$sms, 'update'])->middleware('throttle:20,1,site-meetings.settings.update')->name('site-meetings.settings.update');
    Route::put('site-meetings/settings/notifications', [$sms, 'saveNotifications'])->middleware('throttle:20,1,site-meetings.settings.notifications')->name('site-meetings.settings.notifications');
    Route::put('site-meetings/settings/template', [$sms, 'saveTemplate'])->middleware('throttle:20,1,site-meetings.settings.template')->name('site-meetings.settings.template');
    Route::delete('site-meetings/settings/template', [$sms, 'resetTemplate'])->middleware('throttle:10,1,site-meetings.settings.template-reset')->name('site-meetings.settings.template-reset');
    Route::post('site-meetings', [$sm, 'store'])->middleware('throttle:30,1,site-meetings.store')->name('site-meetings.store');
    Route::get('site-meetings/{site_meeting}', [$sm, 'show'])->whereNumber('site_meeting')->name('site-meetings.show');
    Route::get('site-meetings/{site_meeting}/edit', [$sm, 'edit'])->whereNumber('site_meeting')->name('site-meetings.edit');
    Route::post('site-meetings/{site_meeting}', [$sm, 'update'])->whereNumber('site_meeting')->middleware('throttle:60,1,site-meetings.update')->name('site-meetings.update');
    Route::delete('site-meetings/{site_meeting}', [$sm, 'destroy'])->whereNumber('site_meeting')->name('site-meetings.destroy');
    Route::put('site-meetings/{site_meeting}/restore', [$sm, 'restore'])->whereNumber('site_meeting')->name('site-meetings.restore');
    Route::put('site-meetings/{site_meeting}/move', [$sm, 'move'])->whereNumber('site_meeting')->middleware('throttle:120,1,site-meetings.move')->name('site-meetings.move');
    Route::put('site-meetings/{site_meeting}/status', [$sm, 'status'])->whereNumber('site_meeting')->name('site-meetings.status');
    Route::get('site-meetings/{site_meeting}/print', [$sm, 'print'])->whereNumber('site_meeting')->name('site-meetings.print');
    Route::get('site-meetings/{site_meeting}/pdf', [$sm, 'pdf'])->whereNumber('site_meeting')->name('site-meetings.pdf');
    Route::get('site-meetings/{site_meeting}/attachments/{attachment}/download', [$sm, 'downloadAttachment'])->whereNumber(['site_meeting', 'attachment'])->name('site-meetings.attachments.download');
    Route::delete('site-meetings/{site_meeting}/attachments/{attachment}', [$sm, 'destroyAttachment'])->whereNumber(['site_meeting', 'attachment'])->name('site-meetings.attachments.destroy');
    Route::get('site-meetings/{site_meeting}/report', [$smr, 'show'])->whereNumber('site_meeting')->name('site-meetings.report');
    Route::put('site-meetings/{site_meeting}/report', [$smr, 'save'])->whereNumber('site_meeting')->middleware('throttle:60,1,site-meetings.report.save')->name('site-meetings.report.save');
    Route::get('site-meetings/{site_meeting}/report/sources', [$smr, 'sources'])->whereNumber('site_meeting')->name('site-meetings.report.sources');
    Route::post('site-meetings/{site_meeting}/report/copy', [$smr, 'copyFrom'])->whereNumber('site_meeting')->middleware('throttle:20,1,site-meetings.report.copy')->name('site-meetings.report.copy');
    Route::post('site-meetings/{site_meeting}/report/submit', [$smr, 'submit'])->whereNumber('site_meeting')->name('site-meetings.report.submit');
    Route::post('site-meetings/{site_meeting}/report/finalise', [$smr, 'finalise'])->whereNumber('site_meeting')->name('site-meetings.report.finalise');
    Route::post('site-meetings/{site_meeting}/report/reopen', [$smr, 'reopen'])->whereNumber('site_meeting')->name('site-meetings.report.reopen');
    Route::get('site-meetings/{site_meeting}/report/print', [$smr, 'print'])->whereNumber('site_meeting')->name('site-meetings.report.print');
    Route::get('site-meetings/{site_meeting}/report/pdf', [$smr, 'pdf'])->whereNumber('site_meeting')->name('site-meetings.report.pdf');

    // WIMSChat — internal messenger. JSON endpoints for the chat UI (drawer + module page); files only through the authorised attachment routes.
    Route::get('wimschat', [Controllers\WimsChat\WimsChatController::class, 'index'])->name('wimschat.index');
    Route::get('wimschat/bootstrap', [Controllers\WimsChat\WimsChatController::class, 'bootstrap'])->middleware('throttle:60,1,wimschat.bootstrap')->name('wimschat.bootstrap');
    Route::get('wimschat/unread', [Controllers\WimsChat\WimsChatController::class, 'unread'])->middleware('throttle:60,1,wimschat.unread')->name('wimschat.unread');
    Route::get('wimschat/poll', [Controllers\WimsChat\WimsChatController::class, 'poll'])->middleware('throttle:90,1,wimschat.poll')->name('wimschat.poll');
    Route::get('wimschat/users', [Controllers\WimsChat\WimsChatController::class, 'users'])->middleware('throttle:60,1,wimschat.users')->name('wimschat.users');
    Route::get('wimschat/users/{user}', [Controllers\WimsChat\WimsChatController::class, 'user'])->whereNumber('user')->name('wimschat.users.show');
    Route::put('wimschat/prefs', [Controllers\WimsChat\WimsChatController::class, 'updatePrefs'])->middleware('throttle:30,1,wimschat.prefs.update')->name('wimschat.prefs.update');
    Route::get('wimschat/search', [Controllers\WimsChat\WimsChatController::class, 'search'])->middleware('throttle:60,1,wimschat.search')->name('wimschat.search');
    Route::get('wimschat/settings', [Controllers\WimsChat\WimsChatSettingsController::class, 'index'])->name('wimschat.settings');
    Route::put('wimschat/settings', [Controllers\WimsChat\WimsChatSettingsController::class, 'update'])->middleware('throttle:12,1,wimschat.settings.update')->name('wimschat.settings.update');
    Route::get('wimschat/analytics', [Controllers\WimsChat\WimsChatAnalyticsController::class, 'index'])->name('wimschat.analytics');
    Route::get('wimschat/analytics/print', [Controllers\WimsChat\WimsChatAnalyticsController::class, 'print'])->name('wimschat.analytics.print');
    Route::get('wimschat/analytics/pdf', [Controllers\WimsChat\WimsChatAnalyticsController::class, 'pdf'])->name('wimschat.analytics.pdf');
    Route::get('wimschat/analytics/export', [Controllers\WimsChat\WimsChatAnalyticsController::class, 'export'])->name('wimschat.analytics.export');
    Route::get('wimschat/conversations', [Controllers\WimsChat\WimsChatConversationController::class, 'index'])->middleware('throttle:90,1,wimschat.conversations.index')->name('wimschat.conversations.index');
    Route::post('wimschat/conversations', [Controllers\WimsChat\WimsChatConversationController::class, 'store'])->middleware('throttle:30,1,wimschat.conversations.store')->name('wimschat.conversations.store');
    Route::get('wimschat/conversations/{conversation}/messages', [Controllers\WimsChat\WimsChatConversationController::class, 'messages'])->whereNumber('conversation')->middleware('throttle:120,1,wimschat.conversations.messages')->name('wimschat.conversations.messages');
    Route::post('wimschat/conversations/{conversation}/messages', [Controllers\WimsChat\WimsChatMessageController::class, 'store'])->whereNumber('conversation')->middleware('throttle:90,1,wimschat.messages.store')->name('wimschat.messages.store');
    Route::get('wimschat/conversations/{conversation}/media', [Controllers\WimsChat\WimsChatConversationController::class, 'media'])->whereNumber('conversation')->name('wimschat.conversations.media');
    Route::get('wimschat/conversations/{conversation}/avatar', [Controllers\WimsChat\WimsChatConversationController::class, 'avatar'])->whereNumber('conversation')->name('wimschat.conversations.avatar');
    Route::patch('wimschat/conversations/{conversation}/state', [Controllers\WimsChat\WimsChatConversationController::class, 'state'])->whereNumber('conversation')->middleware('throttle:60,1,wimschat.conversations.state')->name('wimschat.conversations.state');
    Route::post('wimschat/conversations/{conversation}/read', [Controllers\WimsChat\WimsChatConversationController::class, 'read'])->whereNumber('conversation')->middleware('throttle:120,1,wimschat.conversations.read')->name('wimschat.conversations.read');
    Route::post('wimschat/conversations/{conversation}/typing', [Controllers\WimsChat\WimsChatConversationController::class, 'typing'])->whereNumber('conversation')->middleware('throttle:90,1,wimschat.typing')->name('wimschat.typing');
    Route::post('wimschat/conversations/{conversation}/nudge', [Controllers\WimsChat\WimsChatMessageController::class, 'nudge'])->whereNumber('conversation')->middleware('throttle:20,1,wimschat.nudge')->name('wimschat.nudge');
    Route::get('wimschat/invitations', [Controllers\WimsChat\WimsChatConversationController::class, 'invitations'])->middleware('throttle:60,1,wimschat.groups.invitations')->name('wimschat.groups.invitations');
    Route::post('wimschat/groups/{conversation}/invitation', [Controllers\WimsChat\WimsChatConversationController::class, 'respond'])->whereNumber('conversation')->middleware('throttle:30,1,wimschat.groups.respond')->name('wimschat.groups.respond');
    Route::post('wimschat/groups', [Controllers\WimsChat\WimsChatConversationController::class, 'storeGroup'])->middleware('throttle:20,1,wimschat.groups.store')->name('wimschat.groups.store');
    Route::post('wimschat/groups/{conversation}', [Controllers\WimsChat\WimsChatConversationController::class, 'updateGroup'])->whereNumber('conversation')->middleware('throttle:30,1,wimschat.groups.update')->name('wimschat.groups.update');
    Route::post('wimschat/groups/{conversation}/members', [Controllers\WimsChat\WimsChatConversationController::class, 'addMembers'])->whereNumber('conversation')->middleware('throttle:30,1,wimschat.groups.members.add')->name('wimschat.groups.members.add');
    Route::delete('wimschat/groups/{conversation}/members/{user}', [Controllers\WimsChat\WimsChatConversationController::class, 'removeMember'])->whereNumber(['conversation', 'user'])->name('wimschat.groups.members.remove');
    Route::put('wimschat/groups/{conversation}/members/{user}', [Controllers\WimsChat\WimsChatConversationController::class, 'memberRole'])->whereNumber(['conversation', 'user'])->name('wimschat.groups.members.role');
    Route::post('wimschat/groups/{conversation}/leave', [Controllers\WimsChat\WimsChatConversationController::class, 'leave'])->whereNumber('conversation')->name('wimschat.groups.leave');
    Route::put('wimschat/messages/{message}', [Controllers\WimsChat\WimsChatMessageController::class, 'update'])->whereNumber('message')->middleware('throttle:60,1,wimschat.messages.update')->name('wimschat.messages.update');
    Route::delete('wimschat/messages/{message}', [Controllers\WimsChat\WimsChatMessageController::class, 'destroy'])->whereNumber('message')->middleware('throttle:60,1,wimschat.messages.destroy')->name('wimschat.messages.destroy');
    Route::post('wimschat/messages/{message}/react', [Controllers\WimsChat\WimsChatMessageController::class, 'react'])->whereNumber('message')->middleware('throttle:120,1,wimschat.messages.react')->name('wimschat.messages.react');
    Route::post('wimschat/messages/{message}/pin', [Controllers\WimsChat\WimsChatMessageController::class, 'pin'])->whereNumber('message')->middleware('throttle:60,1,wimschat.messages.pin')->name('wimschat.messages.pin');
    Route::post('wimschat/messages/{message}/forward', [Controllers\WimsChat\WimsChatMessageController::class, 'forward'])->whereNumber('message')->middleware('throttle:30,1,wimschat.messages.forward')->name('wimschat.messages.forward');
    Route::get('wimschat/attachments/{attachment}/thumb', [Controllers\WimsChat\WimsChatAttachmentController::class, 'thumb'])->whereNumber('attachment')->name('wimschat.attachments.thumb');
    Route::get('wimschat/attachments/{attachment}/view', [Controllers\WimsChat\WimsChatAttachmentController::class, 'view'])->whereNumber('attachment')->name('wimschat.attachments.view');
    Route::get('wimschat/attachments/{attachment}/download', [Controllers\WimsChat\WimsChatAttachmentController::class, 'download'])->whereNumber('attachment')->name('wimschat.attachments.download');
    Route::delete('wimschat/attachments/{attachment}', [Controllers\WimsChat\WimsChatAttachmentController::class, 'destroy'])->whereNumber('attachment')->name('wimschat.attachments.destroy');

    Route::get('company-assets/full-report/pdf', [Controllers\Asset\AssetController::class, 'pdfFull'])->name('assets.full-report.pdf');
    Route::get('company-assets/dashboard/print', [Controllers\Asset\AssetDashboardController::class, 'print'])->name('assets.dashboard.print');
    Route::get('company-assets/dashboard/pdf', [Controllers\Asset\AssetDashboardController::class, 'pdf'])->name('assets.dashboard.pdf');
    Route::get('company-assets/export', [Controllers\Asset\AssetController::class, 'export'])->name('assets.export');
    Route::get('company-assets/dashboard', [Controllers\Asset\AssetDashboardController::class, 'index'])->name('assets.dashboard');
    Route::get('company-assets/movement-report', [Controllers\Asset\AssetController::class, 'movementReport'])->name('assets.movement-report');
    Route::get('company-assets/movement-report/print', [Controllers\Asset\AssetController::class, 'movementReportPrint'])->name('assets.movement-report.print');
    Route::get('company-assets/movement-report/pdf', [Controllers\Asset\AssetController::class, 'movementReportPdf'])->name('assets.movement-report.pdf');
    Route::get('company-assets/depreciation-report', [Controllers\Asset\AssetDepreciationController::class, 'report'])->name('assets.depreciation-report');
    Route::get('company-assets/depreciation-report/print', [Controllers\Asset\AssetDepreciationController::class, 'reportPrint'])->name('assets.depreciation-report.print');
    Route::get('company-assets/depreciation-report/pdf', [Controllers\Asset\AssetDepreciationController::class, 'reportPdf'])->name('assets.depreciation-report.pdf');
    Route::get('company-assets/reports', [Controllers\Asset\AssetController::class, 'reportsHub'])->name('assets.reports');
    Route::get('company-assets/categories', [Controllers\Asset\AssetCategoryController::class, 'index'])->name('assets.categories.index');
    Route::post('company-assets/categories', [Controllers\Asset\AssetCategoryController::class, 'store'])->name('assets.categories.store');
    Route::patch('company-assets/categories/{asset_category}', [Controllers\Asset\AssetCategoryController::class, 'update'])->name('assets.categories.update');
    Route::delete('company-assets/categories/{asset_category}', [Controllers\Asset\AssetCategoryController::class, 'destroy'])->name('assets.categories.destroy');
    Route::resource('company-assets', Controllers\Asset\AssetController::class)->parameters(['company-assets' => 'asset'])->names('assets');
    Route::post('company-assets/{asset}/convert-to-item', [Controllers\Asset\AssetController::class, 'convertToItem'])->name('assets.convert-to-item');
    Route::patch('company-assets/{asset}/assign', [Controllers\Asset\AssetController::class, 'assign'])->name('assets.assign');
    Route::post('company-assets/{asset}/check-out', [Controllers\Asset\AssetController::class, 'checkOut'])->name('assets.check-out');
    Route::post('company-assets/{asset}/check-in', [Controllers\Asset\AssetController::class, 'checkIn'])->name('assets.check-in');
    Route::post('company-assets/{asset}/transfer', [Controllers\Asset\AssetController::class, 'transfer'])->name('assets.transfer');
    Route::patch('company-assets/{asset}/status', [Controllers\Asset\AssetController::class, 'updateStatus'])->name('assets.status');
    Route::post('company-assets/{asset}/maintenance', [Controllers\Asset\AssetMaintenanceController::class, 'store'])->name('assets.maintenance.store');
    Route::patch('company-assets/{asset}/maintenance/{asset_maintenance_record}', [Controllers\Asset\AssetMaintenanceController::class, 'update'])->name('assets.maintenance.update');
    Route::delete('company-assets/{asset}/maintenance/{asset_maintenance_record}', [Controllers\Asset\AssetMaintenanceController::class, 'destroy'])->name('assets.maintenance.destroy');
    Route::post('company-assets/{asset}/depreciation', [Controllers\Asset\AssetDepreciationController::class, 'store'])->name('assets.depreciation.store');
    Route::post('company-assets/{asset}/appreciation', [Controllers\Asset\AssetAppreciationController::class, 'store'])->name('assets.appreciation.store');
    Route::get('company-assets/{asset}/dispose', [Controllers\Asset\AssetDisposalController::class, 'create'])->name('assets.dispose.create');
    Route::post('company-assets/{asset}/dispose', [Controllers\Asset\AssetDisposalController::class, 'store'])->name('assets.dispose.store');
    Route::get('company-assets/{asset}/print', [Controllers\Asset\AssetController::class, 'print'])->name('assets.print');
    Route::get('company-assets/{asset}/pdf', [Controllers\Asset\AssetController::class, 'pdf'])->name('assets.pdf');
    Route::post('company-assets/{asset}/documents', [Controllers\Asset\AssetController::class, 'uploadDocument'])->name('assets.documents.store');
    Route::get('company-assets/{asset}/attachments/{attachment}/download', [Controllers\Asset\AssetController::class, 'downloadAttachment'])->name('assets.attachments.download');
    Route::delete('company-assets/{asset}/attachments/{attachment}', [Controllers\Asset\AssetController::class, 'destroyAttachment'])->name('assets.attachments.destroy');
    Route::delete('company-assets/{asset}/photo', [Controllers\Asset\AssetController::class, 'destroyPhoto'])->name('assets.photo.destroy');

    Route::get('compliance-records/print-list', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'printList'])->name('compliance-records.print-list');
    Route::get('compliance-records/pdf-list', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'pdfList'])->name('compliance-records.pdf-list');
    Route::get('compliance-records/export', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'export'])->name('compliance-records.export');
    Route::get('compliance-records/dashboard', [Controllers\ComplianceManagement\ComplianceDashboardController::class, 'index'])->name('compliance-records.dashboard');
    Route::get('compliance-records/calendar', [Controllers\ComplianceManagement\ComplianceCalendarController::class, 'index'])->name('compliance-records.calendar');
    Route::get('compliance-records/calendar/widget', [Controllers\ComplianceManagement\ComplianceCalendarController::class, 'widget'])->name('compliance-records.calendar.widget');
    Route::get('compliance-records/calendar/due-count', [Controllers\ComplianceManagement\ComplianceCalendarController::class, 'dueCount'])->name('compliance-records.calendar.due-count');
    Route::get('compliance-records/reports', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'reportsHub'])->name('compliance-records.reports');
    Route::get('compliance-records/categories', [Controllers\ComplianceManagement\ComplianceCategoryController::class, 'index'])->name('compliance-records.categories.index');
    Route::post('compliance-records/categories', [Controllers\ComplianceManagement\ComplianceCategoryController::class, 'store'])->name('compliance-records.categories.store');
    Route::patch('compliance-records/categories/{compliance_category}', [Controllers\ComplianceManagement\ComplianceCategoryController::class, 'update'])->name('compliance-records.categories.update');
    Route::delete('compliance-records/categories/{compliance_category}', [Controllers\ComplianceManagement\ComplianceCategoryController::class, 'destroy'])->name('compliance-records.categories.destroy');
    Route::resource('compliance-records', Controllers\ComplianceManagement\ComplianceRecordController::class)->parameters(['compliance-records' => 'compliance_record']);
    Route::post('compliance-records/{compliance_record}/documents', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'uploadDocument'])->name('compliance-records.documents.store');
    Route::get('compliance-records/{compliance_record}/attachments/{attachment}/download', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'downloadAttachment'])->name('compliance-records.attachments.download');
    Route::delete('compliance-records/{compliance_record}/attachments/{attachment}', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'destroyAttachment'])->name('compliance-records.attachments.destroy');
    Route::delete('compliance-records/{compliance_record}/photo', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'destroyPhoto'])->name('compliance-records.photo.destroy');
    Route::get('compliance-records/{compliance_record}/print', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'print'])->name('compliance-records.print');
    Route::get('compliance-records/{compliance_record}/pdf', [Controllers\ComplianceManagement\ComplianceRecordController::class, 'pdf'])->name('compliance-records.pdf');
    Route::post('compliance-records/{compliance_record}/renewal/start', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'start'])->name('compliance-records.renewal.start');
    Route::patch('compliance-records/{compliance_record}/renewal/submit', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'submit'])->name('compliance-records.renewal.submit');
    Route::patch('compliance-records/{compliance_record}/renewal/approve', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'approve'])->name('compliance-records.renewal.approve');
    Route::post('compliance-records/{compliance_record}/renewal/complete', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'complete'])->name('compliance-records.renewal.complete');
    Route::patch('compliance-records/{compliance_record}/renewal/reject', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'reject'])->name('compliance-records.renewal.reject');
    Route::patch('compliance-records/{compliance_record}/renewal/cancel', [Controllers\ComplianceManagement\ComplianceRenewalController::class, 'cancel'])->name('compliance-records.renewal.cancel');

    // Digital Attendance — self-service (clock in/out, own history/calendar).
    Route::get('digital-attendance', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'show'])->name('digital-attendance.show');
    Route::get('digital-attendance/widget', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'widget'])->name('digital-attendance.widget');
    Route::get('digital-attendance/history', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'history'])->name('digital-attendance.history');
    Route::get('digital-attendance/history/{record}/sessions', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'sessions'])->name('digital-attendance.history-sessions');
    Route::get('digital-attendance/my-print', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'myPrint'])->name('digital-attendance.my-print');
    Route::get('digital-attendance/my-pdf', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'myPdf'])->name('digital-attendance.my-pdf');
    Route::get('digital-attendance/calendar', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'calendar'])->name('digital-attendance.calendar');
    Route::post('digital-attendance/clock-in', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'clockIn'])->name('digital-attendance.clock-in');
    Route::post('digital-attendance/clock-out', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'clockOut'])->name('digital-attendance.clock-out');
    Route::post('digital-attendance/heartbeat', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'heartbeat'])->name('digital-attendance.heartbeat');
    Route::get('digital-attendance/preview-location', [Controllers\DigitalAttendance\DigitalAttendanceController::class, 'previewLocation'])->name('digital-attendance.preview-location');

    // Digital Attendance — reports (registered before /admin/records/{record} so literal segments never get swallowed as an id).
    Route::get('digital-attendance/reports', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'hub'])->name('digital-attendance-reports.hub');
    Route::get('digital-attendance/reports/daily', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'daily'])->name('digital-attendance-reports.daily');
    Route::get('digital-attendance/reports/weekly', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'weekly'])->name('digital-attendance-reports.weekly');
    Route::get('digital-attendance/reports/custom', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'custom'])->name('digital-attendance-reports.custom');
    Route::get('digital-attendance/reports/location', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'location'])->name('digital-attendance-reports.location');
    Route::get('digital-attendance/reports/device', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'device'])->name('digital-attendance-reports.device');
    Route::get('digital-attendance/reports/export', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'export'])->name('digital-attendance-reports.export');
    Route::get('digital-attendance/reports/print', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'print'])->name('digital-attendance-reports.print');
    Route::get('digital-attendance/reports/pdf', [Controllers\DigitalAttendance\DigitalAttendanceReportController::class, 'pdf'])->name('digital-attendance-reports.pdf');

    // Digital Attendance — settings.
    Route::get('digital-attendance/settings', [Controllers\DigitalAttendance\DigitalAttendanceSettingsController::class, 'edit'])->name('digital-attendance-settings.edit');
    Route::put('digital-attendance/settings', [Controllers\DigitalAttendance\DigitalAttendanceSettingsController::class, 'update'])->name('digital-attendance-settings.update');

    // Digital Attendance — admin (dashboard, live presence, records + corrections/voids).
    Route::get('digital-attendance/admin/dashboard', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'dashboard'])->name('digital-attendance-admin.dashboard');
    Route::get('digital-attendance/admin/currently-clocked-in', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'currentlyClockedIn'])->name('digital-attendance-admin.currently-clocked-in');
    Route::get('digital-attendance/admin/currently-online', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'currentlyOnline'])->name('digital-attendance-admin.currently-online');
    Route::post('digital-attendance/admin/buzz', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'buzz'])->name('digital-attendance-admin.buzz');
    Route::get('digital-attendance/admin/records', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'records'])->name('digital-attendance-admin.records');
    Route::get('digital-attendance/admin/records/{record}', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'show'])->name('digital-attendance-admin.show');
    Route::put('digital-attendance/admin/records/{record}/correct', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'correct'])->name('digital-attendance-admin.correct');
    Route::post('digital-attendance/admin/records/{record}/void', [Controllers\DigitalAttendance\DigitalAttendanceAdminController::class, 'void'])->name('digital-attendance-admin.void');

    // Must be registered before Route::resource('staffs', ...) below, or its
    // GET staffs/{staff} show route would swallow "print-list"/"pdf-list" as
    // an id.
    Route::get('staffs/contracts/calendar', [Controllers\StaffContractCalendarController::class, 'index'])->name('staffs.contracts.calendar');
    Route::get('staffs/contracts/summary', [Controllers\StaffContractCalendarController::class, 'summary'])->name('staffs.contracts.summary');
    Route::get('staffs/contracts/calendar/events', [Controllers\StaffContractCalendarController::class, 'events'])->name('staffs.contracts.calendar.events');
    Route::post('staffs/{staff}/contracts', [Controllers\StaffContractController::class, 'store'])->name('staffs.contracts.store');
    Route::put('staffs/{staff}/contracts/{contract}', [Controllers\StaffContractController::class, 'update'])->name('staffs.contracts.update');
    Route::post('staffs/{staff}/contracts/{contract}/renew', [Controllers\StaffContractController::class, 'renew'])->name('staffs.contracts.renew');
    Route::post('staffs/{staff}/contracts/{contract}/stop', [Controllers\StaffContractController::class, 'stop'])->name('staffs.contracts.stop');
    Route::delete('staffs/{staff}/contracts/{contract}', [Controllers\StaffContractController::class, 'destroy'])->name('staffs.contracts.destroy');
    Route::get('staffs/{staff}/contracts/{contract}/print', [Controllers\StaffContractController::class, 'print'])->name('staffs.contracts.print');
    Route::get('staffs/{staff}/contracts/{contract}/pdf', [Controllers\StaffContractController::class, 'pdf'])->name('staffs.contracts.pdf');
    Route::get('staffs/{staff}/contracts/{contract}/attachments/{attachment}/download', [Controllers\StaffContractController::class, 'downloadAttachment'])->name('staffs.contracts.attachments.download');
    Route::delete('staffs/{staff}/contracts/{contract}/attachments/{attachment}', [Controllers\StaffContractController::class, 'destroyAttachment'])->name('staffs.contracts.attachments.destroy');
    Route::post('staffs/{staff}/retire', [Controllers\StaffController::class, 'retire'])->name('staffs.retire');
    Route::post('staffs/{staff}/reinstate', [Controllers\StaffController::class, 'reinstate'])->name('staffs.reinstate');
    Route::get('staffs/print-list', [Controllers\StaffController::class, 'printList'])->name('staffs.print-list');
    Route::get('staffs/pdf-list', [Controllers\StaffController::class, 'pdfList'])->name('staffs.pdf-list');
    Route::get('staffs/{staff}/print', [Controllers\StaffController::class, 'print'])->name('staffs.print');
    Route::get('staffs/{staff}/pdf', [Controllers\StaffController::class, 'pdf'])->name('staffs.pdf');
    Route::delete('staffs/{staff}/photo', [Controllers\StaffController::class, 'destroyPhoto'])->name('staffs.photo.destroy');
    Route::get('staffs/{staff}/attachments/{attachment}/download', [Controllers\StaffController::class, 'downloadAttachment'])->name('staffs.attachments.download');
    Route::delete('staffs/{staff}/attachments/{attachment}', [Controllers\StaffController::class, 'destroyAttachment'])->name('staffs.attachments.destroy');
    // Link a login to an employee record / create the employee record from the login.
    Route::post('users/{user}/staff/link', [Controllers\UserStaffLinkController::class, 'link'])->name('users.staff.link');
    Route::post('users/{user}/staff/convert', [Controllers\UserStaffLinkController::class, 'convert'])->name('users.staff.convert');
    Route::delete('users/{user}/staff', [Controllers\UserStaffLinkController::class, 'unlink'])->name('users.staff.unlink');
    Route::resource('staffs', Controllers\StaffController::class)->parameters(['staffs' => 'staff']);

    // Funds Allocation: dated allocation sheets (what is paid out, by category and payment method), approval,
    // payer tick-off, reports. Route names all start "fund-allocations." so FeatureGate's route_prefixes covers them.
    Route::prefix('fund-allocations')->name('fund-allocations.')->group(function () {
        $c = Controllers\FundAllocation\FundAllocationController::class;
        $s = Controllers\FundAllocation\FundAllocationSettingsController::class;
        $r = Controllers\FundAllocation\FundAllocationReportController::class;

        Route::get('settings', [$s, 'index'])->name('settings');
        Route::post('settings/accounts', [$s, 'storeAccount'])->name('settings.accounts.store');
        Route::put('settings/accounts/{fund_account}', [$s, 'updateAccount'])->name('settings.accounts.update');
        Route::delete('settings/accounts/{fund_account}', [$s, 'destroyAccount'])->name('settings.accounts.destroy');
        Route::post('settings/categories', [$s, 'storeCategory'])->name('settings.categories.store');
        Route::put('settings/categories/{fund_category}', [$s, 'updateCategory'])->name('settings.categories.update');
        Route::delete('settings/categories/{fund_category}', [$s, 'destroyCategory'])->name('settings.categories.destroy');
        Route::post('settings/methods', [$s, 'storeMethod'])->name('settings.methods.store');
        Route::put('settings/methods/{fund_payment_method}', [$s, 'updateMethod'])->name('settings.methods.update');
        Route::delete('settings/methods/{fund_payment_method}', [$s, 'destroyMethod'])->name('settings.methods.destroy');
        Route::post('settings/reorder', [$s, 'reorder'])->name('settings.reorder');

        Route::get('reports', [$r, 'index'])->name('reports');
        Route::get('reports/export', [$r, 'export'])->name('reports.export');
        Route::get('reports/print', [$r, 'print'])->name('reports.print');

        Route::match(['get', 'post'], 'vouchers/search', [$c, 'voucherSearch'])->name('vouchers.search');

        $t = Controllers\FundAllocation\FundAllocationTemplateController::class;
        Route::get('template', [$t, 'show'])->name('template');
        Route::put('template', [$t, 'update'])->name('template.update');
        Route::post('template/generate', [$t, 'generate'])->name('template.generate');
        Route::get('template/print', [$t, 'print'])->name('template.print');
        Route::get('template/pdf', [$t, 'pdf'])->name('template.pdf');

        Route::get('/', [$c, 'index'])->name('index');
        Route::get('create', [$c, 'create'])->name('create');
        Route::post('/', [$c, 'store'])->name('store');
        Route::get('{fund_allocation}', [$c, 'show'])->name('show');
        Route::get('{fund_allocation}/edit', [$c, 'edit'])->name('edit');
        Route::put('{fund_allocation}', [$c, 'update'])->name('update');
        Route::delete('{fund_allocation}', [$c, 'destroy'])->name('destroy');
        Route::get('{fund_allocation}/print', [$c, 'print'])->name('print');
        Route::get('{fund_allocation}/pdf', [$c, 'pdf'])->name('pdf');
        Route::post('{fund_allocation}/duplicate', [$c, 'duplicate'])->name('duplicate');
        Route::patch('{fund_allocation}/submit', [$c, 'submit'])->name('submit');
        Route::patch('{fund_allocation}/approve', [$c, 'approve'])->name('approve');
        Route::patch('{fund_allocation}/clear', [$c, 'clear'])->name('clear');
        Route::post('{fund_allocation}/pay-bulk', [$c, 'payBulk'])->name('pay-bulk');
        Route::post('{fund_allocation}/generate-vouchers', [$c, 'generateVouchers'])->name('generate-vouchers');
        Route::patch('{fund_allocation}/reject', [$c, 'reject'])->name('reject');
        Route::patch('{fund_allocation}/reassign', [$c, 'reassign'])->name('reassign');
        Route::patch('{fund_allocation}/cancel', [$c, 'cancel'])->name('cancel');
        Route::patch('{fund_allocation}/reopen', [$c, 'reopen'])->name('reopen');
        Route::patch('lines/{fund_allocation_item_line}/pay', [$c, 'payLine'])->name('lines.pay');
        Route::patch('lines/{fund_allocation_item_line}/unpay', [$c, 'unpayLine'])->name('lines.unpay');
        Route::patch('items/{fund_allocation_item}/voucher', [$c, 'linkVoucher'])->name('items.voucher');
    });

    // Salary Management — built on top of Staff (the master employee
    // source); route names all start "salary." so FeatureGate's
    // route-prefix matching and LogDownloadPrintActions' name-heuristic
    // both work the same way every other module's print/pdf/export routes
    // already do, with no extra per-route code.
    Route::prefix('salary')->name('salary.')->group(function () {
        Route::get('dashboard', [Controllers\Salary\SalaryDashboardController::class, 'index'])->name('dashboard.index');
        Route::get('analytics', [Controllers\Salary\SalaryAnalyticsController::class, 'index'])->name('analytics');

        Route::prefix('periods')->name('periods.')->group(function () {
            Route::get('/', [Controllers\Salary\SalaryPeriodController::class, 'index'])->name('index');
            Route::get('create', [Controllers\Salary\SalaryPeriodController::class, 'create'])->name('create');
            Route::post('/', [Controllers\Salary\SalaryPeriodController::class, 'store'])->name('store');
            Route::get('{salary_period}', [Controllers\Salary\SalaryPeriodController::class, 'show'])->name('show');
            Route::delete('{salary_period}', [Controllers\Salary\SalaryPeriodController::class, 'destroy'])->name('destroy');
            Route::patch('{salary_period}/submit', [Controllers\Salary\SalaryPeriodController::class, 'submit'])->name('submit');
            Route::patch('{salary_period}/approve', [Controllers\Salary\SalaryPeriodController::class, 'approve'])->name('approve');
            Route::patch('{salary_period}/reject', [Controllers\Salary\SalaryPeriodController::class, 'reject'])->name('reject');
            Route::patch('{salary_period}/cancel', [Controllers\Salary\SalaryPeriodController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('preparation')->name('preparation.')->group(function () {
            Route::get('individual', [Controllers\Salary\SalaryPreparationController::class, 'individual'])->name('individual');
            Route::post('individual', [Controllers\Salary\SalaryPreparationController::class, 'storeIndividual'])->name('individual.store');
            Route::get('bulk', [Controllers\Salary\SalaryPreparationController::class, 'bulk'])->name('bulk');
            Route::post('bulk', [Controllers\Salary\SalaryPreparationController::class, 'storeBulk'])->name('bulk.store');
            Route::delete('records/{salary_record}', [Controllers\Salary\SalaryPreparationController::class, 'destroy'])->name('records.destroy');
        });

        Route::prefix('records')->name('records.')->group(function () {
            Route::post('{salary_record}/adjustments', [Controllers\Salary\SalaryOutstandingController::class, 'store'])->name('adjustments.store');
            Route::post('{salary_record}/items', [Controllers\Salary\SalaryPreparationController::class, 'storeItem'])->name('items.store');
            Route::delete('{salary_record}/items/{salary_record_item}', [Controllers\Salary\SalaryPreparationController::class, 'destroyItem'])->name('items.destroy');
        });

        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('{salary_record}/pay', [Controllers\Salary\SalaryPaymentController::class, 'pay'])->name('pay');
            Route::post('{salary_record}/pay', [Controllers\Salary\SalaryPaymentController::class, 'store'])->name('pay.store');
            Route::patch('{salary_payment}/reverse', [Controllers\Salary\SalaryPaymentController::class, 'reverse'])->name('reverse');
            Route::post('periods/{salary_period}/bulk-pay', [Controllers\Salary\SalaryPaymentController::class, 'bulkStore'])->name('bulk');
        });

        Route::prefix('vouchers')->name('vouchers.')->group(function () {
            Route::get('/', [Controllers\Salary\SalaryVoucherController::class, 'index'])->name('index');
            Route::post('records/{salary_record}/individual', [Controllers\Salary\SalaryVoucherController::class, 'generateIndividual'])->name('generate.individual');
            Route::post('periods/{salary_period}/bulk', [Controllers\Salary\SalaryVoucherController::class, 'generateBulk'])->name('generate.bulk');
            Route::get('{salary_voucher}', [Controllers\Salary\SalaryVoucherController::class, 'show'])->name('show');
            Route::get('{salary_voucher}/print', [Controllers\Salary\SalaryVoucherController::class, 'print'])->name('print');
            Route::get('{salary_voucher}/pdf', [Controllers\Salary\SalaryVoucherController::class, 'pdf'])->name('pdf');
        });

        Route::prefix('advances')->name('advances.')->group(function () {
            Route::get('/', [Controllers\Salary\SalaryAdvanceController::class, 'index'])->name('index');
            Route::get('create', [Controllers\Salary\SalaryAdvanceController::class, 'create'])->name('create');
            Route::post('/', [Controllers\Salary\SalaryAdvanceController::class, 'store'])->name('store');
            Route::get('{salary_advance}', [Controllers\Salary\SalaryAdvanceController::class, 'show'])->name('show');
            Route::patch('{salary_advance}', [Controllers\Salary\SalaryAdvanceController::class, 'update'])->name('update');
            Route::delete('{salary_advance}', [Controllers\Salary\SalaryAdvanceController::class, 'destroy'])->name('destroy');
            Route::patch('{salary_advance}/approve', [Controllers\Salary\SalaryAdvanceController::class, 'approve'])->name('approve');
            Route::patch('{salary_advance}/reject', [Controllers\Salary\SalaryAdvanceController::class, 'reject'])->name('reject');
            Route::patch('{salary_advance}/cancel', [Controllers\Salary\SalaryAdvanceController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('loans')->name('loans.')->group(function () {
            Route::get('/', [Controllers\Salary\SalaryLoanController::class, 'index'])->name('index');
            Route::get('create', [Controllers\Salary\SalaryLoanController::class, 'create'])->name('create');
            Route::post('/', [Controllers\Salary\SalaryLoanController::class, 'store'])->name('store');
            Route::get('{salary_loan}', [Controllers\Salary\SalaryLoanController::class, 'show'])->name('show');
            Route::patch('{salary_loan}', [Controllers\Salary\SalaryLoanController::class, 'update'])->name('update');
            Route::delete('{salary_loan}', [Controllers\Salary\SalaryLoanController::class, 'destroy'])->name('destroy');
            Route::patch('{salary_loan}/approve', [Controllers\Salary\SalaryLoanController::class, 'approve'])->name('approve');
            Route::patch('{salary_loan}/reject', [Controllers\Salary\SalaryLoanController::class, 'reject'])->name('reject');
            Route::patch('{salary_loan}/cancel', [Controllers\Salary\SalaryLoanController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('opening-balances')->name('opening-balances.')->group(function () {
            Route::get('/', [Controllers\Salary\SalaryOpeningBalanceController::class, 'index'])->name('index');
            Route::get('create', [Controllers\Salary\SalaryOpeningBalanceController::class, 'create'])->name('create');
            Route::get('bulk', [Controllers\Salary\SalaryOpeningBalanceController::class, 'bulk'])->name('bulk');
            Route::post('bulk', [Controllers\Salary\SalaryOpeningBalanceController::class, 'bulkStore'])->name('bulk.store');
            Route::post('/', [Controllers\Salary\SalaryOpeningBalanceController::class, 'store'])->name('store');
            Route::get('{salary_opening_balance}', [Controllers\Salary\SalaryOpeningBalanceController::class, 'edit'])->name('edit');
            Route::patch('{salary_opening_balance}', [Controllers\Salary\SalaryOpeningBalanceController::class, 'update'])->name('update');
            Route::delete('{salary_opening_balance}', [Controllers\Salary\SalaryOpeningBalanceController::class, 'destroy'])->name('destroy');
            Route::post('{salary_opening_balance}/apply', [Controllers\Salary\SalaryOpeningBalanceController::class, 'apply'])->name('apply');
        });

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('register', [Controllers\Salary\SalaryReportController::class, 'register'])->name('register');
            Route::get('register/print', [Controllers\Salary\SalaryReportController::class, 'registerPrint'])->name('register.print');
            Route::get('register/pdf', [Controllers\Salary\SalaryReportController::class, 'registerPdf'])->name('register.pdf');
            Route::get('ledger/{staff}', [Controllers\Salary\SalaryReportController::class, 'ledger'])->name('ledger');
            Route::get('ledger/{staff}/print', [Controllers\Salary\SalaryReportController::class, 'ledgerPrint'])->name('ledger.print');
            Route::get('ledger/{staff}/pdf', [Controllers\Salary\SalaryReportController::class, 'ledgerPdf'])->name('ledger.pdf');
            Route::get('statement/{staff}', [Controllers\Salary\SalaryReportController::class, 'statement'])->name('statement');
            Route::get('statement/{staff}/print', [Controllers\Salary\SalaryReportController::class, 'statementPrint'])->name('statement.print');
            Route::get('statement/{staff}/pdf', [Controllers\Salary\SalaryReportController::class, 'statementPdf'])->name('statement.pdf');
            Route::get('payment-history', [Controllers\Salary\SalaryReportController::class, 'paymentHistory'])->name('payment-history');
            Route::get('payment-history/print', [Controllers\Salary\SalaryReportController::class, 'paymentHistoryPrint'])->name('payment-history.print');
            Route::get('payment-history/pdf', [Controllers\Salary\SalaryReportController::class, 'paymentHistoryPdf'])->name('payment-history.pdf');
            Route::get('outstanding', [Controllers\Salary\SalaryReportController::class, 'outstanding'])->name('outstanding');
            Route::get('outstanding/print', [Controllers\Salary\SalaryReportController::class, 'outstandingPrint'])->name('outstanding.print');
            Route::get('outstanding/pdf', [Controllers\Salary\SalaryReportController::class, 'outstandingPdf'])->name('outstanding.pdf');
            Route::get('advances', [Controllers\Salary\SalaryReportController::class, 'advances'])->name('advances');
            Route::get('advances/print', [Controllers\Salary\SalaryReportController::class, 'advancesPrint'])->name('advances.print');
            Route::get('advances/pdf', [Controllers\Salary\SalaryReportController::class, 'advancesPdf'])->name('advances.pdf');
            Route::get('loans', [Controllers\Salary\SalaryReportController::class, 'loans'])->name('loans');
            Route::get('loans/print', [Controllers\Salary\SalaryReportController::class, 'loansPrint'])->name('loans.print');
            Route::get('loans/pdf', [Controllers\Salary\SalaryReportController::class, 'loansPdf'])->name('loans.pdf');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [Controllers\Salary\SalarySettingsController::class, 'index'])->name('index');
            Route::post('components', [Controllers\Salary\SalarySettingsController::class, 'storeComponent'])->name('components.store');
            Route::patch('components/{salary_component}', [Controllers\Salary\SalarySettingsController::class, 'updateComponent'])->name('components.update');
            Route::delete('components/{salary_component}', [Controllers\Salary\SalarySettingsController::class, 'destroyComponent'])->name('components.destroy');
            Route::put('statutory', [Controllers\Salary\SalarySettingsController::class, 'updateStatutorySettings'])->name('statutory.update');
        });
    });

    Route::get('daily-site-reports/export', [Controllers\DailySiteReportController::class, 'export'])->name('daily-site-reports.export');
    Route::get('daily-site-reports/print', [Controllers\DailySiteReportController::class, 'printList'])->name('daily-site-reports.print-list');
    Route::get('daily-site-reports/pdf', [Controllers\DailySiteReportController::class, 'pdfList'])->name('daily-site-reports.pdf-list');
    Route::get('daily-site-reports/compliance', [Controllers\SiteReportComplianceController::class, 'index'])->name('daily-site-reports.compliance.index');
    Route::get('daily-site-reports/compliance/print', [Controllers\SiteReportComplianceController::class, 'print'])->name('daily-site-reports.compliance.print');
    Route::get('daily-site-reports/compliance/pdf', [Controllers\SiteReportComplianceController::class, 'pdf'])->name('daily-site-reports.compliance.pdf');
    Route::get('daily-site-reports/compliance/export', [Controllers\SiteReportComplianceController::class, 'export'])->name('daily-site-reports.compliance.export');
    Route::resource('daily-site-reports', Controllers\DailySiteReportController::class)
        ->parameters(['daily-site-reports' => 'daily_site_report'])
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::patch('daily-site-reports/{daily_site_report}/archive', [Controllers\DailySiteReportController::class, 'archive'])
        ->name('daily-site-reports.archive');
    Route::get('daily-site-reports/{daily_site_report}/print', [Controllers\DailySiteReportController::class, 'print'])
        ->name('daily-site-reports.print');
    Route::get('daily-site-reports/{daily_site_report}/pdf', [Controllers\DailySiteReportController::class, 'pdf'])
        ->name('daily-site-reports.pdf');
    Route::get('daily-site-reports/{daily_site_report}/attachments/{attachment}/download', [Controllers\DailySiteReportController::class, 'downloadAttachment'])
        ->name('daily-site-reports.attachments.download');

    // Issue & Return Module
    Route::get('issue-returns/dashboard', [Controllers\IssueReturnDashboardController::class, 'index'])->name('issue-returns.dashboard');
    Route::resource('issue-materials', Controllers\IssueMaterialController::class)
        ->parameters(['issue-materials' => 'issueMaterial'])
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::get('issue-materials/{issueMaterial}/print', [Controllers\IssueMaterialController::class, 'print'])->name('issue-materials.print');
    Route::get('issue-materials/{issueMaterial}/pdf', [Controllers\IssueMaterialController::class, 'pdf'])->name('issue-materials.pdf');
    Route::get('issue-materials/{issueMaterial}/attachments/{attachment}/download', [Controllers\IssueMaterialController::class, 'downloadAttachment'])
        ->name('issue-materials.attachments.download');
    Route::delete('issue-materials/{issueMaterial}/attachments/{attachment}', [Controllers\IssueMaterialController::class, 'destroyAttachment'])
        ->name('issue-materials.attachments.destroy');

    Route::get('return-materials', [Controllers\ReturnMaterialController::class, 'index'])->name('return-materials.index');
    Route::get('return-materials/{issueMaterial}/create', [Controllers\ReturnMaterialController::class, 'create'])->name('return-materials.create');
    Route::post('return-materials/{issueMaterial}', [Controllers\ReturnMaterialController::class, 'store'])->name('return-materials.store');

    Route::get('issue-return-reports', [Controllers\IssueReturnReportController::class, 'index'])->name('issue-return-reports.index');

    // Single-record print/pdf — must be registered before extendedResources()
    // below, or its GET {name}/{id} show route would swallow "print"/"pdf"
    // as the id parameter (same reasoning as staffs.print/staffs.pdf).
    Route::get('checkins/{checkin}/print', [Controllers\CheckinController::class, 'print'])->name('checkins.print');
    Route::get('checkins/{checkin}/pdf', [Controllers\CheckinController::class, 'pdf'])->name('checkins.pdf');
    Route::get('checkouts/{checkout}/print', [Controllers\CheckoutController::class, 'print'])->name('checkouts.print');
    Route::get('checkouts/{checkout}/pdf', [Controllers\CheckoutController::class, 'pdf'])->name('checkouts.pdf');
    // the value report of an adjustment / super adjustment (unit prices, totals in value): names carry print / pdf / export so each use lands in the Download / Print log
    Route::get('adjustments/{adjustment}/value-report/print', [Controllers\ValueReportController::class, 'adjustmentPrint'])->whereNumber('adjustment')->middleware('throttle:60,1,adjustments.value-report.print')->name('adjustments.value-report.print');
    Route::get('adjustments/{adjustment}/value-report/pdf', [Controllers\ValueReportController::class, 'adjustmentPdf'])->whereNumber('adjustment')->middleware('throttle:30,1,adjustments.value-report.pdf')->name('adjustments.value-report.pdf');
    Route::get('adjustments/{adjustment}/value-report/export', [Controllers\ValueReportController::class, 'adjustmentExport'])->whereNumber('adjustment')->middleware('throttle:30,1,adjustments.value-report.export')->name('adjustments.value-report.export');
    Route::get('super-adjustments/{super_adjustment}/value-report/print', [Controllers\ValueReportController::class, 'superPrint'])->whereNumber('super_adjustment')->middleware('throttle:60,1,super-adjustments.value-report.print')->name('super-adjustments.value-report.print');
    Route::get('super-adjustments/{super_adjustment}/value-report/pdf', [Controllers\ValueReportController::class, 'superPdf'])->whereNumber('super_adjustment')->middleware('throttle:30,1,super-adjustments.value-report.pdf')->name('super-adjustments.value-report.pdf');
    Route::get('super-adjustments/{super_adjustment}/value-report/export', [Controllers\ValueReportController::class, 'superExport'])->whereNumber('super_adjustment')->middleware('throttle:30,1,super-adjustments.value-report.export')->name('super-adjustments.value-report.export');
    Route::get('adjustments/{adjustment}/print', [Controllers\AdjustmentController::class, 'print'])->name('adjustments.print');
    Route::get('adjustments/{adjustment}/pdf', [Controllers\AdjustmentController::class, 'pdf'])->name('adjustments.pdf');
    Route::put('adjustments/{adjustment}/approve', [Controllers\AdjustmentController::class, 'approve'])->name('adjustments.approve');
    Route::put('adjustments/{adjustment}/status', [Controllers\AdjustmentController::class, 'changeStatus'])->name('adjustments.status');
    Route::put('adjustments/{adjustment}/reject', [Controllers\AdjustmentController::class, 'reject'])->name('adjustments.reject');
    Route::get('transfers/{transfer}/print', [Controllers\TransferController::class, 'print'])->name('transfers.print');
    Route::get('transfers/{transfer}/pdf', [Controllers\TransferController::class, 'pdf'])->name('transfers.pdf');
    // Gate Pass: same family as the Delivery Note, plus assigned driver / vehicle chosen at generation.
    Route::get('gate-passes/options', [Controllers\GatePassController::class, 'options'])->name('gate-passes.options');
    Route::post('transfers/{transfer}/gate-pass', [Controllers\GatePassController::class, 'storeForTransfer'])->name('transfers.gate-pass.store');
    Route::post('checkins/{checkin}/gate-pass', [Controllers\GatePassController::class, 'storeForCheckin'])->name('checkins.gate-pass.store');
    Route::post('transfers/{transfer}/transport-route', [Controllers\Transport\TransferRouteController::class, 'store'])->name('transfers.transport-route.store');
    Route::patch('gate-passes/{gate_pass}', [Controllers\GatePassController::class, 'update'])->name('gate-passes.update');
    Route::get('gate-passes/{gate_pass}', [Controllers\GatePassController::class, 'show'])->name('gate-passes.show');
    Route::get('gate-passes/{gate_pass}/print', [Controllers\GatePassController::class, 'print'])->name('gate-passes.print');
    Route::get('gate-passes/{gate_pass}/pdf', [Controllers\GatePassController::class, 'pdf'])->name('gate-passes.pdf');
    Route::post('transfers/{transfer}/delivery-note', [Controllers\DeliveryNoteController::class, 'storeForTransfer'])->name('transfers.delivery-note.store');
    Route::post('checkins/{checkin}/delivery-note', [Controllers\DeliveryNoteController::class, 'storeForCheckin'])->name('checkins.delivery-note.store');
    Route::get('delivery-notes/{delivery_note}', [Controllers\DeliveryNoteController::class, 'show'])->name('delivery-notes.show');
    Route::get('delivery-notes/{delivery_note}/print', [Controllers\DeliveryNoteController::class, 'print'])->name('delivery-notes.print');
    Route::get('delivery-notes/{delivery_note}/pdf', [Controllers\DeliveryNoteController::class, 'pdf'])->name('delivery-notes.pdf');

    Route::get('contacts/print-list', [Controllers\ContactController::class, 'printList'])->name('contacts.print-list');
    Route::get('contacts/pdf-list', [Controllers\ContactController::class, 'pdfList'])->name('contacts.pdf-list');

    Route::extendedResources([
        'items'       => Controllers\ItemController::class,
        'roles'       => Controllers\RoleController::class,
        'units'       => Controllers\UnitController::class,
        'users'       => Controllers\UserController::class,
        'contacts'    => Controllers\ContactController::class,
        'checkins'    => Controllers\CheckinController::class,
        'checkouts'   => Controllers\CheckoutController::class,
        'categories'  => Controllers\CategoryController::class,
        'transfers'   => Controllers\TransferController::class,
        'warehouses'  => Controllers\WarehouseController::class,
        'adjustments' => Controllers\AdjustmentController::class,
        'activities'  => Controllers\WorkActivityController::class,
    ]);

    // Super Adjustment — physical-count reconciliation that, once Super
    // Admin approves, generates real Adjustment(s) via AdjustmentController's
    // own PrepareOrder/AdjustmentEvent path (see SuperAdjustmentController).
    // item-stock must be registered before the resource() call below, or its
    // GET {resource}/{id} show route would swallow "item-stock" as the id
    // (same reasoning as the print/pdf routes above).
    Route::put('super-adjustments/bypass', [Controllers\SuperAdjustmentController::class, 'saveBypass'])
        ->middleware('throttle:10,1,super-adjustments.bypass')
        ->name('super-adjustments.bypass');
    Route::put('super-adjustments/approval-rule', [Controllers\SuperAdjustmentController::class, 'saveApprovalRule'])
        ->middleware('throttle:20,1,super-adjustments.approval-rule')
        ->name('super-adjustments.approval-rule');
    // Adjustment Session (a multi-day count of one warehouse made of several Super Adjustments): registered before the super-adjustments resource so "sessions" is not read as a record id
    Route::get('super-adjustments/sessions', [Controllers\AdjustmentSessionController::class, 'index'])->name('adjustment-sessions.index');
    Route::post('super-adjustments/sessions', [Controllers\AdjustmentSessionController::class, 'store'])->middleware('throttle:20,1')->name('adjustment-sessions.store');
    Route::get('super-adjustments/sessions/{session}', [Controllers\AdjustmentSessionController::class, 'show'])->name('adjustment-sessions.show')->whereNumber('session');
    Route::post('super-adjustments/sessions/{session}/end', [Controllers\AdjustmentSessionController::class, 'end'])->middleware('throttle:20,1')->name('adjustment-sessions.end')->whereNumber('session');
    Route::post('super-adjustments/sessions/{session}/halt', [Controllers\AdjustmentSessionController::class, 'halt'])->middleware('throttle:20,1')->name('adjustment-sessions.halt')->whereNumber('session');
    Route::get('super-adjustments/sessions/{session}/print', [Controllers\AdjustmentSessionController::class, 'print'])->name('adjustment-sessions.print')->whereNumber('session');
    Route::get('super-adjustments/sessions/{session}/pdf', [Controllers\AdjustmentSessionController::class, 'pdf'])->name('adjustment-sessions.pdf')->whereNumber('session');
    Route::get('super-adjustments/item-stock', [Controllers\SuperAdjustmentController::class, 'itemStock'])
        ->name('super-adjustments.item-stock');
    Route::get('super-adjustments/warehouse-stock', [Controllers\SuperAdjustmentController::class, 'warehouseStock'])
        ->name('super-adjustments.warehouse-stock');
    Route::get('super-adjustments/warehouse-categories', [Controllers\SuperAdjustmentController::class, 'warehouseCategories'])
        ->name('super-adjustments.warehouse-categories');
    Route::get('super-adjustments/category-stock', [Controllers\SuperAdjustmentController::class, 'categoryStock'])
        ->name('super-adjustments.category-stock');
    Route::post('super-adjustments/uncounted-items', [Controllers\SuperAdjustmentController::class, 'uncountedItems'])
        ->name('super-adjustments.uncounted-items');
    Route::resource('super-adjustments', Controllers\SuperAdjustmentController::class)
        ->parameters(['super-adjustments' => 'super_adjustment']);
    Route::put('super-adjustments/{super_adjustment}/restore', [Controllers\SuperAdjustmentController::class, 'restore'])
        ->name('super-adjustments.restore');
    Route::delete('super-adjustments/{super_adjustment}/permanently', [Controllers\SuperAdjustmentController::class, 'destroyPermanently'])
        ->name('super-adjustments.destroy.permanently');
    Route::put('super-adjustments/{super_adjustment}/approve', [Controllers\SuperAdjustmentController::class, 'approve'])
        ->name('super-adjustments.approve');
    Route::put('super-adjustments/{super_adjustment}/status', [Controllers\SuperAdjustmentController::class, 'changeStatus'])
        ->name('super-adjustments.status');
    Route::put('super-adjustments/{super_adjustment}/reject', [Controllers\SuperAdjustmentController::class, 'reject'])
        ->name('super-adjustments.reject');
    Route::get('super-adjustments/{super_adjustment}/attachments/{attachment}/download', [Controllers\SuperAdjustmentController::class, 'downloadAttachment'])
        ->name('super-adjustments.attachments.download');
    Route::delete('super-adjustments/{super_adjustment}/attachments/{attachment}', [Controllers\SuperAdjustmentController::class, 'destroyAttachment'])
        ->name('super-adjustments.attachments.destroy');
    Route::get('super-adjustments/{super_adjustment}/print', [Controllers\SuperAdjustmentController::class, 'print'])
        ->name('super-adjustments.print');
    Route::get('super-adjustments/{super_adjustment}/pdf', [Controllers\SuperAdjustmentController::class, 'pdf'])
        ->name('super-adjustments.pdf');

    // Project Execution Management
    Route::get('project-execution/dashboard', [Controllers\ProjectExecutionController::class, 'dashboard'])
        ->name('project-execution.dashboard');
    Route::get('project-execution/projects', [Controllers\ProjectExecutionController::class, 'projects'])
        ->name('project-execution.projects');
    Route::resource('work-packages', Controllers\WorkPackageController::class)
        ->parameters(['work-packages' => 'work_package']);
    Route::put('work-packages/{work_package}/restore', [Controllers\WorkPackageController::class, 'restore'])
        ->name('work-packages.restore');
    Route::delete('work-packages/{work_package}/permanently', [Controllers\WorkPackageController::class, 'destroyPermanently'])
        ->name('work-packages.destroy.permanently');
    Route::post('work-packages/{work_package}/attachments', [Controllers\WorkPackageController::class, 'storeAttachments'])
        ->name('work-packages.attachments.store');
    Route::get('work-packages/{work_package}/attachments/{attachment}/download', [Controllers\WorkPackageController::class, 'downloadAttachment'])
        ->name('work-packages.attachments.download');
    Route::delete('work-packages/{work_package}/attachments/{attachment}', [Controllers\WorkPackageController::class, 'destroyAttachment'])
        ->name('work-packages.attachments.destroy');

    Route::get('daily-updates', [Controllers\WorkPackageDailyUpdateController::class, 'index'])
        ->name('work-package-daily-updates.index');
    Route::post('work-packages/{work_package}/daily-updates', [Controllers\WorkPackageDailyUpdateController::class, 'store'])
        ->name('work-package-daily-updates.store');
    Route::put('daily-updates/{daily_update}/approve', [Controllers\WorkPackageDailyUpdateController::class, 'approve'])
        ->name('work-package-daily-updates.approve');
    Route::put('daily-updates/{daily_update}/reject', [Controllers\WorkPackageDailyUpdateController::class, 'reject'])
        ->name('work-package-daily-updates.reject');

    Route::post('work-packages/{work_package}/material-forecasts', [Controllers\WorkPackageMaterialForecastController::class, 'store'])
        ->name('work-package-material-forecasts.store');
    Route::delete('work-packages/{work_package}/material-forecasts/{material_forecast}', [Controllers\WorkPackageMaterialForecastController::class, 'destroy'])
        ->name('work-package-material-forecasts.destroy');

    Route::post('work-packages/{work_package}/labour-forecasts', [Controllers\WorkPackageLabourForecastController::class, 'store'])
        ->name('work-package-labour-forecasts.store');
    Route::delete('work-packages/{work_package}/labour-forecasts/{labour_forecast}', [Controllers\WorkPackageLabourForecastController::class, 'destroy'])
        ->name('work-package-labour-forecasts.destroy');

    Route::post('work-packages/{work_package}/equipment-forecasts', [Controllers\WorkPackageEquipmentForecastController::class, 'store'])
        ->name('work-package-equipment-forecasts.store');
    Route::delete('work-packages/{work_package}/equipment-forecasts/{equipment_forecast}', [Controllers\WorkPackageEquipmentForecastController::class, 'destroy'])
        ->name('work-package-equipment-forecasts.destroy');

    Route::get('work-package-issues', [Controllers\WorkPackageIssueController::class, 'index'])
        ->name('work-package-issues.index');
    Route::post('work-packages/{work_package}/issues', [Controllers\WorkPackageIssueController::class, 'store'])
        ->name('work-package-issues.store');
    Route::put('work-packages/{work_package}/issues/{issue}', [Controllers\WorkPackageIssueController::class, 'update'])
        ->name('work-package-issues.update');
    Route::delete('work-packages/{work_package}/issues/{issue}', [Controllers\WorkPackageIssueController::class, 'destroy'])
        ->name('work-package-issues.destroy');

    Route::portResources([
        'items'      => Controllers\ItemPortController::class,
        'units'      => Controllers\UnitPortController::class,
        'contacts'   => Controllers\ContactPortController::class,
        'checkins'   => Controllers\CheckinPortController::class,
        'checkouts'  => Controllers\CheckoutPortController::class,
        'categories' => Controllers\CategoryPortController::class,
        'warehouses' => Controllers\WarehousePortController::class,
    ]);

    // Notifications
    Route::get('notifications/checkin/{checkin}', [Controllers\NotificationController::class, 'checkin'])->name('notifications.checkin');
    Route::get('notifications/checkout/{checkout}', [Controllers\NotificationController::class, 'checkout'])->name('notifications.checkout');
    Route::get('notifications/transfer/{transfer}', [Controllers\NotificationController::class, 'transfer'])->name('notifications.transfer');
    Route::get('notifications/adjustment/{adjustment}', [Controllers\NotificationController::class, 'adjustment'])->name('notifications.adjustment');
    Route::post('notifications/alerts/read-all', [Controllers\AjaxController::class, 'readAllAlertNotifications'])->name('notifications.alerts.read-all');
    Route::post('notifications/alerts/module/{module}/read', [Controllers\AjaxController::class, 'readModuleAlertNotifications'])->name('notifications.alerts.read-module');
    Route::post('notifications/alerts/{notification}/read', [Controllers\AjaxController::class, 'readAlertNotification'])->name('notifications.alerts.read');
    // Notifications Preview
    Route::get('preview/low_stock', [Controllers\NotificationController::class, 'stock'])->name('notifications.stock.preview');
    Route::get('preview/checkin/{checkin}', [Controllers\NotificationController::class, 'checkin'])->name('notifications.checkin.preview');
    Route::get('preview/checkout/{checkout}', [Controllers\NotificationController::class, 'checkout'])->name('notifications.checkout.preview');
    Route::get('preview/transfer/{transfer}', [Controllers\NotificationController::class, 'transfer'])->name('notifications.transfer.preview');
    Route::get('preview/adjustment/{adjustment}', [Controllers\NotificationController::class, 'adjustment'])->name('notifications.adjustment.preview');

    // Reports
    // Reports > Stock Archive: a kept record of the whole company's stock, with its history
    Route::get('stock-archives', [Controllers\StockArchiveController::class, 'index'])->name('stock-archives.index');
    Route::post('stock-archives', [Controllers\StockArchiveController::class, 'generate'])->middleware('throttle:6,1,stock-archives.generate')->name('stock-archives.generate');
    Route::post('stock-archives/bulk-delete', [Controllers\StockArchiveController::class, 'bulkDestroy'])->middleware('throttle:10,1,stock-archives.bulk-delete')->name('stock-archives.bulk-delete');
    Route::post('stock-archives/wipes', [Controllers\StockArchiveController::class, 'wipeStart'])->middleware('throttle:10,1,stock-archives.wipes.start')->name('stock-archives.wipes.start');
    Route::post('stock-archives/wipes/{wipe}/confirm', [Controllers\StockArchiveController::class, 'wipeConfirm'])->whereNumber('wipe')->middleware('throttle:10,1,stock-archives.wipes.confirm')->name('stock-archives.wipes.confirm');
    Route::delete('stock-archives/wipes/{wipe}', [Controllers\StockArchiveController::class, 'wipeCancel'])->whereNumber('wipe')->name('stock-archives.wipes.cancel');
    Route::put('stock-archives/settings', [Controllers\StockArchiveController::class, 'settings'])->name('stock-archives.settings');
    Route::get('stock-archives/{archive}/preview', [Controllers\StockArchiveController::class, 'preview'])->whereNumber('archive')->middleware('throttle:60,1,stock-archives.preview')->name('stock-archives.preview');
    Route::get('stock-archives/{archive}/download', [Controllers\StockArchiveController::class, 'download'])->whereNumber('archive')->middleware('throttle:60,1,stock-archives.download')->name('stock-archives.download');
    Route::get('stock-archives/{archive}/history', [Controllers\StockArchiveController::class, 'history'])->whereNumber('archive')->name('stock-archives.history');
    Route::post('stock-archives/{archive}/email', [Controllers\StockArchiveController::class, 'email'])->whereNumber('archive')->middleware('throttle:10,1,stock-archives.email')->name('stock-archives.email');
    Route::delete('stock-archives/{archive}', [Controllers\StockArchiveController::class, 'destroy'])->whereNumber('archive')->middleware('throttle:10,1,stock-archives.destroy')->name('stock-archives.destroy');
    Route::get('reports', [Controllers\ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/general', [Controllers\ReportController::class, 'general'])->name('reports.general');
    Route::get('reports/general/pdf', [Controllers\ReportController::class, 'generalPdf'])->name('reports.general.pdf');
    Route::get('reports/warehouse-items/{warehouse}', [Controllers\ReportController::class, 'warehouseItems'])->whereNumber('warehouse')->name('reports.warehouse-items');
    Route::match(['GET', 'POST'], 'reports/checkin', [Controllers\ReportController::class, 'checkin'])->name('reports.checkin');
    Route::match(['GET', 'POST'], 'reports/checkout', [Controllers\ReportController::class, 'checkout'])->name('reports.checkout');
    Route::match(['GET', 'POST'], 'reports/transfer', [Controllers\ReportController::class, 'transfer'])->name('reports.transfer');
    Route::match(['GET', 'POST'], 'reports/adjustment', [Controllers\ReportController::class, 'adjustment'])->name('reports.adjustment');
    Route::match(['GET', 'POST'], 'reports/warehousestock', [Controllers\ReportController::class, 'warehousestock'])->name('reports.warehousestock');
    Route::match(['GET', 'POST'], 'reports/warehouseamounts', [Controllers\ReportController::class, 'warehouseamounts'])->name('reports.warehouseamounts');
    Route::match(['GET', 'POST'], 'reports/warehousecheckin', [Controllers\ReportController::class, 'warehousecheckin'])->name('reports.warehousecheckin');
    Route::match(['GET', 'POST'], 'reports/most-used-material', [Controllers\ReportController::class, 'mostUsedMaterial'])->name('reports.most_used_material');
    Route::match(['GET', 'POST'], 'reports/item-transactional', [Controllers\ReportController::class, 'itemTransactional'])->name('reports.item_transactional');
    Route::get('reports/item-transactional/{transactionKey}/{transactionId}/form', [Controllers\ReportController::class, 'itemTransactionalForm'])->name('reports.item_transactional.form');
    Route::match(['GET', 'POST'], 'reports/usage-material-per-site-cost', [Controllers\ReportController::class, 'usageMaterialPerSiteCost'])->name('reports.usage_material_per_site_cost');
    Route::match(['GET', 'POST'], 'reports/timely-material-usage', [Controllers\ReportController::class, 'timelyMaterialUsage'])->name('reports.timely_material_usage');
    Route::match(['GET', 'POST'], 'reports/boq-material-compliance', [Controllers\ReportController::class, 'boqMaterialCompliance'])->name('reports.boq_material_compliance');
    Route::post('reports/boq-material-compliance/save', [Controllers\ReportController::class, 'saveBoqMaterialCompliance'])->name('reports.boq_material_compliance.save');
    Route::match(['GET', 'POST'], 'reports/material-reallocation-summary', [Controllers\ReportController::class, 'materialReallocationSummary'])->name('reports.material_reallocation_summary');
    Route::match(['GET', 'POST'], 'reports/material-movement', [Controllers\ReportController::class, 'materialMovement'])->name('reports.material_movement');
    Route::match(['GET', 'POST'], 'reports/driver-dispatch', [Controllers\ReportController::class, 'driverDispatch'])->name('reports.driver_dispatch');
    Route::match(['GET', 'POST'], 'reports/warehouse-receiving', [Controllers\ReportController::class, 'warehouseReceiving'])->name('reports.warehouse_receiving');
    Route::match(['GET', 'POST'], 'reports/discrepancy', [Controllers\ReportController::class, 'discrepancy'])->name('reports.discrepancy');
    Route::post('reports/email', [Controllers\ReportController::class, 'emailReport'])->name('reports.email');
    Route::match(['GET', 'POST'], 'reports/item-category', [Controllers\ItemCategoryReportController::class, 'index'])->name('item-category-report.index');
    Route::get('reports/item-category/item-details/{item}', [Controllers\ItemCategoryReportController::class, 'getItemDetails'])->name('item-category-report.item-details');
    Route::match(['GET', 'POST'], 'material-value', [Controllers\MaterialValueController::class, 'index'])->name('material-value.index');
    Route::get('material-value/item-details/{item}', [Controllers\MaterialValueController::class, 'itemDetails'])->name('material-value.item-details');
    Route::match(['GET', 'POST'], 'movement-value', [Controllers\MovementValueReportController::class, 'index'])->name('movement-value.index');
    Route::match(['GET', 'POST'], 'project-material-ledger', [Controllers\ProjectMaterialLedgerController::class, 'ledger'])->name('project-material-ledger.index');
    Route::get('project-material-ledger/{transactionType}/{transactionId}', [Controllers\ProjectMaterialLedgerController::class, 'transaction'])->name('project-material-ledger.show');

    // Role Permissions
    Route::post('roles/{role}/permissions', [Controllers\RoleController::class, 'permissions'])->name('roles.permissions');
    Route::post('roles/{role}/duplicate', [Controllers\RoleController::class, 'duplicate'])->name('roles.duplicate');

    // Maintenance: wipe checkouts/checkins and zero out stock
    // POST only (never a GET: anything that just visits an address must not be able to empty the system), with password + typed confirmation
    Route::post('maintenance/reset-stock', [Controllers\MaintenanceController::class, 'resetStock'])->middleware('throttle:2,1,maintenance.reset-stock')->name('maintenance.reset-stock');

    // Database backup & restore (Super Admin only)
    Route::get('backup', [Controllers\BackupController::class, 'index'])->name('backup.index');
    Route::put('backup/auto-settings', [Controllers\BackupController::class, 'autoSettings'])->name('backup.auto.settings');
    Route::post('backup/system/create', [Controllers\BackupController::class, 'createSystemBackup'])->name('backup.system.create');
    Route::post('backup/download', [Controllers\BackupController::class, 'download'])->name('backup.download');
    Route::post('backup/download/{backupActivity}/auto', [Controllers\BackupController::class, 'downloadAuto'])->name('backup.download.auto');
    Route::post('backup/restore', [Controllers\BackupController::class, 'restore'])->name('backup.restore');
    Route::post('backup/restore/{backupActivity}/auto', [Controllers\BackupController::class, 'restoreAuto'])->name('backup.restore.auto');
});

// Routes to run storage & migration commands
// Email/SMS code for the 2FA challenge page. Only reachable by someone mid-login (session holds login.id).
Route::post('/two-factor-challenge/send', [Controllers\Auth\TwoFactorChallengeController::class, 'send'])
    ->middleware(['guest:' . config('fortify.guard'), 'throttle:6,1'])
    ->name('two-factor.otp.send');

// Email/SMS two-factor methods, managed from the Profile page (password-confirmed, like the authenticator setup).
Route::middleware(['auth:sanctum', 'password.confirm', 'throttle:20,1'])->prefix('user/two-factor-otp')->name('two-factor.otp.')->group(function () {
    Route::post('send', [Controllers\TwoFactorOtpSettingsController::class, 'send'])->name('enroll.send');
    Route::post('enable', [Controllers\TwoFactorOtpSettingsController::class, 'enable'])->name('enable');
    Route::delete('{channel}', [Controllers\TwoFactorOtpSettingsController::class, 'destroy'])->name('disable');
});

Route::view('/notification', 'notification')->name('notification');
Route::prefix('commands')->middleware(['auth:sanctum', 'system.lockdown', 'throttle:6,10', 'purchased'])->group(function () {
    Route::get('storage_link', function () {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403);
        Artisan::call('storage:link');

        return redirect('notification')->with('message', Artisan::output());
    });
    // Subcontract data prepared in database/data/subcontracts: first run is a CHECK only (nothing kept); add ?confirm=yes to load it. Refuses a database that already has agreements.
    Route::get('subcontracts_load_data', function () {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403);
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        $load = request('confirm') === 'yes';
        Artisan::call('subcontracts:load-data', ['--dry-run' => ! $load]);
        $out = Artisan::output();
        if ($load) {
            Artisan::call('subcontracts:sweep');
        }

        return redirect('notification')->with('message', (! $load ? "CHECK ONLY, nothing was kept. Add ?confirm=yes to the address to load it.\n" : '') . $out);
    });
    // After an update: new abilities and simulation-only tables reach the simulation database (needs Simulation WIMS to be installed)
    Route::get('simulation_sync', function () {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin') && ! \App\Support\Simulation\Environment::isSimulation(), 403);
        Artisan::call('simulation:sync');

        return redirect('notification')->with('message', Artisan::output());
    });
    Route::get('update_database', function () {
        abort_unless(auth()->user() && auth()->user()->hasRole('Super Admin'), 403);
        Artisan::call('migrate --force');

        return redirect('notification')->with('message', Artisan::output());
    });
});

// A completely unmatched URL never runs the 'web' middleware group at all
// (there's no route to attach it to), so session/auth/Inertia-shared-props
// never get resolved — the Error page's "go home if logged in" logic would
// always see a logged-out user, even for someone with a perfectly valid
// session, just because they mistyped a URL. Routing every unmatched GET
// through this fallback makes it a real matched route, which does run 'web'
// middleware, so auth state is correctly known by the time Handler::render()
// builds the Error page response.
Route::fallback(function () {
    abort(404);
});

// Face login. Enrolling / removing sits behind password confirmation (like the authenticator setup); signing in is for guests.
Route::middleware(['auth:sanctum', 'password.confirm:password.confirm,300', 'throttle:30,1'])->prefix('user/face')->name('profile.face.')->group(function () {
    Route::post('enrol', [Controllers\FaceEnrolmentController::class, 'store'])->name('enrol');
    Route::post('check', [Controllers\FaceEnrolmentController::class, 'check'])->name('check');
    Route::post('devices', [Controllers\FaceEnrolmentController::class, 'addDevice'])->name('devices.store');
    Route::delete('devices/{device}', [Controllers\FaceEnrolmentController::class, 'removeDevice'])->name('devices.destroy');
    Route::delete('/', [Controllers\FaceEnrolmentController::class, 'destroy'])->name('destroy');
});
Route::middleware(['auth:sanctum', 'throttle:20,1'])->delete('users/{user}/face', [Controllers\FaceEnrolmentController::class, 'reset'])->name('users.face.reset');
Route::middleware(['guest', 'throttle:20,1', 'not.simulation'])->prefix('face-login')->name('face-login.')->group(function () {
    Route::post('challenge', [Controllers\Auth\FaceLoginController::class, 'challenge'])->name('challenge');
    Route::post('verify', [Controllers\Auth\FaceLoginController::class, 'verify'])->name('verify');
});

// Prayer Times: the settings page (Super Admin, or a role given manage-prayer-times). The times, alert and lock work for every signed-in user without a route.
Route::middleware(['auth:sanctum', 'system.lockdown', 'feature.gate'])->prefix('prayer-times')->name('prayer.')->group(function () {
    Route::get('state', [Controllers\PrayerSettingsController::class, 'state'])->middleware('throttle:60,1')->name('state');
    Route::get('settings', [Controllers\PrayerSettingsController::class, 'show'])->name('settings');
    Route::put('settings', [Controllers\PrayerSettingsController::class, 'update'])->name('settings.update');
    Route::post('adhans', [Controllers\PrayerSettingsController::class, 'adhanStore'])->middleware('throttle:20,1')->name('adhans.store');
    Route::delete('adhans/{adhan}', [Controllers\PrayerSettingsController::class, 'adhanDestroy'])->name('adhans.destroy');
    Route::post('messages', [Controllers\PrayerSettingsController::class, 'messageStore'])->name('messages.store');
    Route::put('messages/{message}', [Controllers\PrayerSettingsController::class, 'messageUpdate'])->name('messages.update');
    Route::delete('messages/{message}', [Controllers\PrayerSettingsController::class, 'messageDestroy'])->name('messages.destroy');
});

// WIMS Notification > Low Stock Alert.
Route::middleware(['auth:sanctum', 'system.lockdown', 'feature.gate', 'log.download.print'])->prefix('low-stock-alert')->name('low-stock-alert.')->group(function () {
    Route::get('/', [Controllers\LowStockAlertController::class, 'index'])->name('index');
    Route::put('/', [Controllers\LowStockAlertController::class, 'update'])->name('update');
    Route::get('preview', [Controllers\LowStockAlertController::class, 'preview'])->name('preview');
    Route::get('pdf', [Controllers\LowStockAlertController::class, 'pdf'])->middleware('throttle:20,1')->name('pdf');
    Route::post('test', [Controllers\LowStockAlertController::class, 'sendTest'])->middleware('throttle:6,1')->name('test');
    Route::post('send-now', [Controllers\LowStockAlertController::class, 'sendNow'])->middleware('throttle:3,1')->name('send-now');
});
