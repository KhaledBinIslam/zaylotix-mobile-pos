<?php

namespace Tests;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * App\Support\Tenancy::shop() now memoizes per PHP process (see
     * docs/SCALING_GUIDE.md) — correct for a real request (PHP-FPM: one
     * fresh process each time), but PHPUnit runs every test in ONE
     * long-lived process, so without this reset a shop cached by an
     * earlier test could leak into a later one that happens to reuse the
     * same (RefreshDatabase-rolled-back-then-reused) auto-increment id.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::forgetShopCache();
    }
}
