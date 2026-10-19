<?php

namespace App\Services;

use App\Models\ProjectCertificate;
use App\Models\ProjectCertificateContractAddendum;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Project Certificates notices beyond the certificate steps: edits, the calculation, payment corrections and status override, the project
 * contract and its addendums. Who is told, on which channels and with which words is set on the Project Certificates workflow page.
 */
class CertificateNotifier
{
    /** An action on one certificate (the certificate tags are filled from the record). */
    public static function certificate(string $event, ProjectCertificate $c, ?User $actor, string $title, string $message, array $ctx = []): bool
    {
        $actor ??= auth()->user();
        // the reference is computed from the project's code: load the whole project (the sender would otherwise load only its name)
        $c->loadMissing('warehouse');

        return WorkflowEvent::fire('certificates', $event, $c, $actor, $title, $message, $ctx + [
            'owner_user_id' => (int) $c->created_by, 'warehouse_ids' => $c->warehouse_id ? [$c->warehouse_id] : null,
            'url' => self::route('project-certificates.show', $c->id),
        ]);
    }

    /** An action on a project's contract or one of its addendums. */
    public static function contract(string $event, Warehouse $warehouse, ?ProjectCertificateContractAddendum $addendum, ?User $actor, string $title, string $message): bool
    {
        $actor ??= auth()->user();
        $reference = $addendum ? (string) $addendum->addendum_number : (string) optional($warehouse->certificateContract ?? null)->contract_reference;

        return WorkflowEvent::fire('certificates', $event, $addendum, $actor, $title, $message, [
            'owner_user_id' => (int) ($addendum?->created_by ?? 0), 'warehouse_ids' => [$warehouse->id],
            'url' => $addendum ? self::route('project-certificates.contract.addendums.show', [$warehouse->id, $addendum->id]) : self::route('project-certificates.project.index', $warehouse->id),
            'details' => array_filter([__('Project') => $warehouse->name, __('Addendum') => $addendum?->addendum_number, __('Title') => $addendum?->title], fn ($v) => filled($v)),
            'values' => [
                'reference' => $reference, 'project' => (string) $warehouse->name, 'status' => (string) ($addendum?->status ?? ''), 'type' => $addendum ? 'Addendum' : 'Contract',
                'amount' => $addendum ? 'TZS ' . number_format((float) $addendum->total_amount, 0) : '', 'period' => '', 'creator' => (string) optional($addendum?->createdBy)->name,
            ],
        ]);
    }

    private static function route(string $name, $params): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
