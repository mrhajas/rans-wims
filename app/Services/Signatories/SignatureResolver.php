<?php

namespace App\Services\Signatories;

use App\Models\SignatureMissing;
use App\Models\SignatureSnapshot;
use App\Models\SignatureVersion;
use App\Models\Signatory;
use App\Models\SigningMapping;
use App\Models\Staff;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Signatories\Documents\SignableDocument;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works out, for one printable record, which signature goes where. It reads the document's REAL workflow history (never the signed-in
 * person), applies the document setup (signing roles, qualifying action, when to show, what to do when missing) and returns slots ready for
 * the report partial. It never invents a name, a date or a picture:
 *
 *  - an action that did not happen gives an empty "pending" slot (blank line), never a signature;
 *  - an action whose actor or time is not recorded is reported as such;
 *  - a record in progress uses the person's CURRENT usable signature (active and not suspended or revoked);
 *  - a FINAL record uses the signature that was valid when the action happened (and is frozen into a snapshot the first time it is
 *    resolved), so a later replacement, suspension or revocation never rewrites a finalised document;
 *  - when no signature was on file at the time of the action, the slot says so instead of showing today's signature.
 */
class SignatureResolver
{
    /** Lookups remembered while scoped() runs (null = off, so nothing is ever remembered between requests or tests). */
    private static ?array $memo = null;

    /**
     * Runs $callback with the lookups that cannot change while it runs (the setup, the settings, the people and their signatories) remembered, so
     * a page that resolves many records of one kind (a printed batch of vouchers) reads them once instead of once per record.
     */
    public static function scoped(callable $callback)
    {
        $outer = self::$memo;
        self::$memo ??= [];
        try {
            return $callback();
        } finally {
            self::$memo = $outer;
        }
    }

    private static function remember(string $key, callable $make)
    {
        if (self::$memo === null) {
            return $make();
        }
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $make();
        }

