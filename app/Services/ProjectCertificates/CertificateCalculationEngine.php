<?php

namespace App\Services\ProjectCertificates;

use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Evaluates a certificate's calculation lines without ever using eval() or a
 * formula-string parser — every "formula" is structured data referencing
 * other lines by line_key, which is what makes a no-code visual builder and
 * real cycle detection possible. All arithmetic uses bcmath at a fixed
 * 2-decimal scale, never PHP floats (spec req. 50).
 *
 * Operand contract per line type:
 *   input                — no operands; `value` is authoritative (set directly).
 *   reference             — {"source": "previous_certificate_cumulative"|"previous_certificate_net_paid"
 *                            |"contract_original_sum"|"contract_revised_sum"|"contract_variation_amount"|"other"}
 *                            — every source pulls a value from referenceContext() except "other", which
 *                            resolves from the line's own `value` (a manually-typed amount) instead.
 *   percentage             — {"base_ref": "<line_key>", "rate": <number>} → base.value * rate / 100
 *   addition/deduction/     — {"terms": [{"ref": "<line_key>"|null, "value": <number>|null, "operator": "+"|"-"}]}
 *   formula/subtotal/total    an ordered signed sum. The type only differs in how the UI defaults new
 *                             terms' operator (addition→"+", deduction→"-") — the computation is identical,
 *                             which keeps this engine small: every one of these five types is just a
 *                             named "formula" line.
 */
class CertificateCalculationEngine
{
    private const SCALE = 2;

    /**
     * The fixed set of sources a `reference` line's operands.source may
     * name — single source of truth for both validateAndOrder()'s
     * fail-fast check and referenceContext()'s actual resolution, so the
     * two can't silently drift apart (e.g. a source added to Builder.vue
     * without a matching key here, which previously just resolved to 0.00
     * with no error at all).
     */
    private const REFERENCE_SOURCES = [
        'previous_certificate_cumulative',
        'previous_certificate_net_paid',
        'contract_original_sum',
        'contract_revised_sum',
        'contract_variation_amount',
        // Not a real cross-certificate lookup — a manually-typed reference
        // (a document/PO number the QS enters by hand) paired with a
        // manually-typed amount, so it resolves from the line's own
        // `value` in evaluateLine() below instead of referenceContext().
        'other',
    ];

    /**
     * Every line_key another line's operands reference, for cycle detection
     * and evaluation ordering.
     */
    private function dependencies(array $line): array
    {
        $type = $line['type'];
        $operands = $line['operands'] ?? [];

        // The callback form, not bare array_filter()/Collection::filter() —
        // those drop every falsy element, including a line_key of the
        // literal string "0", making such a line invisible to both cycle
        // detection and the existence check below.
        $notEmpty = fn ($v) => $v !== null && $v !== '';

        if ($type === ProjectCertificateLine::TYPE_PERCENTAGE) {
            return array_filter([$operands['base_ref'] ?? null], $notEmpty);
        }

        if (in_array($type, [
            ProjectCertificateLine::TYPE_ADDITION, ProjectCertificateLine::TYPE_DEDUCTION,
            ProjectCertificateLine::TYPE_FORMULA, ProjectCertificateLine::TYPE_SUBTOTAL, ProjectCertificateLine::TYPE_TOTAL,
        ], true)) {
            return collect($operands['terms'] ?? [])->pluck('ref')->filter($notEmpty)->values()->all();
        }

        return [];
    }

