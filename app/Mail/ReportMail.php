<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;

class ReportMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $pdf,
        public string $filename,
        public ?string $companyName = null
    ) {}

    public function build()
    {
        return $this
            ->subject($this->title)
            ->view('mail.report', [
                'title'       => $this->title,
                'companyName' => $this->companyName,
                'filename'    => $this->filename,
                'generatedAt' => now(),
                'generatedBy' => optional(auth()->user())->name,
            ])
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
