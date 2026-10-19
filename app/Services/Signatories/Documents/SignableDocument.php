<?php

namespace App\Services\Signatories\Documents;

use Illuminate\Support\Carbon;

/**
 * How one kind of printable record tells the Signatories module WHO did each workflow action and WHEN, from the data WIMS really keeps
 * (stamped columns, status logs). An adapter never guesses: an action that is not recorded is simply absent from history().
 */
interface SignableDocument
{
    /** Stable key used in the document setup (for example "payment_voucher"). */
    public static function key(): string;

    public static function label(): string;

    /** The recorded workflow actions this document can have: key => label (for the document setup page). */
    public static function actions(): array;

    /** The record, or null when it does not exist. */
    public function find(int $id): ?object;

    /** "draft" (not yet in the workflow), "in_progress" (some actions done), "final" (issued: no more signing actions will happen). */
    public function state(object $record): string;

    /**
     * What the system really recorded. action key => ['user_id' => ?int, 'at' => ?Carbon, 'name' => ?string (only when the record has just a name, no user), 'quality' => 'good'|'partial', 'note' => ?string].
     * Leave an action out when it did not happen (or happened and is no longer standing, for example an approval that was reversed).
     *
     * @return array<string, array{user_id: ?int, at: ?Carbon, name: ?string, quality: string, note: ?string}>
     */
    public function history(object $record): array;

    /**
     * The starting document setup (editable on the Document Setup page): list of
     * ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'|'after'|'final'] where 'always' keeps a blank signature line for a signer who has not acted yet (the same as the old printed layout), 'after' shows the line only once the action happened, 'final' only on the final document.
     * Only signing roles that already exist (see the seeded list) can be used. Nothing here is required by default.
     */
    public static function defaultMappings(): array;

    /** A short human reference for messages ("PV-2026-0042"). */
    public function reference(object $record): string;
}
