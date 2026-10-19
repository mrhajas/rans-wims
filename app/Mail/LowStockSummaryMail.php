<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** The daily Low Stock Alert: the watched materials that are low, grouped by project. */
class LowStockSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $digest,
        public string $recipientName,
        public string $generatedAt,
        public array $config,
        public bool $sample = false,
        public ?string $pdfBytes = null,
        public string $pdfName = 'low-stock-alert.pdf',
    ) {
    }

    public function build()
    {
        $t = $this->digest['totals'];
        if ($this->pdfBytes) {
            $this->attachData($this->pdfBytes, $this->pdfName, ['mime' => 'application/pdf']);
        }
        $subject = $t['items'] === 0
            ? __('Low Stock Alert: all watched materials are well stocked')
            : __('Low Stock Alert: :n item(s) low in :p project(s)', ['n' => $t['items'], 'p' => $t['with_problem']]).($t['out'] ? ' · '.__(':n out of stock', ['n' => $t['out']]) : '');

        return $this->subject(($this->sample ? '[Sample] ' : '').$subject)->view('mail.low-stock-summary');
    }
}
