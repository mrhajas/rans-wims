<?php

namespace App\Services\Licensing;

use Illuminate\Validation\ValidationException;

/** A licence key or file that cannot be used, with a `kind` so the page can explain exactly what happened. */
class LicenceRejected extends ValidationException
{
    public string $kind = 'invalid';

    public static function because(string $kind, string $message): self
    {
        $e = static::withMessages(['licence' => $message]);
        $e->kind = $kind;

        return $e;
    }
}
