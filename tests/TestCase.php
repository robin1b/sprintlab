<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test must never reach GitHub or the AI provider for real. Any HTTP
        // call that is not faked fails the test instead of going out.
        Http::preventStrayRequests();
    }
}
