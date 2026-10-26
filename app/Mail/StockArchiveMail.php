<?php

namespace App\Mail;

use App\Models\StockArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** The Stock Report e-mail, with the PDF of the archive entry attached. */
class StockArchiveMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StockArchive $archive, public string $recipientName, public string $companyName, public string $pdf, public ?string $byName = null)
    {
    }

    public function build()
    {
        return $this->subject('Stock Report ' . $this->archive->generated_at->format('d/m/Y') . ' (' . $this->archive->reference . ')')
            ->view('mail.stock-archive')
            ->attachData($this->pdf, $this->archive->reference . '.pdf', ['mime' => 'application/pdf']);
    }
}
