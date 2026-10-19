<?php

namespace App\Http\Controllers;

use App\Support\Wimshow\Knowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public knowledge base at /wimshow: how every WIMS module works, with screenshots, for anyone (no login).
 */
class WimshowController extends Controller
{
    public function __construct(private Knowledge $kb)
    {
        // Public pages: skip the automatic `{action}-{route-prefix}` permission check of the Authorizable trait.
        $this->setAbilities([]);
    }

    public function index(): View
    {
        return view('wimshow.home', ['kb' => $this->kb, 'groups' => $this->kb->groups(), 'stats' => $this->kb->stats()]);
    }

    public function show(string $slug): View
    {
        $guide = $this->kb->guide($slug) ?? abort(404);
        [$previous, $next] = $this->kb->neighbours($slug);

        return view('wimshow.guide', [
            'kb' => $this->kb,
            'guide' => $guide,
            'group' => $this->kb->groups()->firstWhere('slug', $guide['group']),
            'previous' => $previous,
            'next' => $next,
            'related' => collect($guide['related'])->map(fn (string $related): ?array => $this->kb->guide($related))->filter()->values(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $items = $this->kb->searchIndex();
        $icons = collect($items)->pluck('i')->unique()->mapWithKeys(fn (string $name): array => [$name => \App\Support\Wimshow\Icons::paths($name)])->all();

        return response()->json(['icons' => $icons, 'items' => $items])
            ->setEtag($this->kb->version())
            ->setPublic()->setMaxAge(300);
    }
}
