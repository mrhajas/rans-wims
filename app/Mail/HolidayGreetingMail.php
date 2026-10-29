<?php

namespace App\Mail;

use App\Models\HolidayDay;
use App\Services\HolidayGreetings\GreetingTemplates;
use App\Services\WimsConnect\OutboundLog;
use Illuminate\Mail\Mailable;

/** One holiday greeting to one person, built from the holiday's template. Sent at once (the sending itself is queued per person by GreetingSender). */
class HolidayGreetingMail extends Mailable
{
    public function __construct(public HolidayDay $holiday, public ?string $title, public ?string $name, public ?array $template = null)
    {
    }

    public function build()
    {
        $data = GreetingTemplates::emailData($this->holiday, $this->title, $this->name, $this->template);
        $plain = GreetingTemplates::emailText($this->holiday, $this->title, $this->name, $this->template);

        return $this->subject($data['subject'])->view('emails.holiday-greeting', $data)
            ->withSymfonyMessage(function ($m) use ($plain) {
                $m->text($plain);
                $m->getHeaders()->addTextHeader(OutboundLog::EVENT_HEADER, 'holiday-greetings');
            });
    }
}
