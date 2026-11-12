<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSetSignupEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_turns_signup_off(): void
    {
        $this->artisan('site:set-signup-enabled', ['--enabled' => '0'])->assertSuccessful();

        $this->assertFalse(SiteSetting::current()->fresh()->signup_enabled);
    }

    public function test_turns_signup_back_on(): void
    {
        SiteSetting::current()->update(['signup_enabled' => false]);

        $this->artisan('site:set-signup-enabled', ['--enabled' => '1'])->assertSuccessful();

        $this->assertTrue(SiteSetting::current()->fresh()->signup_enabled);
    }

    public function test_requires_a_valid_enabled_value(): void
    {
        $this->artisan('site:set-signup-enabled')->assertFailed();
    }
}
