<?php

namespace Tests\Feature;

use Tests\TestCase;

class AutoBackupCommandTest extends TestCase
{
    public function test_application_timezone_is_set_for_local_time()
    {
        $this->assertSame('Africa/Dar_es_Salaam', config('app.timezone'));
    }
}
