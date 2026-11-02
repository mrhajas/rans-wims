<?php

namespace Tests\Feature;

use Tests\TestCase;

// Messages about items are shown in Title Case (small joining words stay lowercase), with the item as NAME (CODE).
class TitleWordsTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_every_word_is_capitalised_except_joining_words(): void
    {
        $this->assertSame(
            '1 GANG 2 WAY SWITCH SOCKET (RSI-0123) Does Not Have 1 Pcs in Stock, Available Quantity 0 Pcs.',
            title_words('1 GANG 2 WAY SWITCH SOCKET (RSI-0123) does not have 1 Pcs in stock, available quantity 0 Pcs.')
        );
        $this->assertSame('Every Variation of Cement Needs a Quantity Greater than 0.', title_words('every variation of Cement needs a quantity greater than 0.'));
        $this->assertSame('Only 5 Are in Stock at the Warehouse and Ready for Transfer.', title_words('only 5 are in stock at the warehouse and ready for transfer.'));
    }

    public function test_the_first_word_and_the_word_after_a_colon_are_always_capitalised(): void
    {
        $this->assertSame('The Item: In Stock Now', title_words('the item: in stock now'));
        $this->assertSame('Of Course', title_words('of course'));
    }

    public function test_codes_numbers_and_other_letters_are_left_alone(): void
    {
        $this->assertSame('RSI-0123 Pcs 10', title_words('RSI-0123 pcs 10'));
        $this->assertSame('(RSI-0123) Is Low', title_words('(RSI-0123) is low'));
        $this->assertSame('IPhone', title_words('iPhone')); // only the first letter changes; the rest of a word is never touched
        $this->assertSame('CEMENT Bag', title_words('CEMENT bag')); // an item name is capitalised word by word like the rest
        $this->assertSame('', title_words(''));
    }

    public function test_other_languages_are_not_changed(): void
    {
        app()->setLocale('fr');
        $this->assertSame('le stock de la maison', title_words('le stock de la maison'));
    }

    public function test_item_text_puts_the_code_in_brackets(): void
    {
        $this->assertSame('1 GANG 2 WAY SWITCH SOCKET (RSI-0123)', item_text('1 GANG 2 WAY SWITCH SOCKET', 'RSI-0123'));
        $this->assertSame('CEMENT', item_text('CEMENT', null));
        $this->assertSame('CEMENT', item_text(' CEMENT ', '  '));
    }

    public function test_item_code_html_is_small_bold_dark_blue_and_escaped(): void
    {
        $html = (string) item_code_html('RSI-0123');
        $this->assertStringContainsString('font-size:85%', $html);
        $this->assertStringContainsString('font-weight:bold', $html);
        $this->assertStringContainsString('#1e3a8a', $html);
        $this->assertStringContainsString('(RSI-0123)', $html);
        $this->assertSame('', (string) item_code_html(''));
        $this->assertStringNotContainsString('<script>', (string) item_code_html('<script>'));
    }
}
