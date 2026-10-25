<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** The BinCard of an item: a photo of the stock card kept with the goods. One is current (with its date and time and who set it); older ones stay as history. */
class BinCard
{
    /** Makes this photo the item's current BinCard and stamps the date and time. */
    public static function assign(Item $item, ItemPhoto $photo, ?User $by = null): ItemPhoto
    {
        $photo->forceFill(['kind' => 'bincard', 'user_id' => optional($by)->id])->save();
        $item->forceFill(['bincard_photo_id' => $photo->id, 'bincard_at' => now(), 'bincard_by' => optional($by)->id])->saveQuietly();
        log_activity(__('BinCard updated'), ['item' => $item->name, 'code' => $item->code, 'photo' => $photo->id], $item, 'Item');

        return $photo;
    }

    /** A new photo becomes the current BinCard (the old one is kept in the history). */
    public static function refresh(Item $item, UploadedFile $file, ?User $by = null): ItemPhoto
    {
        $photo = ItemPhoto::create(['item_id' => $item->id, 'path' => ItemImage::store($file, 'bincard'), 'kind' => 'bincard', 'position' => 0, 'user_id' => optional($by)->id]);

        return self::assign($item, $photo, $by);
    }

    /** The BinCard and its history, for the BinCard window. */
    public static function details(Item $item): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('assets');
        $rows = ItemPhoto::where('item_id', $item->id)->where('kind', 'bincard')->orderByDesc('id')->get();
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $map = fn (ItemPhoto $p) => ['id' => $p->id, 'url' => $disk->url($p->path), 'at' => optional($p->created_at)->toIso8601String(), 'by' => $p->user_id ? ($names[$p->user_id] ?? null) : null];
        $current = $rows->firstWhere('id', $item->bincard_photo_id);

        return [
            'item' => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code],
            'current' => $current ? $map($current) + ['at' => optional($item->bincard_at)->toIso8601String()] : null,
            'history' => $rows->where('id', '!=', $item->bincard_photo_id)->map($map)->values(),
        ];
    }
}
