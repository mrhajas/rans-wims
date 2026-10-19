<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Laravel's own default Illuminate\Auth\Notifications\ResetPassword
 * mail (its generic Markdown template) with the same custom-view convention
 * every other transactional email in this app already uses — see
 * App\Notifications\AssetExpiryNotification and resources/views/emails/*.
 * Wired in via User::sendPasswordResetNotification().
 */
class ResetPasswordNotification extends Notification
{
    public function __construct(protected string $token)
    {
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(\App\Services\WimsConnect\OutboundLog::SENSITIVE_HEADER, '1'))
            ->subject(__('Reset Your Password'))
            ->view('emails.reset-password', [
                'notifiable' => $notifiable,
                'url' => $url,
                'expireMinutes' => (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60),
            ]);
    }
}
