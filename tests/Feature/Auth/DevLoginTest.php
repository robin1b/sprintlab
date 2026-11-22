<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_login_route_does_not_exist_outside_local(): void
    {
        $this->assertFalse(app()->environment('local'));

        $this->get('/dev/login')->assertNotFound();
        $this->assertGuest();
    }
}
