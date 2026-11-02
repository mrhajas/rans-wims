<?php

namespace Tests\Unit;

use App\Models\Item;
use PHPUnit\Framework\TestCase;

// An item code can be typed as its number, or with RSI, with or without the dash and leading zeros.
class ItemCodeSearchTest extends TestCase
{
    public function test_number_and_rsi_forms_mean_the_same_code(): void
    {
        foreach (['562', '0562', 'RSI0562', 'rsi 562', 'RSI-0562', ' rsi-562 '] as $typed) {
            $this->assertSame('RSI-0562', Item::canonicalCode($typed), $typed);
        }
        $this->assertSame('RSI-0007', Item::canonicalCode('7'));
        $this->assertSame('RSI-12345', Item::canonicalCode('12345'));
    }

    public function test_names_are_not_codes(): void
    {
        foreach (['cement', 'cement 50', 'RSI-ABC', '', 'nails 3'] as $typed) {
            $this->assertNull(Item::canonicalCode($typed), $typed);
        }
    }
}
