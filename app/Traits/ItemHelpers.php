<?php

namespace App\Traits;

use App\Models\Warehouse;

trait ItemHelpers
{
    public function addVariations()
    {
        if ($this->has_variants && ! empty($this->variants)) {
            $warehouses = Warehouse::all();
            $meta = $this->getCombinations(collect($this->variants)->pluck('option', 'name')->all());
            foreach ($meta as $data) {
                if (config('database.default') == 'sqlite') {
                    $variation = null;
                    foreach ($this->variations as $v) {
                        if (json_encode($v->meta) == json_encode($data)) {
                            $variation = $v;
                        }
                    }
                } else {
                    $variation = $this->variations()->where('account_id', getAccountId())->whereJsonContains('meta', $data)->exists();
                }
                if (! $variation) {
                    $variation = $this->variations()->create([
                        'meta'       => $data,
                        'sku'        => uuid1(),
                        'account_id' => getAccountId(),
                    ]);
                    if ($variation) {
                        foreach ($warehouses as $warehouse) {
                            $variation->stock()->create([
                                'weight'        => 0,
                                'quantity'      => 0,
                                'item_id'       => $this->id,
                                'warehouse_id'  => $warehouse->id,
                                'rack_location' => $this->rack_location,
                                'account_id'    => getAccountId(),
                            ]);
                        }
                    }
                }
            }
        }

        return $this;
    }

    public function saveRelations($data)
    {
        $categories = [];
        $variations = $data['variations'] ?? [];
        if (isset($data['category_id'])) {
            $categories[] = $data['category_id'];
        }
        if (isset($data['child_category_id'])) {
            $categories[] = $data['child_category_id'];
        }
        $this->setStock();
        $this->setVariations($variations);
        $this->categories()->sync($categories);
        $this->refresh()->syncVariations();

        return $this;
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['trashed'] ?? null, fn ($q, $t) => $q->{$t . 'Trashed'}())
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->search($search))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->ofCategory($category));
    }

    /**
     * The item code a person means when they type only its number or leave out the dash: "562", "0562", "RSI562", "rsi 562" and "RSI-0562" all mean RSI-0562.
     * Null when the text does not look like a code (a name, a sentence, a barcode of another make).
     */
    public static function canonicalCode($s): ?string
    {
        if (preg_match('/^(?:RSI)?[\s\-_.]*0*(\d{1,6})$/i', trim((string) $s), $m)) {
            return 'RSI-' . str_pad($m[1], 4, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Words are searched one by one (every word must be found somewhere in the code, the name, the SKU or a category), so "cement 50" finds "CEMENT 50KG BAG";
     * a word that is an item code or just its number also finds that exact code.
     */
    public function scopeSearch($query, $s)
    {
        // a search is cut at 100 characters and 8 words: more never helps a person and only makes the question longer
        $words = array_slice(preg_split('/\s+/u', mb_substr(trim((string) $s), 0, 100), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
        $whole = self::canonicalCode($s); // "RSI 562" is one code written with a space
        if (! $words) {
            return $query;
        }
        $oneWord = function ($q, string $w) {
            $code = self::canonicalCode($w);
            // a % or _ typed in the box is the character itself, not a wildcard that matches everything
            $like = '%' . addcslashes($w, '\\%_') . '%';
            $q->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhereHas('categories', fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like));
            if ($code) {
                $q->orWhere('code', $code);
            }
        };
        $query->where(function ($all) use ($words, $whole, $oneWord) {
            $all->where(function ($each) use ($words, $oneWord) {
                foreach ($words as $w) {
                    $each->where(fn ($q) => $oneWord($q, $w));
                }
            });
            if ($whole && count($words) > 1) {
                $all->orWhere('code', $whole);
            }
        });

        return $query;
    }

    /**
     * Makes sure the item has a stock row in every warehouse. This used to loop over the warehouses INSIDE a loop over the warehouses and ask the database about
     * each pair (W x W questions, 2 more for each: with 60 warehouses over 7,000 queries every time an item was saved). Now one question lists the rows that
     * exist, and only the missing ones are created.
     */
    public function setStock()
    {
        $have = $this->stock()->pluck('warehouse_id')->flip();
        foreach (Warehouse::all() as $warehouse) {
            if (! isset($have[$warehouse->id])) {
                $this->stock()->create([
                    'weight'         => 0,
                    'quantity'       => 0,
                    'warehouse_id'   => $warehouse->id,
                    'rack_location'  => $this->rack_location,
                    'alert_quantity' => $this->alert_quantity,
                ]);
            }
        }
    }

    public function setVariations(array $variations)
    {
        if (! empty($variations)) {
            foreach ($variations as $variation) {
                if (isset($variation['name']) && ! empty($variation['option'])) {
                    $instance = $this->variations()->updateOrCreate(['name' => $variation['name']], $variation);
                    if (! empty($variation['stock'])) {
                        foreach ($variation['stock'] as $stock) {
                            $instance->stock()->updateOrCreate(['warehouse_id' => $stock['warehouse_id']], $stock);
                        }
                    }
                }
            }
        }
    }

    private function getCombinations($arrays)
    {
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    if ($property_value) {
                        $tmp[] = array_merge($result_item, [$property => $property_value]);
                    }
                }
            }
            $result = $tmp;
        }

        return $result;
    }

    private function syncVariations()
    {
        $current = [];
        $variations = [];
        $meta = $this->getCombinations(collect($this->variants)->pluck('option', 'name')->all());
        foreach ($meta as $data) {
            if (config('database.default') == 'sqlite') {
                $variation = null;
                foreach ($this->variations as $v) {
                    if (json_encode($v->meta) == json_encode($data)) {
                        $variation = $v;
                        $variations[] = $variation;
                        $current[] = $variation->id;
                    }
                }
            } else {
                $variation = $this->variations()->where('account_id', getAccountId())->whereJsonContains('meta', $data)->first();
                if ($variation) {
                    $variation->update(['meta' => $data]);
                    $variations[] = $variation;
                    $current[] = $variation->id;
                }
            }
        }

        if (! empty($current)) {
            $missingVariations = $this->variations()->whereNotIn('id', $current)->get();
            foreach ($missingVariations as $missingVariation) {
                $this->stockTrails()->where('variation_id', $missingVariation->id)->forceDelete();
                $missingVariation->stock()->forceDelete();
                $missingVariation->forceDelete();
            }

            $warehouses = Warehouse::all();
            foreach ($warehouses as $warehouse) {
                $quantity = 0;
                foreach ($variations as $v) {
                    $quantity += $v->stock()->ofWarehouse($warehouse->id)->first()->quantity;
                }
                $this->stock()->ofWarehouse($warehouse->id)->update(['quantity' => $quantity]);
            }
        }

        if (! $this->has_variants && $this->variations()->exists()) {
            $this->variations->each(function ($variation) {
                $variation->stock()->forceDelete();
            });
            $this->variations()->forceDelete();
        }
    }
}
