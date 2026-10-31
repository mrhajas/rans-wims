<?php

namespace App\Services\IdPass;

use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassEvent;
use App\Models\Staff;
use App\Models\User;
use App\Support\TzPhone;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sync Data: what an ID holds (photograph, contact number, work title) is saved to the company record of the person: the Staff record for a Staff ID, the Contact for a Compound Worker ID
 * or a Gate Pass of a contact. Nothing is changed before the person has seen what is different and said yes; a field that is empty in the record is simply filled, a field that holds
 * something else is replaced only when it is ticked. Every sync is written to the history of the ID and to the activity log of the record, with the old and the new value.
 */
class RecordSync
{
    /** The record an ID belongs to, or null (Visitor Passes and typed Gate Passes belong to no company record). @return array{type:string,model:Staff|Contact,label:string,name:string,url:string,ability:string}|null */
    public static function target(IdPassCard $c): ?array
    {
        if ($c->staff_id && ($s = Staff::query()->find($c->staff_id))) {
            return ['type' => 'staff', 'model' => $s, 'label' => __('the Staff record'), 'name' => (string) $s->name, 'url' => route('staffs.show', $s->id), 'ability' => 'update-staffs'];
        }
        if ($c->contact_id && ($k = Contact::query()->find($c->contact_id))) {
            return ['type' => 'contact', 'model' => $k, 'label' => __('the Contact'), 'name' => (string) $k->name, 'url' => route('contacts.edit', $k->id), 'ability' => 'update-contacts'];
        }

        return null;
    }

    public static function may(IdPassCard $c, ?User $u = null): bool
    {
        $u ??= auth()->user();
        $t = self::target($c);

        return (bool) ($t && $u && $u->can($t['ability']));
    }

