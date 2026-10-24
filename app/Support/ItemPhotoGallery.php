<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stores the photos taken or attached for an item (three at most, besides the main photo). */
class ItemPhotoGallery
{
    public static function add(Item $item, $files): void
    {
        $files = array_values(array_filter((array) $files, fn ($f) => $f instanceof UploadedFile));
        if (! $files) {
            return;
        }

        $have = $item->photos()->count();
        if ($have + count($files) > ItemPhoto::MAX) {
            throw ValidationException::withMessages(['extra_photos' => __('An item can have at most :n extra photos.', ['n' => ItemPhoto::MAX])]);
        }

        foreach ($files as $i => $file) {
            $item->photos()->create(['path' => $file->store('items', 'assets'), 'position' => $have + $i]);
        }

        // an item with no main photo yet shows the first photo taken, so lists and cards are not left empty
        $item->refresh();
        if (! $item->photo) {
            $first = $item->photos()->first();
            if ($first && Storage::disk('assets')->exists($first->path)) {
                $copy = 'items/' . Str::uuid() . '.' . pathinfo($first->path, PATHINFO_EXTENSION);
                Storage::disk('assets')->copy($first->path, $copy);
                $item->update(['photo' => $copy]);
            }
        }
    }
}
