<?php

namespace App\Mail;

use App\Services\WimsConnect\OutboundLog;
use Illuminate\Mail\Mailable;

/** "A holiday is coming": sent to the Super Admins before the holiday, with the button that opens the greetings for review. */
class HolidayReminderMail extends Mailable
{
    public function __construct(public array $data)
    {
    }

    public function build()
    {
        return $this->subject($this->data['title'])->view('emails.holiday-reminder', $this->data)
            ->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(OutboundLog::EVENT_HEADER, 'holiday-greetings-reminder'));
    }
}
