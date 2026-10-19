<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $data;

    /**
     * `data->module` values surfaced in the bell alerts dropdown/count. Add a
     * module here whenever a new module starts sending AppNotification —
     * used identically by AjaxController::alertsNotificationQuery() (the
     * dropdown list) and HandleInertiaRequests (the badge count), so both
     * stay in sync automatically.
     */
    public static function alertModules(): array
    {
        return [
            'notice_board',
            'accounts_tracking',
            'admin_operations',
            'material_requests',
            'issue_returns',
            'site_reports',
            'material_reallocations',
            'work_package_issues',
            'task_reminders',
            'payment_vouchers',
            'labour',
            'transport',
            'assets',
            'compliance_records',
            'digital_attendance',
            'wims_connect',
            'security_reminders',
            'case_tracking',
            'checkins',
            'checkouts',
            'transfers',
            'multi_transfers',
            'external_stock',
            'subcontracts',
            'stock_alerts',
            'executive_reports',
            'task_manager',
            'salary',
            'project_certificates',
            'fund_allocations',
            'archive',
            'site_diary',
            'site_meetings',
            'wimschat',
            'smart_brief',
            'responsive_audit',
            'signatories',
            'weather_risk',
            'users',
            'contacts',
            'categories',
            'units',
            'supplier_records',
            'adjustments',
            'items',
            'projects',
            'staff',
        ];
    }

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function toArray($notifiable)
    {
        return $this->data;
    }

    /**
     * 'mail' is strictly opt-in per instance via $data['send_email'] — every
     * existing call site across the app (dozens, built long before real
     * email delivery existed here) constructs this without that key, so
     * defaulting it to always include 'mail' would have silently turned
     * every one of those into a live email send. Only
     * EmailRecipientResolver::notify() (the WIMS Notification matrix's own
     * dispatcher) sets the flag, so email only ever goes out for a function
     * a user explicitly subscribed to.
     *
     * 'database' is skipped for a non-User notifiable (Staff/Contact, used
     * by Task Manager's multi-type assignment) — neither has a login
     * session, so nothing would ever read a bell-dropdown row for them;
     * email is their only real channel.
     */
    public function via($notifiable)
    {
        $channels = $notifiable instanceof \App\Models\User && empty($this->data['email_only']) ? ['database'] : [];

        if (! empty($this->data['send_email'])) {
            $mailAddress = method_exists($notifiable, 'routeNotificationForMail')
                ? $notifiable->routeNotificationForMail($this)
                : ($notifiable->email ?? null);

            if (! empty($mailAddress)) {
                $channels[] = 'mail';
            }
        }

        return $channels;
    }

    /**
     * icon (emoji, renders natively in every mail client without needing
     * images to load) + accent (hex, used for the header gradient and the
     * content panel's left border) per `data->module` — keeps every
     * EmailRecipientResolver-routed email visually distinct at a glance
     * (Low Stock Alert amber, Payment Voucher Created emerald, etc.)
     * without a client-visible difference in the underlying template.
     * Falls back to a plain bell/navy for a module not listed here, so a
     * new module never breaks the email, it just looks generic until
     * added.
     */
    /** Label / emoji / accent colour for a module, for UIs that group or badge notifications by module. */
    public static function moduleMeta(string $module): array
    {
        return self::moduleStyle($module);
    }

    private static function moduleStyle(string $module): array
    {
        return match ($module) {
            'stock_alerts' => ['icon' => '📦', 'accent' => '#b45309', 'label' => __('Stock Alert')],
            'payment_vouchers' => ['icon' => '🧾', 'accent' => '#059669', 'label' => __('Payment Voucher')],
            'archive' => ['icon' => '🗄️', 'accent' => '#1c3f66', 'label' => __('Archive')],
            'wimschat' => ['icon' => '💬', 'accent' => '#2563eb', 'label' => __('WIMSChat')],
            'signatories' => ['icon' => '✍️', 'accent' => '#1b3fa0', 'label' => __('Signatories')],
            'responsive_audit' => ['icon' => '📱', 'accent' => '#7c3aed', 'label' => __('Responsive Audit')],
            'smart_brief' => ['icon' => '✨', 'accent' => '#0d9488', 'label' => __('Smart Brief')],
            'weather_risk' => ['icon' => '⛅', 'accent' => '#0369a1', 'label' => __('Weather & Site Risk')],
            'site_diary' => ['icon' => '📷', 'accent' => '#0f766e', 'label' => __('Site Diary')],
            'site_meetings' => ['icon' => '🗓️', 'accent' => '#4f46e5', 'label' => __('Site Meetings')],
            'transport' => ['icon' => '🚚', 'accent' => '#0284c7', 'label' => __('Transport Routes')],
            'checkins' => ['icon' => '📥', 'accent' => '#059669', 'label' => __('Checkin')],
            'checkouts' => ['icon' => '📤', 'accent' => '#e11d48', 'label' => __('Checkout')],
            'transfers' => ['icon' => '🔄', 'accent' => '#7c3aed', 'label' => __('Transfer')],
            'external_stock' => ['icon' => '📦', 'accent' => '#c2410c', 'label' => __('External Stock')],
            'subcontracts' => ['icon' => '🏗️', 'accent' => '#7c2d12', 'label' => __('Subcontractors')],
            'multi_transfers' => ['icon' => '🔀', 'accent' => '#6d28d9', 'label' => __('Multi Transfer')],
            'material_requests' => ['icon' => '📋', 'accent' => '#4f46e5', 'label' => __('Material Request')],
            'material_reallocations' => ['icon' => '🔀', 'accent' => '#0891b2', 'label' => __('Material Reallocation')],
            'site_reports' => ['icon' => '📝', 'accent' => '#b45309', 'label' => __('Site Report')],
            'executive_reports' => ['icon' => '📊', 'accent' => '#334155', 'label' => __('Executive Report')],
            'task_reminders' => ['icon' => '⏰', 'accent' => '#4f46e5', 'label' => __('Task Reminder')],
            'compliance_records' => ['icon' => '📁', 'accent' => '#be123c', 'label' => __('Compliance')],
            'assets' => ['icon' => '🔧', 'accent' => '#2563eb', 'label' => __('Assets')],
            'security_reminders' => ['icon' => '🔒', 'accent' => '#dc2626', 'label' => __('Security')],
            'labour' => ['icon' => '👷', 'accent' => '#0f766e', 'label' => __('Labour')],
            'case_tracking' => ['icon' => '📌', 'accent' => '#7c3aed', 'label' => __('Case Tracking')],
            'task_manager' => ['icon' => '✅', 'icon_image' => 'icons/task-manager.png', 'accent' => '#6366f1', 'label' => __('Task Manager')],
            'work_package_issues' => ['icon' => '⚠️', 'accent' => '#d97706', 'label' => __('Work Package')],
            'digital_attendance' => ['icon' => '🕒', 'accent' => '#0891b2', 'label' => __('Digital Attendance')],
            'notice_board' => ['icon' => '📣', 'accent' => '#4f46e5', 'label' => __('Notice Board')],
            'accounts_tracking' => ['icon' => '🏦', 'accent' => '#0f766e', 'label' => __('Accounts Tracking')],
            'fund_allocations' => ['icon' => '🧮', 'accent' => '#0f766e', 'label' => __('Funds Allocation')],
            'salary' => ['icon' => '💰', 'accent' => '#059669', 'label' => __('Salary')],
            'users' => ['icon' => '👤', 'accent' => '#475569', 'label' => __('Users')],
            'contacts' => ['icon' => '📇', 'accent' => '#0369a1', 'label' => __('Contacts')],
            'categories' => ['icon' => '🗂️', 'accent' => '#7c3aed', 'label' => __('Categories')],
            'units' => ['icon' => '📏', 'accent' => '#0f766e', 'label' => __('Units')],
            'supplier_records' => ['icon' => '🏷️', 'accent' => '#b45309', 'label' => __('Supplier Records')],
            'issue_returns' => ['icon' => '↩️', 'accent' => '#0e7490', 'label' => __('Issue & Return')],
            'adjustments' => ['icon' => '⚖️', 'accent' => '#b45309', 'label' => __('Adjustments')],
            'items' => ['icon' => '🧱', 'accent' => '#0369a1', 'label' => __('Items')],
            'projects' => ['icon' => '🏗️', 'accent' => '#334155', 'label' => __('Projects')],
            'staff' => ['icon' => '🧑‍💼', 'accent' => '#7c3aed', 'label' => __('Staff')],
            'project_certificates' => ['icon' => '📜', 'accent' => '#0f766e', 'label' => __('Project Certificates')],
            default => ['icon' => '🔔', 'accent' => '#0b1a3a', 'label' => __('WIMS Notification')],
        };
    }

    public function toMail($notifiable)
    {
        $style = self::moduleStyle($this->data['module'] ?? '');

        $eventKey = (string) ($this->data['type'] ?? ($this->data['module'] ?? 'notification'));

        return (new MailMessage)
            ->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(\App\Services\WimsConnect\OutboundLog::EVENT_HEADER, $eventKey))
            ->subject($this->data['title'] ?? __('WIMS Notification'))
            ->view('emails.notification', [
                'title' => $this->data['title'] ?? __('WIMS Notification'),
                'message_text' => $this->data['message'] ?? '',
                'url' => $this->data['url'] ?? null,
                'icon' => $style['icon'],
                'iconImage' => $style['icon_image'] ?? null,
                'accent' => $style['accent'],
                'eyebrow' => $style['label'],
                'details' => $this->data['details'] ?? [],
                'items' => $this->data['items'] ?? [],
            ]);
    }
}
