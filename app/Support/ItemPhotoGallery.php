<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Stores the photos taken or attached for an item (three at most, besides the main photo). */
class ItemPhotoGallery
{
    public static function add(Item $item, $files, ?int $bincardIndex = null, ?\App\Models\User $by = null): void
    {
        $files = array_values(array_filter((array) $files, fn ($f) => $f instanceof UploadedFile));
        if (! $files) {
            return;
        }

        // three photos in all, the face of the item (items.photo) being one of them
        $have = ($item->photo ? 1 : 0) + $item->photos()->count();
        $plain = $bincardIndex === null ? count($files) : count($files) - 1;
        if ($have + $plain > ItemPhoto::MAX) {
            throw ValidationException::withMessages(['extra_photos' => __('An item can have at most :n photos.', ['n' => ItemPhoto::MAX])]);
        }

        $position = $item->photos()->count();
        foreach ($files as $i => $file) {
            // the one the person marked as the BinCard goes in as the BinCard (it does not count among the three photos, and it stamps the BinCard date)
            if ($bincardIndex !== null && $bincardIndex === $i) {
                BinCard::assign($item, ItemPhoto::create(['item_id' => $item->id, 'path' => ItemImage::store($file, 'bincard'), 'kind' => 'bincard', 'position' => 0, 'user_id' => optional($by)->id]), $by);
                continue;
            }
            // an item with no face yet takes the first photo as its face (the same file, not a copy)
            if (! $item->photo) {
                $item->forceFill(['photo' => ItemImage::store($file)])->saveQuietly();
                continue;
            }
            [$path, $cut] = ItemImage::storeInfo($file);
            ItemPhoto::create(['item_id' => $item->id, 'path' => $path, 'kind' => 'photo', 'cutout' => $cut, 'position' => $position++, 'user_id' => optional($by)->id]);
        }
    }
}
