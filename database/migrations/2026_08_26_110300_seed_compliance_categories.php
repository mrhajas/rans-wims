<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the 11 group headings from the spec (§4), each with its example
     * sub-types as children. Admin-editable afterward via the Compliance
     * Categories screen.
     */
    private const GROUPS = [
        'Company/Business' => ['Business Licence', 'Company Registration', 'Contractor Registration', 'Trade Licence', 'Professional Registration', 'Tax Registration', 'Tax Clearance Certificate'],
        'Government/Statutory' => ['Tax Filing', 'VAT Filing', 'Payroll Filing', 'Statutory Returns', 'Government Levy', 'Regulatory Submission', 'Annual Return'],
        'Construction' => ['Contractor Licence', 'Construction Permit', 'Building Permit', 'Environmental Permit', 'Occupational Safety Certificate', 'Site Permit', 'Professional Certification', 'Equipment Certification'],
        'Financial' => ['Bank Guarantee', 'Performance Guarantee', 'Advance Payment Guarantee', 'Bid Bond', 'Retention Guarantee', 'Loan', 'Loan Facility', 'Credit Facility', 'Letter of Credit', 'Bank Facility'],
        'Insurance' => ['Company Insurance', 'Vehicle Insurance', 'Equipment Insurance', "Contractor's All Risk", 'Workers Insurance', 'Public Liability', 'Professional Indemnity', 'Property Insurance'],
        'Vehicles' => ['Vehicle Registration', 'Road Licence', 'Insurance', 'Inspection', 'Roadworthiness Certificate'],
        'Assets/Equipment' => ['Equipment Certification', 'Calibration Certificate', 'Inspection Certificate', 'Safety Certificate', 'Warranty', 'Maintenance Certification'],
        'People' => ['Professional Licence', 'Professional Membership', 'Work Permit', 'Visa', 'Training Certificate', 'Safety Certificate', 'Medical Certificate'],
        'Contracts' => ['Supplier Contract', 'Subcontract Contract', 'Lease', 'Rental Agreement', 'Service Contract', 'Maintenance Contract', 'Employment Contract'],
        'Project' => ['Project Permit', 'Site Permit', 'Environmental Compliance', 'Authority Approval', 'Project Insurance', 'Performance Guarantee'],
        'Other' => ['Other Compliance'],
    ];

    public function up(): void
    {
        foreach (self::GROUPS as $group => $children) {
            foreach ($children as $child) {
                DB::table('compliance_categories')->updateOrInsert(
                    ['name' => $child, 'group' => $group],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('compliance_categories')->whereIn('group', array_keys(self::GROUPS))->delete();
    }
};
