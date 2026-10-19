<?php

namespace App\Services\Signatories;

use App\Models\SignatureVersion;
use App\Models\Signatory;
use App\Models\Staff;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use Illuminate\Support\Facades\DB;

/**
 * The life of a signature: preview > confirm (consent) > verify > activate > replace / suspend / revoke. Every step checks the rules in the
 * settings, is done inside a transaction and is written to the activity log (log name "Signatories") without any picture data. A picture
 * uploaded for somebody never makes them a signatory by itself: it stays pending until a verifier activates it.
 */
class SignatoryService
{
    public function __construct(private SignatureProcessor $processor, private SignatureStore $store)
    {
    }

    /** First half: look at the picture. Nothing is saved as a signature. @return array{ok:bool, token:?string, preview:?string, reasons:list<string>, metrics:array} */
    public function preview(User $actor, User $subject, string $binary, string $method, float $sensitivity = 1.0, ?string $token = null): array
    {
        $s = SignatorySettings::get((int) $subject->account_id);
        $opts = SignatorySettings::processorOptions($s);
        $opts['sensitivity'] = max(0.5, min(1.8, $sensitivity));
        $r = $this->processor->process($binary, $opts);
        // the original is kept (private) even when processing failed, so the person can adjust or retry without uploading again
        $token = $token ?: $this->store->stash((int) $actor->id, $binary, ['subject_user_id' => $subject->id, 'method' => $method]);
        log_activity($r['ok'] ? 'Signature picture processed' : 'Signature picture could not be processed', ['subject_user_id' => $subject->id, 'subject' => $subject->name, 'method' => $method, 'ok' => $r['ok'], 'reasons' => $r['reasons'], 'metrics' => $r['metrics']], null, 'Signatories');

        return ['ok' => $r['ok'], 'token' => $token, 'preview' => $r['ok'] ? 'data:image/png;base64,' . base64_encode($r['png']) : null, 'reasons' => $r['reasons'], 'metrics' => $r['metrics'], 'size' => $r['ok'] ? [$r['width'], $r['height']] : null];
    }

    /** Second half: the person looked at the processed picture and confirms it. Creates a new version (pending, or active when the settings do not require verification). */
    public function confirm(User $actor, User $subject, string $token, float $sensitivity, bool $consent): SignatureVersion
    {
        $s = SignatorySettings::get((int) $subject->account_id);
        // A person with the "without confirmation" ability may register for somebody else: the signatory is told in the app bell instead of confirming first.
        $waived = (int) $actor->id !== (int) $subject->id && $s['consent_required'] && $this->mayWaiveConsent($actor);
        if ($s['consent_required'] && ! $consent && ! $waived) {
            throw new SignatoryException('The signatory must confirm that this signature is theirs and may be used on the listed documents.');
        }
        $stash = $this->store->stashed((int) $actor->id, $token);
        if (! $stash || (int) ($stash[1]['subject_user_id'] ?? 0) !== (int) $subject->id) {
            throw new SignatoryException('This picture is no longer waiting for confirmation. Upload or capture it again.');
        }
        [$binary, $meta] = $stash;
        $opts = SignatorySettings::processorOptions($s);
        $opts['sensitivity'] = max(0.5, min(1.8, $sensitivity));
        $r = $this->processor->process($binary, $opts);
        if (! $r['ok']) {
            throw new SignatoryException(implode(' ', $r['reasons']));
        }

        $version = DB::transaction(function () use ($actor, $subject, $s, $binary, $meta, $r, $opts, $waived) {
            $sig = Signatory::query()->where('account_id', $subject->account_id)->where('user_id', $subject->id)->lockForUpdate()->first();
            if ($sig && $sig->status === Signatory::REVOKED) {
                throw new SignatoryException('This signatory was revoked. A person with the revoke permission has to reinstate the person first.');
            }
            if (! $sig) {
                $staff = Staff::query()->where('user_id', $subject->id)->first();
                $sig = Signatory::create(['account_id' => $subject->account_id, 'user_id' => $subject->id, 'staff_id' => $staff?->id, 'status' => Signatory::PENDING, 'registered_by' => $actor->id, 'registered_at' => now()]);
            }
            $no = (int) SignatureVersion::query()->where('signatory_id', $sig->id)->max('version_no') + 1;
            $paths = $this->store->saveVersion((int) $subject->account_id, $sig->id, $no, $r['png'], $this->processor->thumbnail($r['png'], 260), (int) $s['original_retention_days'] > 0 ? $binary : null);
            $version = SignatureVersion::create([
                'signatory_id' => $sig->id, 'version_no' => $no, 'status' => SignatureVersion::PENDING, 'method' => $meta['method'] ?? 'upload', 'processed_path' => $paths['processed'], 'thumb_path' => $paths['thumb'],
                'original_path' => $paths['original'], 'width' => $r['width'], 'height' => $r['height'], 'sha256' => hash('sha256', $r['png']), 'ink_color' => $opts['ink_color'], 'metrics' => $waived ? $r['metrics'] + ['consent_waived_by' => $actor->id] : $r['metrics'],
                'uploaded_by' => $actor->id, 'uploaded_at' => now(),
                // consent is the SIGNATORY's own confirmation: given here only when they register themselves (or the settings do not ask for it)
                'consent_by' => ($actor->id === $subject->id || ! $s['consent_required'] || $waived) ? $actor->id : null,
                'consent_at' => ($actor->id === $subject->id || ! $s['consent_required'] || $waived) ? now() : null,
            ]);

            return $version;
        });
        $this->store->forget((int) $actor->id, $token);
        $this->alert('registered', $actor, (int) $subject->id, __('Signature waiting for verification'), __(':a registered a signature for :s. It has to be verified before it can be used.', ['a' => $actor->name, 's' => $subject->name]));
        log_activity('Signature registered', ['subject_user_id' => $subject->id, 'subject' => $subject->name, 'version' => $version->version_no, 'method' => $version->method, 'sha256' => $version->sha256, 'by_self' => $actor->id === $subject->id, 'consent_by' => $version->consent_by, 'consent_waived' => $waived], null, 'Signatories');
        if ($waived) {
            // the signatory did not confirm first, so they are told (Signatories Alerts: "A signature was registered for you")
            $this->alert('registered_for_you', $actor, (int) $subject->id, __('A signature was registered for you'), __(':a registered a signature for you. It is not used on any report until it has been verified.', ['a' => $actor->name]));
        }

        return $version;
    }

