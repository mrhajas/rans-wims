<?php

namespace App\Services\ProjectCertificates;

use App\Models\ProjectCertificate;

/**
 * get_reference() (app/Helpers/functions.php) is global/random (ulid/uuid/
 * table auto-increment) and has no concept of scoping within a parent
 * record, so it can't produce "next certificate number within THIS
 * project." Certificate numbers are instead a plain sequential integer per
 * warehouse, enforced by a DB unique constraint on
 * (warehouse_id, certificate_number) as the final race-safety net.
 */
class CertificateNumbering
{
    public function nextNumber(int $warehouseId): int
    {
        $max = ProjectCertificate::where('warehouse_id', $warehouseId)->max('certificate_number');

        return ((int) $max) + 1;
    }

    public function isDuplicate(int $warehouseId, int $number, ?int $excludeCertificateId = null): bool
    {
        return ProjectCertificate::where('warehouse_id', $warehouseId)
            ->where('certificate_number', $number)
            ->when($excludeCertificateId, fn ($q) => $q->where('id', '!=', $excludeCertificateId))
            ->exists();
    }

    /**
     * RANS/{year}/{project code}/{00001} — a display label only; nothing
     * calculates against it (certificate_number + warehouse_id remain the
     * real uniqueness/ordering key). $year comes from the certificate's own
     * date, not today's date, so a certificate dated for an earlier period
     * still gets that period's year in its reference.
     */
    public function buildReference(string $warehouseCode, int $certificateNumber, int $year): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $warehouseCode) ?: 'PRJ');

        return sprintf('RANS/%d/%s/%05d', $year, $code, $certificateNumber);
    }

    /**
     * RCT-{project code}-{001} — a display label only, same philosophy as
     * buildReference() above: nothing calculates against it, and it's never
     * stored/trusted as a stale value (see ProjectCertificatePayment's
     * `reference` accessor, which calls this fresh on every read using the
     * warehouse's CURRENT code, exactly like ProjectCertificate::reference).
     */
    public function buildPaymentReference(string $warehouseCode, int $sequenceNumber): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $warehouseCode) ?: 'PRJ');

        return sprintf('RCT-%s-%03d', $code, $sequenceNumber);
    }
}
