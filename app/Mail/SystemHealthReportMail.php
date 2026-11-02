<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The System Health report, in the WIMS e-mail style (header, containers, shadows, footer). Plain data only; sent at once, never queued. */
class SystemHealthReportMail extends Mailable
{
    public function __construct(public array $report, public string $sentTo, public ?string $companyName = null)
    {
    }

    public function build()
    {
        $label = ['excellent' => __('Excellent'), 'very_good' => __('Very Good'), 'good' => __('Good'), 'fair' => __('Fair'), 'bad' => __('Bad')][$this->report['rating']] ?? '';

        return $this->subject('RANS WIMS System Health: ' . $label . ' (' . $this->report['score'] . '/100) - ' . $this->report['host'])
            ->view('emails.system-health', ['report' => $this->report, 'sentTo' => $this->sentTo, 'companyName' => $this->companyName]);
    }
}
