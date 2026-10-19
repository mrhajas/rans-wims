<?php

namespace App\Services\Subcontracts;

use App\Models\Contact;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractHistoricalPayment as H;
use App\Models\SubcontractImportBatch as B;
use App\Models\SubcontractImportSheet as S;
use App\Models\SubcontractReconciliation;
use App\Models\SubcontractWorkGroup;
use App\Models\SubcontractWorkItem;
use App\Support\ReferenceLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage, review and import the historical workbook.
 *
 * Staging reads the file (nothing is written to the live tables), proposes a Contact for every account sheet and flags what needs a human decision. Confirming creates, for each
 * sheet that was chosen: an agreement (Active, source "import"), its groups and items, the PAID figure of each item as a COUNTED historical payment (never a Payment Voucher),
 * the dated payment list as NOT COUNTED evidence, and a reconciliation row comparing the workbook with what was imported. The same payment is never counted twice.
 */
class SubcontractImporter
{
    public static function stage(UploadedFile $file, $user): B
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $parsed = (new WorkbookParser(new XlsxReader($file->getRealPath())))->parseAll();
        $summary = $parsed['summary'];
        $byRef = [];
        foreach ($summary as $row) {
            if ($row['ref']) {
                $byRef[$row['ref']][] = $row;
            }
        }
        $contacts = Contact::query()->where('category', 'SubContractors')->get(['id', 'name']);
        $existing = DB::table('subcontract_agreements')->whereNull('deleted_at')->where('source', 'import')->get(['id', 'reference', 'contact_id', 'extra_attributes']);
        $already = $existing->map(fn ($r) => json_decode((string) $r->extra_attributes, true)['import_key'] ?? null)->filter()->all();
        // the accounts already in WIMS by the name of the sheet they came from: an edited copy of the workbook has a new file hash, so the name (and the contact) is what tells that an account is already there
        $bySheet = [];
        foreach ($existing as $r) {
            if ($nm = json_decode((string) $r->extra_attributes, true)['sheet'] ?? null) {
                $bySheet[mb_strtolower(trim((string) $nm))][] = $r;
            }
        }
        $batch = ReferenceLock::run('subcontract_import', fn () => B::create(['reference' => B::nextReference(), 'file_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'file_hash' => $hash, 'status' => 'staged', 'user_id' => $user->id]));
        $names = array_column($parsed['sheets'], 'name');
        $sheetCount = 0;
        $totals = ['contract' => 0.0, 'paid' => 0.0];
        foreach ($parsed['sheets'] as $s) {
            [$cid, $score] = isset($s['suggested_name']) ? self::matchContact($s['suggested_name'], $contacts) : [null, 0];
            $flags = $s['flags'] ?? [];
            $inSummary = $byRef[$s['name']] ?? [];
            if (isset($s['error'])) {
                $decision = 'skip';
            } else {
                $decision = ($s['state'] === 'visible' || $inSummary) ? 'import' : 'pending';
                if ($s['state'] !== 'visible') {
                    $flags[] = ['level' => 'info', 'text' => 'Hidden sheet in the workbook.'];
                }
                if (! $inSummary) {
                    $flags[] = ['level' => 'warn', 'text' => 'Not listed in the SUMMARY sheet.'];
                }
                if (count($inSummary) > 1) {
                    $flags[] = ['level' => 'warn', 'text' => 'Listed ' . count($inSummary) . ' times in the SUMMARY sheet (rows ' . implode(', ', array_column($inSummary, 'row')) . '): its figures are counted twice there. Import it once.'];
                }
                foreach (self::similarSheets($s['name'], $names) as $o) {
                    $flags[] = ['level' => 'warn', 'text' => 'Looks like another version of the same account: sheet "' . $o . '". Import only the current one.'];
                }
                $key = sha1($hash . '|' . $s['name']);
                if (in_array($key, $already, true)) {
                    $flags[] = ['level' => 'warn', 'text' => 'This sheet of this file was already imported.'];
                    $decision = 'skip';
                }
                foreach ($bySheet[mb_strtolower(trim((string) $s['name']))] ?? [] as $e) {
                    if ($decision !== 'skip') {
                        // same sheet name as an account that is already in WIMS (a different file hash, so the "same file" check above did not catch it); a different contact on the match does not make it a different account
                        $other = $score >= 0.75 && (int) $e->contact_id !== (int) $cid ? ' (under another contact: check the subcontractor)' : '';
                        $flags[] = ['level' => 'warn', 'text' => 'An account from a sheet of this name is already in WIMS: agreement ' . $e->reference . $other . '. It is set to Skip so the account is not created twice; choose Import only if this is really another account.'];
                        $decision = 'skip';
                    }
                }
                if (self::looksLikeProjectAccount($s)) {
                    $flags[] = ['level' => 'info', 'text' => 'A project labour account (several people are paid on it). The beneficiary is kept on every item; choose the contact that owns the account.'];
                }
            }
            $sum = $s['sums'] ?? ['contract' => 0, 'paid' => 0, 'dated' => 0];
            $sheet = S::create(['batch_id' => $batch->id, 'sheet_name' => $s['name'], 'sheet_state' => $s['state'], 'layout' => $s['layout'] ?? null, 'title' => $s['title'] ?? null, 'suggested_name' => $s['suggested_name'] ?? $s['name'], 'as_at_date' => $s['as_at'] ?? null,
                'currency' => $s['currency'] ?? 'TZS', 'contact_id' => $score >= 0.75 ? $cid : null, 'decision' => $decision, 'flags' => $flags, 'parsed' => $s,
                'totals' => ['contract' => $s['reported']['contract'] ?? null, 'paid' => $s['reported']['paid'] ?? null, 'items_contract' => $sum['contract'], 'items_paid' => $sum['paid'], 'dated_sum' => $sum['dated'], 'match_contact_id' => $cid, 'match_score' => round($score, 2),
                    'summary_rows' => array_column($inSummary, 'row'), 'summary_contract' => $inSummary[0]['contract'] ?? null, 'summary_paid' => $inSummary[0]['paid'] ?? null, 'summary_balance' => $inSummary[0]['balance'] ?? null]]);
            $sheetCount++;
            if ($decision === 'import') {
                $totals['contract'] += $sum['contract'];
                $totals['paid'] += $sum['paid'];
            }
        }
        $dupSummary = collect($summary)->groupBy('ref')->filter(fn ($g, $k) => $k && $g->count() > 1)->keys()->all();
        $batch->update(['sheet_count' => $sheetCount, 'summary' => ['summary_rows' => count($summary), 'summary_total_contract' => round(array_sum(array_column($summary, 'contract')), 2), 'summary_total_paid' => round(array_sum(array_column($summary, 'paid')), 2), 'summary_duplicates' => $dupSummary, 'staged_import_contract' => round($totals['contract'], 2), 'staged_import_paid' => round($totals['paid'], 2)]]);
        log_activity('Subcontract workbook staged: ' . $batch->reference, ['file' => $batch->file_name, 'sheets' => $sheetCount, 'hash' => $hash], $batch, 'Subcontracts');

        return $batch;
    }

    /** Other sheets that look like the same account (same words, or only "(P)", "H", "OLD", "NEW", "(2)" apart). */
    private static function similarSheets(string $name, array $all): array
    {
        $base = fn (string $n) => trim(preg_replace('/\s+/', ' ', preg_replace('/\((P|2)\)|\b(OLD|NEW|H)\b|[_.\-]/i', ' ', strtoupper($n))));
        $mine = $base($name);
        $out = [];
        foreach ($all as $o) {
            if ($o !== $name && $mine !== '' && $base($o) === $mine) {
                $out[] = $o;
            }
        }

        return $out;
    }

    private static function looksLikeProjectAccount(array $s): bool
    {
        $names = [];
        foreach ($s['groups'] ?? [] as $g) {
            foreach ($g['items'] as $i) {
                if ($i['benef'] !== '') {
                    $names[strtoupper($i['benef'])] = ($names[strtoupper($i['benef'])] ?? 0) + 1;
                }
            }
        }

        return count($names) >= 4 && ($s['item_count'] ?? 0) > 150;
    }

    /** @return array{0:?int,1:float} the best matching Contact and how well it matches (0 to 1) */
    private static function matchContact(string $name, $contacts): array
    {
        $norm = fn (string $v) => preg_replace('/[^A-Z0-9]/', '', strtoupper(preg_replace('/\([^)]*\)/', '', $v)));
        $n = $norm($name);
        $best = [null, 0.0];
        foreach ($contacts as $c) {
            $m = $norm($c->name);
            if ($n === '' || $m === '') {
                continue;
            }
            if ($n === $m) {
                return [$c->id, 1.0];
            }
            $score = (str_contains($n, $m) || str_contains($m, $n)) ? 0.85 : (similar_text($n, $m, $pct) ? $pct / 100 : 0);
            if ($score > $best[1]) {
                $best = [$c->id, $score];
            }
        }

        return $best;
    }

    /** What the sheet would bring in, after the currency decisions (US dollar groups converted at their rate). */
    public static function converted(array $p, string $sheetCurrency): array
    {
        $contract = 0.0;
        $paid = 0.0;
        foreach ($p['groups'] ?? [] as $g) {
            $f = ($g['currency'] === 'USD' && $sheetCurrency !== 'USD' && ! empty($g['rate'])) ? (float) $g['rate'] : 1.0;
            foreach ($g['items'] as $i) {
                $contract += $i['contract'] * $f;
                $paid += $i['paid'] * $f;
            }
        }
        foreach ($p['pay_only'] ?? [] as $x) {
            $paid += $x['amount'];
        }

        return ['contract' => round($contract, 2), 'paid' => round($paid, 2)];
    }

    /** Imports every sheet whose decision is "import" (a live agreement) or "archive" (kept in the archive: read-only, balance shown, left out of the totals). Returns a result list per sheet. */
    public static function confirm(B $batch, $user): array
    {
        abort_unless($batch->status === 'staged', 422, __('This batch is not waiting for confirmation.'));
        $sheets = $batch->sheets()->whereIn('decision', ['import', 'archive'])->get();
        $errors = [];
        foreach ($sheets as $s) {
            if (! $s->contact_id && ! trim((string) (($s->totals ?? [])['new_contact'] ?? ''))) {
                $errors[] = $s->sheet_name . ': ' . __('choose the subcontractor (a Contact) or say a new one should be created.');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['batch' => $errors]);
        }
        $result = [];
        DB::transaction(function () use (&$batch, $sheets, $user, &$result) {
            // the batch is locked and re-read, so a double click or a second tab cannot import the same sheets twice
            $batch = B::query()->whereKey($batch->id)->lockForUpdate()->first();
            abort_unless($batch->status === 'staged', 422, __('This batch is not waiting for confirmation.'));
            foreach ($sheets as $s) {
                $result[] = self::importSheet($batch, $s, $user);
            }
            $batch->update(['status' => 'imported', 'imported_by_user_id' => $user->id, 'imported_at' => now()]);
        });
        log_activity('Subcontract import confirmed: ' . $batch->reference, ['sheets' => count($result)], $batch, 'Subcontracts');

        return $result;
    }

    private static function importSheet(B $batch, S $s, $user): array
    {
        $p = $s->parsed;
        $cur = $s->currency ?: 'TZS';
        $contactId = $s->contact_id;
        $created = false;
        if (! $contactId) {
            abort_unless($user->can('create-contacts'), 403, __('You may not create Contacts: choose an existing subcontractor for :s.', ['s' => $s->sheet_name]));
            $name = trim((string) (($s->totals ?? [])['new_contact'] ?? $s->suggested_name));
            $c = Contact::query()->where('category', 'SubContractors')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first() ?: Contact::create(['name' => mb_substr($name, 0, 50), 'category' => 'SubContractors', 'account_id' => $user->account_id, 'details' => 'Created by the subcontracts import of ' . $batch->file_name]);
            $contactId = $c->id;
            $created = true;
        }
        $key = sha1($batch->file_hash . '|' . $s->sheet_name);
        if (DB::table('subcontract_agreements')->whereNull('deleted_at')->where('extra_attributes', 'like', '%' . $key . '%')->exists()) {
            return ['sheet' => $s->sheet_name, 'status' => 'skipped', 'note' => 'already imported'];
        }
        $conv = self::converted($p, $cur);
        $archive = $s->decision === 'archive';
        $a = A::create(['reference' => A::nextReference(), 'contact_id' => $contactId, 'warehouse_id' => $s->warehouse_id, 'title' => mb_substr(($s->suggested_name ?: $s->sheet_name) . ($archive ? ' [' . $s->sheet_name . ']' : ''), 0, 190), 'scope' => 'Imported from the sheet "' . $s->sheet_name . '" of ' . $batch->file_name . ' (' . $batch->reference . ').',
            'agreement_type' => ($p['layout'] ?? 'A') === 'A' ? 'quantity' : 'lump_sum', 'currency' => $cur, 'original_amount' => number_format($conv['contract'], 2, '.', ''), 'status' => $archive ? A::ARCHIVED : A::ACTIVE, 'as_at_date' => $s->as_at_date, 'source' => 'import', 'import_sheet_id' => $s->id,
            'user_id' => $user->id, 'approved_by_user_id' => $user->id, 'approved_at' => now(), 'decision_remarks' => 'Approved by the confirmation of import ' . $batch->reference, 'extra_attributes' => ['import_key' => $key, 'import_batch' => $batch->reference, 'sheet' => $s->sheet_name, 'sheet_state' => $s->sheet_state]
                + ($archive ? ['archived' => ['from' => A::COMPLETED, 'at' => now()->toDateTimeString(), 'by' => $user->id, 'reason' => 'Archived at import: the ' . ($s->sheet_state === 'visible' ? 'sheet' : 'hidden sheet') . ' "' . $s->sheet_name . '" of the workbook (an older version or a finished account), kept for reference.']] : [])]);
        $rowToItem = [];
        $sort = 0;
        $line = 0;
        $paidCounted = 0.0;
        foreach ($p['groups'] ?? [] as $g) {
            $group = null;
            if ($g['name'] !== null) {
                $group = SubcontractWorkGroup::create(['agreement_id' => $a->id, 'name' => mb_substr($g['name'], 0, 190), 'kind' => 'location', 'sort_order' => ++$sort]);
            }
            $f = ($g['currency'] === 'USD' && $cur !== 'USD' && ! empty($g['rate'])) ? (float) $g['rate'] : 1.0;
            foreach ($g['items'] as $i) {
                $amount = round($i['contract'] * $f, 2);
                $isQty = $i['qty'] !== null && $i['rate'] !== null && ! empty($i['qty_rate_ok']) && $f === 1.0;
                $f_proposal = $f !== 1.0 ? $f : 1.0;
                $remarks = trim($i['remarks'] . ($f !== 1.0 ? ' [US$ ' . number_format($i['contract'], 2) . ' at ' . number_format($f, 2) . ']' : '') . (! empty($i['proposal']) ? ' [PROPOSAL on the sheet: ' . number_format($i['proposal'] * $f_proposal, 2) . ']' : '') . (! empty($i['no_amount']) ? ' [no amounts on the sheet]' : '') . ($i['paid'] > $i['contract'] + 0.5 ? ' [paid above contract on the sheet]' : ''));
                $type = preg_match('/\bnew\b/i', $i['remarks']) ? 'additional' : (preg_match('/revised/i', $i['remarks']) ? 'revised' : 'original');
                $wi = SubcontractWorkItem::create(['agreement_id' => $a->id, 'group_id' => $group->id ?? null, 'line_no' => ++$line, 'description' => mb_substr($i['desc'], 0, 2000), 'beneficiary_name' => $i['benef'] !== '' ? mb_substr($i['benef'], 0, 190) : null, 'unit' => $i['unit'], 'quantity' => $i['qty'], 'rate' => $i['rate'],
                    'pricing_method' => $isQty ? 'qty_rate' : 'fixed', 'amount' => number_format($amount, 2, '.', ''), 'item_type' => $type, 'status' => 'active', 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 1000) : null, 'source_sheet' => $s->sheet_name, 'source_row' => $i['row']]);
                $rowToItem[$i['row']] = $wi->id;
                if ($i['paid'] > 0) {
                    $pa = round($i['paid'] * $f, 2);
                    $paidCounted += $pa;
                    H::create(['agreement_id' => $a->id, 'work_item_id' => $wi->id, 'import_sheet_id' => $s->id, 'kind' => 'opening_paid', 'counted' => true, 'amount' => number_format($pa, 2, '.', ''), 'currency' => $cur, 'exchange_rate' => $f !== 1.0 ? $f : null, 'amount_tzs' => $cur === 'TZS' ? number_format($pa, 2, '.', '') : null,
                        'source_sheet' => $s->sheet_name, 'source_row' => $i['row'], 'note' => $i['paid_parts'] ? 'Instalments from the workbook formula: ' . implode(' + ', array_map(fn ($x) => number_format($x, 2), $i['paid_parts'])) : 'PAID figure of the item in the workbook', 'imported_by_user_id' => $user->id]);
                }
            }
        }
        foreach ($p['pay_only'] ?? [] as $x) {
            $paidCounted += $x['amount'];
            H::create(['agreement_id' => $a->id, 'work_item_id' => $rowToItem[$x['after_item_row']] ?? null, 'import_sheet_id' => $s->id, 'kind' => 'opening_paid', 'counted' => true, 'amount' => number_format($x['amount'], 2, '.', ''), 'currency' => $cur, 'amount_tzs' => $cur === 'TZS' ? number_format($x['amount'], 2, '.', '') : null,
                'source_sheet' => $s->sheet_name, 'source_row' => $x['row'], 'note' => 'Paid amount without a description (kept on the previous item)', 'imported_by_user_id' => $user->id]);
        }
        $datedSum = 0.0;
        foreach ($p['pays'] ?? [] as $pay) {
            if ($pay['amount'] === null) {
                continue;
            }
            $datedSum += $pay['amount'];
            H::create(['agreement_id' => $a->id, 'work_item_id' => null, 'import_sheet_id' => $s->id, 'kind' => 'dated_evidence', 'counted' => false, 'payment_date' => $pay['date'], 'amount' => number_format($pay['amount'], 2, '.', ''), 'currency' => ! empty($pay['usd']) ? 'USD' : $cur,
                'source_sheet' => $s->sheet_name, 'source_row' => $pay['row'], 'note' => trim('From the PAYMENT DATE list of the workbook' . ($pay['date'] === null ? '; the date "' . $pay['raw_date'] . '" could not be read' : '') . ($pay['note'] ? '; ' . $pay['note'] : '')), 'imported_by_user_id' => $user->id]);
        }
        $rep = $p['reported'] ?? [];
        $paidSrc = $rep['paid'] ?? null;
        $diff = $paidSrc === null ? 0.0 : $paidCounted - (float) $paidSrc;
        SubcontractReconciliation::create(['agreement_id' => $a->id, 'import_sheet_id' => $s->id, 'source_contract' => $rep['contract'] ?? null, 'source_paid' => $paidSrc, 'source_balance' => $rep['balance'] ?? null, 'imported_contract' => number_format($conv['contract'], 2, '.', ''),
            'imported_paid' => number_format($paidCounted, 2, '.', ''), 'dated_list_total' => number_format($datedSum, 2, '.', ''), 'status' => ($paidSrc !== null && abs($diff) <= (float) SubcontractSettings::get()['dated_list_tolerance'] && ($rep['contract'] === null || abs($conv['contract'] - (float) $rep['contract']) <= 1.0)) ? 'approved' : 'open',
            'note' => $paidSrc === null ? 'The sheet has no paid total to compare with: check the figures by hand.' : (abs($diff) > 1 ? 'Imported paid differs from the sheet paid total by ' . number_format($diff, 2) : null), 'approved_by_user_id' => null]);
        if (SubcontractReconciliation::where('agreement_id', $a->id)->value('status') === 'approved') {
            SubcontractReconciliation::where('agreement_id', $a->id)->update(['approved_by_user_id' => $user->id, 'approved_at' => now(), 'note' => 'Matches the workbook (within rounding): closed automatically at import']);
        }
        $s->update(['agreement_id' => $a->id]);
        log_activity('Subcontract agreement ' . $a->reference . ' imported from sheet "' . $s->sheet_name . '"', ['batch' => $batch->reference, 'contract' => $conv['contract'], 'paid_counted' => $paidCounted, 'dated_evidence_sum' => $datedSum, 'new_contact' => $created], $a, 'Subcontracts');

        return ['sheet' => $s->sheet_name, 'status' => 'imported', 'agreement' => $a->reference, 'agreement_id' => $a->id, 'contract' => $conv['contract'], 'paid' => round($paidCounted, 2), 'dated' => round($datedSum, 2), 'new_contact' => $created];
    }

    /** Takes a batch back: only agreements that have no payment request, variation, voucher payment or anything else added since the import. */
    public static function revert(B $batch, $user): array
    {
        abort_unless($batch->status === 'imported', 422, __('Only an imported batch can be taken back.'));
        $ids = $batch->sheets()->whereNotNull('agreement_id')->pluck('agreement_id');
        $blocked = DB::table('subcontract_payment_requests')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->pluck('agreement_id')->unique()->all();
        $blocked = array_merge($blocked, DB::table('subcontract_variations')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->pluck('agreement_id')->unique()->all());
        if ($blocked) {
            throw ValidationException::withMessages(['batch' => __('These agreements already have requests or variations and cannot be taken back: :a', ['a' => implode(', ', A::withoutGlobalScopes()->whereIn('id', array_unique($blocked))->pluck('reference')->all())])]);
        }
        // an account that has had anything done to it since the import (an opening balance saved, the reset to opening balances, a voucher payment, a work item added by hand) is no longer what the import made: taking it back would delete that work
        $changed = DB::table('subcontract_historical_payments')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->whereNull('import_sheet_id')->pluck('agreement_id')->all();
        $changed = array_merge($changed, DB::table('subcontract_payments')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->pluck('agreement_id')->all());
        $changed = array_merge($changed, DB::table('subcontract_work_items')->whereIn('agreement_id', $ids)->whereNull('deleted_at')->whereNull('source_sheet')->pluck('agreement_id')->all());
        $reset = DB::table('activity_log')->where('log_name', 'Subcontracts')->where('description', 'Subcontract accounts reset to opening balances')->when($batch->imported_at, fn ($q, $v) => $q->where('created_at', '>=', $v))->exists();
        if ($changed || $reset) {
            throw ValidationException::withMessages(['batch' => $reset && ! $changed
                ? __('The accounts were reset to opening balances after this import, so the import can no longer be taken back (it would delete the opening balances).')
                : __('These agreements have more than their imported lines now (an opening balance, a voucher payment or work added later), so the import cannot be taken back: :a', ['a' => implode(', ', A::withoutGlobalScopes()->whereIn('id', array_unique($changed))->pluck('reference')->all())])]);
        }
        DB::transaction(function () use ($batch, $ids, $user) {
            foreach ($ids as $id) {
                H::where('agreement_id', $id)->delete();
                SubcontractWorkItem::where('agreement_id', $id)->delete();
                SubcontractWorkGroup::where('agreement_id', $id)->delete();
                SubcontractReconciliation::where('agreement_id', $id)->delete();
                A::withoutGlobalScopes()->whereKey($id)->get()->each->delete();
            }
            $batch->sheets()->update(['agreement_id' => null]);
            $batch->update(['status' => 'reverted']);
        });
        log_activity('Subcontract import taken back: ' . $batch->reference, ['agreements' => $ids->count()], $batch, 'Subcontracts');

        return ['agreements' => $ids->count()];
    }
}
