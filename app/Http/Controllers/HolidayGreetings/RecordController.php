<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayDay;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** The record of a holiday: who was sent what and how it went, as a print, a PDF or a CSV (every export is written to the activity log). */
class RecordController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function print(Request $request, int $holiday)
    {
        return $this->pdfOf($this->report($request, $holiday, 'print'))->stream($this->name($holiday));
    }

    public function pdf(Request $request, int $holiday)
    {
        return $this->pdfOf($this->report($request, $holiday, 'pdf'))->download($this->name($holiday));
    }

    public function csv(Request $request, int $holiday)
    {
        $r = $this->report($request, $holiday, 'csv');
        $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;

        return response()->streamDownload(function () use ($r, $safe) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, array_column($r['columns'], 'label'));
            foreach ($r['raw'] as $row) {
                fputcsv($o, array_map(fn ($c) => $safe($row[$c['key']] ?? ''), $r['columns']));
            }
            fclose($o);
        }, 'holiday-greetings-' . $this->slug($holiday) . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function slug(int $id): string
    {
        $h = $this->holiday($id);

        return \Illuminate\Support\Str::slug($h->name) . '-' . $h->year . '-' . now()->format('Ymd-His');
    }

    private function name(int $id): string
    {
        return 'holiday-greetings-' . $this->slug($id) . '.pdf';
    }

    private function report(Request $request, int $id, string $format): array
    {
        $this->need('print-holiday-greetings');
        $h = $this->holiday($id);
        $s = GreetingSender::summary($h);
        $tpl = GreetingTemplates::effective($h);
        $rows = $h->deliveries()->orderBy('channel')->orderBy('name')->get()->map(fn ($d) => [
            'channel' => $d->channel === 'email' ? 'Email' : 'SMS', 'name' => $d->fullName(), 'address' => $d->address, 'status' => $d->status, 'attempts' => $d->attempts,
            'sent_at' => $d->sent_at ? $d->sent_at->timezone(config('app.timezone'))->format('d M Y H:i') : '', 'error' => (string) $d->error,
        ])->all();
        $cols = [['key' => 'channel', 'label' => __('Channel'), 'align' => 'left'], ['key' => 'name', 'label' => __('Name'), 'align' => 'left'], ['key' => 'address', 'label' => __('Email / Mobile'), 'align' => 'left'],
            ['key' => 'status', 'label' => __('Status'), 'align' => 'left'], ['key' => 'attempts', 'label' => __('Tries'), 'align' => 'right'], ['key' => 'sent_at', 'label' => __('Sent at'), 'align' => 'left'], ['key' => 'error', 'label' => __('Problem'), 'align' => 'left']];
        $r = [
            'type' => 'holiday_record', 'title' => __('Holiday Greetings: :h :y', ['h' => $h->name, 'y' => $h->year]), 'reference' => 'HG-' . $h->year . '-' . str_pad((string) $h->id, 4, '0', STR_PAD_LEFT), 'subtitle' => __('The record of the greetings of :h, :d.', ['h' => $h->name, 'd' => $h->date->locale('en')->translatedFormat('l, j F Y')]),
            'table_title' => __('Delivery of every greeting'),
            'filters' => array_filter([__('Sent by') => optional($h->sentBy)->name ?: __('Automatic sending'), __('First sent') => $h->send_started_at ? $h->send_started_at->timezone(config('app.timezone'))->format('d M Y H:i') : null, __('Email subject') => GreetingTemplates::fill($tpl['subject'], GreetingTemplates::vars($h, null, null))]),
            'tiles' => [['label' => __('Emails sent'), 'value' => $s['email']['sent'] . ' / ' . $s['email']['total']], ['label' => __('SMS sent'), 'value' => $s['sms']['sent'] . ' / ' . $s['sms']['total']], ['label' => __('Failed'), 'value' => $s['failed']], ['label' => __('Waiting'), 'value' => $s['pending']]],
            'columns' => $cols, 'rows' => $rows, 'raw' => $rows, 'total_rows' => count($rows), 'truncated' => false, 'sections' => [],
            'notes' => [__('"Sent" means the mail server or the SMS gateway accepted the message. WIMS does not receive a read or delivery receipt from the phone or the mailbox.'), __('A repeated email address or mobile number is sent to once.'), __('Times are in the WIMS timezone (:tz).', ['tz' => config('app.timezone')])],
            'qr' => \App\Support\PrintQrCode::dataUri(route('holiday-greetings.holidays.show', $h->id)),
        ];
        log_activity('Holiday record exported', ['holiday' => $h->name, 'year' => $h->year, 'format' => $format, 'rows' => count($rows), 'reference' => $r['reference']], $h, 'Holiday Greetings');

        return $r;
    }

    private function pdfOf(array $r)
    {
        $landscape = true;
        $pdf = Pdf::loadView('signatories.report-print', ['report' => $r, 'landscape' => $landscape, 'isPdf' => true, 'autoPrint' => false, 'signatures' => ['enabled' => false],
            'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i')])
            ->setPaper('a4', 'landscape');
        $pdf->render();
        $d = $pdf->getDomPDF();
        $c = $d->getCanvas();
        $c->page_text($c->get_width() - 95, $c->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('Helvetica'), 8.5, [0.61, 0.64, 0.69]);

        return $pdf;
    }
}
