<?php

namespace App\Services\Signatories;

/**
 * The signatures of a printed module report. A report may carry `signing` => ['type' => <document key>, 'id' => ?int, 'reference' => ?string]:
 * with an id it is the record's own document (an agreement, a movement); without one it is a report generated on request (Prepared By = the person who generated it).
 * Inert (no block) when the module has no document setup or the report names no document.
 */
class ReportSignatures
{
    public static function resolve(array $report): ?array
    {
        $s = $report['signing'] ?? null;
        $user = auth()->user();
        if (! $s || ! $user) {
            return null;
        }
        try {
            $resolver = app(SignatureResolver::class);
            if (! empty($s['id'])) {
                return $resolver->resolve($s['type'], (int) $s['id'], $user);
            }
            $class = SignatureResolver::adapters()[$s['type']] ?? null;
            if (! $class || ! method_exists($class, 'record')) {
                return null;
            }

            return $resolver->resolve($s['type'], $class::record($user, now(), $s['reference'] ?? ($report['reference'] ?? null)), $user);
        } catch (\Throwable) {
            return null; // a signature block must never break a printout
        }
    }
}
