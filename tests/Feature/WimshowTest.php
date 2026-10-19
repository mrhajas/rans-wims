<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The public knowledge base (/wimshow) must open for guests, with no login and no database. */
class WimshowTest extends TestCase
{
    public function test_home_is_public(): void
    {
        $this->get('/wimshow')->assertOk()->assertSee('WIMS Show');
    }

    public function test_a_guide_is_public(): void
    {
        $this->get('/wimshow/welcome')->assertOk()->assertSee('Welcome to WIMS');
    }

    public function test_unknown_guide_is_404(): void
    {
        $this->get('/wimshow/does-not-exist')->assertNotFound();
    }

    public function test_search_index_is_json(): void
    {
        $this->getJson('/wimshow/search.json')->assertOk()->assertJsonStructure(['icons', 'items']);
    }
}
