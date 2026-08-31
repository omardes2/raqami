<?php

namespace Tests;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Start every test from a closed-by-default tenant context so nothing
        // leaks between tests (GUCs are session-level).
        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            app(TenantContext::class)->clear();
        }

        parent::tearDown();
    }
}
