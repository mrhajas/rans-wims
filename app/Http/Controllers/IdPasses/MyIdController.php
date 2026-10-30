<?php

namespace App\Http\Controllers\IdPasses;

use App\Http\Controllers\Controller;
use App\Models\IdPassCard;
use App\Services\IdPass\CardRenderer;
use App\Services\IdPass\IdPassQuery;
use App\Services\IdPass\IdPassSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "My ID": the signed-in person sees their own RANS Staff ID (the Staff record linked to their login), both sides, its status and dates. It needs no permission because it only ever shows
 * the card of the person who is signed in; nothing can be changed or printed from here.
 */
class MyIdController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** The card to show: the active one, else the newest. */
    public static function cardFor($user): ?IdPassCard
    {
        $staff = $user ? $user->staff : null;
        if (! $staff) {
            return null;
        }
        $cards = IdPassCard::query()->where('kind', 'staff')->where('staff_id', $staff->id)->orderByDesc('id')->get();

        return $cards->firstWhere('status', IdPassCard::ACTIVE) ?: $cards->first();
    }

    public function show(Request $request)
    {
        $card = self::cardFor($request->user());
        $staff = $request->user()->staff;
        if (! $card) {
            return Inertia::render('IdPasses/MyId', ['card' => null, 'linked' => (bool) $staff, 'front' => null, 'back' => null, 'css' => null]);
        }
        $card->load('category');
        $data = CardRenderer::data($card);

        return Inertia::render('IdPasses/MyId', [
            'linked' => true,
            'card' => IdPassQuery::row($card) + ['status_raw' => $card->status, 'title' => $card->title, 'validity_text' => $card->expires_at ? $card->expires_at->locale('en')->translatedFormat('j F Y') : IdPassSettings::get('idpass_no_expiry_text')],
            'front' => CardRenderer::front($data, 'web'), 'back' => CardRenderer::back($data, 'web'), 'css' => CardRenderer::stylesheet('web'),
        ]);
    }
}
