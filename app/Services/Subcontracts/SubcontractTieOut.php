<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractImportBatch as B;
use App\Models\SubcontractImportSheet as S;

/**
 * Ties the TOTAL line of the workbook's SUMMARY sheet to what the dashboard shows, line by line, so the difference is never a mystery.
 *
 *   Excel SUMMARY total
 *   - a subcontractor that is listed twice on the SUMMARY (counted once in WIMS)
 *   - a line in another currency (kept in its own currency in WIMS, never added to TZS)
 *   - a line whose account is archived or not imported (outside the dashboard totals)
 *   +/- the difference between the SUMMARY line and the account's own sheet (WIMS follows the sheet's detail)
 *   + accounts that are active in WIMS but are not on the SUMMARY
 *   = the dashboard total for the currency
 *
 * Figures are decimal strings added with bcmath. Only agreements the viewer may see are counted by the caller (this runs for money viewers only).
 */
class SubcontractTieOut
{
    public static function build(string $currency = 'TZS'): ?array
    {
        $batch = B::query()->where('status', 'imported')->whereNotNull('summary')->orderByDesc('id')->first();
        if (! $batch || ! isset($batch->summary['summary_total_contract'])) {
            return null;
        }
        $sheets = S::query()->where('batch_id', $batch->id)->get()->filter(fn ($s) => ! empty($s->totals['summary_rows']));
        $agreements = A::withoutGlobalScopes()->whereIn('id', $sheets->pluck('agreement_id')->filter()->all())->get()->keyBy('id');
        $fig = SubcontractLedger::forAgreements($agreements->keys()->all());

        $excelC = self::d($batch->summary['summary_total_contract']);
        $excelP = self::d($batch->summary['summary_total_paid']);
        $lines = [];
        $notes = [];
        $typedBal = bcsub($excelC, $excelP, 2);
        $c = $excelC;
        $p = $excelP;
        $onSummary = [];

        foreach ($sheets as $sh) {
            $t = $sh->totals;
            $sc = self::d($t['summary_contract'] ?? 0);
            $sp = self::d($t['summary_paid'] ?? 0);
            $times = count($t['summary_rows']);
            if (isset($t['summary_balance'])) {
                $gap = bcsub(self::d($t['summary_balance']), bcsub($sc, $sp, 2), 2);
                if (abs((float) $gap) >= 1) {
                    $typedBal = bcadd($typedBal, bcmul($gap, (string) $times, 2), 2);
                    $notes[] = ['name' => $sh->suggested_name ?: $sh->sheet_name, 'typed' => self::d($t['summary_balance']), 'worked' => bcsub($sc, $sp, 2), 'gap' => $gap];
                }
            }
            $a = $sh->agreement_id ? ($agreements[$sh->agreement_id] ?? null) : null;
            $name = $a ? $a->title : ($sh->suggested_name ?: $sh->sheet_name);
            $link = $a ? ['type' => 'agreement', 'id' => $a->id] : null;
            if ($a) {
                $onSummary[$a->id] = true;
            }
            if ($times > 1) {
                $dc = bcmul($sc, (string) ($times - 1), 2);
                $dp = bcmul($sp, (string) ($times - 1), 2);
                $c = bcsub($c, $dc, 2);
                $p = bcsub($p, $dp, 2);
                $lines[] = self::line('duplicate', $name, '-' . $dc, '-' . $dp, __('Listed :n times on the SUMMARY sheet; WIMS counts the account once.', ['n' => $times]), $link);
            }
            if (! $a || in_array($a->status, [A::ARCHIVED, A::DRAFT, A::CANCELLED], true)) {
                $c = bcsub($c, $sc, 2);
                $p = bcsub($p, $sp, 2);
                $lines[] = self::line('outside', $name, '-' . $sc, '-' . $sp, $a ? ($a->status === A::ARCHIVED ? __('Archived in WIMS: shown in the archive, not in the dashboard totals.') : __('A :s agreement is not in the dashboard totals.', ['s' => strtolower(__($a->status))])) : __('Not imported into WIMS.'), $link);
                continue;
            }
            if ($a->currency !== $currency) {
                $c = bcsub($c, $sc, 2);
                $p = bcsub($p, $sp, 2);
                $f = $fig[$a->id] ?? null;
                $lines[] = self::line('currency', $name, '-' . $sc, '-' . $sp, __('Account kept in :c (:r revised, :p paid) and never added to :t. The SUMMARY converted it into TZS.', ['c' => $a->currency, 'r' => $f ? number_format((float) $f['revised'], 2) : '-', 'p' => $f ? number_format((float) $f['paid_total'], 2) : '-', 't' => $currency]), $link);
                continue;
            }
            $f = $fig[$a->id] ?? null;
            if ($f) {
                $dc = bcsub((string) $f['revised'], $sc, 2);
                $dp = bcsub((string) $f['paid_total'], $sp, 2);
                if (bccomp($dc, '0', 0) !== 0 || bccomp($dp, '0', 0) !== 0) {
                    $c = bcadd($c, $dc, 2);
                    $p = bcadd($p, $dp, 2);
                    $lines[] = self::line('detail', $name, self::signed($dc), self::signed($dp), __('The SUMMARY line differs from the account\'s own sheet. WIMS follows the sheet\'s detail.'), $link);
                }
            }
        }

        $extra = A::withoutGlobalScopes()->where('currency', $currency)->whereNotIn('status', [A::ARCHIVED, A::DRAFT, A::CANCELLED])->whereNotIn('id', array_keys($onSummary))->get();
        if ($extra->isNotEmpty()) {
            $xf = SubcontractLedger::forAgreements($extra->pluck('id')->all());
            foreach ($extra as $a) {
                $f = $xf[$a->id] ?? null;
                if (! $f) {
                    continue;
                }
                $c = bcadd($c, (string) $f['revised'], 2);
                $p = bcadd($p, (string) $f['paid_total'], 2);
                $lines[] = self::line('extra', $a->title, self::signed((string) $f['revised']), self::signed((string) $f['paid_total']), __('Active in WIMS but not on the SUMMARY sheet.'), ['type' => 'agreement', 'id' => $a->id]);
            }
        }

        $dash = SubcontractLedger::foldByCurrency(SubcontractLedger::forAgreements(A::withoutGlobalScopes()->whereNotIn('status', [A::ARCHIVED, A::DRAFT, A::CANCELLED])->where('currency', $currency)->pluck('id')->all()));
        $d = $dash[$currency] ?? ['revised' => '0.00', 'paid_total' => '0.00'];

        return [
            'batch' => ['id' => $batch->id, 'reference' => $batch->reference ?? ('#' . $batch->id), 'file' => $batch->file_name ?? null, 'at' => optional($batch->created_at)->format('Y-m-d')],
            'currency' => $currency,
            'excel' => ['contract' => $excelC, 'paid' => $excelP, 'balance' => bcsub($excelC, $excelP, 2), 'balance_typed' => $typedBal],
            'balance_notes' => $notes,
            'lines' => $lines,
            'result' => ['contract' => $c, 'paid' => $p, 'balance' => bcsub($c, $p, 2)],
            'dashboard' => ['contract' => self::d($d['revised']), 'paid' => self::d($d['paid_total']), 'balance' => bcsub(self::d($d['revised']), self::d($d['paid_total']), 2)],
            'ties' => bccomp($c, self::d($d['revised']), 0) === 0 && bccomp($p, self::d($d['paid_total']), 0) === 0,
        ];
    }

    private static function d($v): string
    {
        return is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) ? bcadd($v, '0', 2) : number_format((float) $v, 2, '.', '');
    }

    private static function signed(string $v): string
    {
        return bccomp($v, '0', 2) >= 0 ? '+' . $v : $v;
    }

    private static function line(string $kind, string $name, string $c, string $p, string $note, ?array $link): array
    {
        return ['kind' => $kind, 'name' => $name, 'contract' => $c, 'paid' => $p, 'note' => $note, 'link' => $link];
    }
}
