<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = [
        'Business Licence', 'Contractor Licence', 'Tax Certificate', 'Insurance', 'Bank Guarantee',
        'Performance Guarantee', 'Advance Payment Guarantee', 'Bid Bond', 'Loan', 'Work Permit',
        'Environmental Permit', 'Fire Certificate', 'Building Permit', 'Professional Certificate',
        'Vehicle Licence', 'Vehicle Insurance', 'Employee Certificate', 'Contract', 'Lease',
        'Government Registration', 'Statutory Filing', 'Tax Return', 'Audit Requirement',
        'Safety Certification', 'Equipment Certification',
    ];

    private const AUTHORITIES = [
        'Tanzania Revenue Authority', 'Zanzibar Revenue Authority', 'Zanzibar Social Security Fund',
        'Contractors Registration Board', 'Zanzibar Building Agency', 'Ministry of Finance Zanzibar',
        'Ministry of Health Zanzibar', 'Ministry of Education Zanzibar', 'People\'s Bank of Zanzibar', 'NMB Bank',
    ];

    public function up(): void
    {
        foreach (self::TYPES as $text) {
            DB::table('compliance_types')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach (self::AUTHORITIES as $text) {
            DB::table('compliance_issuing_authorities')->updateOrInsert(
                ['text' => $text],
                ['use_count' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('compliance_types')->whereIn('text', self::TYPES)->delete();
        DB::table('compliance_issuing_authorities')->whereIn('text', self::AUTHORITIES)->delete();
    }
};
