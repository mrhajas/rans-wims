<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seeds the initial Terms & Conditions content (as Setting rows, same
     * EAV table every other app-wide setting already lives in) — organized
     * into "## Heading" sections so TermsController/Terms/Show.vue can split
     * on them into icon-labeled cards. Editable afterward from
     * /settings — see SettingController::updateTerms().
     */
    public function up(): void
    {
        $content = <<<'MD'
## What Is WIMS
By accessing or using **WIMS (Workflow Integrated Management System)**, you acknowledge and agree that WIMS is an organizational system used for managing business operations, records, workflows, projects, inventory, communications, attendance, reports, approvals, and other authorized activities.

## Information We Collect
WIMS may collect, store, and process information necessary for the operation, security, administration, and improvement of the system. This may include **user account information, IP address, device and browser information, login and access times, system activity, audit logs, records created or modified by users, attendance information, geographic location where applicable, and other technical or operational information generated through use of WIMS**.

## Monitoring & Auditing
WIMS activity may be monitored, recorded, and audited by authorized administrators for purposes including **security, accountability, troubleshooting, operational management, fraud prevention, compliance, investigation of incidents, verification of records, and protection of organizational information and assets**.

## Your Responsibilities
Users are responsible for all activity performed through their accounts and must keep their login credentials secure. Users must not share accounts, impersonate another user, provide false information, manipulate records, bypass permissions, interfere with system controls, or attempt to gain unauthorized access to information or functionality.

## Business Records
Users acknowledge that information entered into WIMS, including operational records, transactions, approvals, reports, documents, comments, and other business data, may form part of the organization's official records and may be retained according to applicable organizational policies.

## Module-Specific Data Collection
Certain WIMS features may collect additional information when required for their intended purpose. For example, **Digital Attendance may collect location, IP address, device, browser, and time information**, while other WIMS modules may record transaction history, approvals, access activity, documents, communications, or audit information.

## Cookies, Sessions & Offline Data
WIMS may use cookies, sessions, local storage, cached data, security mechanisms, and similar technologies required to provide, secure, and improve the system. Where WIMS supports offline functionality, locally stored information may remain on an authorized device until it is cleared or expires according to system policies.

## Access Control & Permissions
Access to WIMS and its individual modules is controlled by organizational permissions and administrative settings. Features may be enabled, disabled, restricted, monitored, or modified by authorized administrators without affecting the underlying business records unless a legitimate administrative action requires otherwise.

## Administrator Access
Authorized administrators may access, review, correct, export, or investigate WIMS information where permitted by their role and applicable organizational policies.

## Prohibited Conduct
Users must not attempt to **circumvent security controls, manipulate URLs or requests, modify client-side data to obtain unauthorized access, interfere with logs, falsify attendance or location information, exploit vulnerabilities, introduce malicious code, or otherwise compromise the integrity, confidentiality, or availability of WIMS**.

## Account & Access Suspension
WIMS administrators may temporarily suspend or restrict accounts, sessions, devices, features, or access where necessary to protect the system, users, organizational data, or business operations.

## System Availability
While reasonable measures are taken to maintain the availability, security, and integrity of WIMS, no software system can guarantee uninterrupted availability or complete protection against every possible technical or security incident.

## Your Agreement
By continuing to access or use WIMS, you confirm that you have read and understood this notice and agree to use WIMS responsibly, securely, and in accordance with applicable organizational policies, procedures, and laws.
MD;

        // get_settings($key) reads the first matching row with no account_id
        // filter at all (same as every other single-value app setting here —
        // app_name, footer_credit, version_label, ...), so — matching that
        // existing behavior rather than fighting it — this is seeded once,
        // not per-account.
        Setting::updateOrCreate(['tec_key' => 'terms_content', 'account_id' => 1], ['tec_value' => $content]);
        Setting::updateOrCreate(['tec_key' => 'terms_version', 'account_id' => 1], ['tec_value' => '1']);
    }

    public function down(): void
    {
        Setting::where('tec_key', 'terms_content')->delete();
        Setting::where('tec_key', 'terms_version')->delete();
    }
};