    /** May this person register for somebody else without that person's own confirmation? */
    public function mayWaiveConsent(User $actor): bool
    {
        return $actor->can('register-signatories-without-confirmation');
    }

    /** Tell the Alerts audience (all events start as set on the Signatories Alerts page). The picture is never part of any message. */
    private function alert(string $event, User $actor, int $subjectUserId, string $title, string $message, ?string $remarks = null): void
    {
        $subject = User::query()->withTrashed()->find($subjectUserId);
        WorkflowMessenger::dispatch('signatories', $event, null, $actor, [
            'account_id' => $actor->account_id, 'title' => $title, 'message' => $message, 'remarks' => (string) $remarks, 'owner_user_id' => $subjectUserId, 'url' => route('signatories.show', Signatory::query()->where('user_id', $subjectUserId)->value('id') ?: 0),
            'values' => ['signatory' => (string) ($subject->name ?? '')], 'dedupe' => 'signatories|' . $event . '|' . $subjectUserId . '|' . intdiv(time(), 30),
        ]);
    }

    /** The signatory confirms that a picture registered for them is theirs and may be used on the listed documents. Only they can. */
    public function consent(User $actor, SignatureVersion $version): SignatureVersion
    {
        $sig = $version->signatory;
        if ((int) $actor->id !== (int) $sig->user_id) {
            throw new SignatoryException('Only the signatory can confirm their own signature.');
        }
        if ($version->status !== SignatureVersion::PENDING) {
            throw new SignatoryException('Only a signature that is waiting for verification can be confirmed.');
        }
        $version->forceFill(['consent_by' => $actor->id, 'consent_at' => now()])->save();
        log_activity('Signature confirmed by the signatory', ['subject_user_id' => $sig->user_id, 'version' => $version->version_no], null, 'Signatories');

        return $version;
    }