    /**
     * Topologically sorts $lines (each an array with line_key/type/operands)
     * and throws a ValidationException naming every line that couldn't be
     * ordered — i.e. participates in a cycle, or references a line_key that
     * doesn't exist. Call this BEFORE persisting any line change.
     *
     * @param  array<int, array>  $lines
     * @return array<int, array> the same lines, in dependency-safe evaluation order
     */
    public function validateAndOrder(array $lines): array
    {
        // Every check below ACCUMULATES into $errors (keyed by the indexed
        // "lines.{index}.{field}" path Laravel's error bag and Inertia both
        // already understand natively) instead of throwing on the first
        // failure — a QS with 8 lines and 3 separate mistakes used to see
        // only the first one, fix it, resave, and hit the next one blind.
        // One ValidationException with every problem at once, each
        // addressable to its exact row/field, is thrown at the very end.
        $errors = [];

        // A duplicate line_key collapses in keyBy() below, silently
        // discarding one line — validateAndOrder() would then see
        // count($ordered) !== count($lines) with every in-degree at 0 (no
        // real cycle) and throw a "circular reference" naming no lines at
        // all. Caught here first, with an error that actually names the
        // problem, before that happens.
        $duplicateKeys = collect($lines)->countBy('line_key')->filter(fn ($n) => $n > 1)->keys();
        if ($duplicateKeys->isNotEmpty()) {
            foreach ($lines as $idx => $line) {
                if ($duplicateKeys->contains($line['line_key'])) {
                    $errors["lines.{$idx}.line_key"] = "This line's key (\"{$line['line_key']}\") is also used by another line, every line needs a unique key.";
                }
            }
        }

        $byKey = collect($lines)->keyBy('line_key');
        // Compared as strings below (PHP casts an all-digit array key to
        // int on the way into keyBy(), which made a purely-numeric
        // line_key like "1" or "2026" fail a strict in_array() against a
        // dependency reference of the same value stored as a string).
        $knownKeys = $byKey->keys()->map(fn ($k) => (string) $k)->all();

        // Fail fast on a reference to a line_key that doesn't exist at all —
        // a much clearer error than letting it silently resolve to 0.
        foreach ($lines as $idx => $line) {
            foreach ($this->dependencies($line) as $dep) {
                if (! in_array((string) $dep, $knownKeys, true)) {
                    $errors["lines.{$idx}.operands"] = "This line references \"{$dep}\", which doesn't exist on this certificate.";
                }
            }
        }

        // A `reference` line pointing at a source outside the fixed set
        // referenceContext() actually resolves (a typo, a renamed source, a
        // frontend option added without a matching backend key) would
        // otherwise silently evaluate to 0.00 — contradicting the whole
        // point of this fail-fast validation pass.
        $knownReferenceSources = self::REFERENCE_SOURCES;
        foreach ($lines as $idx => $line) {
            if ($line['type'] !== ProjectCertificateLine::TYPE_REFERENCE) {
                continue;
            }
            $source = $line['operands']['source'] ?? null;
            if (! in_array($source, $knownReferenceSources, true)) {
                $errors["lines.{$idx}.operands.source"] = "This line references an unknown source \"{$source}\".";
            }
        }

        // Reject any non-numeric literal before it ever reaches bcmath — the
        // top-level `value`/`operands` request rules can't fully validate a
        // polymorphic per-type JSON shape, so this is the real backstop.
        // Financial data must never silently coerce a bad value to 0.
        foreach ($lines as $idx => $line) {
            $errors += $this->assertNumericOperands($line, $idx);
        }

        // Every check above can identify its own row without needing the
        // topological sort — bail out before it if any were found, both to
        // avoid the sort running on data already known bad, and because a
        // stray reference to a line_key that doesn't exist would otherwise
        // ALSO be reported a second time as "circular" below (it's excluded
        // from every dependent's in-degree, which looks identical to a
        // real cycle from the sort's perspective).
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $inDegree = [];
        $dependents = [];
        foreach ($lines as $line) {
            $inDegree[$line['line_key']] = 0;
        }
        foreach ($lines as $line) {
            foreach ($this->dependencies($line) as $dep) {
                $dependents[$dep][] = $line['line_key'];
                $inDegree[$line['line_key']]++;
            }
        }

        $queue = collect($inDegree)->filter(fn ($d) => $d === 0)->keys()->all();
        $ordered = [];
        while (! empty($queue)) {
            $key = array_shift($queue);
            $ordered[] = $key;
            foreach ($dependents[$key] ?? [] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($ordered) !== count($lines)) {
            $stuck = collect($inDegree)->filter(fn ($d) => $d > 0)->keys()->values();
            $errors = [];
            foreach ($lines as $idx => $line) {
                if ($stuck->contains($line['line_key'])) {
                    $errors["lines.{$idx}.line_key"] = 'This line is part of a circular reference and cannot be calculated.';
                }
            }
            throw ValidationException::withMessages($errors);
        }

        return collect($ordered)->map(fn ($key) => $byKey->get($key))->values()->all();
    }

    /**
     * Returns accumulated "lines.{idx}.…" errors instead of throwing, so
     * validateAndOrder() can report every bad line at once rather than
     * stopping at the first.
     */
    private function assertNumericOperands(array $line, int $idx): array
    {
        $operands = $line['operands'] ?? [];
        $errors = [];

        if ($line['type'] === ProjectCertificateLine::TYPE_PERCENTAGE) {
            if (isset($operands['rate']) && ! is_numeric($operands['rate'])) {
                $errors["lines.{$idx}.operands.rate"] = 'This percentage rate must be a number.';
            }

            return $errors;
        }

        if (in_array($line['type'], [
            ProjectCertificateLine::TYPE_ADDITION, ProjectCertificateLine::TYPE_DEDUCTION,
            ProjectCertificateLine::TYPE_FORMULA, ProjectCertificateLine::TYPE_SUBTOTAL, ProjectCertificateLine::TYPE_TOTAL,
        ], true)) {
            foreach ($operands['terms'] ?? [] as $ti => $term) {
                $hasLiteral = ($term['ref'] ?? null) === null || $term['ref'] === '';
                if ($hasLiteral && isset($term['value']) && ! is_numeric($term['value'])) {
                    $errors["lines.{$idx}.operands.terms.{$ti}.value"] = 'This term\'s value must be a number.';
                }
            }
        }

        return $errors;
    }

    /**
     * Recomputes and persists every line's `value` on $certificate, in
     * dependency order. Returns the certificate's lines, freshly evaluated.
     */
    public function evaluate(ProjectCertificate $certificate): Collection
    {
        $lines = $certificate->lines()->get();
        $plain = $lines->map(fn (ProjectCertificateLine $l) => [
            'line_key' => $l->line_key, 'label' => $l->label, 'type' => $l->type, 'operands' => $l->operands,
        ])->all();

        $ordered = $this->validateAndOrder($plain);
        $context = $this->referenceContext($certificate);
        $computed = []; // line_key => bcmath string value

        foreach ($ordered as $line) {
            $model = $lines->firstWhere('line_key', $line['line_key']);
            $value = $this->evaluateLine($line, $computed, $context, $model);
            $computed[$line['line_key']] = $value;
            $model->update(['value' => $value]);
        }

        return $lines->fresh(); // reload with persisted values
    }

    private function evaluateLine(array $line, array $computed, array $context, ProjectCertificateLine $model): string
    {
        $type = $line['type'];
        $operands = $line['operands'] ?? [];

        if ($type === ProjectCertificateLine::TYPE_INPUT) {
            return bcadd((string) $model->value, '0', self::SCALE);
        }

        if ($type === ProjectCertificateLine::TYPE_REFERENCE) {
            if (($operands['source'] ?? null) === 'other') {
                return bcadd((string) $model->value, '0', self::SCALE);
            }

            return bcadd($context[$operands['source'] ?? ''] ?? '0.00', '0', self::SCALE);
        }

        if ($type === ProjectCertificateLine::TYPE_PERCENTAGE) {
            $base = $computed[$operands['base_ref'] ?? ''] ?? '0.00';
            $rate = (string) ($operands['rate'] ?? 0);

            // bcdiv truncates rather than rounds — computing at high
            // precision then dividing straight to SCALE would silently
            // chop e.g. 30864.19725 down to 30864.19 instead of rounding to
            // 30864.20, a one-sided (always-toward-zero) error that
            // systematically under-deducts every retention/percentage line.
            $raw = bcdiv(bcmul($base, $rate, self::SCALE + 4), '100', self::SCALE + 4);

            return $this->bcRound($raw, self::SCALE);
        }

        // addition / deduction / formula / subtotal / total — signed sum of terms.
        $sum = '0.00';
        foreach ($operands['terms'] ?? [] as $term) {
            // Null-safe (?? ), matching assertNumericOperands()'s own check
            // above — a term with no 'ref' key at all (a shape the
            // validator explicitly tolerates) would otherwise throw an
            // "Undefined array key" warning here, which Laravel promotes to
            // a 500.
            $ref = $term['ref'] ?? null;
            $termValue = $ref !== null && $ref !== ''
                ? ($computed[$ref] ?? '0.00')
                : bcadd((string) ($term['value'] ?? 0), '0', self::SCALE);

            $sum = ($term['operator'] ?? '+') === '-'
                ? bcsub($sum, $termValue, self::SCALE)
                : bcadd($sum, $termValue, self::SCALE);
        }

        return $sum;
    }

    /**
     * Resolves the fixed set of cross-certificate/project values a
     * `reference` line can pull in — cheap lookups against denormalized
     * columns rather than re-evaluating any prior certificate's full graph.
     */
    private function referenceContext(ProjectCertificate $certificate): array
    {
        $certificate->loadMissing('contract');
        $contract = $certificate->contract;

        $previous = ProjectCertificate::where('warehouse_id', $certificate->warehouse_id)
            ->where('status', ProjectCertificate::STATUS_ISSUED)
            ->where('certificate_number', '<', $certificate->certificate_number)
            ->orderByDesc('certificate_number')
            ->first();

        return [
            'previous_certificate_cumulative' => $previous ? (string) $previous->cumulative_certified_amount : '0.00',
            // SUM('amount') straight from the payments table, not
            // totalPaid()'s (float) cast — a float round-trip starts losing
            // cents on figures beyond roughly 1e13 and throws outright
            // beyond ~1e14, where the decimal(18,2) column it came from
            // never had a problem representing the value at all.
            'previous_certificate_net_paid' => $previous
                ? (string) $previous->payments()->sum('amount')
                : '0.00',
            'contract_original_sum' => $contract ? (string) $contract->original_contract_sum : '0.00',
            // "Contract Sum" in the QS-facing picker — must be the CURRENT
            // total, i.e. revised sum plus every already-approved addendum,
            // matching the Contract Setup page's own "Total Project Amount"
            // and Builder.vue's addendumStats card exactly. Bare
            // revised_contract_sum never moves once an addendum is approved,
            // so using it alone here would silently under-value any
            // certificate line built from this reference.
            'contract_revised_sum' => $contract
                ? bcadd((string) $contract->revised_contract_sum, (string) $contract->addendums()->where('status', \App\Models\ProjectCertificateContractAddendum::STATUS_APPROVED)->sum('total_amount'), 2)
                : '0.00',
            'contract_variation_amount' => $contract ? (string) $contract->contract_variation_amount : '0.00',
        ];
    }

    /**
     * bcmath has no native rounding — bcadd/bcsub/bcmul/bcdiv all truncate
     * at the given scale. This rounds half-away-from-zero (matching PHP's
     * own round()) by nudging the value by half a unit at the target scale
     * before truncating, sign-aware so a negative (deduction) value rounds
     * the same way a positive one does rather than toward zero.
     */
    private function bcRound(string $number, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';

        return bccomp($number, '0', $scale + 4) < 0
            ? bcsub($number, $half, $scale)
            : bcadd($number, $half, $scale);
    }
}
