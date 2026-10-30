<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Models\IdPassCard;
use App\Services\FeatureManager;
use App\Services\IdPass\CardAssets;
use App\Services\IdPass\IdPassKinds;
use App\Services\IdPass\IdPassSettings;
use App\Services\IdPass\NameFitter;
use Illuminate\Http\Request;

/**
 * The page a scanned QR code opens. The code holds only a long random token (never a database number). Anybody who scans sees whether the ID is valid, its type, number and dates and the
 * holder's first name with the initial of the last; a signed-in person who may read that kind also sees the photograph, the full name, the title and the vehicle. With the setting
 * "login" nobody sees anything before signing in. A revoked, suspended, expired, replaced or waiting card is never shown as valid. The route is rate limited.
 */
class VerifyController extends Controller
{
    private const STATES = [
        'Active' => ['VALID', '#15803d', 'The ID is valid.'], 'Expired' => ['EXPIRED', '#b45309', 'The validity of this ID has ended.'], 'Suspended' => ['SUSPENDED', '#b91c1c', 'This ID is suspended. Do not let the holder through.'],
        'Revoked' => ['REVOKED', '#b91c1c', 'This ID has been revoked. Do not accept it.'], 'Replaced' => ['REPLACED', '#b91c1c', 'This ID was replaced by a newer one. Do not accept it.'], 'Pending Approval' => ['NOT YET VALID', '#b45309', 'This pass has not been approved yet.'],
        'Rejected' => ['REJECTED', '#b91c1c', 'This pass was rejected. Do not accept it.'],
    ];

    public function __construct()
    {
        $this->setAbilities([]); // public page: the checks are made inside
    }

    public function show(Request $request, string $token)
    {
        $notFound = fn () => response()->view('idpass.verify', ['state' => 'notfound'] + $this->shell(), 404);
        if (! \Schema::hasTable('idpass_cards') || ! preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
            return $notFound();
        }
        $card = IdPassCard::query()->withoutGlobalScope('account')->with('category')->where('token', $token)->first();
        $user = $request->user();
        if (! $card) {
            return $notFound();
        }
        if (! FeatureManager::canAccess('id_verify', $user)) {
            return response()->view('idpass.verify', ['state' => 'off'] + $this->shell(), 503);
        }
        if (IdPassSettings::qrMode() === 'login' && ! $user) {
            return response()->view('idpass.verify', ['state' => 'login'] + $this->shell(), 401);
        }
        $eff = $card->effective_status;
        [$word, $color, $note] = self::STATES[$eff] ?? [strtoupper($eff), '#64748b', ''];
        if ($eff === 'Active' && $card->issued_at->isFuture()) {
            [$word, $color, $note] = ['NOT YET VALID', '#b45309', 'This ID starts on ' . $card->issued_at->format('j F Y') . '.'];
        }
        $full = $user && IdPassKinds::canRead($card->kind, $user);
        $name = NameFitter::display((string) $card->full_name);
        $parts = array_values(array_filter(explode(' ', $name)));
        $short = count($parts) > 1 ? $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $name;
        $photo = null;
        if ($full && ($p = CardAssets::photo($card))) {
            $photo = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($p));
        }
        $cat = $card->category;
        $with = null;
        if (optional($cat)->pooled) {
            $a = \App\Models\IdPassAssignment::query()->where('card_id', $card->id)->whereNull('released_at')->latest('id')->first();
            if ($a) {
                $ap = array_values(array_filter(explode(' ', NameFitter::display((string) $a->holder_name))));
                $with = $full ? NameFitter::display((string) $a->holder_name) : (count($ap) > 1 ? $ap[0] . ' ' . mb_strtoupper(mb_substr(end($ap), 0, 1)) . '.' : ($ap[0] ?? ''));
                $host = $full ? $a->host_name : null;
            }
        }

        // how far through its validity the ID is, for the meter (an ID without an end date shows a full, calm bar)
        $today = \Carbon\Carbon::today(config('app.timezone'));
        $daysLeft = $card->expires_at ? (int) $today->diffInDays($card->expires_at->copy()->startOfDay(), false) : null;
        $pct = 100;
        if ($card->expires_at) {
            $total = max(1, $card->issued_at->copy()->startOfDay()->diffInDays($card->expires_at->copy()->startOfDay()));
            $pct = (int) max(0, min(100, round(($daysLeft / $total) * 100))); // what is left of the validity
        }
        $phones = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/', IdPassSettings::get('idpass_phones')))));
        $contact = ['address' => IdPassSettings::get('idpass_address'), 'pobox' => IdPassSettings::get('idpass_pobox'), 'phones' => $phones, 'email' => IdPassSettings::get('idpass_email'), 'website' => IdPassSettings::get('idpass_website')];

        return response()->view('idpass.verify', [
            'contact' => $contact, 'intro' => IdPassSettings::get('idpass_intro'), 'terms' => IdPassSettings::terms(), 'band' => $cat ? $cat->uiColor() : '#1565c0', 'bandInk' => $cat ? $cat->uiInk() : '#ffffff',
            'daysLeft' => $daysLeft, 'pct' => $pct, 'hasEnd' => (bool) $card->expires_at, 'initials' => mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')),
            'state' => 'card', 'word' => $word, 'color' => $color, 'note' => __($note), 'kind' => __(\App\Models\IdPassCategory::KINDS[$card->kind] ?? ''), 'category' => optional($cat)->name, 'number' => $card->number,
            'holder' => ! empty($with) ? $with : (optional($cat)->pooled ? __('Visitor Pass (not in use)') : ($full ? $name : $short)), 'title' => $full ? $card->title : null, 'issued' => $card->issued_at->format('j F Y'), 'expires' => $card->expires_at ? $card->expires_at->format('j F Y') : IdPassSettings::get('idpass_no_expiry_text'),
            'photo' => $photo, 'vehicle' => $full ? $card->vehicle_registration : null, 'host' => $full ? ($host ?? $card->host_name) : null, 'restrictions' => $full ? $card->restrictions : null, 'full' => $full,
            'replacedBy' => $card->replaced_by_id ? IdPassCard::query()->withoutGlobalScope('account')->whereKey($card->replaced_by_id)->value('number') : null, 'signedIn' => (bool) $user,
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** What every version of the page needs besides the card: the contacts of RANS for the footer. */
    private function shell(): array
    {
        $phones = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/', IdPassSettings::get('idpass_phones')))));

        return ['contact' => ['address' => IdPassSettings::get('idpass_address'), 'pobox' => IdPassSettings::get('idpass_pobox'), 'phones' => $phones, 'email' => IdPassSettings::get('idpass_email'), 'website' => IdPassSettings::get('idpass_website')]];
    }
}