    /** Verify (a person other than the signatory, when the settings require it) and activate in one recorded step. */
    public function verifyAndActivate(User $actor, SignatureVersion $version): SignatureVersion
    {
        $sig = $version->signatory;
        $subject = User::query()->withTrashed()->findOrFail($sig->user_id);
        $s = SignatorySettings::get((int) $sig->account_id);
        if ($version->status !== SignatureVersion::PENDING) {
            throw new SignatoryException('Only a signature that is waiting for verification can be verified.');
        }
        if ($sig->status === Signatory::REVOKED) {
            throw new SignatoryException('This signatory is revoked.');
        }
        // by default somebody other than the owner verifies; a person holding "verify-own-signatures" (Super Admin) may verify their own too
        if ($s['verification_required'] && (int) $actor->id === (int) $sig->user_id && ! $actor->can('verify-own-signatures')) {
            throw new SignatoryException('A signature has to be verified by somebody other than its owner.');
        }
        $waived = ! empty(($version->metrics ?? [])['consent_waived_by']) && (int) ($version->metrics['consent_waived_by']) === (int) $version->consent_by;
        if ($s['consent_required'] && ! $version->consent_at) {
            throw new SignatoryException('The signatory has not confirmed this signature yet.');
        }
        if ($s['consent_required'] && ! $waived && (int) $version->consent_by !== (int) $sig->user_id) {
            throw new SignatoryException('The signature was registered for the person by somebody else. The signatory must confirm it themselves before it is verified.');
        }

        DB::transaction(function () use ($actor, $version, $sig) {
            $previous = $sig->active_version_id ? SignatureVersion::query()->lockForUpdate()->find($sig->active_version_id) : null;
            if ($previous && $previous->id !== $version->id) {
                $previous->forceFill(['status' => SignatureVersion::REPLACED, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_reason' => 'Replaced by version ' . $version->version_no])->save();
            }
            $version->forceFill(['status' => SignatureVersion::ACTIVE, 'verified_by' => $actor->id, 'verified_at' => now(), 'activated_by' => $actor->id, 'activated_at' => now()])->save();
            $sig->forceFill(['status' => Signatory::ACTIVE, 'active_version_id' => $version->id, 'verified_by' => $actor->id, 'verified_at' => now(), 'suspended_at' => null, 'suspended_by' => null, 'suspend_reason' => null])->save();
        });
        $this->alert('activated', $actor, (int) $sig->user_id, __('Your signature is active'), __('Your signature was verified by :a and is now used on the reports you sign.', ['a' => $actor->name]));
        log_activity('Signature verified and activated', ['subject_user_id' => $sig->user_id, 'subject' => $subject->name, 'version' => $version->version_no, 'verified_by' => $actor->id], null, 'Signatories');

        return $version->fresh();
    }

    /** May this person choose an earlier signature of $sig as the one in use? The owner (for their own) or a person who may activate signatures. */
    public function mayChoose(User $actor, Signatory $sig): bool
    {
        return $actor->can('activate-signatures') || ((int) $actor->id === (int) $sig->user_id && $actor->can('register-own-signature'));
    }

    /**
     * Use an EARLIER signature again: a version that was verified before and has been replaced. It becomes the one in use (the one now in use is
     * kept in the history as replaced). No new verification is needed, because it was verified once already; reports already issued keep what they
     * showed. A suspended signatory stays suspended.
     */
    public function useVersion(User $actor, SignatureVersion $version): SignatureVersion
    {
        $sig = $version->signatory;
        if (! $this->mayChoose($actor, $sig)) {
            throw new SignatoryException('You are not allowed to choose the signature in use.');
        }
        if ($sig->status === Signatory::REVOKED) {
            throw new SignatoryException('This signatory is revoked.');
        }
        if ($version->status !== SignatureVersion::REPLACED || ! $version->verified_at) {
            throw new SignatoryException('Only a signature that was verified before and later replaced can be chosen again.');
        }
        DB::transaction(function () use ($actor, $version, $sig) {
            $previous = $sig->active_version_id ? SignatureVersion::query()->lockForUpdate()->find($sig->active_version_id) : null;
            if ($previous && $previous->id !== $version->id) {
                $previous->forceFill(['status' => SignatureVersion::REPLACED, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_reason' => 'Replaced by version ' . $version->version_no . ' (chosen again)'])->save();
            }
            $version->forceFill(['status' => SignatureVersion::ACTIVE, 'activated_by' => $actor->id, 'activated_at' => now(), 'ended_at' => null, 'ended_by' => null, 'ended_reason' => null])->save();
            $sig->forceFill(['active_version_id' => $version->id] + ($sig->status === Signatory::PENDING ? ['status' => Signatory::ACTIVE] : []))->save();
        });
        log_activity('Signature version chosen as the one in use', ['subject_user_id' => $sig->user_id, 'version' => $version->version_no, 'chosen_by' => $actor->id], null, 'Signatories');
        $this->alert('activated', $actor, (int) $sig->user_id, __('Your signature in use was changed'), __(':a chose version :n as the signature that is used on the reports you sign.', ['a' => $actor->name, 'n' => $version->version_no]));

        return $version->fresh();
    }

    public function reject(User $actor, SignatureVersion $version, string $reason): SignatureVersion
    {
        if ($version->status !== SignatureVersion::PENDING) {
            throw new SignatoryException('Only a signature that is waiting for verification can be rejected.');
        }
        $version->forceFill(['status' => SignatureVersion::REJECTED, 'ended_at' => now(), 'ended_by' => $actor->id, 'ended_reason' => $reason])->save();
        $this->alert('rejected', $actor, (int) $version->signatory->user_id, __('Your signature was not accepted'), __(':a did not accept the signature you registered.', ['a' => $actor->name]), $reason);
        log_activity('Signature rejected', ['subject_user_id' => $version->signatory->user_id, 'version' => $version->version_no, 'reason' => $reason], null, 'Signatories');

        return $version;
    }

    /** Suspended: not used on NEW reports. Documents already signed keep what they show. */
    public function suspend(User $actor, Signatory $sig, string $reason): Signatory
    {
        if ($sig->status !== Signatory::ACTIVE) {
            throw new SignatoryException('Only an active signature can be suspended.');
        }
        $sig->forceFill(['status' => Signatory::SUSPENDED, 'suspended_at' => now(), 'suspended_by' => $actor->id, 'suspend_reason' => $reason])->save();
        $this->alert('suspended', $actor, (int) $sig->user_id, __('A signature was suspended'), __(':a suspended a signature.', ['a' => $actor->name]), $reason);
        log_activity('Signature suspended', ['subject_user_id' => $sig->user_id, 'reason' => $reason], null, 'Signatories');

        return $sig;
    }

    public function reinstate(User $actor, Signatory $sig): Signatory
    {
        if ($sig->status !== Signatory::SUSPENDED || ! $sig->active_version_id) {
            throw new SignatoryException('Only a suspended signature can be reinstated.');
        }
        $sig->forceFill(['status' => Signatory::ACTIVE, 'suspended_at' => null, 'suspended_by' => null, 'suspend_reason' => null])->save();
        log_activity('Signature reinstated', ['subject_user_id' => $sig->user_id], null, 'Signatories');

        return $sig;
    }

    /** Revoked: finished for good. The picture stays on file (history) but is never used on a new report. */
    public function revoke(User $actor, Signatory $sig, string $reason): Signatory
    {
        if ($sig->status === Signatory::REVOKED) {
            throw new SignatoryException('This signature is already revoked.');
        }
        DB::transaction(function () use ($actor, $sig, $reason) {
            SignatureVersion::query()->where('signatory_id', $sig->id)->whereIn('status', [SignatureVersion::ACTIVE, SignatureVersion::PENDING, SignatureVersion::SUSPENDED])
                ->each(fn ($v) => $v->forceFill(['status' => SignatureVersion::REVOKED, 'ended_at' => $v->ended_at ?: now(), 'ended_by' => $actor->id, 'ended_reason' => $reason])->save());
            $sig->forceFill(['status' => Signatory::REVOKED, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
        });
        $this->alert('revoked', $actor, (int) $sig->user_id, __('A signature was revoked'), __(':a revoked a signature.', ['a' => $actor->name]), $reason);
        log_activity('Signature revoked', ['subject_user_id' => $sig->user_id, 'reason' => $reason], null, 'Signatories');

        return $sig;
    }

    /** A revoked person can be allowed to register again. Their old versions stay revoked; a new picture starts at version n+1, pending. */
    public function reinstateRevoked(User $actor, Signatory $sig): Signatory
    {
        if ($sig->status !== Signatory::REVOKED) {
            throw new SignatoryException('Only a revoked signatory can be reinstated.');
        }
        $sig->forceFill(['status' => Signatory::PENDING, 'active_version_id' => null, 'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null])->save();
        log_activity('Revoked signatory reinstated', ['subject_user_id' => $sig->user_id], null, 'Signatories');

        return $sig;
    }

    /** Housekeeping: originals past their retention, and old versions beyond the version-retention setting (never the active one). */
    public function housekeeping(): array
    {
        $out = ['originals' => 0, 'stash' => $this->store->purgeStash(), 'versions' => 0];
        foreach (DB::table('signatories')->pluck('account_id')->unique() as $account) {
            $s = SignatorySettings::get((int) $account);
            $days = (int) $s['original_retention_days'];
            SignatureVersion::query()->whereNotNull('original_path')->whereNull('original_purged_at')
                ->whereIn('signatory_id', DB::table('signatories')->where('account_id', $account)->pluck('id'))
                ->where(fn ($q) => $q->where('uploaded_at', '<', now()->subDays($days))->orWhere(fn ($w) => $w->whereNotIn('status', [SignatureVersion::PENDING])->whereRaw('? = 0', [$days])))
                ->each(function ($v) use (&$out) {
                    $this->store->deleteOriginal($v);
                    $out['originals']++;
                });
        }

        return $out;
    }
}
