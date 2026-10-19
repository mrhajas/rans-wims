<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        \App\Events\AttachmentEvent::class => [\App\Listeners\MoveAttachments::class],
        \App\Events\CheckinEvent::class    => [\App\Listeners\CheckinEventListener::class],
        \App\Events\CheckoutEvent::class   => [\App\Listeners\CheckoutEventListener::class],
        // WIMS Connect listeners below are additive siblings — each existing
        // listener stays first and unmodified; the WimsConnect-namespaced one
        // only ever queues external communication, never internal notifications.
        \App\Events\TransferEvent::class   => [\App\Listeners\TransferEventListener::class],
        \App\Events\AdjustmentEvent::class => [\App\Listeners\AdjustmentEventListener::class],
        \App\Events\MaterialRequestStatusChanged::class => [\App\Listeners\MaterialRequestStatusChangedListener::class, \App\Listeners\WimsConnect\MaterialRequestStatusChangedListener::class],
        \App\Events\MaterialReallocationEvent::class => [\App\Listeners\MaterialReallocationEventListener::class],
        \App\Events\PaymentVoucherStatusChanged::class => [\App\Listeners\PaymentVoucherStatusChangedListener::class, \App\Listeners\UpdateTransportRecordsFromVoucher::class],
        \App\Events\LabourStatusChanged::class => [\App\Listeners\LabourStatusChangedListener::class],
        \App\Events\CaseTicketStatusChanged::class => [\App\Listeners\CaseTicketStatusChangedListener::class],
        \App\Events\CaseTicketAssigned::class => [\App\Listeners\CaseTicketAssignedListener::class],
        \App\Events\CaseCommentAdded::class => [\App\Listeners\CaseCommentAddedListener::class],
        \App\Events\TaskAssigned::class => [\App\Listeners\TaskAssignedListener::class],
        \App\Events\TaskCommented::class => [\App\Listeners\TaskCommentedListener::class],
        \App\Events\TaskStatusChanged::class => [\App\Listeners\TaskStatusChangedListener::class],

        \Illuminate\Auth\Events\PasswordReset::class     => [\App\Listeners\LogPasswordReset::class],
        \Illuminate\Auth\Events\Login::class             => [\App\Listeners\RecordSessionEnvironment::class . '@handleLogin', \App\Listeners\LogSuccessfulLogin::class, \App\Listeners\CaptureSuccessfulLoginAttempt::class],
        \Illuminate\Auth\Events\Failed::class            => [\App\Listeners\CaptureFailedLoginAttempt::class, \App\Listeners\RecordSessionEnvironment::class . '@handleFailed'],
        \Illuminate\Auth\Events\Logout::class            => [\App\Listeners\RecordSessionEnvironment::class . '@handleLogout', \App\Listeners\LogSuccessfulLogout::class],
        \Illuminate\Auth\Events\OtherDeviceLogout::class => [\App\Listeners\LogOtherDeviceLogout::class],
        \Illuminate\Auth\Events\Registered::class        => [\Illuminate\Auth\Listeners\SendEmailVerificationNotification::class],
    ];

    public function boot()
    {
    }
}
