<?php

namespace App\Services\Signatories\Documents;

use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateRevision;
use Illuminate\Support\Carbon;

/**
 * Project Certificate (interim payment certificate). Real records: created_by + created_at, submitted_by/at, approved_by/at, issued_by/at.
 *
 * Workflow: draft -> submitted -> approved -> issued. Revising an issued certificate sets it back to draft and bumps the version but LEAVES
 * the old submitted/approved/issued columns on the row, and Cancel writes no actor. So an action is reported only when
 *  - the current status is at or past that stage (a revised draft shows nothing but the preparer), and
 *  - for a certificate that was revised (version above 1) the action happened AFTER the latest revision was recorded
 *    (project_certificate_revisions.created_at), which is the only way to tell a stamp of this version from one left by a previous version.
 * A cancelled certificate has no stage in its status, so the same timestamp test decides which stamps still describe it.
 * Who revised it is not an approval and is never reported.
 */
final class ProjectCertificateDocument implements SignableDocument
{
    private const RANK = [
        ProjectCertificate::STATUS_DRAFT => 0, ProjectCertificate::STATUS_REVISED => 0, ProjectCertificate::STATUS_SUBMITTED => 1,
        ProjectCertificate::STATUS_APPROVED => 2, ProjectCertificate::STATUS_ISSUED => 3,
    ];

    public static function key(): string
    {
        return 'project_certificate';
    }

    public static function label(): string
    {
        return 'Project Certificate';
    }

    public static function actions(): array
    {
        return [
            'created' => 'Prepared (certificate created by the Quantity Surveyor)',
            'submitted' => 'Submitted for review',
            'approved' => 'Approved',
            'issued' => 'Issued',
        ];
    }

    public function find(int $id): ?object
    {
        return ProjectCertificate::withoutGlobalScopes()->find($id);
    }

    public function state(object $record): string
    {
        return match ($record->status) {
            ProjectCertificate::STATUS_DRAFT, ProjectCertificate::STATUS_REVISED => 'draft',
            ProjectCertificate::STATUS_ISSUED => 'final',
            default => 'in_progress', // submitted, approved, cancelled
        };
    }

    public function history(object $record): array
    {
        $h = [];
        if ($record->created_by || $record->created_at) {
            $h['created'] = $this->entry($record->created_by, $record->created_at, $record->created_by ? null : 'The person who created this certificate is not recorded.');
        }

        $rank = self::RANK[$record->status] ?? null; // null: cancelled
        $revisedAt = $this->latestRevisionAt($record);
        $stages = ['submitted' => [1, 'submitted_by', 'submitted_at'], 'approved' => [2, 'approved_by', 'approved_at'], 'issued' => [3, 'issued_by', 'issued_at']];
        foreach ($stages as $action => [$need, $byCol, $atCol]) {
            $by = $record->{$byCol};
            $at = $record->{$atCol};
            if ($rank !== null && $rank < $need) {
                continue; // not at that stage (any stamp left is from a previous version)
            }
            if (! $by && ! $at) {
                continue; // never recorded
            }
            if ((int) $record->version > 1) {
                if ($rank === null && ! $revisedAt) {
                    continue; // cancelled, revised, and no revision row to compare with: cannot tell, leave out
                }
                if ($revisedAt && $at && $at->lte($revisedAt)) {
                    continue; // stamped before the latest revision: belongs to the superseded version
                }
                if ($revisedAt && ! $at && $rank === null) {
                    continue;
                }
            }
            $note = null;
            if (! $by) {
                $note = 'The person who did this is not recorded.';
            } elseif (! $at) {
                $note = 'The date and time of this action are not recorded.';
            }
            $h[$action] = $this->entry($by, $at, $note);
        }

        return $h;
    }

    public static function defaultMappings(): array
    {
        // Printed labels: Prepared By (Quantity Surveyor), Approved By, Issued By.
        return [
            ['role' => 'prepared_by', 'action' => 'created', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
            ['role' => 'approved_by', 'action' => 'approved', 'slot' => 2, 'required' => false, 'show_when' => 'always'],
            ['role' => 'issued_by', 'action' => 'issued', 'slot' => 3, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?: ('Certificate #' . $record->certificate_number));
    }

    /** A copy of the certificate as it will be once $userId issues it (nothing is saved): for the finalisation guard. */
    public function asIfIssued(object $record, int $userId): object
    {
        $copy = clone $record;
        $copy->forceFill(['status' => ProjectCertificate::STATUS_ISSUED, 'issued_by' => $userId, 'issued_at' => now()]);

        return $copy;
    }

    private function latestRevisionAt(object $record): ?Carbon
    {
        $at = ProjectCertificateRevision::query()->where('certificate_id', $record->id)->max('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    private function entry(?int $userId, $at, ?string $note): array
    {
        return [
            'user_id' => $userId ? (int) $userId : null,
            'at' => $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : null),
            'name' => null,
            'quality' => $note ? 'partial' : 'good',
            'note' => $note,
        ];
    }
}
