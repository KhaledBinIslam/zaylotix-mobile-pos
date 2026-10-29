<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * bootstrap/app.php's 422 branch — an abort(422, 'বাংলা বার্তা') hit by an
 * INERTIA visit must come back as a redirect with the message in the
 * flash.error toast (AppLayout shows it), never as Symfony's bare English
 * "Oops! An Error Occurred" page inside Inertia's error modal (seen live
 * on the sale page's void/installment sheets). Every other caller keeps
 * its exact old behavior — that scoping is what these tests pin down.
 */
class Inertia422RedirectTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_an_inertia_request_hitting_abort_422_gets_the_message_as_a_flash_error(): void
    {
        [, $owner] = $this->createShopWithOwner();

        // open a shift, then try to open a second one — WorkPeriodController
        // aborts 422 'ইতিমধ্যে একটি শিফট চলছে।'
        $this->actingAs($owner, 'web')->post('/app/work-period/open', ['opening_cash' => 100])->assertRedirect();

        $this->actingAs($owner, 'web')
            ->post('/app/work-period/open', ['opening_cash' => 100], ['X-Inertia' => 'true'])
            ->assertRedirect()
            ->assertSessionHas('error', 'ইতিমধ্যে একটি শিফট চলছে।');
    }

    public function test_a_plain_non_inertia_request_still_gets_a_422_status(): void
    {
        [, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web')->post('/app/work-period/open', ['opening_cash' => 100])->assertRedirect();

        // no X-Inertia header (plain client / the other 80+ existing tests'
        // style) — behavior unchanged, still a raw 422
        $this->actingAs($owner, 'web')
            ->post('/app/work-period/open', ['opening_cash' => 100])
            ->assertStatus(422);
    }

    public function test_a_json_caller_still_gets_message_json_not_a_redirect(): void
    {
        [, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web')->post('/app/work-period/open', ['opening_cash' => 100])->assertRedirect();

        // POS-checkout-style fetch (Accept: application/json, no X-Inertia)
        // — data.message is what its error handler reads
        $this->actingAs($owner, 'web')
            ->postJson('/app/work-period/open', ['opening_cash' => 100])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ইতিমধ্যে একটি শিফট চলছে।');
    }

    public function test_validation_errors_on_an_inertia_request_keep_their_errors_bag(): void
    {
        [, $owner] = $this->createShopWithOwner();

        // ValidationException is NOT an HttpException — the 422 branch must
        // leave it alone so Inertia keeps receiving field-level errors
        $this->actingAs($owner, 'web')
            ->post('/app/work-period/open', ['opening_cash' => 'not-a-number'], ['X-Inertia' => 'true'])
            ->assertRedirect()
            ->assertSessionHasErrors('opening_cash')
            ->assertSessionMissing('error');
    }
}