        return self::$memo[$key];
    }

    /** @return array<string, class-string<SignableDocument>> every document adapter found in app/Services/Signatories/Documents, by its key */
    public static function adapters(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (glob(__DIR__ . '/Documents/*Document.php') as $file) {
                $class = 'App\\Services\\Signatories\\Documents\\' . basename($file, '.php');
                if ($class !== SignableDocument::class && class_exists($class) && is_subclass_of($class, SignableDocument::class)) {
                    $map[$class::key()] = $class;
                }
            }
            ksort($map);
        }

        return $map;
    }

    public function adapter(string $type): ?SignableDocument
    {
        $class = self::adapters()[$type] ?? null;

        return $class ? app($class) : null;
    }

    public function enabledFor(string $type, ?User $viewer = null): bool
    {
        $viewer ??= auth()->user();

        return (bool) $viewer && self::remember('enabled|' . $viewer->id . '|' . $type, fn () => FeatureManager::canAccess('signatories', $viewer) && FeatureManager::canAccess('signatories_auto_signing', $viewer)
            && $this->adapter($type) !== null
            && SigningMapping::query()->where('account_id', $viewer->account_id)->where('document_type', $type)->where('is_active', true)->exists());
    }

    /**
     * @return array{enabled:bool, state:string, draft:bool, slots:list<array>, missing:list<array>, blockers:list<array>}
     */
    public function resolve(string $type, int|object $recordOrId, ?User $viewer = null, bool $record = true, bool $live = false): array
    {
        $viewer ??= auth()->user();
        $empty = ['enabled' => false, 'state' => 'draft', 'draft' => false, 'slots' => [], 'missing' => [], 'blockers' => []];
        $adapter = $this->adapter($type);
        if (! $viewer || ! $adapter || ! $this->enabledFor($type, $viewer)) {
            return $empty;
        }
        $rec = is_object($recordOrId) ? $recordOrId : $adapter->find((int) $recordOrId);
        if (! $rec) {
            return $empty;
        }
        $account = (int) $viewer->account_id;
        $settings = self::remember('settings|' . $account, fn () => SignatorySettings::get($account));
        $state = $adapter->state($rec);
        // a report that is generated on request (no stored record, id 0) is never frozen or logged as missing: it always uses the current usable signature
        $transient = (int) ($rec->id ?? 0) === 0;
        if ($transient && $state === 'final') {
            $state = 'in_progress';
        }
        $draft = $state === 'draft';
        $history = $adapter->history($rec);
        $recordId = (int) ($rec->id ?? 0);
        $reference = $adapter->reference($rec);
        $maps = self::remember('maps|' . $account . '|' . $type, fn () => SigningMapping::query()->with('role')->where('account_id', $account)->where('document_type', $type)->where('is_active', true)->orderBy('slot')->get());
        $snapshots = SignatureSnapshot::query()->where('document_type', $type)->where('record_id', $recordId)->get()->keyBy(fn ($s) => $s->signing_role_key . '|' . $s->workflow_action);

        $slots = [];
        $missing = [];
        $blockers = [];
        foreach ($maps as $m) {
            if (! $m->role || ! $m->role->is_active) {
                continue;
            }
            if ($m->permission && ! $viewer->can($m->permission)) {
                continue;
            }
            $slot = ['slot' => (int) $m->slot, 'role_key' => $m->role->key, 'role_label' => $m->role->label, 'action' => $m->workflow_action, 'required' => (bool) $m->is_required,
                'state' => 'pending', 'name' => null, 'designation' => null, 'date' => null, 'time' => null, 'at' => null, 'image' => null, 'version_id' => null, 'user_id' => null, 'note' => null, 'action_label' => $adapter::actions()[$m->workflow_action] ?? $m->workflow_action];
            $key = $m->role->key . '|' . $m->workflow_action;
            $did = $history[$m->workflow_action] ?? null;

            // visibility rules: draft documents show no signatures unless allowed; "final only" setups wait for the final state
            $hide = ($draft && ! $settings['show_on_draft']) || ($m->show_when === 'final' && $state !== 'final');
            if ($hide) {
                $slot['state'] = $draft ? 'draft' : 'pending';
                $slots[] = $slot;
                continue;
            }
            if (! $did) {
                if ($state === 'final' && $m->is_required) {
                    $this->gap($missing, $blockers, $m, $slot, 'no_action', 'The ' . $slot['action_label'] . ' action is not recorded on this record.');
                }
                // "after the action": the line only appears once the action has happened; "always": a blank line waits for the signer
                if ($m->show_when !== 'after') {
                    $slots[] = $slot;
                }
                continue;
            }

            // the action happened: who, and when (as recorded)
            $at = $did['at'] instanceof Carbon ? $did['at'] : null;
            $slot['note'] = ($did['quality'] ?? 'good') === 'partial' && ! empty($did['note']) ? (string) $did['note'] : null;
            $slot['at'] = $at?->toDateTimeString();
            if ($at && $settings['show_datetime']) {
                $slot['date'] = $at->format($settings['date_format']);
                $slot['time'] = $at->format($settings['time_format']);
            } elseif (! $at && $settings['show_datetime']) {
                $slot['note'] = trim(($slot['note'] ? $slot['note'] . ' ' : '') . 'The date and time of this action were not recorded.');
            }
            $user = $did['user_id'] ? self::remember('user|' . $did['user_id'], fn () => User::query()->withTrashed()->find($did['user_id'])) : null;
            if (! $user) {
                // a name written on the record without a user cannot be matched to a signature
                $slot['state'] = 'unmatched';
                $slot['name'] = $did['name'] ?: null;
                $slot['note'] = trim(($slot['note'] ? $slot['note'] . ' ' : '') . 'The person who did this is not linked to a WIMS user, so no signature can be shown.');
                $this->gap($missing, $blockers, $m, $slot, 'no_actor', 'The ' . $slot['action_label'] . ' was recorded without a WIMS user.');
                $slots[] = $slot;
                continue;
            }
            $staff = self::remember('staff|' . $user->id, fn () => Staff::query()->where('user_id', $user->id)->first());
            $slot['user_id'] = $user->id;
            $slot['name'] = $settings['show_name'] ? $user->name : null;
            $slot['designation'] = $settings['show_designation'] ? ($staff->position ?? null) : null;

            // frozen set of a finalised record wins
            // a frozen set only counts while it still describes the same person and the same moment (an approval that was reversed and given
            // again by somebody else, or at another time, is a different action: the old freeze is dropped and the current one is frozen)
            if (isset($snapshots[$key]) && ((int) $snapshots[$key]->user_id !== (int) $user->id || ($at ? (string) optional($snapshots[$key]->action_at)->toDateTimeString() !== $at->toDateTimeString() : $snapshots[$key]->action_at !== null))) {
                $snapshots[$key]->delete();
                unset($snapshots[$key]);
            }
            // a record frozen earlier WITHOUT a signature (printed before the signer had one) is looked at again: if a signature can be shown now, it replaces that blank freeze
            if ($state === 'final' && isset($snapshots[$key]) && ! $snapshots[$key]->signature_version_id) {
                [$again] = $this->pick($user, true, $at);
                if ($again) {
                    $snapshots[$key]->delete();
                    unset($snapshots[$key]);
                }
            }
            if ($state === 'final' && isset($snapshots[$key])) {
                $snap = $snapshots[$key];
                $v = $snap->signature_version_id ? SignatureVersion::query()->find($snap->signature_version_id) : null;
                $slot['name'] = $settings['show_name'] ? ($snap->name ?: $slot['name']) : null;
                $slot['designation'] = $settings['show_designation'] ? ($snap->designation ?: $slot['designation']) : null;
                $slot['version_id'] = $v?->id;
                $slot['image'] = $v ? app(SignatureStore::class)->dataUri($v) : null;
                $slot['state'] = $slot['image'] ? 'signed' : 'no_signature';
                $slot['note'] = $snap->limitation ?: $slot['note'];
                $slot['applied_after'] = $slot['image'] && str_starts_with((string) $snap->limitation, 'Signature applied after');
                if (! $slot['image']) {
                    $this->gap($missing, $blockers, $m, $slot, 'no_version_at_time', $snap->limitation ?: 'No signature was on file for this person when the action was taken.');
                }
                $slots[] = $slot;
                continue;
            }

            [$version, $why, $limitation] = $this->pick($user, $state === 'final' && ! $live, $at);
            if ($version && ($img = app(SignatureStore::class)->dataUri($version))) {
                $slot['image'] = $img;
                $slot['version_id'] = $version->id;
                $slot['state'] = 'signed';
                if ($limitation) {
                    $slot['note'] = trim(($slot['note'] ? $slot['note'] . ' ' : '') . $limitation); // e.g. "Signature applied after the action"
                    $slot['applied_after'] = true;
                }
            } else {
                $slot['state'] = 'no_signature';
                $slot['note'] = trim(($slot['note'] ? $slot['note'] . ' ' : '') . ($limitation ?: $this->reasonText($why)));
                $this->gap($missing, $blockers, $m, $slot, $why, $limitation ?: $this->reasonText($why));
            }
            $slots[] = $slot;

            if ($state === 'final' && $record) {
                $this->freeze($account, $type, $recordId, $m, $slot, $version, $limitation);
            }
        }

        if ($record && ! $transient && $missing) {
            $this->recordMissing($viewer, $type, $recordId, $reference, $missing, $settings);
        }

        return ['enabled' => true, 'state' => $state, 'draft' => $draft, 'slots' => $slots, 'missing' => $missing, 'blockers' => $blockers];
    }

    /** Required signatures that would stop a record being finalised (setups with "blocks final"); used by the workflow hooks. */
    public function blockers(string $type, int|object $recordOrId, ?User $viewer = null): array
    {
        // the person acts NOW, so their signature must be usable now (not merely have been valid at some past moment)
        return $this->resolve($type, $recordOrId, $viewer, false, true)['blockers'];
    }

    /**
     * The signature version to use, or why there is none.
     * @return array{0:?SignatureVersion, 1:?string, 2:?string} version, reason key, limitation text
     */
    private function pick(User $user, bool $historical, ?Carbon $at): array
    {
        $sig = self::remember('signatory|' . $user->account_id . '|' . $user->id, fn () => Signatory::query()->where('account_id', $user->account_id)->where('user_id', $user->id)->first());
        if (! $sig) {
            return [null, 'not_registered', null];
        }
        if ($historical) {
            if (! $at) {
                if (SignatorySettings::get((int) $user->account_id)['apply_to_older_records'] && $sig->usable() && ($cur = SignatureVersion::query()->find($sig->active_version_id))) {
                    return [$cur, null, 'Signature applied after the action: the time of the action was not recorded.'];
                }

                return [null, 'no_version_at_time', 'The time of the action is not recorded, so the signature in use then cannot be established.'];
            }
            $v = SignatureVersion::query()->where('signatory_id', $sig->id)->whereNotNull('activated_at')->where('activated_at', '<=', $at)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->orderByDesc('activated_at')->first();

            if ($v) {
                return [$v, null, null];
            }
            // the signer had no signature on file when the action was taken: with the setting on, the one now in use is shown and labelled as applied afterwards
            if (SignatorySettings::get((int) $user->account_id)['apply_to_older_records'] && $sig->usable() && ($cur = SignatureVersion::query()->find($sig->active_version_id)) && $cur->activated_at) {
                return [$cur, null, 'Signature applied after the action' . ($at ? ' (' . $at->format('d M Y') . ')' : '') . ': it was registered on ' . $cur->activated_at->format('d M Y') . '.'];
            }

            return [null, 'no_version_at_time', 'No signature was on file for this person when the action was taken (' . $at->format('d M Y') . ').'];
        }
        if (! $sig->usable()) {
            return [null, 'not_active', null];
        }

        return [SignatureVersion::query()->find($sig->active_version_id), null, null];
    }

    private function reasonText(?string $why): string
    {
        return [
            'not_registered' => 'This person has no registered signature.', 'not_active' => 'This person\'s signature is not active (pending, suspended or revoked).',
            'no_version_at_time' => 'No signature was on file when the action was taken.', 'no_actor' => 'The person who did this is not recorded.',
        ][$why] ?? 'The signature is not available.';
    }

    private function gap(array &$missing, array &$blockers, SigningMapping $m, array $slot, string $reason, string $detail): void
    {
        $row = ['role_key' => $slot['role_key'], 'role_label' => $slot['role_label'], 'action' => $slot['action'], 'user_id' => $slot['user_id'], 'reason' => $reason, 'detail' => $detail, 'required' => (bool) $m->is_required];
        $missing[] = $row;
        $behaviour = $m->missing_behaviour === 'block' || ($m->blocks_final && $m->is_required) ? 'block' : $m->missing_behaviour;
        if ($m->is_required && $m->blocks_final && $behaviour === 'block') {
            $blockers[] = $row;
        }
    }

    private function freeze(int $account, string $type, int $recordId, SigningMapping $m, array $slot, ?SignatureVersion $version, ?string $limitation): void
    {
        SignatureSnapshot::query()->firstOrCreate(
            ['document_type' => $type, 'record_id' => $recordId, 'signing_role_key' => $slot['role_key'], 'workflow_action' => $slot['action']],
            ['account_id' => $account, 'user_id' => $slot['user_id'], 'signature_version_id' => $version?->id, 'name' => $slot['name'], 'designation' => $slot['designation'], 'action_at' => $slot['at'], 'action_label' => $slot['action_label'], 'limitation' => $limitation, 'frozen_at' => now()],
        );
    }

    private function recordMissing(User $viewer, string $type, int $recordId, string $reference, array $missing, array $settings): void
    {
        foreach ($missing as $g) {
            if (! $g['required'] && $settings['default_missing_behaviour'] === 'blank') {
                continue;
            }
            $row = SignatureMissing::query()->firstOrNew(['document_type' => $type, 'record_id' => $recordId, 'signing_role_key' => $g['role_key'], 'reason' => $g['reason']]);
            $isNew = ! $row->exists;
            $row->fill(['account_id' => $viewer->account_id, 'user_id' => $g['user_id'], 'detail' => mb_substr($g['detail'], 0, 250), 'blocked' => $g['required'], 'raised_by' => $viewer->id, 'seen_at' => now()])->save();
            if ($isNew) {
                log_activity('Missing signature on a report', ['document' => $type, 'record_id' => $recordId, 'reference' => $reference, 'role' => $g['role_key'], 'reason' => $g['reason'], 'subject_user_id' => $g['user_id']], null, 'Signatories');
                WorkflowMessenger::dispatch('signatories', 'missing_signature', null, $viewer, [
                    'account_id' => $viewer->account_id, 'title' => __('A required signature is missing'), 'message' => __('The :r signature is missing on :d :ref: :why', ['r' => $g['role_label'], 'd' => (self::adapters()[$type] ?? null) ? (self::adapters()[$type])::label() : $type, 'ref' => $reference, 'why' => $g['detail']]),
                    'values' => ['signingrole' => $g['role_label'], 'document' => (string) (self::adapters()[$type] ?? null) ? (self::adapters()[$type])::label() : $type, 'reference' => $reference, 'reason' => $g['detail']], 'dedupe' => 'signatories|missing|' . $type . '|' . $recordId . '|' . $g['role_key'],
                ]);
            }
        }
    }
}
