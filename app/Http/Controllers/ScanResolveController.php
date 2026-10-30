<?php

namespace App\Http\Controllers;

use App\Models\IdPassCard;
use App\Models\Item;
use App\Services\IdPass\IdPassKinds;
use App\Services\Qr\QrCodeService;
use App\Support\Qr\QrRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The background scanner: a code read by a handheld scanner anywhere in the system (no field in focus) is looked up here once, and the answer
 * is the page to open in a new tab. A WIMS QR code (its own checks and log), an item code / SKU, or the number of an ID / pass. Nothing is
 * opened for a record the person may not see: the answer is only "not allowed".
 */
class ScanResolveController extends Controller
{
    public function __construct(private QrCodeService $qr)
    {
        $this->setAbilities([]);
    }

    public function resolve(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:300']);
        $user = $request->user();
        $code = trim($d['code']);

        // the address of a WIMS page (a printed QR code that carries a plain page address): opened as it is, the page does its own permission checks
        if (preg_match('#^https?://#i', $code) && ! preg_match('#/q/[A-Za-z0-9]{8,64}(?:[/?\#]|$)#', $code)) {
            $host = parse_url($code, PHP_URL_HOST);
            if ($host && strcasecmp($host, parse_url(config('app.url'), PHP_URL_HOST) ?: $request->getHost()) === 0 || $host === $request->getHost()) {
                return response()->json(['status' => 'allowed', 'go' => $code, 'kind' => __('Page')]);
            }

            return response()->json(['status' => 'foreign']);
        }

        // a WIMS QR code: its address (/q/<token>) or the bare token
        $token = null;
        if (preg_match('#/q/([A-Za-z0-9]{8,64})(?:[/?\#]|$)#', $code, $m)) {
            $token = $m[1];
        } elseif (preg_match('/^[A-Za-z0-9]{12,40}$/', $code)) {
            $token = $code;
        }
        if ($token && $user->can('scan-qr-codes')) {
            $r = $this->qr->resolveScan($user, $token);
            if ($r['status'] === 'allowed') {
                return response()->json(['status' => 'allowed', 'go' => route('qr.scan', ['token' => $token, 'via' => 'scanner'])]);
            }
            if ($r['status'] !== 'invalid') {
                $this->qr->record($user, $r, 'scanner', $token);

                return response()->json(['status' => $r['status'], 'kind' => __($r['entry']['label'] ?? 'record')]);
            }
            // not one of ours: it may still be a barcode of an item
        }

        $clean = str_replace(["\x1D", "\u{001D}"], '', $code);
        $noPrefix = preg_replace('/^\][A-Za-z0-9]{2}/', '', $clean);
        $compact = preg_replace('/\s+/', '', $noPrefix ?: $clean);
        $candidates = collect([$code, $clean, $noPrefix, $compact, ltrim((string) $compact, '0')])->filter(fn ($v) => is_string($v) && $v !== '')->unique()->values()->all();

        $item = Item::query()->whereIn('code', $candidates)->orWhereIn('sku', $candidates)->first();
        if ($item) {
            return $user->can('read-items')
                ? response()->json(['status' => 'allowed', 'go' => route('items.show', $item->id), 'kind' => __('Item')])
                : response()->json(['status' => 'no_permission', 'kind' => __('Item')]);
        }

        $card = IdPassCard::query()->whereIn('number', $candidates)->first();
        if ($card) {
            $k = IdPassKinds::get($card->kind);

            return IdPassKinds::canRead($card->kind, $user)
                ? response()->json(['status' => 'allowed', 'go' => route('id-passes.' . $k['route'] . '.show', $card->id), 'kind' => __($k['label'])])
                : response()->json(['status' => 'no_permission', 'kind' => __($k['label'])]);
        }

        // the reference number of a document or record (a voucher, a transfer, a request, a staff number...)
        if ($hit = $this->byReference($user, $candidates)) {
            return response()->json(['status' => 'allowed', 'go' => $hit['go'], 'kind' => $hit['kind']]);
        }

        return response()->json(['status' => 'not_found']);
    }

    /** The columns of each kind's table that hold its reference number (found once, kept for an hour). */
    private function referenceColumns(): array
    {
        return Cache::remember('scan.reference-columns', 3600, function () {
            $want = ['reference', 'reference_no', 'pv_number', 'asset_number', 'staff_number', 'compliance_number', 'certificate_number', 'trip_number', 'voucher_number', 'report_number', 'case_number', 'number', 'batch_reference', 'document_number', 'request_number', 'order_number', 'invoice_number'];
            $out = [];
            $seen = [];
            foreach (QrRegistry::all() as $type => $e) {
                if (! class_exists($e['model']) || ! empty($e['where'])) {
                    continue;
                }
                $key = $e['model'] . '|' . $e['route'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                try {
                    $table = (new $e['model'])->getTable();
                    $cols = array_values(array_intersect($want, Schema::getColumnListing($table)));
                } catch (\Throwable) {
                    $cols = [];
                }
                if ($cols) {
                    $out[$type] = $cols;
                }
            }

            return $out;
        });
    }

    /** @param string[] $candidates @return array{go:string,kind:string}|null */
    private function byReference($user, array $candidates): ?array
    {
        $found = null;
        foreach ($this->referenceColumns() as $type => $cols) {
            $e = QrRegistry::get($type);
            if (! $e || ! QrRegistry::canOpen($user, $e)) {
                continue;
            }
            foreach ($cols as $col) {
                $q = $e['model']::query()->whereIn($col, $candidates);
                if (! empty($e['scope']) && method_exists($e['model'], 'scope' . ucfirst($e['scope']))) {
                    $q->{$e['scope']}($user);
                }
                $id = $q->value((new $e['model'])->getKeyName());
                if ($id) {
                    return ['go' => route($e['route'], [$e['param'] => $id]), 'kind' => __($e['label'])];
                }
            }
        }

        return $found;
    }
}
