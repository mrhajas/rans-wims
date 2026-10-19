<?php

namespace App\Http\Controllers\Simulation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Shared checks of the simulation workspace pages. */
trait GuardsSimulation
{
    protected function need(string ...$abilities): void
    {
        $u = auth()->user();
        abort_unless($u && collect($abilities)->contains(fn ($a) => $u->can($a)), 403);
    }

    /** a destructive step needs the person's own password and the word typed out */
    protected function confirmed(Request $request, string $word): void
    {
        $request->validate(['password' => 'required|string', 'confirm' => 'required|string']);
        if (strtoupper(trim((string) $request->input('confirm'))) !== $word) {
            throw ValidationException::withMessages(['confirm' => __('Type :w to confirm.', ['w' => $word])]);
        }
        if (! Hash::check((string) $request->input('password'), (string) auth()->user()->password)) {
            throw ValidationException::withMessages(['password' => __('That password is not right.')]);
        }
    }
}
