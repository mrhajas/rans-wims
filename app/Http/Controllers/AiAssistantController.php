<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\User;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function ask(Request $request, AiAssistantService $service)
    {
        abort_unless($request->user()->can('use-ai-assistant'), 403);

        $data = $request->validate([
            'question' => 'required|string|max:500',
            'history' => 'nullable|array|max:10',
            'history.*.question' => 'nullable|string|max:500',
            'history.*.answer' => 'nullable|string|max:2000',
        ]);

        $result = $service->ask($data['question'], $request->user(), $data['history'] ?? []);

        return response()->json($result);
    }

    /**
     * Lightweight name lookup powering the #i / #u tag pickers in the ARIS
     * question box. Read-only, capped, account-scoped.
     */
    public function lookup(Request $request)
    {
        abort_unless($request->user()->can('use-ai-assistant'), 403);

        $type = $request->get('type');
        $q = trim((string) $request->get('q', ''));

        $results = match ($type) {
            'items' => Item::query()
                ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')
                ->limit(8)
                ->pluck('name'),
            'users' => User::query()
                ->where('account_id', $request->user()->account_id)
                ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')
                ->limit(8)
                ->pluck('name'),
            default => collect(),
        };

        return response()->json(['results' => $results->values()]);
    }
}
