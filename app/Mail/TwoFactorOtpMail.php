<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Deliberately NOT queued: a sign-in code that arrives after a worker backlog is useless,
 * and the code must never sit in a queue payload longer than necessary.
 */
class TwoFactorOtpMail extends Mailable
{
    public function __construct(
        public string $code,
        public int $minutes,
        public ?string $name = null,
        public string $purpose = 'login',
    ) {
    }

    public function build()
    {
        return $this->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(\App\Services\WimsConnect\OutboundLog::SENSITIVE_HEADER, '1'))
            ->subject(__('Your WIMS verification code'))->view('mail.two-factor-otp');
    }
}
