<?php

namespace App\Mail;

use App\Services\WimsConnect\OutboundLog;
use Illuminate\Mail\Mailable;

/** The test email of WIMS Connect > Test Email / SMS. Sent to one person so an administrator can see that email reaches them. */
class WimsConnectTestMail extends Mailable
{
    public function __construct(public string $recipientName, public string $body, public string $sentBy, public ?string $companyName = null)
    {
    }

    public function build()
    {
        return $this->subject(__('WIMS test email'))
            ->view('emails.wims-connect-test', ['recipientName' => $this->recipientName, 'body' => $this->body, 'sentBy' => $this->sentBy, 'companyName' => $this->companyName, 'sentAt' => now()])
            ->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(OutboundLog::EVENT_HEADER, 'wims-connect-test'));
    }
}
