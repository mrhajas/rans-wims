<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Http\Controllers\IdPasses\Concerns\Guards;
use App\Models\IdPassCard;
use App\Models\IdPassPrint;
use App\Services\IdPass\CardPdf;
use App\Services\IdPass\CardRenderer;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassSettings;
use App\Services\IdPass\NameFitter;
use App\Services\IdPass\PrintLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** Printing and downloading ONE card: A4 sheets (front and back side by side, or double sided), the card-size PDF (two pages of 55 x 85 mm) and the WIMS report page. Each is written to the print history. */
class PrintController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** Only a card that is active (not waiting, rejected, suspended, revoked or replaced) is made into paper. */
    private function printable(IdPassCard $card): void
    {
        abort_unless($card->status === IdPassCard::ACTIVE, 422, __('Only an active ID can be printed (this one is :s).', ['s' => strtolower($card->effective_status)]));
    }

    /** A card that was printed before can only be printed again with a reason (lost, broken, changed...); the reason goes to the history of the card and of the printing. */
    private function reasonFor(Request $request, IdPassCard $card): ?string
    {
        if ((int) $card->print_count < 1) {
            return null;
        }
        $r = trim((string) $request->query('reason', ''));
        $note = trim((string) $request->query('note', ''));
        abort_if($r === '', 422, __('This ID was printed before. Say why it is printed again (lost, broken, details changed...).'));

        return mb_substr($r . ($note !== '' ? ': ' . $note : ''), 0, 240);
    }

    private function layoutOf(Request $request, string $default): string
    {
        $l = (string) $request->query('layout', $default);

        return array_key_exists($l, IdPassPrint::LAYOUTS) ? $l : $default;
    }

    private function make(IdPassCard $card, string $layout, bool $landscape): array
    {
        $data = CardRenderer::data($card);
        $name = 'rans-' . strtolower($card->kind === 'staff' ? 'staff-id' : ($card->kind === 'compound' ? 'worker-id' : $card->kind . '-pass')) . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $card->number);
        if ($layout === 'report') {
            $pdf = Pdf::loadView('idpass.report', ['card' => $card, 'kindLabel' => __(\App\Models\IdPassCategory::KINDS[$card->kind]), 'front' => CardRenderer::front($data, 'pdf'), 'back' => CardRenderer::back($data, 'pdf'),
                'cardCss' => CardRenderer::stylesheet('pdf'), 'holder' => NameFitter::display((string) $card->full_name), 'statusClass' => $card->effective_status === 'Active' ? 'active' : ($card->effective_status === 'Pending Approval' ? 'warn' : 'inactive'),
                'noExpiry' => IdPassSettings::get('idpass_no_expiry_text'), 'printedBy' => auth()->user()->name, 'printedAt' => now()->format('d M Y, H:i'), 'isPdf' => true, 'companyName' => preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) optional(auth()->user()->account)->name)])
                ->setPaper('a4', 'portrait')->setOptions(['fontDir' => CardPdf::fontDir(), 'fontCache' => CardPdf::fontDir(), 'chroot' => [base_path()], 'isFontSubsettingEnabled' => true]);

            return [$pdf->output(), $name . '-report.pdf'];
        }
        if ($layout === 'a4_side' || $layout === 'a4_duplex') {
            return [CardPdf::a4([$data], $layout, $landscape), $name . ($layout === 'a4_duplex' ? '-a4-double-sided' : '-a4') . '.pdf'];
        }

        return [CardPdf::card($data), $name . '.pdf'];
    }

    /** Opens the PDF in the browser to be printed ("Print initiated": the file was made; whether paper came out is not known to WIMS). */
    public function print(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'print');
        $this->printable($card);
        $why = $this->reasonFor($request, $card);
        $layout = $this->layoutOf($request, 'a4_side');
        [$bytes, $name] = $this->make($card, $layout, $request->query('orientation') === 'landscape');
        PrintLogger::record($card, 'print', $layout, max(1, min(20, (int) $request->query('copies', 1))), null, 'Initiated', $why ? __('Printed again: :r', ['r' => $why]) : __('PDF generated and opened for printing'));
        if ($why) {
            \App\Models\IdPassEvent::record($card, 'reprinted', null, null, ['reason' => $why, 'layout' => $layout]);
        }
        PrintLogger::audit('RANS ID print initiated', ['number' => $card->number, 'layout' => $layout]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** Downloads the PDF. The default is the card-size file: page 1 the front, page 2 the back, each exactly 55 x 85 mm. */
    public function pdf(Request $request)
    {
        $kind = $this->kindOf($request);
        $card = $this->cardOf($request, $kind);
        $this->must($kind, 'download');
        $this->feature('id_pdf_export');
        $this->printable($card);
        $why = $this->reasonFor($request, $card);
        $layout = $this->layoutOf($request, 'card');
        [$bytes, $name] = $this->make($card, $layout, $request->query('orientation') === 'landscape');
        PrintLogger::record($card, 'pdf', $layout, 1, null, 'Generated', $why ? __('Printed again: :r', ['r' => $why]) : __('PDF generated'));
        if ($why) {
            \App\Models\IdPassEvent::record($card, 'reprinted', null, null, ['reason' => $why, 'layout' => $layout]);
        }
        PrintLogger::audit('RANS ID PDF generated', ['number' => $card->number, 'layout' => $layout]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }
}