    private static function same(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /** The photograph that goes to the record: the picture as first taken when the ID has it, else the picture on the card. */
    private static function cardPhotoFile(IdPassCard $c): ?string
    {
        $disk = Storage::disk('local');
        foreach ([$c->photo_original, $c->photo_path] as $p) {
            if ($p && $disk->exists($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * What differs between the ID and its record. Each field: key, label, record (what the record holds now), card (what the ID holds), state:
     * 'new' (the record has nothing: it will be filled), 'different' (the record holds something else: it will be replaced if ticked), 'same' (nothing to do), 'blocked' (cannot be saved, see note).
     *
     * @return array{target:array,fields:array<int,array>}|null
     */
    public static function diff(IdPassCard $c): ?array
    {
        $t = self::target($c);
        if (! $t) {
            return null;
        }
        $m = $t['model'];
        $fields = [];
        $route = 'id-passes.' . IdPassKinds::get($c->kind)['route'] . '.photo-file';

        // the contact number
        $cardPhone = trim((string) $c->phone);
        $recordPhone = trim((string) ($t['type'] === 'staff' ? $m->mobile_no : $m->phone));
        $norm = TzPhone::normalize($cardPhone);
        $f = ['key' => 'phone', 'label' => $t['type'] === 'staff' ? __('Mobile number') : __('Phone'), 'record' => $recordPhone ?: null, 'card' => $cardPhone ?: null, 'kind' => 'text'];
        if ($cardPhone === '') {
            $f['state'] = 'same';
            $f['note'] = __('The ID has no number to save.');
        } elseif (! $norm) {
            $f['state'] = 'blocked';
            $f['note'] = __('This is not a Tanzanian mobile number, so it cannot be saved to the record. Change it on the ID first.');
        } elseif ($recordPhone === '') {
            $f['state'] = 'new';
            $f['save'] = $norm;
        } elseif (TzPhone::normalize($recordPhone) === $norm || self::same($recordPhone, $norm)) {
            $f['state'] = 'same';
        } else {
            $f['state'] = 'different';
            $f['save'] = $norm;
        }
        $fields[] = $f;

        if ($t['type'] === 'staff') {
            // the work title
            $cardTitle = trim((string) $c->title);
            $f = ['key' => 'position', 'label' => __('Position'), 'record' => trim((string) $m->position) ?: null, 'card' => $cardTitle ?: null, 'kind' => 'text'];
            $f['state'] = $cardTitle === '' || self::same($cardTitle, $m->position) ? 'same' : (trim((string) $m->position) === '' ? 'new' : 'different');
            $f['save'] = $cardTitle;
            $fields[] = $f;

            // the photograph
            $disk = Storage::disk('assets');
            $has = $m->photo && $disk->exists($m->photo);
            $src = self::cardPhotoFile($c);
            $f = ['key' => 'photo', 'label' => __('Photograph'), 'kind' => 'photo', 'record' => $has ? $disk->url($m->photo) : null, 'card' => $src ? route($route, [$c->id]) . '?w=240' : null];
            if (! $src) {
                $f['state'] = 'same';
                $f['note'] = __('The ID has no photograph to save.');
            } elseif (! $has) {
                $f['state'] = 'new';
            } elseif (md5_file($disk->path($m->photo)) === md5(Storage::disk('local')->get($src))) {
                $f['state'] = 'same';
            } else {
                $f['state'] = 'different';
            }
            $fields[] = $f;
        }

        return ['target' => ['type' => $t['type'], 'label' => $t['label'], 'name' => $t['name'], 'url' => $t['url']], 'fields' => $fields];
    }

    /**
     * Saves the chosen fields to the record. $keys: the fields to save (only those whose state is new or different are touched). $onlyGaps: never replace, only fill what is empty.
     *
     * @return array{saved:string[],replaced:string[]}
     */
    public static function apply(IdPassCard $card, array $keys, User $by, bool $onlyGaps = false): array
    {
        $d = self::diff($card);
        abort_unless($d, 422, __('This ID has no company record to sync with.'));
        $t = self::target($card);
        abort_unless($by->can($t['ability']), 403, __('You may not change this record.'));
        $m = $t['model'];
        $before = $after = $saved = $replaced = [];
        $updates = [];
        foreach ($d['fields'] as $f) {
            if (! in_array($f['key'], $keys, true) || ! in_array($f['state'], ['new', 'different'], true)) {
                continue;
            }
            if ($onlyGaps && $f['state'] !== 'new') {
                continue;
            }
            $col = ['phone' => $t['type'] === 'staff' ? 'mobile_no' : 'phone', 'position' => 'position', 'photo' => 'photo'][$f['key']];
            if ($f['key'] === 'photo') {
                $src = self::cardPhotoFile($card);
                if (! $src) {
                    continue;
                }
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
                $new = 'staffs/' . Str::random(40) . '.' . (in_array($ext, ['jpg', 'jpeg', 'png'], true) ? $ext : 'jpg');
                Storage::disk('assets')->put($new, Storage::disk('local')->get($src));
                $before['photo'] = $m->photo;       // the old file stays on disk: the history names it
                $after['photo'] = $new;
                $updates['photo'] = $new;
            } else {
                $before[$f['key']] = $m->{$col};
                $after[$f['key']] = $f['save'];
                $updates[$col] = $f['save'];
            }
            $saved[] = $f['key'];
            if ($f['state'] === 'different') {
                $replaced[] = $f['key'];
            }
        }
        if (! $updates) {
            return ['saved' => [], 'replaced' => []];
        }
        $m->forceFill($updates)->save();
        IdPassEvent::record($card, 'synced', null, null, ['target' => $t['type'], 'record_id' => $m->id, 'record' => $t['name'], 'before' => $before, 'after' => $after, 'only_gaps' => $onlyGaps ?: null]);
        log_activity($t['type'] === 'staff' ? 'Staff record updated from the RANS ID' : 'Contact updated from the RANS ID', ['id' => $card->number, 'fields' => $saved, 'before' => $before, 'after' => $after], $m, 'RANS ID & Pass Management');

        return ['saved' => $saved, 'replaced' => $replaced];
    }

    /** After a run of many IDs: for each card, the empty fields of the record are filled from the ID (nothing is ever replaced). @param iterable<IdPassCard> $cards @return array{people:int,phones:int,photos:int,positions:int,skipped:int} */
    public static function fillGaps(iterable $cards, User $by): array
    {
        $out = ['people' => 0, 'phones' => 0, 'photos' => 0, 'positions' => 0, 'skipped' => 0];
        foreach ($cards as $card) {
            $t = self::target($card);
            if (! $t || ! $by->can($t['ability'])) {
                $out['skipped']++;

                continue;
            }
            $r = self::apply($card, ['phone', 'photo', 'position'], $by, true);
            if ($r['saved']) {
                $out['people']++;
                $out['phones'] += in_array('phone', $r['saved'], true) ? 1 : 0;
                $out['photos'] += in_array('photo', $r['saved'], true) ? 1 : 0;
                $out['positions'] += in_array('position', $r['saved'], true) ? 1 : 0;
            }
        }

        return $out;
    }
}
