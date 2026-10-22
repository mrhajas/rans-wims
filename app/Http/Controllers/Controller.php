<?php

namespace App\Http\Controllers;

use App\Traits\Authorizable;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use Authorizable;
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * Permission for actions whose name the automatic check (App\Traits\Authorizable) does not know (export, import, save, print, pdf...).
     * `['export' => 'read-items', 'import' => 'create-items']`: the action named on the left needs the permission on the right; the others are left to their own checks.
     */
    protected function requirePermissions(array $map): void
    {
        $this->middleware(function ($request, $next) use ($map) {
            $permission = $map[$request->route()->getActionMethod()] ?? null;
            abort_unless(! $permission || ($request->user() && $request->user()->can($permission)), 403);

            return $next($request);
        });
    }
}
