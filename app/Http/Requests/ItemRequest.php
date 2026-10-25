<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Http\FormRequest;

class ItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'price'             => 'numeric|min:0',
            'details'           => 'nullable',
            'variants'          => 'nullable|array',
            'stock'             => 'nullable|array',
            'track_quantity'    => 'nullable|boolean',
            'track_weight'      => 'nullable|boolean',
            'is_used'           => 'nullable|boolean',
            'has_variants'      => 'nullable|boolean',
            'has_serials'       => 'nullable|boolean',
            'alert_quantity'    => 'nullable|numeric',
            'rack_location'     => 'nullable|string',
            'unit_id'           => 'nullable|exists:units,id',
            'child_category_id' => 'nullable|exists:categories,id',
            'photo'             => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            'extra_photos'      => 'nullable|array|max:3',
            'extra_photos.*'    => 'image|mimes:png,jpg,jpeg,webp|max:15360',
            'bincard_index'     => 'nullable|integer|min:0|max:2',
            'sku'               => 'nullable|max:50|unique:items,sku,' . optional($this->route('item'))->id,

            'name'        => 'required|string',
            'symbology'   => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'code'        => 'max:50|unique:items,code,' . optional($this->route('item'))->id,

            'variants.*.option'   => 'array',
            'variants.*.name'     => 'required_if:has_variants,true',
            'variants.*.option.*' => 'required_if:has_variants,true',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = $this->validator->validated();
        unset($data['extra_photos'], $data['bincard_index']); // stored by the controller (ItemPhotoGallery), not an items column

        // no new file picked: the form's empty `photo` field must never wipe the item's saved face photo (removal has its own action)
        if (! $this->file('photo')) {
            unset($data['photo']);
        }

        if ($this->file('photo')) {
            $data['photo'] = \App\Support\ItemImage::store($this->file('photo'));
            if (optional($this->route('item'))->photo) {
                Storage::disk('assets')->delete($this->route('item')->photo);
            }
        }

        return $data;
    }

    public function withValidator($validator)
    {
        $validator->setImplicitAttributesFormatter(function ($attribute) {
            $attributes = explode('.', $attribute);
            if ($attributes[0] == 'variants') {
                if ($attributes[2]) {
                    return 'variant ' . ((int) $attributes[1] + 1) . ' ' . $attributes[2] . ' ' . (isset($attributes[3]) ? ((int) $attributes[3] + 1) : '');
                }

                return 'variant ' . ((int) $attributes[1] + 1);
            } elseif ($attributes[0] == 'attachments') {
                return 'attachments ' . ((int) $attributes[1] + 1);
            }

            return $attributes;
        });
    }
}
