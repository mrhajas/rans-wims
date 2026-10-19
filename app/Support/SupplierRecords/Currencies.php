<?php

namespace App\Support\SupplierRecords;

class Currencies
{
    /**
     * Major world currencies for the Purchase Order currency picker.
     * TZS listed first so it sorts to the top as the default.
     */
    public static function all(): array
    {
        $list = [
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling'],
            ['code' => 'USD', 'name' => 'US Dollar'],
            ['code' => 'EUR', 'name' => 'Euro'],
            ['code' => 'GBP', 'name' => 'British Pound'],
            ['code' => 'KES', 'name' => 'Kenyan Shilling'],
            ['code' => 'UGX', 'name' => 'Ugandan Shilling'],
            ['code' => 'RWF', 'name' => 'Rwandan Franc'],
            ['code' => 'ZAR', 'name' => 'South African Rand'],
            ['code' => 'CNY', 'name' => 'Chinese Yuan'],
            ['code' => 'INR', 'name' => 'Indian Rupee'],
            ['code' => 'AED', 'name' => 'UAE Dirham'],
            ['code' => 'SAR', 'name' => 'Saudi Riyal'],
            ['code' => 'JPY', 'name' => 'Japanese Yen'],
            ['code' => 'CHF', 'name' => 'Swiss Franc'],
            ['code' => 'CAD', 'name' => 'Canadian Dollar'],
            ['code' => 'AUD', 'name' => 'Australian Dollar'],
            ['code' => 'NGN', 'name' => 'Nigerian Naira'],
            ['code' => 'EGP', 'name' => 'Egyptian Pound'],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr'],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha'],
            ['code' => 'MWK', 'name' => 'Malawian Kwacha'],
            ['code' => 'BWP', 'name' => 'Botswana Pula'],
            ['code' => 'XOF', 'name' => 'West African CFA Franc'],
            ['code' => 'SGD', 'name' => 'Singapore Dollar'],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar'],
        ];

        return array_map(fn ($c) => ['id' => $c['code'], 'code' => $c['code'], 'name' => $c['name'], 'label' => "{$c['code']} - {$c['name']}"], $list);
    }
}
