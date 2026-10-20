<?php

namespace App\Models;

/**
 * An item of the Administration documents (for example "Weekly fuel - Mr Nassor"). Items marked "in the template" start every new document; every line typed
 * on a document is remembered here too, so a report can follow one item through the months.
 */
class AdminItem extends Model
{
    public static $tracksDeletes = true;

    protected $table = 'admin_items';

    protected $setUser = true;

    protected $fillable = ['account_id', 'name', 'name_key', 'category', 'staff_id', 'contact_id', 'default_beneficiary', 'default_beneficiary_user_id', 'default_amount', 'in_template', 'is_active', 'sort_order', 'user_id', 'deleted_by', 'extra_attributes'];

    public $casts = ['default_amount' => 'decimal:2', 'in_template' => 'boolean', 'is_active' => 'boolean', 'extra_attributes' => 'array'];

    public static function keyOf(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /** The item with this name (a new one when there is none yet). Typing the same item twice, in any case, is the same item. */
    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id')->withTrashed();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withTrashed();
    }

    public static function forName(string $name, ?string $beneficiary = null, float $amount = 0, ?string $category = null, ?int $staffId = null, ?int $contactId = null): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $key = self::keyOf($name);
        $item = self::query()->where('name_key', $key)->first();
        if ($item) {
            return $item;
        }

        return self::create(['name' => $name, 'name_key' => $key, 'category' => $category ?: \App\Services\Administration\AdminCategories::guess($name), 'staff_id' => $staffId, 'contact_id' => $staffId ? null : $contactId, 'default_beneficiary' => $beneficiary ?: null, 'default_amount' => $amount, 'in_template' => false, 'is_active' => true,
            'sort_order' => (int) self::query()->max('sort_order') + 1]);
    }
}
