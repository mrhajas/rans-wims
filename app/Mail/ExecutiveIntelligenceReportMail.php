<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

class ExecutiveIntelligenceReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public string $weekStart;
    public string $weekEnd;
    public string $companyName;
    public array $summary;
    public array $metrics;
    public ?string $recipientName = null;

    /** Report Content settings as an array (a queued mailable can only carry plain data). */
    public array $cfgData = [];

    // Base64-encoded PDF bytes — see MainWarehouseActivityMail for why raw binary
    // can't be stored directly on a ShouldQueue mailable (json_encode breaks on it).
    public string $pdfData;
    public string $pdfFilename;

    // Optional second attachment (e.g. the Total Inventory Report), same base64 pattern.
    public ?string $secondPdfData = null;
    public ?string $secondPdfFilename = null;

    public function __construct(string $weekStart, string $weekEnd, string $companyName, array $summary, array $metrics, string $pdf, string $pdfFilename, ?string $secondPdf = null, ?string $secondPdfFilename = null, ?\App\Services\ExecutiveReportConfig $cfg = null)
    {
        $this->cfgData = $cfg ? $cfg->toArray() : [];
        $this->weekStart = $weekStart;
        $this->weekEnd = $weekEnd;
        $this->companyName = $companyName;
        $this->summary = $summary;
        $this->metrics = $metrics;
        $this->pdfData = base64_encode($pdf);
        $this->pdfFilename = $pdfFilename;

        if ($secondPdf !== null) {
            $this->secondPdfData = base64_encode($secondPdf);
            $this->secondPdfFilename = $secondPdfFilename;
        }
    }

    public function build()
    {
        $mail = $this
            ->subject("Executive Intelligence Report - {$this->weekStart} to {$this->weekEnd}")
            ->view('mail.executive-intelligence-report', ['recipientName' => $this->recipientName, 'cfg' => \App\Services\ExecutiveReportConfig::make($this->cfgData)])
            ->attachData(base64_decode($this->pdfData), $this->pdfFilename, ['mime' => 'application/pdf']);

        if ($this->secondPdfData) {
            $mail->attachData(base64_decode($this->secondPdfData), $this->secondPdfFilename, ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
