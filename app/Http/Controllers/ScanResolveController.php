<?php

namespace App\Http\Controllers;

use App\Models\IdPassCard;
use App\Models\Item;
use App\Services\IdPass\IdPassKinds;
use App\Services\Qr\QrCodeService;
use Illuminate\Http\Request;

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

        return response()->json(['status' => 'not_found']);
    }
}
